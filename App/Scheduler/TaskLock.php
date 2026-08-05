<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Scheduler;

use alirezax5\TelegramBase\App\Logger\LogHandler;

/**
 * Prevents parallel execution of the same task when cron fires
 * more than once before the previous run completes.
 */
final class TaskLock
{
    private string $file;

    public function __construct()
    {
        $dir = dirname($_SERVER['argv'][0] ?? __DIR__ . '/../../..') . '/AppData/scheduler_locks';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $this->file = $dir;
    }

    /**
     * Try to acquire lock. Returns false if already locked.
     */
    public function acquire(string $taskName, int $ttl = 300): bool
    {
        $path = $this->file . '/' . md5($taskName) . '.lock';

        if (file_exists($path)) {
            $lockTime = (int)file_get_contents($path);
            if (time() - $lockTime < $ttl) {
                return false;
            }
            // expired lock — remove and re-acquire
            @unlink($path);
        }

        return file_put_contents($path, (string)time(), LOCK_EX) !== false;
    }

    /**
     * Release a task lock.
     */
    public function release(string $taskName): void
    {
        $path = $this->file . '/' . md5($taskName) . '.lock';
        if (file_exists($path)) {
            @unlink($path);
        }
    }
}
