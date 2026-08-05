<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Cron;

class CronManager
{
    private CronWorker $worker;

    public function __construct()
    {
        $this->worker = new CronWorker(
            new WorkerLocker()
        );
    }

    public function run(
        int $maxWorkers,
        callable $callback
    ): void {

        $this->worker->run(
            $maxWorkers,
            $callback
        );
    }
}