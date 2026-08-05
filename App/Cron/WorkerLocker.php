<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Cron;

use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Paths;
class WorkerLocker
{
    /**
     * Try to acquire one of the free worker slot locks.
     *
     * A flock() is taken per slot, so concurrent cron processes cannot
     * run the same worker twice. Returns a slot handle + id on success.
     *
     * @param int $maxWorkers Total number of available slots
     * @return array{handle:resource, workerId:int}|null Acquired slot, or null when all busy
     */
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

    /**
     * Release an acquired worker slot lock.
     *
     * @param resource $handle   File handle from acquire()
     * @param int      $workerId Slot number
     */
    public function release($handle, int $workerId): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);

        LogHandler::debug(
            "🔓 Worker slot {$workerId} released"
        );
    }
}