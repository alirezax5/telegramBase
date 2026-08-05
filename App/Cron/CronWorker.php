<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Cron;

use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Paths;
class CronWorker
{
    public function __construct(
        private readonly WorkerLocker $locker
    ) {}

    public function run(
        int $maxWorkers,
        callable $callback
    ): void {

        $slot = $this->locker->acquire($maxWorkers);

        if (!$slot) {
            LogHandler::debug(
                "ℹ️ All {$maxWorkers} worker slots busy"
            );

            return;
        }

        $handle = $slot['handle'];
        $workerId = $slot['workerId'];

        try {

            $callback();

        } catch (\Throwable $e) {

            LogHandler::error(
                "❌ Worker #{$workerId} fatal error: "
                . $e->getMessage()
            );

        } finally {

            $this->locker->release(
                $handle,
                $workerId
            );
        }
    }
}