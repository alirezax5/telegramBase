# TelegramBase

[English](README.md) · [فارسی](README.fa.md) · [中文](README.zh.md) · **Русский** · [Français](README.fr.md) · [Português](README.pt.md) · [العربية](README.ar.md)

Модульный мини-фреймворк на PHP 8.1+ для создания Telegram-ботов — с системой плагинов, бэкендами очередей, валидацией вебхуков, сессиями, middleware, планировщиком, миграциями и CLI-скаффолдингом. Построен на компонентах Illuminate (Laravel) вместо их переизобретения.

## Что это такое — в одном абзаце?

TelegramBase берёт на себя всю *обвязку*, которая нужна каждому Telegram-боту, — приём обновлений (polling или вебхук), валидацию webhook-запросов, диспетчеризацию обновлений в ваш код, повторные попытки для неудавшихся вызовов API и общение с бэкендами очередей вроде Redis и RabbitMQ, — так что вам остаётся писать только *интересную* часть: небольшие PHP-классы под названием **плагины**, которые реагируют на сообщения. Положите класс в `Plugin/`, и фреймворк обнаружит и запустит его. В этом вся идея.

## Почему он существует?

Писать бота «напрямую против API» легко, пока не приходит реальный трафик. Тогда нужно найти ответы на вопросы вроде: *Что произойдёт, если мой воркер упадёт посреди обновления? Где валидируются webhook-запросы? Как повторить `429 Too Many Requests`, не потеряв обновление? Могу ли я вынести тяжёлую работу за пределы HTTP-запроса?* TelegramBase отвечает на все эти вопросы заранее:

- **Шесть режимов работы** (`update_direct`, `update_queue`, `webhook_direct`, `webhook_queue`, `cronjob_update`, `cronjob_queue`) — переключайтесь между polling и вебхуком, инлайн- и очередённой обработкой, меняя одно значение в `.env`.
- **Честная семантика доставки** — фреймворк прошёл crash-тестирование от начала до конца (SIGKILL внутри каждого этапа конвейера). Гарантия каждого режима задокументирована: пути webhook/direct — *at-least-once*, конвейер RabbitMQ — *at-least-once* (ack-after-process), разрушающие драйверы (Redis/JSON/Memcached) — *at-most-once*. См. [docs/reliability.md](docs/ru/reliability.md).
- **Защита от реальных ошибок конфигурации** — запуск режима «только CLI» за webhook-точкой входа отклоняется с HTTP 500 вместо зависания вашего веб-воркера в бесконечном polling-цикле (баг, с которым мы реально столкнулись в продакшен-тестировании).
- **Батарейки в комплекте** — сессии, конструктор клавиатур, i18n, middleware (rate-limit / admin-guard / audit-log), планировщик, Eloquent-миграции, ограниченные очереди, автоматические повторы с учётом Telegram `retry_after`.

## Требования

- PHP 8.1+
- Composer
- Расширения: `json`, `mbstring`, `curl`
- Необязательно: `redis`, `memcached`, `amqp` (бэкенды очереди/кэша)
- Необязательно: MySQL/MariaDB (база данных, Eloquent-миграции)

## Быстрый старт

```bash
git clone https://github.com/alirezax5/telegramBase.git
cd telegramBase
composer install
cp .env.example .env
# Edit .env — set BOT_TOKEN (get one from @BotFather)
php bot.php
```

Отправьте боту `/start` — отвечает стартовый плагин в `Plugin/start.php`. Затем напишите свой собственный плагин (см. [docs/plugins.md](docs/ru/plugins.md)).

## Как проходит обновление

```
Telegram
   │
   ├─ getUpdates long-poll ──► bot.php ──┐
   │                                     │
   └─ webhook POST ──► webhook.php ──────┤
                                         ▼
                                    Core::run()
                                         │
                       validate (webhook)│mode routing
                                         ▼
                                    Processor
                                         │
                             middleware pipeline
                                         ▼
                              your plugins (Plugin/)
```

## Документация

Всё лежит в `docs/` — начните с обзора:

| Документ | Что внутри |
|----------|------------|
| [docs/overview.md](docs/ru/overview.md) | Что такое фреймворк, что он делает и чего сознательно не делает |
| [docs/architecture.md](docs/ru/architecture.md) | Последовательность загрузки, поток обновления, карта компонентов `App/` |
| [docs/getting-started.md](docs/ru/getting-started.md) | Установка → первый плагин → развёртывание, шаг за шагом |
| [docs/bot-modes.md](docs/ru/bot-modes.md) | Все шесть `BOT_MODE`, точки входа, какие процессы запускать |
| [docs/plugins.md](docs/ru/plugins.md) | Написание плагинов: хендлеры, приоритет, `#[ChatType]`, обнаружение |
| [docs/queue-pipeline.md](docs/ru/queue-pipeline.md) | Бэкенды очередей, воркеры, очередь повторов, семантика ack |
| [docs/webhook.md](docs/ru/webhook.md) | Настройка вебхука, 5 слоёв валидации, заметки о прокси/CDN |
| [docs/reliability.md](docs/ru/reliability.md) | Результаты crash-тестов: гарантии доставки по каждому режиму, руководство по идемпотентности |
| [docs/configuration.md](docs/ru/configuration.md) | Полный справочник `.env` |
| [docs/features.md](docs/ru/features.md) | Сессии, клавиатуры, middleware, кэш, i18n, планировщик, база данных |
| [docs/caching.md](docs/ru/caching.md) | Глубокий разбор CacheManager: драйверы, TTL, охват flush, кросс-процессная матрица (проверено вживую) |
| [docs/sessions.md](docs/ru/sessions.md) | Состояние сессий по чатам: API, многошаговые сценарии, заметки по безопасности |
| [docs/i18n.md](docs/ru/i18n.md) | Переводы: раскладка файлов, хелпер `__()`, язык по пользователю, кэширование |
| [docs/buttons.md](docs/ru/buttons.md) | Конструктор клавиатур + меню, задаваемые файлами (`btn()`), обработка callback |
| [docs/multi-bot.md](docs/ru/multi-bot.md) | Несколько ботов в одном процессе: `BotManager`, `BOT_EXTRA_BOTS`, стек токенов (проверено вживую) |
| [docs/testing.md](docs/ru/testing.md) | Офлайн-набор, живые тесты Telegram, smoke-тест, CI |
| [docs/troubleshooting.md](docs/ru/troubleshooting.md) | Типовые симптомы и решения, подводные камни развёртывания |

Другие полезные файлы:

- [CHANGELOG.md](CHANGELOG.md) — что изменилось в каждой версии
- [llms.txt](llms.txt) / [llms-ctx.txt](llms-ctx.txt) — ориентированный на LLM индекс документации и однофайловый контекст
- [.env.example](.env.example) — каждый конфигурационный ключ со значениями по умолчанию
- [examples/HelloBot.php](examples/HelloBot.php) — полный пример плагина

## CLI

```bash
php bin/tgbase list              # all commands
php bin/tgbase make:plugin admin/ban   # scaffold a plugin
php bin/tgbase doctor            # environment diagnostics
php bin/tgbase plugins           # list discovered plugins
php bin/tgbase migrate           # run database migrations
```

## Лицензия

MIT
