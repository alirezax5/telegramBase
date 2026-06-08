<?php

namespace alirezax5\TelegramBase\App\Queue;

use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Plugin\PluginHandler;
use alirezax5\TelegramBase\App\Queue\QueueManager;
use alirezax5\TelegramBase\App\Update\Processor;
use telegramBotApiPhp\Telegram;

class QueueWorker
{
    private QueueManager $queue;
    private Processor $processor;

    public function __construct(
        QueueManager $queue,
        ?PluginHandler $plugins,
        Telegram $Telegram
    ) {
        $this->queue = $queue;
        $this->processor = new Processor($plugins, $Telegram);
    }

    /**
     * Daemon mode
     */
    public function startInfinite(): void
    {
        set_time_limit(0);

        LogHandler::info('🔄 Queue worker started');

        $this->processLoop();
    }

    /**
     * Cron mode
     */
    public function runLimited(int $maxSeconds = 50): void
    {
        $processed = $this->processLoop(
            time() + $maxSeconds
        );

        LogHandler::info(
            "✅ Limited worker finished: {$processed} updates"
        );
    }

    /**
     * Shared processing loop
     */
    private function processLoop(?int $endTime = null): int
    {
        $processed = 0;

        while ($endTime === null || time() < $endTime) {

            try {

                if (!$this->queue->isConnected()) {
                    usleep(500_000);
                    continue;
                }

                $update = $this->queue->pop();

                if (!$update) {
                    usleep(100_000);
                    continue;
                }

                $this->processor->processOne($update);

                $processed++;

            } catch (\Throwable $e) {

                LogHandler::error(
                    'Queue worker error: ' . $e->getMessage()
                );

                usleep(500_000);
            }
        }

        return $processed;
    }
}