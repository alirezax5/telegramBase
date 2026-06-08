<?php

namespace alirezax5\TelegramBase\App\Update;

use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Shared\SharedManagement;
use alirezax5\TelegramBase\App\Update\Processor;

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

        LogHandler::info('🔄 Polling loop started');

        $this->runLoop();

        LogHandler::info('🛑 Polling stopped');
    }

    /**
     * Main loop (clean separation)
     */
    private function runLoop(): void
    {
        $lastUpdateId = 0;

        while (!$this->shouldStop) {

            $this->dispatchSignals();

            try {
                $updates = $this->fetcher->fetch();

                if (empty($updates)) {
                    usleep(250000);
                    continue;
                }

                $this->handleBatch($updates, $lastUpdateId);

                $this->processedCount++;

                $this->maintenance();

            } catch (\Throwable $e) {
                LogHandler::error('Polling error: ' . $e->getMessage());
                usleep(500000);
            }
        }
    }

    /**
     * Batch handling separated
     */
    private function handleBatch( $updates, int &$lastUpdateId): void
    {
        $this->processor->handleBatch(
            $updates,
            function ($update) use (&$lastUpdateId) {
                $id = $update->update_id  ?? 0;

                if ($id > $lastUpdateId) {
                    $lastUpdateId = $id;
                }
            }
        );

        if ($lastUpdateId > 0) {
            $this->fetcher->updateLastId($lastUpdateId + 1);
            $lastUpdateId = 0;
        }
    }

    /**
     * Maintenance logic isolated
     */
    private function maintenance(): void
    {
        if ($this->processedCount % 50 === 0) {
            gc_collect_cycles();
            clearstatcache();
        }

        SharedManagement::clear();

        if ($this->processedCount >= $this->cleanupInterval) {
            LogHandler::info('♻️ Restart cycle reached');
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