<?php


namespace alirezax5\TelegramBase\App\Environment;

class EnvHandler
{
    private static array $cache = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$cache[$key] ??= $_ENV[$key] ?? $default;
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }
}