# TelegramBase Documentation — Language Hub

Documentation is available in **7 languages**. Every folder below is a complete, self-contained copy of the full doc set (17 documents):

| Language | Folder | شروع | 入门 | Начало | Démarrage | Início | البداية |
|---|---|---|---|---|---|---|---|
| English | [docs/en/](en/) | [overview](en/overview.md) | [getting-started](en/getting-started.md) | | | | |
| فارسی (Persian) | [docs/fa/](fa/) | [مرور کلی](fa/overview.md) | [شروع کار](fa/getting-started.md) | | | | |
| 中文 (Chinese) | [docs/zh/](zh/) | [概述](zh/overview.md) | [快速上手](zh/getting-started.md) | | | | |
| Русский (Russian) | [docs/ru/](ru/) | [обзор](ru/overview.md) | [начало работы](ru/getting-started.md) | | | | |
| Français (French) | [docs/fr/](fr/) | [aperçu](fr/overview.md) | [démarrage](fr/getting-started.md) | | | | |
| Português (Portuguese) | [docs/pt/](pt/) | [visão geral](pt/overview.md) | [primeiros passos](pt/getting-started.md) | | | | |
| العربية (Arabic) | [docs/ar/](ar/) | [نظرة عامة](ar/overview.md) | [البداية](ar/getting-started.md) | | | | |

## Document index (identical in every language folder)

1. `overview.md` — what the framework is and what it deliberately doesn't do
2. `architecture.md` — boot sequence, update flow, component map
3. `getting-started.md` — install → first plugin → deploy
4. `bot-modes.md` — all six `BOT_MODE`s, entry points, delivery semantics
5. `plugins.md` — handlers, priority, `#[ChatType]`, discovery
6. `queue-pipeline.md` — queue backends, RabbitMQ ack contract, retry queue
7. `webhook.md` — webhook setup, 5 validation layers, proxy notes
8. `reliability.md` — crash-test results, delivery guarantees, idempotency
9. `configuration.md` — full `.env` reference
10. `features.md` — sessions, keyboards, middleware, cache, i18n, scheduler, database
11. `caching.md` — CacheManager deep dive (live-verified)
12. `sessions.md` — per-chat session state
13. `i18n.md` — translations, `__()` helper, per-user language
14. `buttons.md` — keyboard builder + file-defined menus
15. `multi-bot.md` — multiple bots in one process (live-verified)
16. `testing.md` — offline suite, live tests, CI
17. `troubleshooting.md` — symptoms → causes → fixes

> **Canonical language:** English (`docs/en/`). Translations are refreshed from the English set at release time — if a translation reads stale, the English original is the source of truth.
