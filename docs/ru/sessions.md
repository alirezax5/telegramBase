# Сессии — SessionManager

Состояние по чатам, сохраняющееся между сообщениями: многошаговые мастера форм, «нажмите 1 для…», корзины покупок, переключатели режимов на пользователя. Сессии хранятся через [CacheManager](caching.md), ключом служит chat id, а TTL автоматически завершает неактивные разговоры.

```php
use alirezax5\TelegramBase\App\Session\SessionManager;
```

## API

```php
// Start (or resume) a session — creates one when none exists
$session = SessionManager::start($chatId);

$session->set('step', 'waiting_email');        // persists immediately
$session->setMany(['cart' => ['item1'], 'lang' => 'fa']);
$step = $session->get('step');                  // null when unset
$step = $session->get('step', 'default');       // with fallback
$session->has('step');                          // true even when value is null
$session->all();                                // whole payload as array
$session->forget('cart');                       // remove one key
$session->regenerate();                         // new random session id (after login!)
$session->touch();                              // refresh last-activity
$session->destroy();                            // delete entirely

// Read WITHOUT creating (null when absent — use in read-only paths)
$existing = SessionManager::get($chatId);
SessionManager::has($chatId);
SessionManager::flush($chatId);                 // delete
```

Под капотом вся полезная нагрузка — это одна запись кэша:

| Ключ кэша | `SESSION_PREFIX` (по умолчанию `tgbase:session:`) + chat id |
|---|---|
| TTL | `SESSION_TTL` (по умолчанию 3600 с, минимум 60 с) — **каждый `set()` заново сохраняет и сбрасывает окно** |
| Полезная нагрузка | `['id' => sessionId, 'data' => […], 'last_activity' => ts]` |

## Многошаговый сценарий

```php
public function onMessage($message, Telegram $tg): void
{
    $text = trim($message->text ?? '');
    $chatId = $tg->chatId();

    $session = SessionManager::start($chatId);

    switch ($session->get('step')) {
        case null:
            if ($text === '/register') {
                $session->set('step', 'ask_email');
                $resp = $tg->sendMessage($chatId, 'ایمیلت را بفرست:');
                tg_retryable($resp, 'sendMessage');
            }
            return;

        case 'ask_email':
            if (!filter_var($text, FILTER_VALIDATE_EMAIL)) {
                $resp = $tg->sendMessage($chatId, 'ایمیل معتبر نیست — دوباره بفرست:');
                tg_retryable($resp, 'sendMessage');
                return;                              // stay on the same step
            }
            $session->setMany(['email' => $text, 'step' => 'done']);
            $resp = $tg->sendMessage($chatId, "ثبت شد: {$text}");
            tg_retryable($resp, 'sendMessage');
            return;
    }
}
```

У каждого пользователя своя независимая машина состояний, потому что сессия привязана к chat id.

## Какой драйвер кэша мне нужен?

- **Polling-демон (`update_direct` / фетчер `update_queue`):** один долгоживущий PHP-процесс — даже `array` сохраняет состояние между сообщениями. Но состояние умирает при перезапуске.
- **Вебхук (`webhook_*`) и воркеры очереди:** состояние должно пересекать границы процессов → `file`, `redis` или `memcached`.

Проверено вживую: сессия, записанная в процессе A (`sid d5dfbf11…`, шаг + корзина), была прочитана обратно в свежем процессе B на `file`, `redis` и `memcached` — **тот же id сессии, идентичная полезная нагрузка**; на `array` процесс B не увидел ничего (задокументировано).

## Заметки по безопасности

- `regenerate()` после любого изменения привилегий (логина), чтобы предотвратить фиксацию сессии — id представляет собой свежий 32-символьный hex random.
- Сессии сами по себе не несут идентичности: ключ — chat id. В групповых чатах все участники разделяют сессию чата — для состояния по пользователю в группах вместо этого ключуйте по `$tg->fromId()` в собственных ключах кэша.
- Долго неактивные сессии истекают молча (`SESSION_TTL`); проектируйте сценарии так, чтобы переживать отсутствие сессии (`SessionManager::get() === null` → начать заново).

## Тестовые команды (integration/TestBot)

`/session` выводит id + ключи + данные · `/session-set <k> <v>` · `/session-get <k>` · `/session-clear`. Пара set/get была выполнена через реальный конвейер плагинов в двух отдельных процессах в рамках верификации P-CACHE.
