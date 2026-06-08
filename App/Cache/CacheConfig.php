<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Cache;

use alirezax5\TelegramBase\App\Environment\EnvHandler;

final  class CacheConfig
{
    public function __construct(
        public string $driver,
        public string $prefix,
        public string $path,
        public int $ttl,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            driver: EnvHandler::get('CACHE_DRIVER', 'array'),
            prefix: EnvHandler::get('CACHE_PREFIX', 'balebase_'),
            path: rtrim((string) EnvHandler::get('CACHE_PATH', sys_get_temp_dir() . '/balebase-cache'), '/\\'),
            ttl: (int) EnvHandler::get('CACHE_TTL', 3600),
        );
    }
}