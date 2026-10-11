# Конвейер очереди — когда одного процесса недостаточно

## Зачем вообще очередь?

У инлайн-обработки есть простая слабость: процесс, который *принимает* обновление, должен и *завершить* его обработку. Если хендлер загружает видео на 50 МБ или ждёт медленный сторонний API, приёмник заблокирован (а в режиме вебхука Telegram ждёт свой 200). Конвейер очереди разделяет работу:

```
producer                          broker                    consumers (N processes)
──────                            ──────                    ────────
queue.php / webhook.php  ──push──► Redis / RabbitMQ ──pop──► bot.php workers
(pick up updates fast,            (holds updates while       (do the slow work,
 advance offset / 200)             nobody is around)          ack after processing)
```

Преимущества: быстрый приём (Telegram никогда не ждёт ваши хендлеры), горизонтальное масштабирование (запускайте больше воркеров) и изоляция сбоев (умирающий воркер не влияет на приёмник).

## Выбор драйвера

| Драйвер | `QUEUE_SAVE_TYPE` | Требует | Поведение pop | Гарантия при падении воркера |
|---------|-------------------|---------|---------------|------------------------------|
| JSON | `json` *(по умолчанию)* | ничего | разрушающее чтение файла | **at-most-once** — задача теряется при падении посреди обработки |
| Redis | `redis` | расширение `redis` + сервер | `LPOP` (разрушающее) | **at-most-once** — задача теряется при падении посреди обработки |
| Memcached | `memcached` | расширение `memcached` + сервер | разрушающие get/delete | **at-most-once** |
| RabbitMQ | `rabbitmq` | расширение `amqp` + сервер | `basic_get` + **ack-after-process** (v2.4.0) | **at-least-once** — неподтверждённая задача доставляется повторно |

Выбирайте RabbitMQ, когда потеря даже одного обновления недопустима; выбирайте Redis/JSON ради пропускной способности и простоты, когда эпизодическая потеря терпима (или когда обновления дёшево воссоздать).

## Контракт ack в RabbitMQ (v2.4.0)

Начиная с v2.4.0 драйвер RabbitMQ использует семантику **ack-late**:

1. `pop()` извлекает сообщение и хранит его delivery tag — сообщение ещё *не* подтверждено.
2. Выполняется ваш хендлер.
3. Воркер вызывает `ack()` **после** обработки. Только тогда брокер удаляет сообщение.
4. Если воркер умирает в любой момент между 1 и 3, брокер повторно доставляет сообщение следующему воркеру.

На уровне кода воркер делает ровно это:

```php
$update = $queue->pop();
try {
    $processor->handle($update);
} finally {
    $queue->ack();   // success → done; handled exception → discarded (no poison loops)
}
```

Последствия, которые стоит знать:

- **Возможна повторная обработка** (падение после обработки, но до ack — брокер этого не различает). Делайте хендлеры идемпотентными: сохраняйте `update_id` перед действием, пропускайте, если уже видели. См. [reliability.md](reliability.md).
- Сообщение, которое не удалось декодировать, подтверждается немедленно (отбрасывается), чтобы оно не отравило очередь.
- Пользовательские драйверы очереди не обязаны реализовывать `ack()` — `QueueManager::ack()` является no-op для драйверов без брокерского подтверждения.

Конфигурация живёт в секции `rabbitmq` файла `.env` (host, port, user, password, vhost, имя очереди) — см. [.env.example](../../.env.example).

## Запуск конвейера

`update_queue` (polling-продюсер):

```bash
# terminal 1 — fetcher: long-polls Telegram, pushes to the broker
php queue.php

# terminal 2..N — consumers: pop + process + ack
php bot.php
```

`webhook_queue` (webhook-продюсер):

```bash
# web server receives Telegram POSTs → validates → enqueues → 200
# (webhook.php handles this automatically when BOT_MODE=webhook_queue)

# terminal 1..N — consumers
php bot.php
```

Запускайте их все под супервизором. Воркеры автоматически переподключаются к брокеру с экспоненциальной задержкой (`BACKOFF_INITIAL=1s` … `BACKOFF_MAX=60s`), так что вы можете перезапускать Redis/RabbitMQ, не трогая воркеров.

## Ограниченные очереди

`QUEUE_MAX_SIZE` (по умолчанию согласно [.env.example](../../.env.example)) ограничивает, сколько обновлений может находиться в брокере. Когда лимит достигнут, новые отправки **отклоняются и логируются** (WARNING), а не молча сваливаются в неограниченную кучу. Это защищает диск/ОЗУ, когда воркеры долго простаивают. Значение 0 отключает ограничение.

## Очередь повторов (отдельно от основной очереди)

Очередь повторов обрабатывает *временные сбои хендлеров* (HTTP `429`, `5xx`, таймауты, ошибки cURL) для всех режимов, а не только для очередей:

- `Processor::handle()` ловит повторяемую ошибку → отправляет обновление в `RetryQueue`.
- Долгоживущие воркеры сливают созревшие элементы на своём тике обслуживания (каждая итерация цикла в воркерах очереди).
- Задержка удваивается с каждой попыткой; когда Telegram передаёт `retry_after`, учитывается именно это значение (`RetryableException::retryAfter`).
- После `RETRY_MAX_ATTEMPTS` элемент отбрасывается и логируется.

Ваши хендлеры подключаются с помощью хелпера `tg_retryable()`:

```php
use function alirezax5\TelegramBase\App\Queue\tg_retryable;

$resp = tg_retryable($tg->sendMessage($chatId, 'Hello!'), 'sendMessage');
// ok=true            → returned unchanged
// 429 / 5xx          → RetryableException thrown → retry queue
// other 4xx          → returned unchanged (permanent errors are not retried)
```

Конфигурация: `RETRY_ENABLED`, `RETRY_QUEUE_DRIVER` (`json`|`redis`), `RETRY_MAX_ATTEMPTS`, `RETRY_BASE_DELAY`, `RETRY_MAX_SIZE` — значения по умолчанию в [.env.example](../../.env.example).
