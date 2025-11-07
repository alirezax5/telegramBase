<?php
namespace alirezax5\TelegramBase\App\Shared;

class SharedManagement
{
    private static array $data = [];

    public static function set(string $key, mixed $value): void
    {
        self::$data[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$data[$key] ?? $default;
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::$data);
    }

    public static function all(): array
    {
        return self::$data;
    }

    public static function remove(string $key): void
    {
        unset(self::$data[$key]);
    }

    public static function clear(): void
    {
        self::$data = [];
    }
}