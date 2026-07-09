<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Queue;

use alirezax5\TelegramBase\App\Logger\LogHandler;
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

    private int $processedCount = 0;

    public function __construct(
        QueueManager $queue,
        ?PluginHandler $plugins,
        Telegram $Telegram
    ) {
        $this->queue = $queue;
        $this->processor = new Processor($plugins, $Telegram);
    }

    public function startInfinite(): void
    {
        set_time_limit(0);

        LogHandler::info('Queue worker started');

        $this->processLoop();
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

        while ($endTime === null || time() < $endTime) {
            try {
                if (!$this->queue->isConnected()) {
                    usleep(500_000);
                    continue;
                }

                $update = $this->queue->pop(self::BLOCK_TIMEOUT);

                if (!$update) {
                    $this->maintenance();
                    continue;
                }

                $this->processor->handle($update);

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
                        'update_id' => isset($update) && is_object($update) ? ($update->update_id ?? null) : null,
                    ]
                );

                usleep(500_000);
            }
        }

        return $processed;
    }

    private function maintenance(): void
    {
        if ($this->processedCount % 50 === 0) {
            gc_collect_cycles();
            clearstatcache();
            SharedManagement::clear();
            Language::getInstance()->flushMissingKeys();
        }
    }
}