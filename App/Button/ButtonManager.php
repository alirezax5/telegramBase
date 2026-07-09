<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Button;

use alirezax5\TelegramBase\App\Config\Config;
use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Cache\CacheManager;

final class ButtonManager
{
    private static array $buttons = [];
    private static bool $loaded = false;
    private static bool $init = false;
    private static int $ttl = 300;
    private static string $buttonDir;
    private static string $buttonFile;

    private const CACHE_KEY = 'buttons:all';

    private static function init(): void
    {
        if (self::$init) {
            return;
        }

        $config = Config::buttons();

        self::$buttonFile = $config->file;
        self::$buttonDir = $config->dir;
        self::$ttl = $config->cacheTTL;

        self::$init = true;
    }

    private static function load(): void
    {
        self::init();

        if (self::$loaded) {
            return;
        }

        if (self::fromCache()) {
            self::$loaded = true;
            return;
        }

        self::fromFiles();
        self::$loaded = true;
    }

    private static function fromCache(): bool
    {
        if (!CacheManager::isInitialized() || self::$ttl <= 0) {
            return false;
        }

        $data = CacheManager::get(self::CACHE_KEY);

        if (!is_array($data)) {
            return false;
        }

        self::$buttons = $data;

        LogHandler::debug("Buttons cache HIT (" . count($data) . " entries)");

        return true;
    }

    private static function fromFiles(): void
    {
        $merged = [];

        // 1) Load main file: btn.php (keys as-is)
        if (is_readable(self::$buttonFile)) {
            $data = require self::$buttonFile;

            if (is_array($data)) {
                $merged = $data;
                LogHandler::debug("Buttons main file loaded: " . self::$buttonFile . " (" . count($data) . " keys)");
            }
        }

        // 2) Load other *.php files from the same directory (with filename prefix)
        if (is_dir(self::$buttonDir)) {
            $entries = @scandir(self::$buttonDir);

            if ($entries !== false) {
                $mainBasename = basename(self::$buttonFile);
                $subFiles = [];

                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    if ($entry === $mainBasename) {
                        continue;
                    }
                    if (str_ends_with($entry, '.php')) {
                        $subFiles[] = $entry;
                    }
                }

                sort($subFiles);

                foreach ($subFiles as $file) {
                    $path = self::$buttonDir . DIRECTORY_SEPARATOR . $file;
                    $data = require $path;

                    if (is_array($data)) {
                        $prefix = basename($file, '.php') . '.';
                        $before = count($merged);
                        $merged = array_merge($merged, self::prefixKeys($data, $prefix));
                        $after = count($merged);
                        LogHandler::debug("Buttons subfile loaded: {$file} (+" . ($after - $before) . " keys, prefix: {$prefix})");
                    }
                }
            }
        }

        self::$buttons = $merged;

        if (CacheManager::isInitialized() && self::$ttl > 0) {
            CacheManager::put(self::CACHE_KEY, $merged, self::$ttl);
        }

        LogHandler::debug("Buttons total: " . count($merged) . " keys");
    }

    private static function prefixKeys(array $data, string $prefix): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $result[$prefix . $key] = $value;
        }

        return $result;
    }

    public static function get(string $name, array $replace = []): ?array
    {
        self::load();

        $btn = self::$buttons[$name] ?? null;

        if (!$btn) {
            return null;
        }

        if ($replace) {
            foreach ($replace as $k => $v) {
                $btn = self::replaceRecursive($btn, '{' . $k . '}', (string)$v);
                $btn = self::replaceRecursive($btn, '{{' . $k . '}}', (string)$v);
            }
        }

        return $btn;
    }

    private static function replaceRecursive($data, string $key, string $value)
    {
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                $data[$k] = self::replaceRecursive($v, $key, $value);
            }
            return $data;
        }

        if (is_string($data)) {
            return str_replace($key, $value, $data);
        }

        return $data;
    }

    public static function getAll(): array
    {
        self::load();

        return self::$buttons;
    }

    public static function has(string $name): bool
    {
        self::load();

        return isset(self::$buttons[$name]);
    }

    public static function clearCache(): void
    {
        self::$buttons = [];
        self::$loaded = false;

        if (CacheManager::isInitialized()) {
            CacheManager::forget(self::CACHE_KEY);
        }
    }

    public static function reload(): void
    {
        self::clearCache();
        self::load();
    }
}