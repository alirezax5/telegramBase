<?php

namespace alirezax5\TelegramBase\App\Cron;

use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Paths;
class WorkerLocker
{
    public function acquire(int $maxWorkers): ?array
    {
        $dir = Paths::cronSlotsDirectory();

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        for ($i = 1; $i <= $maxWorkers; $i++) {

            $lockFile = $dir . "/bot_worker_{$i}.lock";

            $fp = @fopen($lockFile, 'c');

            if (!$fp) {
                continue;
            }

            if (flock($fp, LOCK_EX | LOCK_NB)) {
                LogHandler::debug("🔒 Worker slot {$i} acquired");

                return [
                    'handle' => $fp,
                    'workerId' => $i,
                ];
            }

            fclose($fp);
        }

        return null;
    }

    public function release($handle, int $workerId): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);

        LogHandler::debug(
            "🔓 Worker slot {$workerId} released"
        );
    }
}