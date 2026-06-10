<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Cache;

use alirezax5\TelegramBase\App\Environment\EnvHandler;

final  class CacheConfig
{
    public function __construct(
        public string $host,
        public   $port,
        public  $database,
        public string $password,
        public string $driver,
        public string $prefix,
        public string $path,
        public int    $ttl,
    )
    {
    }

    public static function fromEnv(): self
    {
        return new self(
            host: EnvHandler::get('REDIS_HOST', '127.0.0.1'),
            port: EnvHandler::get('REDIS_PORT', '6379'),
            database: EnvHandler::get('REDIS_DATABASE', '0'),
            password: EnvHandler::get('REDIS_PASSWORD', null),
            driver: EnvHandler::get('CACHE_DRIVER', 'array'),
            prefix: EnvHandler::get('CACHE_PREFIX', 'balebase_'),
            path: rtrim((string)EnvHandler::get('CACHE_PATH', sys_get_temp_dir() . '/balebase-cache'), '/\\'),
            ttl: (int)EnvHandler::get('CACHE_TTL', 3600),
        );
    }
}