<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Queue;

use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Middleware\MiddlewarePipeline;
use alirezax5\TelegramBase\App\Plugin\PluginHandler;
use alirezax5\TelegramBase\App\Update\Processor;
use alirezax5\TelegramBase\App\Shared\SharedManagement;
use alirezax5\TelegramBase\App\Language\Language;
use telegramBotApiPhp\Telegram;

final class QueueWorker
{
    private QueueManager $queue;
    private Processor $processor;

    private const BLOCK_TIMEOUT = 5;
    private const CLEANUP_INTERVAL = 100;
    private const BACKOFF_INITIAL = 1;    // seconds
    private const BACKOFF_MAX = 60;

    private int $processedCount = 0;
    private int $backoffSec = 0;
    private bool $shouldStop = false;

    public function __construct(
        QueueManager $queue,
        ?PluginHandler $plugins,
        Telegram $Telegram,
        ?MiddlewarePipeline $middleware = null,
    ) {
        $this->queue = $queue;
        $this->processor = new Processor($plugins, $Telegram);

        // Wire middleware into the queue worker's processor too, so
        // middlewares registered via $core->middleware()->add(...) also
        // apply to updates consumed from the queue.
        if ($middleware !== null) {
            $this->processor->setMiddleware($middleware);
        }
    }

    public function startInfinite(): void
    {
        set_time_limit(0);
        $this->registerSignalHandler();

        LogHandler::info('Queue worker started');

        $this->processLoop();

        LogHandler::info('Queue worker stopped');
    }

    public function runLimited(int $maxSeconds = 50): void
    {
        $processed = $this->processLoop(
            time() + $maxSeconds
        );

        LogHandler::info(
            "Limited worker finished: {$processed} updates"
        );
    }

    private function processLoop(?int $endTime = null): int
    {
        $processed = 0;

        while (!$this->shouldStop && ($endTime === null || time() < $endTime)) {
            $this->dispatchSignals();

            try {
                if (!$this->queue->isConnected()) {
                    $this->backoffSec = $this->backoffSec === 0
                        ? self::BACKOFF_INITIAL
                        : min($this->backoffSec * 2, self::BACKOFF_MAX);
                    LogHandler::warning("Queue not connected, backing off {$this->backoffSec}s");
                    sleep($this->backoffSec);
                    continue;
                }
                $this->backoffSec = 0;

                $update = $this->queue->pop(self::BLOCK_TIMEOUT);

                if (!$update) {
                    $this->maintenance();
                    continue;
                }

                try {
                    $this->processor->handle($update);
                } finally {
                    // P3.3.1-F006 fix: ack AFTER processing. On success the
                    // message is done; on a handled exception it is discarded
                    // (prevents poison redelivery loops). Only a hard process
                    // death BEFORE this point leaves the message unacked so
                    // the broker can redeliver it — at-least-once instead of
                    // the old ack-at-pop at-most-once.
                    $this->queue->ack();
                }

                $processed++;
                $this->processedCount++;

                $this->maintenance();

            } catch (\Throwable $e) {
                LogHandler::error(
                    'Queue worker error: ' . $e->getMessage(),
                    [
                        'message'   => $e->getMessage(),
                        'code'      => $e->getCode(),
                        'file'      => $e->getFile(),
                        'line'      => $e->getLine(),
                        'update_id' => isset($update)
                            ? (is_array($update) ? ($update['update_id'] ?? null) : ($update->update_id ?? null))
                            : null,
                    ]
                );

                usleep(500_000);
            }
        }

        return $processed;
    }

    private function maintenance(): void
    {
        // Drain any due retry items so the retry queue is not
        // write-only (Phase 2 G2 fix).
        try {
            $this->processor->processRetries();
        } catch (\Throwable $e) {
            LogHandler::warning('Retry drain error: ' . $e->getMessage());
        }

        if ($this->processedCount % 50 === 0) {
            gc_collect_cycles();
            clearstatcache();
            SharedManagement::clear();
            Language::getInstance()->flushMissingKeys();
        }
    }

    /**
     * Register SIGINT/SIGTERM handlers so the worker can exit cleanly.
     *
     * Mirrors PollingLoop's signal handling. The handler sets a flag
     * that the loop checks each iteration; a long-blocking pop(5) will
     * still complete before the flag is honored.
     */
    private function registerSignalHandler(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        pcntl_signal(SIGINT, fn() => $this->shouldStop = true);
        pcntl_signal(SIGTERM, fn() => $this->shouldStop = true);
    }

    private function dispatchSignals(): void
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }
}