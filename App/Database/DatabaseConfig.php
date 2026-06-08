<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Database;

final  class DatabaseConfig
{
    public function __construct(
        public bool $enable,
        public string $default,
        public array $connections,
    ) {}
}