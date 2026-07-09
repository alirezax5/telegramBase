<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Environment;

class EnvHandler
{
    private static array $cache = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        if (array_key_exists($key, $_ENV)) {
            $value = $_ENV[$key];
        } else {
            $value = getenv($key);
            if ($value === false) {
                $value = $default;
            }
        }

        if (is_string($value)) {
            $value = trim($value);
        }

        return self::$cache[$key] = $value;
    }
    public static function has(string $key): bool
    {
        return isset($_ENV[$key]) || getenv($key) !== false;
    }

    public static function string(string $key, ?string $default = null): string
    {
        return (string)self::get($key, $default);
    }

    public static function int(string $key, int $default = 0): int
    {
        return (int)self::get($key, $default);
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default);

        return match (strtolower((string)$value)) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => (bool)$value,
        };
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }
}