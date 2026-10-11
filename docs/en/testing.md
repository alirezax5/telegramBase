# Testing — the offline suite, live tests and CI

## The offline test suite

The framework ships with a dependency-free test runner (no PHPUnit needed):

```bash
composer install        # vendor/ must exist (the suite boots the framework)
php tests/run.php
```

Structure:

| Directory | What lives there |
|-----------|------------------|
| `tests/Unit/` | pure unit tests — env handler, config, queue drivers, offset storage, webhook dispatcher validation, middleware wiring, retry lifecycle, ... (no network) |
| `tests/Integration/` | multi-component tests (still offline) |
| `tests/Live/` | **real Telegram API tests — opt-in only** (see below) |
| `tests/smoke.php` | standalone bootstrap smoke test (bad token, empty token, bad mode, valid boot) |
| `tests/benchmark.php` | micro-benchmarks for hot paths |

Filter by name:

```bash
php tests/run.php --filter=Keyboard
php tests/run.php --filter=Queue
```

## Known suite drift — RESOLVED

Up to v2.4.0 the offline run reported **196 pass / 18 fail / 12 skip**. The 18 failures (`SessionTest` ×10, `BackwardCompatTest` ×4, `MiddlewareWiringTest` ×4) were pre-existing and had been proven unrelated to the framework fixes by reverting each fix and re-running.

The root cause was found during the pre-release review (P-REL-B05): `tests/run.php` seeds env defaults and calls `Bootstrap::boot()`, but `EnvironmentBootstrap` used Dotenv's `load()`, which **throws when the repo has no `.env`** (a fresh clone only ships `.env.example`). Boot aborted, `Config` stayed uninitialized, and every config-dependent test failed. With the switch to `safeLoad()` the suite runs green in a fresh clone without any setup:

```
Pass: 224  Fail: 0  Skip: 12
```

## Live Telegram tests

Live tests hit the real Bot API and are skipped unless explicitly enabled:

```bash
RUN_TELEGRAM_LIVE_TESTS=1 \
TELEGRAM_BOT_TOKEN="<your-token>" \
TELEGRAM_TEST_CHAT_ID="<your-chat-id>" \
php tests/run.php
```

House rules (enforced by the tests themselves):

- every message they send is tagged `[telegramBase automated test]`
- they delete their own messages after verification where possible — and never delete messages they didn't create
- negative paths included: invalid chat, invalid token, simulated 429
- the token is read **only** from environment variables

## CI (GitHub Actions)

`.github/workflows/tests.yml` runs on every push/PR across PHP **8.1 – 8.4**: composer validation, `composer audit`, and the offline suite.

`.github/workflows/live-tests.yml` is a **manual** (`workflow_dispatch`) workflow for live tests; it reads the token from the `TGBASE_LIVE_BOT_TOKEN` repository secret and a `chat_id` input — no secrets in the workflow file, and default CI never touches secrets.

## Smoke test

```bash
php tests/smoke.php
```

Six cases: bad token / empty token / log-traversal attempt / log absolute path / bad mode / valid boot. Use it after deployments as a 5-second health check.

## What the suite covers (and why it matters for trust)

The reliability claims in [reliability.md](reliability.md) are backed by tests plus crash-tested integration scenarios (SIGKILL at every pipeline stage, real broker redelivery checks, webhook transition tests with `getWebhookInfo` verification). The most relevant suites for auditing those claims:

```bash
php tests/run.php --filter=Queue      # driver behaviour (json/redis/rabbitmq contracts)
php tests/run.php --filter=Offset     # offset storage atomicity
php tests/run.php --filter=Retry      # retry queue lifecycle + retry_after
php tests/run.php --filter=Webhook    # the 5 webhook validation layers
php tests/run.php --filter=Security   # env/log hardening rules
```

The integration harness used during crash-testing (a real bot + scenario scripts) lives in `integration/TestBot/` — see its README for what it does and how to adapt it to your own deployment.
