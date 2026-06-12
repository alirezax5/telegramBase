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
    private $config;

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
        if (!$this->fs->exists($dir)) {
            LogHandler::error("❌ Language directory not found: {$dir}");
            return $this;
        }

        $this->languageDir = realpath($dir) ?: null;
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
            LogHandler::debug("📂 Language cache MISS → loading file: {$lang}");
            $this->loadLanguageFile($lang);
        }
        return $this;
    }

    /**
     * =========================
     * CACHE LOAD (OPTIMIZED)
     * =========================
     */
    private function loadFromCache(string $lang): bool
    {
        if (!CacheManager::isInitialized() || $this->cacheTTL <= 0) {
            return false;
        }

        $cacheKey = self::CACHE_PREFIX . $lang;
        $cached = CacheManager::get($cacheKey);

        if (!is_array($cached)) {
            LogHandler::debug("❌ Language cache MISS: {$lang}");
            return false;
        }

        $this->translations[$lang] = $cached;
        $this->cacheTime[$lang] = time();

        LogHandler::debug("🌍 Language cache HIT: {$lang} (" . count($cached) . " entries)");

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

    /**
     * =========================
     * FILE LOADING (OPTIMIZED)
     * =========================
     */
    private function loadLanguageFile(string $lang): void
    {
        $dir = $this->languageDir ?? $this->config->dir;

        if (!$dir || !$this->fs->exists($dir)) {
            $this->translations[$lang] = [];
            return;
        }

        $file = $this->getFilePath($dir, $lang);

        if (!is_readable($file)) {
            $this->translations[$lang] = [];
            return;
        }

        $data = $this->parseFile($file);

        if (!is_array($data)) {
            $this->translations[$lang] = [];
            return;
        }

        $this->translations[$lang] = $data;
        $this->cacheTime[$lang] = time();
        $this->saveToCache($lang, $data);
    }

    private function getFilePath(string $dir, string $lang): string
    {
        $ext = $this->langDriver === 'php' ? 'php' : 'json';
        return $dir . DIRECTORY_SEPARATOR . $lang . '.' . $ext;
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

    /**
     * =========================
     * CORE GET (OPTIMIZED)
     * =========================
     */
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
            $this->missingKeys[$lang][$key] = true;
            $this->saveMissingKeysToCache();

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