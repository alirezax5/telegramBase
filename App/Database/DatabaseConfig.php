<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Database;

final class DatabaseConfig
{
    /**
     * @param bool   $enable      Whether the database layer is active
     * @param string $default     Default connection name
     * @param array  $connections Connection definitions (driver, host, ...)
     */
    public function __construct(
        public readonly bool $enable,
        public readonly string $default,
        public readonly array $connections,
    ) {}
}