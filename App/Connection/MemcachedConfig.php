<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Connection;

final  class MemcachedConfig
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $username,
        public readonly string $password,
    ) {}
}
