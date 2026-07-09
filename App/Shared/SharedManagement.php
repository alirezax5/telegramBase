<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Shared;

class SharedManagement
{
    private static array $data = [];
    private static array $protectedKeys = [];

    /**
     * Set value
     */
    public static function set(string $key, mixed $value, bool $protected = false): void
    {
        self::$data[$key] = $value;

        if ($protected) {
            self::$protectedKeys[$key] = true;
        }
    }

    /**
     * Get value
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$data[$key] ?? $default;
    }

    /**
     * Exists check (fast & correct)
     */
    public static function has(string $key): bool
    {
        return array_key_exists($key, self::$data);
    }

    /**
     * Remove key (respects protected keys)
     */
    public static function remove(string $key, bool $force = false): void
    {
        if (!$force && isset(self::$protectedKeys[$key])) {
            return;
        }

        unset(self::$data[$key], self::$protectedKeys[$key]);
    }

    /**
     * Clear non-protected data
     */
    public static function clear(): void
    {
        if (empty(self::$protectedKeys)) {
            self::$data = [];
            return;
        }

        foreach (self::$data as $key => $_) {
            if (!isset(self::$protectedKeys[$key])) {
                unset(self::$data[$key]);
            }
        }
    }

    /**
     * Clear everything (dangerous reset)
     */
    public static function flush(): void
    {
        self::$data = [];
        self::$protectedKeys = [];
    }

    /**
     * Get all data
     */
    public static function all(): array
    {
        return self::$data;
    }

    /**
     * Get protected keys only
     */
    public static function protectedKeys(): array
    {
        return array_keys(self::$protectedKeys);
    }
}