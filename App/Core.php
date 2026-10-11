<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App;

use alirezax5\TelegramBase\App\Bootstrap\Bootstrap;
use alirezax5\TelegramBase\App\Bootstrap\PluginsBootstrap;
use alirezax5\TelegramBase\App\Bootstrap\QueueBootstrap;
use alirezax5\TelegramBase\App\Bot\BotManager;
use alirezax5\TelegramBase\App\Config\Config;
use alirezax5\TelegramBase\App\Update\Processor;
use alirezax5\TelegramBase\App\Update\Fetcher;
use alirezax5\TelegramBase\App\Update\PollingLoop;
use alirezax5\TelegramBase\App\Update\WebhookDispatcher;
use alirezax5\TelegramBase\App\Middleware\MiddlewarePipeline;
use telegramBotApiPhp\Telegram;
use alirezax5\TelegramBase\App\Enum\CoreMode;
use alirezax5\TelegramBase\App\Plugin\PluginHandler;
use alirezax5\TelegramBase\App\Queue\QueueWorker;
use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Shared\SharedManagement;
use alirezax5\TelegramBase\App\Language\Language;
use alirezax5\TelegramBase\App\Cron\CronManager;

class Core
{
    public ?Telegram $Telegram;
    private ?PluginHandler $pluginHandler = null;
    private CronManager $cronManager;
    private MiddlewarePipeline $middleware;

    public function __construct(CoreMode $mode = CoreMode::FULL, ?string $customToken = null)
    {
        Bootstrap::boot();

        $this->middleware = new MiddlewarePipeline();

        $this->initBot($customToken);
        $this->cronManager = new CronManager();

        if ($mode === CoreMode::FULL) {
            PluginsBootstrap::boot();
            $this->pluginHandler = PluginsBootstrap::getPlugins();
        }
    }

    /**
     * Access the global middleware pipeline.
     *
     * Call `->add(...)` before `run()` to register middlewares. They
     * execute in registration order, before plugin dispatch, for every
     * update (polling, webhook, or queue).
     *
     *   $core->middleware()->add(new RateLimitMiddleware());
     *   $core->run();
     */
    public function middleware(): MiddlewarePipeline
    {
        return $this->middleware;
    }

    private function initBot(?string $customToken = null): void
    {
        if ($customToken !== null) {
            BotManager::getInstance()->setDefaultToken($customToken);
        }

        $this->Telegram = BotManager::getInstance()->get();
    }

    public function run(): void
    {
        // Webhook mode: do NOT wrap dispatch() in a try/catch.
        // The WebhookDispatcher handles its own errors (returns 400/403/500
        // via dispatchIntoResponse). If we catch here, dispatch exceptions
        // are swallowed and the client gets a 200 with empty body — which
        // makes Telegram think the webhook is healthy when it's not.
        $mode = Config::bot()->mode;

        if ($mode === 'webhook_direct') {
            $this->webhookDispatcher()->dispatch();
            return;
        }

        // P3.3.1-F008 guard: an HTTP SAPI may only serve webhook-style work.
        // Previously any other BOT_MODE on the webhook entry (webhook.php)
        // fell through to the CLI switch below and started an INFINITE loop
        // (PollingLoop / QueueWorker) inside the FPM worker: Telegram never
        // received a response ("Read timeout expired" → endless retries) and
        // the worker was killed at request_terminate_timeout (502).
        if (self::isHttpSapi()) {
            $this->runHttpMode($mode);
            return;
        }

        try {

            switch ($mode) {

                case 'update_queue':
                case 'webhook_queue':
                    $this->queueWorker()?->startInfinite();
                    break;

                case 'cronjob_update':
                    $this->cronManager->run(
                        1,
                        fn() => $this->pollingLoop()->start()
                    );
                    break;

                case 'cronjob_queue':
                    $this->cronManager->run(
                        Config::cron()->cronWorker,
                        fn() => $this->queueWorker()?->runLimited(
                            Config::cron()->cronMaxTime
                        )
                    );
                    break;

                default:
                    $this->pollingLoop()->start();
            }

        } catch (\Throwable $e) {

            LogHandler::error(
                'Runtime error: ' . $e->getMessage()
            );
        }
    }

    /**
     * P3.3.1-F008: true when PHP is serving an HTTP request instead of
     * running as a standalone CLI process.
     *
     * Kept as a tiny public static helper so the guard is unit-testable
     * without actually changing the SAPI.
     */
    public static function isHttpSapi(?string $sapi = null): bool
    {
        $sapi ??= PHP_SAPI;

        // 'cli' = command line, 'phpdbg' = the debugger. Everything else
        // (fpm-fcgi, cgi-fcgi, cli-server, apache2handler, litespeed, ...)
        // is an HTTP-serving SAPI.
        return $sapi !== 'cli' && $sapi !== 'phpdbg';
    }

    /**
     * P3.3.1-F008: route an HTTP (web SAPI) request according to BOT_MODE.
     *
     * Contract:
     *   - webhook_direct  → handled earlier (fast path before the guard)
     *   - webhook_queue   → validate + enqueue the single webhook payload,
     *                       respond 200 (producer semantics)
     *   - update_direct   → process the single incoming update synchronously
     *                       (same "direct" contract, just entered via HTTP);
     *                       a warning is logged so misconfiguration is visible
     *   - everything else → CLI-only (update_queue / cronjob_*): fail fast
     *                       with HTTP 500 instead of hanging the worker
     */
    private function runHttpMode(string $mode): void
    {
        if ($mode === 'webhook_queue') {
            $this->webhookDispatcher()->dispatch();
            return;
        }

        if ($mode === 'update_direct') {
            LogHandler::warning(
                'F008 guard: BOT_MODE=update_direct served an HTTP webhook request'
                . ' — processing synchronously. Set BOT_MODE=webhook_direct on web'
                . ' entry points to make this explicit.'
            );

            $this->webhookDispatcher()->dispatch();
            return;
        }

        $msg = "F008 guard: BOT_MODE '{$mode}' is CLI-only and cannot serve HTTP"
            . ' requests. Use webhook_direct or webhook_queue on web entry points'
            . ' (webhook.php), or run this mode from the CLI (bot.php / queue.php).';

        LogHandler::error($msg);

        if (!headers_sent()) {
            http_response_code(500);
        }

        echo $msg;
    }

    /**
     * Run the update-fetch phase (polling or webhook) and push updates
     * into the configured queue.
     *
     * - webhook_queue mode: reads the raw webhook payload once and pushes it.
     * - other queue modes: long-polls Telegram for new updates and pushes
     *   each one, advancing the local offset only on successful push.
     *
     * Graceful shutdown: SIGINT/SIGTERM set a flag that the fetch loop
     * checks each iteration. A long-polling getUpdates call (up to
     * POLLING_TIMEOUT seconds) will complete before the flag is honored.
     */
    public function runFetchQueueUpdate(): void
    {
        if (Config::bot()->mode === 'webhook_queue') {

            $input = $this->Telegram->getInputData();

            if ($input) {
                QueueBootstrap::getQueue()?->push($input);
            }

            $this->cleanup();

            return;
        }

        $fetcher = new Fetcher($this->Telegram);

        // Register signal handlers so the fetcher can exit cleanly.
        // Same pattern as PollingLoop and QueueWorker.
        $shouldStop = false;
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () use (&$shouldStop) {
                $shouldStop = true;
            });
            pcntl_signal(SIGTERM, function () use (&$shouldStop) {
                $shouldStop = true;
            });
        }

        LogHandler::info('Queue fetcher started');

        while (!$shouldStop) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            try {
                foreach ($fetcher->fetch() as $update) {
                    if (QueueBootstrap::getQueue()?->push($update)) {
                        $fetcher->updateLastId(
                            $update->update_id + 1
                        );
                    }
                }
                $this->cleanup();
            } catch (\Throwable $e) {
                LogHandler::error('Fetch loop error: ' . $e->getMessage());
                usleep(500000);
            }
        }

        LogHandler::info('Queue fetcher stopped');
    }

    /**
     * Clean up per-iteration state (shared data + missing language keys).
     */
    private function cleanup(): void
    {
        SharedManagement::clear();
        Language::getInstance()->flushMissingKeys();
    }

    private function processor(): Processor
    {
        $processor = new Processor(
            $this->pluginHandler,
            $this->Telegram
        );

        // Wire the middleware pipeline so that middlewares registered via
        // $core->middleware()->add(...) run before plugin dispatch for
        // every update — polling, webhook, and queue worker alike.
        $processor->setMiddleware($this->middleware);

        return $processor;
    }

    private function webhookDispatcher(): WebhookDispatcher
    {
        return new WebhookDispatcher($this->processor());
    }

    private function pollingLoop(): PollingLoop
    {
        return new PollingLoop(
            new Fetcher($this->Telegram),
            $this->processor()
        );
    }

    private function queueWorker(): QueueWorker
    {
        return new QueueWorker(
            QueueBootstrap::getQueue(),
            $this->pluginHandler,
            $this->Telegram,
            $this->middleware,
        );
    }
}