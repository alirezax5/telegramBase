<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Scheduler;

use alirezax5\TelegramBase\App\Environment\EnvHandler;

final class SchedulerConfig
{
    public function __construct(
        public readonly string $lockTable,
        public readonly int    $lockTtl,
        public readonly string $scheduleFile,
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(
            lockTable: EnvHandler::string('SCHEDULER_LOCK_TABLE', 'task_locks'),
            lockTtl: EnvHandler::int('SCHEDULER_LOCK_TTL', 300),
            scheduleFile: EnvHandler::string('SCHEDULE_FILE', '' ),
        );
    }
}