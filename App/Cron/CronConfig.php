<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Cron;

use alirezax5\TelegramBase\App\Environment\EnvHandler;

final  class CronConfig
{
    public function __construct(

        public readonly int $cronMaxTime,
        public readonly int $cronWorker,
    )
    {
    }

    public static function fromEnv(): self
    {
        return new self(
            cronMaxTime: EnvHandler::int('CRON_MAX_TIME', 50),
            cronWorker: EnvHandler::int('CRON_WORKER', 1),
        );
    }


}