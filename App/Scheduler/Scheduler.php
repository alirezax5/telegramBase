<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Scheduler;

use alirezax5\TelegramBase\App\Config\Config;
use alirezax5\TelegramBase\App\Logger\LogHandler;

/**
 * Registers scheduled tasks and runs the ones due.
 *
 * Designed for cron-based hosting: call run() once per process
 * (cron fires the entry script every minute), the script exits.
 * TaskLock prevents double-run of long tasks.
 */
final class Scheduler
{
    /** @var ScheduledTask[] */
    private array $tasks = [];

    private TaskLock $lock;

    public function __construct(?TaskLock $lock = null)
    {
        $this->lock = $lock ?? new TaskLock();
    }

    /**
     * Run every N minutes.
     */
    public function everyMinutes(int $n, callable $fn, ?string $name = null): self
    {
        return $this->register('*/' . max(1, $n) . ' * * * *', $fn, $name);
    }

    /**
     * Run every hour.
     */
    public function hourly(callable $fn, ?string $name = null): self
    {
        return $this->register('0 * * * *', $fn, $name);
    }

    /**
     * Run daily at HH:MM (24h).
     */
    public function dailyAt(string $time, callable $fn, ?string $name = null): self
    {
        [$h, $m] = array_map('intval', explode(':', $time));
        return $this->register(sprintf('%d %d * * *', $m, $h), $fn, $name);
    }

    /**
     * Run every day at midnight.
     */
    public function daily(callable $fn, ?string $name = null): self
    {
        return $this->register('0 0 * * *', $fn, $name);
    }

    /**
     * Register a raw cron expression.
     */
    public function register(string $expression, callable $fn, ?string $name = null): self
    {
        $this->tasks[] = new ScheduledTask($expression, $fn, $name);
        return $this;
    }

    /**
     * Run all tasks that are due now. Returns number of tasks run.
     */
    public function run(): int
    {
        $now = time();
        $ran = 0;

        foreach ($this->tasks as $task) {
            if (!$task->isDue($now)) {
                continue;
            }

            $lockTtl = max(60, (int)Config::scheduler()->lockTtl);

            if (!$this->lock->acquire($task->name(), $lockTtl)) {
                LogHandler::debug("Scheduler: task '{$task->name()}' already running, skip");
                continue;
            }

            try {
                $task->run();
                $ran++;
            } catch (\Throwable $e) {
                LogHandler::error("Scheduler: task '{$task->name()}' failed: {$e->getMessage()}");
            } finally {
                $this->lock->release($task->name());
            }
        }

        return $ran;
    }

    /**
     * Task count.
     */
    public function count(): int
    {
        return count($this->tasks);
    }
}
