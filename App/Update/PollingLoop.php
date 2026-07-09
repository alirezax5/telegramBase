<?php

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
        private int       $cleanupInterval = 100
    )
    {
    }

    public function start(): void
    {
        set_time_limit(0);
        $this->registerSignalHandler();

        LogHandler::info('Polling loop started');

        $this->runLoop();

        LogHandler::info('Polling stopped');
    }

    /**
     * Main loop
     */
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

    /**
     * Batch handling with per-update offset confirmation
     */
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
     * Maintenance logic isolated
     */
    private function maintenance(): void
    {
        if ($this->processedCount % 50 === 0) {
            gc_collect_cycles();
            clearstatcache();
            SharedManagement::clear();
            Language::getInstance()->flushMissingKeys();
        }

        if ($this->processedCount >= $this->cleanupInterval) {
            LogHandler::info('Restart cycle reached');
            $this->shouldStop = true;
        }
    }

    /**
     * Signal handling
     */
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
