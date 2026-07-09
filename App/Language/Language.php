<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Language;

use alirezax5\TelegramBase\App\Config\Config;
use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Cache\CacheManager;
use Symfony\Component\Filesystem\Filesystem;

class Language
{
    private static ?self $instance = null;

    private string $currentLang = 'fa';
    private array $translations = [];
    private array $cacheTime = [];
    private Filesystem $fs;
    private ?string $languageDir = null;

    private int $cacheTTL = 60;
    private string $langDriver = 'json';
    private array $missingKeys = [];
    private bool $missingKeysDirty = false;
    private LanguageConfig $config;

    private const CACHE_PREFIX = 'lang:';
    private const CACHE_MISSING_KEYS = 'lang:missing_keys';

    private function __construct()
    {
        $this->fs = new Filesystem();
        $this->config = Config::language();
        $this->loadConfiguration();
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function loadConfiguration(): void
    {
        $this->cacheTTL = $this->config->cacheTTL;
        $this->langDriver = $this->config->driver;
        if (CacheManager::isInitialized()) {
            $cached = CacheManager::get(self::CACHE_MISSING_KEYS);
            if (is_array($cached)) {
                $this->missingKeys = $cached;
            }
        }
    }

    public function setLanguageDir(string $dir): self
    {
        $dir = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $dir);

        if (!is_dir($dir)) {
            LogHandler::error("Language directory not found: {$dir}");
            return $this;
        }

        $real = realpath($dir);
        $this->languageDir = $real !== false ? $real : $dir;

        LogHandler::debug("Language dir set: {$this->languageDir}");

        return $this;
    }

    public function setLanguage(string $lang): self
    {
        $lang = trim($lang);
        if ($lang === '') {
            return $this;
        }

        if ($this->currentLang === $lang && isset($this->translations[$lang])) {
            return $this;
        }

        $this->currentLang = $lang;

        if (!$this->loadFromCache($lang)) {
            LogHandler::debug("Language cache MISS -> loading file: {$lang}");
            $this->loadLanguageFile($lang);
        }
        return $this;
    }

    private function loadFromCache(string $lang): bool
    {
        if (!CacheManager::isInitialized() || $this->cacheTTL <= 0) {
            return false;
        }

        $cacheKey = self::CACHE_PREFIX . $lang;
        $cached = CacheManager::get($cacheKey);

        if (!is_array($cached)) {
            return false;
        }

        $this->translations[$lang] = $cached;
        $this->cacheTime[$lang] = time();

        LogHandler::debug("Language cache HIT: {$lang} (" . count($cached) . " entries)");

        return true;
    }

    private function saveToCache(string $lang, array $data): void
    {
        if (!CacheManager::isInitialized() || $this->cacheTTL <= 0) {
            return;
        }

        CacheManager::put(self::CACHE_PREFIX . $lang, $data, $this->cacheTTL);
    }

    private function saveMissingKeysToCache(): void
    {
        if (CacheManager::isInitialized()) {
            CacheManager::put(
                self::CACHE_MISSING_KEYS,
                $this->missingKeys,
                $this->cacheTTL * 2
            );
        }
    }

    public function flushMissingKeys(): void
    {
        if ($this->missingKeysDirty) {
            $this->saveMissingKeysToCache();
            $this->missingKeysDirty = false;
        }
    }

    private function loadLanguageFile(string $lang): void
    {
        $dir = $this->languageDir;

        if (!$dir || !is_dir($dir)) {
            $dir = $this->resolveDir();
        }

        if (!$dir || !is_dir($dir)) {
            LogHandler::error("Language directory not available for lang: {$lang}");
            $this->translations[$lang] = [];
            return;
        }

        $merged = [];

        // 1) Load main file: {dir}/{lang}.php
        $mainFile = $dir . DIRECTORY_SEPARATOR . $lang . '.php';

        if (is_readable($mainFile)) {
            $data = $this->parseFile($mainFile);
            if (is_array($data)) {
                $merged = $data;
                LogHandler::debug("Language main file loaded: {$mainFile} (" . count($data) . " keys)");
            }
        } else {
            LogHandler::debug("Language main file not readable: {$mainFile}");
        }

        // 2) If {dir}/{lang}/ subdirectory exists, load all php files from it
        $subDir = $dir . DIRECTORY_SEPARATOR . $lang;

        if (is_dir($subDir)) {
            $ext = $this->langDriver === 'php' ? 'php' : 'json';
            $entries = @scandir($subDir);

            if ($entries !== false) {
                $subFiles = [];
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    $extLen = strlen($ext) + 1;
                    if (substr($entry, -$extLen) === '.' . $ext) {
                        $subFiles[] = $entry;
                    }
                }
                sort($subFiles);

                foreach ($subFiles as $file) {
                    $path = $subDir . DIRECTORY_SEPARATOR . $file;
                    $data = $this->parseFile($path);
                    if (is_array($data)) {
                        $prefix = basename($file, '.' . $ext) . '.';
                        $before = count($merged);
                        $merged = array_merge($merged, $this->prefixKeys($data, $prefix));
                        $after = count($merged);
                        LogHandler::debug("Language subfile loaded: {$file} (+" . ($after - $before) . " keys, prefix: {$prefix})");
                    }
                }
            }
        }

        $this->translations[$lang] = $merged;
        $this->cacheTime[$lang] = time();
        $this->saveToCache($lang, $merged);

        LogHandler::debug("Language '{$lang}' total: " . count($merged) . " keys");
    }

    private function resolveDir(): ?string
    {
        $dir = ltrim($this->config->dir, '/\\');
        $full = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $dir;

        return is_dir($full) ? $full : null;
    }

    private function prefixKeys(array $data, string $prefix): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $result[$prefix . $key] = $value;
        }

        return $result;
    }

    private function parseFile(string $file): ?array
    {
        try {
            if ($this->langDriver === 'php') {
                $res = require $file;
                return is_array($res) ? $res : null;
            }

            $json = file_get_contents($file);
            return $json ? json_decode($json, true) : null;

        } catch (\Throwable $e) {
            LogHandler::error("Language parse error: " . $e->getMessage());
            return null;
        }
    }

    public function get(string $key, $default = null, array $replacements = []): string
    {
        $lang = $this->currentLang;

        if (!isset($this->translations[$lang])) {
            $this->setLanguage($lang);
        }

        $value = $this->getNestedValue(
            $this->translations[$lang] ?? [],
            $key
        );

        if ($value === null) {
            if (!isset($this->missingKeys[$lang][$key])) {
                $this->missingKeys[$lang][$key] = true;
                $this->missingKeysDirty = true;
            }

            $value = $default ?? $key;
        }

        return $this->applyReplacements((string)$value, $replacements);
    }

    private function getNestedValue(array $array, string $key): mixed
    {
        if (isset($array[$key])) {
            return $array[$key];
        }

        $keys = explode('.', $key);
        $value = $array;

        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    private function applyReplacements(string $value, array $replacements): string
    {
        if (!$replacements) {
            return $value;
        }

        $search = [];
        $replace = [];

        foreach ($replacements as $k => $v) {
            $search[] = "#{$k}";
            $replace[] = (string)$v;
        }

        return str_replace($search, $replace, $value);
    }

    public function has(string $key, ?string $lang = null): bool
    {
        $lang ??= $this->currentLang;

        if (!isset($this->translations[$lang])) {
            $this->setLanguage($lang);
        }

        return $this->getNestedValue(
                $this->translations[$lang] ?? [],
                $key
            ) !== null;
    }

    public function getAll(?string $lang = null): array
    {
        $lang ??= $this->currentLang;

        return $this->translations[$lang] ?? [];
    }

    public function getCurrentLanguage(): string
    {
        return $this->currentLang;
    }

    public function clearCache(?string $lang = null): void
    {
        if (!CacheManager::isInitialized()) {
            if ($lang) {
                unset($this->cacheTime[$lang]);
            } else {
                $this->cacheTime = [];
            }
            return;
        }

        if ($lang) {
            CacheManager::forget(self::CACHE_PREFIX . $lang);
            unset($this->translations[$lang], $this->cacheTime[$lang]);
        } else {
            foreach ($this->translations as $l => $_) {
                CacheManager::forget(self::CACHE_PREFIX . $l);
            }

            CacheManager::forget(self::CACHE_MISSING_KEYS);

            $this->translations = [];
            $this->cacheTime = [];
            $this->missingKeys = [];
        }
    }

    public function reloadLanguage(?string $lang = null): void
    {
        $lang ??= $this->currentLang;

        unset($this->translations[$lang], $this->cacheTime[$lang]);

        if (CacheManager::isInitialized()) {
            CacheManager::forget(self::CACHE_PREFIX . $lang);
        }

        $this->loadLanguageFile($lang);
    }

    public function getCacheStats(): array
    {
        $total = 0;
        $langs = [];

        foreach ($this->translations as $lang => $data) {
            $count = count($data);
            $total += $count;

            $langs[$lang] = [
                'entries' => $count,
                'valid' => isset($this->cacheTime[$lang]) &&
                    (time() - $this->cacheTime[$lang]) < $this->cacheTTL
            ];
        }

        return [
            'total_entries' => $total,
            'languages' => $langs,
            'cache_ttl' => $this->cacheTTL
        ];
    }
}