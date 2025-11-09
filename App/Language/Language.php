<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Language;

use alirezax5\TelegramBase\App\Environment\EnvHandler;
use alirezax5\TelegramBase\App\Logger\LogHandler;
use Symfony\Component\Filesystem\Filesystem;

class Language
{
    private static ?Language $instance = null;

    private string $currentLang = 'fa';
    private array $translations = [];
    private ?string $languageDir = null;
    private array $cacheTime = [];
    private Filesystem $fs;

    private function __construct()
    {
        $this->fs = new Filesystem();
    }

    public static function getInstance(): Language
    {
        return self::$instance ??= new self();
    }

    public function setLanguageDir(string $dir): self
    {
        if (!$this->fs->exists($dir)) {
            LogHandler::error("❌ Language directory does not exist: {$dir}");
            return $this;
        }

        $this->languageDir = realpath($dir);

        LogHandler::info("📂 Language directory set: {$this->languageDir}");
        return $this;
    }

    public function setLanguage(string $lang): self
    {
        $this->currentLang = $lang;

        if ($this->isCachedValid($lang)) {
            LogHandler::info("🌍 Language '{$lang}' loaded from cache");
        } else {
            LogHandler::info("🔄 Loading language file: {$lang}");
            $this->loadLanguageFile($lang);
        }

        return $this;
    }

    private function loadLanguageFile(string $lang): void
    {
        $langDir = $this->languageDir ?? ($_ENV['LANG_DIR'] ?? null);

        if (!$langDir || !$this->fs->exists($langDir)) {
            LogHandler::error("❌ Language directory not found (LANG_DIR missing or invalid)");
            $this->translations[$lang] = [];
            return;
        }

        $filePath = $langDir . DIRECTORY_SEPARATOR . $lang . '.json';

        if (!$this->fs->exists($filePath)) {
            LogHandler::warning("⚠️ Language file not found: {$filePath}");
            $this->translations[$lang] = [];
            return;
        }

        if (!is_readable($filePath)) {
            LogHandler::error("🚫 Language file not readable: {$filePath}");
            $this->translations[$lang] = [];
            return;
        }

        $jsonContent = file_get_contents($filePath);
        $parsed = json_decode($jsonContent, true);

        if (!is_array($parsed)) {
            LogHandler::error("❌ Invalid JSON in language file: {$filePath}");
            $this->translations[$lang] = [];
            return;
        }

        $this->translations[$lang] = $parsed;
        $this->cacheTime[$lang] = time();

        LogHandler::info("✅ Language '{$lang}' loaded and cached (" . count($parsed) . " entries)");
    }

    private function isCachedValid(string $lang): bool
    {
        $ttl = (int)(EnvHandler::get('LANG_CACHE_TTL',60) );

        if ($ttl <= 0 || !isset($this->cacheTime[$lang])) {
            return false;
        }

        $valid = (time() - $this->cacheTime[$lang]) < $ttl;

        if ($valid) {
            LogHandler::info("⏱ Cache valid for '{$lang}' (TTL: {$ttl}s)");
        } else {
            LogHandler::info("⌛ Cache expired for '{$lang}', reloading...");
        }

        return $valid;
    }

    public function get(string $key, $default = null, array $replacements = []): string
    {
        if (!$this->isCachedValid($this->currentLang)) {
            $this->loadLanguageFile($this->currentLang);
        }

        $value = $this->translations[$this->currentLang][$key] ?? $default ?? $key;

        if (!isset($this->translations[$this->currentLang][$key])) {
            LogHandler::warning("⚠️ Missing translation key '{$key}' in '{$this->currentLang}'");
        }

        foreach ($replacements as $placeholder => $replacement) {
            $value = str_replace("#{$placeholder}", (string)$replacement, $value);
        }

        return $value;
    }

    public function getAll(?string $lang = null): array
    {
        $lang = $lang ?? $this->currentLang;
        return $this->translations[$lang] ?? [];
    }

    public function getCurrentLanguage(): string
    {
        return $this->currentLang;
    }
}
