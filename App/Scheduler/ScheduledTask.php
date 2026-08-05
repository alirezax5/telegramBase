<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Scheduler;

use alirezax5\TelegramBase\App\Logger\LogHandler;

/**
 * A single scheduled task with a cron expression and a callback.
 */
final class ScheduledTask
{
    private string $name;

    /** @var callable */
    private $callback;

    public function __construct(
        private readonly string $expression,
        callable $callback,
        ?string $name = null,
    ) {
        $this->name = $name ?? "task_" . bin2hex(random_bytes(4));
        $this->callback = $callback;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function expression(): string
    {
        return $this->expression;
    }

    /**
     * Check if the task is due at the given timestamp, matching a cron minute pattern.
     */
    public function isDue(int $now): bool
    {
        $parts = preg_split('/\s+/', $this->expression);
        if ($parts === false || count($parts) < 5) {
            return false;
        }

        [$minute, $hour, $monthDay, $month, $weekDay] = $parts;

        $currentMin   = (int)date('i', $now);
        $currentHour  = (int)date('G', $now);
        $currentDay   = (int)date('d', $now);
        $currentMonth = (int)date('n', $now);
        $currentWD    = (int)date('w', $now);

        if (!$this->matchesCronPart($minute, $currentMin)) return false;
        if (!$this->matchesCronPart($hour, $currentHour)) return false;
        if (!$this->matchesCronPart($monthDay, $currentDay)) return false;
        if (!$this->matchesCronPart($month, $currentMonth)) return false;
        if (!$this->matchesCronPart($weekDay, $currentWD)) return false;

        return true;
    }

    public function run(): void
    {
        ($this->callback)();
    }

    private function matchesCronPart(string $pattern, int $value): bool
    {
        $pattern = trim($pattern);

        if ($pattern === '*') return true;

        // "*/5" → $value % 5 === 0
        if (str_starts_with($pattern, '*/')) {
            $divisor = (int)substr($pattern, 2);
            return $divisor > 0 && $value % $divisor === 0;
        }

        // "1,3,5" or "0-5" or comma list
        foreach (explode(',', $pattern) as $p) {
            $p = trim($p);
            if (str_contains($p, '-')) {
                [$a, $b] = explode('-', $p, 2);
                if ($value >= (int)$a && $value <= (int)$b) return true;
            } elseif ((int)$p === $value) {
                return true;
            }
        }

        return false;
    }
}