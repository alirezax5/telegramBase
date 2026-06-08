<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Button;

use alirezax5\TelegramBase\App\Config\Config;
use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Language\Language;
use alirezax5\TelegramBase\App\Cache\CacheManager;
use alirezax5\TelegramBase\App\Environment\EnvHandler;

final class ButtonManager
{
    private static array $cache = [];
    private static array $loaded = [];
    private static array $mtime = [];
    private static array $timestamp = [];

    private static bool $init = false;
    private static int $ttl = 300;
    private static string $buttonFile;

    private const CACHE_PREFIX = 'buttons:';

    private static function init(): void
    {
        if (self::$init) return;

        $config = Config::buttons();

        self::$buttonFile = $config->file;
        self::$ttl = $config->cacheTTL;

        self::$init = true;
    }

    private static function load(): void
    {
        self::init();

        $lang = Language::getInstance()->getCurrentLanguage();

        if (self::isValid($lang)) {
            return;
        }

        if (self::fromCache($lang)) {
            return;
        }

        self::fromFile($lang);
    }

    private static function isValid(string $lang): bool
    {
        if (!isset(self::$loaded[$lang])) {
            return false;
        }

        if (self::$ttl <= 0) {
            return false;
        }

        return (time() - (self::$timestamp[$lang] ?? 0)) < self::$ttl;
    }

    private static function fromCache(string $lang): bool
    {
        if (!CacheManager::isInitialized()) {
            return false;
        }

        $data = CacheManager::get(self::CACHE_PREFIX . $lang);

        if (!is_array($data)) {
            return false;
        }

        self::$cache[$lang] = $data;
        self::$loaded[$lang] = true;
        self::$timestamp[$lang] = time();

        return true;
    }

    private static function fromFile(string $lang): void
    {
        if (!is_readable(self::$buttonFile)) {
            self::$cache[$lang] = [];
            self::$loaded[$lang] = true;
            return;
        }

        $mtime = filemtime(self::$buttonFile);

        if (isset(self::$mtime[$lang]) && self::$mtime[$lang] === $mtime) {
            self::$timestamp[$lang] = time();
            return;
        }

        $buttons = require self::$buttonFile;

        if (!is_array($buttons)) {
            $buttons = [];
        }

        self::$cache[$lang] = $buttons;
        self::$loaded[$lang] = true;
        self::$mtime[$lang] = $mtime;
        self::$timestamp[$lang] = time();

        if (CacheManager::isInitialized()) {
            CacheManager::put(self::CACHE_PREFIX . $lang, $buttons, self::$ttl);
        }
    }

    public static function get(string $name, array $replace = []): ?array
    {
        self::load();

        $lang = Language::getInstance()->getCurrentLanguage();

        $btn = self::$cache[$lang][$name] ?? null;

        if (!$btn) {
            return null;
        }

        // fast replace (no JSON encode/decode)
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

    public static function getAll(?string $lang = null): array
    {
        self::load();

        $lang ??= Language::getInstance()->getCurrentLanguage();

        return self::$cache[$lang] ?? [];
    }

    public static function has(string $name): bool
    {
        self::load();

        $lang = Language::getInstance()->getCurrentLanguage();

        return isset(self::$cache[$lang][$name]);
    }

    public static function clearCache(?string $lang = null): void
    {
        if ($lang === null) {
            self::$cache = [];
            self::$loaded = [];
            self::$mtime = [];
            self::$timestamp = [];
            return;
        }

        unset(
            self::$cache[$lang],
            self::$loaded[$lang],
            self::$mtime[$lang],
            self::$timestamp[$lang]
        );
    }

    public static function reload(): void
    {
        self::$cache = [];
        self::$loaded = [];
        self::$mtime = [];
        self::$timestamp = [];
    }
}