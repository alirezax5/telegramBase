<?php
namespace alirezax5\TelegramBase\App\Shared;

class SharedManagement
{
    private static array $data = [];
    private static array $protected = [];   // ✅ کلیدهایی که نباید با clear پاک شوند

    /**
     * ذخیره مقدار
     */
    public static function set(string $key, mixed $value, bool $isProtected = false): void
    {
        self::$data[$key] = $value;

        // اگر خواستیم این داده با clear پاک نشود
        if ($isProtected && !in_array($key, self::$protected, true)) {
            self::$protected[] = $key;
        }
    }

    /**
     * گرفتن مقدار
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$data[$key] ?? $default;
    }
    /**
     * بررسی مقدار
     */
    public static function has(string $key): bool
    {
        return array_key_exists($key, self::$data);
    }

    public static function all(): array
    {
        return self::$data;
    }

    /**
     * حذف یک کلید (حتی protected)
     */
    public static function remove(string $key): void
    {
        unset(self::$data[$key]);
        self::$protected = array_filter(self::$protected, fn($k) => $k !== $key);
    }

    /**
     * پاک کردن همه غیرمحافظت‌ها
     */
    public static function clear(): void
    {
        foreach (self::$data as $key => $value) {
            if (!in_array($key, self::$protected, true)) {
                unset(self::$data[$key]);
            }
        }
    }

    /**
     * پاک کردن محافظت‌شده‌ها
     */
    public static function clearProtected(): void
    {
        foreach (self::$protected as $key) {
            unset(self::$data[$key]);
        }
        self::$protected = [];
    }
}
