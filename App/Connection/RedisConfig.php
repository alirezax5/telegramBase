<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Connection;

final  class RedisConfig
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $password,
        public readonly int $database,
        public readonly float $timeout,
    ) {}
}
