<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Cache;

use alirezax5\TelegramBase\App\Environment\EnvHandler;

final class CacheConfig
{
    public function __construct(
        public readonly string $host,
        public readonly int    $port,
        public readonly int    $database,
        public readonly string $password,
        public readonly string $driver,
        public readonly string $prefix,
        public readonly string $path,
        public readonly int    $ttl,
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(
            host: EnvHandler::string('REDIS_HOST', '127.0.0.1'),
            port: EnvHandler::int('REDIS_PORT', 6379),
            database: EnvHandler::int('REDIS_DATABASE', 0),
            password: EnvHandler::string('REDIS_PASSWORD', ''),
            driver: EnvHandler::string('CACHE_DRIVER', 'array'),
            prefix: EnvHandler::string('CACHE_PREFIX', 'balebase_'),
            path: rtrim(EnvHandler::string('CACHE_PATH', sys_get_temp_dir() . '/balebase-cache'), '/\\'),
            ttl: EnvHandler::int('CACHE_TTL', 3600),
        );
    }
}