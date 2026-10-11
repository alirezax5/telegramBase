<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Update;

use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Shared\SharedManagement;
use alirezax5\TelegramBase\App\Language\Language;

class PollingLoop
{
    private bool $shouldStop = false;
    private int $processedCount = 0;

    public function __construct(
        private Fetcher   $fetcher,
        private Processor $processor,
        private int       $cleanupInterval = 0
    )
    {
        // Default: self-restart every 100 batches to keep memory bounded
        // on long-running workers. This expects a process supervisor
        // (systemd / supervisord / Docker restart:always) to restart
        // the process after exit.
        //
        // Set POLLING_RESTART_INTERVAL=0 in .env to disable self-restart
        // (use only when memory growth is confirmed to be zero and no
        // supervisor is in place — not recommended for production).
        if ($this->cleanupInterval === 0) {
            $this->cleanupInterval = (int)(
                \alirezax5\TelegramBase\App\Environment\EnvHandler::get(
                    'POLLING_RESTART_INTERVAL',
                    100
                )
            );
        }
    }

    public function start(): void
    {
        set_time_limit(0);
        $this->registerSignalHandler();

        LogHandler::info('Polling loop started');

        $this->runLoop();

        LogHandler::info('Polling stopped');
    }

    private function runLoop(): void
    {
        while (!$this->shouldStop) {

            $this->dispatchSignals();

            try {
                $updates = $this->fetcher->fetch();

                if (empty($updates)) {
                    usleep(250000);
                    continue;
                }

                $this->handleBatch($updates);

                $this->processedCount++;

                $this->maintenance();

            } catch (\Throwable $e) {
                LogHandler::error('Polling error: ' . $e->getMessage());
                usleep(500000);
            }
        }
    }

    private function handleBatch($updates): void
    {
        $this->processor->handleBatch(
            $updates,
            function ($update) {
                $id = $update->update_id ?? 0;

                if ($id > 0) {
                    $this->fetcher->updateLastId($id + 1);
                }
            }
        );
    }

    /**
     * Periodic maintenance: drain retry queue, force GC + filesystem
     * stat cache purge, flush per-request shared state.
     *
     * Shared plugin state and missing-language-key queues are flushed so
     * long-running loops do not accumulate memory.
     */
    private function maintenance(): void
    {
        // Drain any due retry items on every maintenance tick so the
        // retry queue is not write-only (Phase 2 G2 fix).
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

        // Self-restart: when processedCount reaches cleanupInterval, exit
        // so a process supervisor can restart us with fresh memory. Set
        // POLLING_RESTART_INTERVAL=0 to disable (not recommended unless
        // you've confirmed zero memory growth and have no supervisor).
        if ($this->cleanupInterval > 0 && $this->processedCount >= $this->cleanupInterval) {
            LogHandler::info('Restart cycle reached');
            $this->shouldStop = true;
        }
    }

    private function registerSignalHandler(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        pcntl_signal(SIGINT, fn() => $this->shouldStop = true);
        pcntl_signal(SIGTERM, fn() => $this->shouldStop = true);
    }

    /**
     * Dispatch system signals
     */
    private function dispatchSignals(): void
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }
}
