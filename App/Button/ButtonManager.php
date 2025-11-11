<?php

namespace alirezax5\TelegramBase\App\Button;

use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Language\Language;

class ButtonManager
{
    private static array $cache = [];          // cache per language
    private static array $lastModified = [];   // last modified time per language
    private static array $loaded = [];         // per language loaded status

    private static function load(): void
    {
        try {
            $lang = Language::getInstance()->getCurrentLanguage();

            if (isset(self::$loaded[$lang]) && !self::fileChanged($lang)) {
                LogHandler::debug("ButtonManager::load → cache used for lang: {$lang}");
                return;
            }

            if (!defined('BUTTON_FILE')) {
                LogHandler::error("BUTTON_FILE is not defined.");
                return;
            }

            if (!file_exists(BUTTON_FILE)) {
                LogHandler::error("BUTTON_FILE not found: " . BUTTON_FILE);
                return;
            }

            $buttons = require BUTTON_FILE;

            if (!is_array($buttons)) {
                LogHandler::error("BUTTON_FILE must return array.");
                return;
            }

            self::$cache[$lang] = $buttons;
            self::$loaded[$lang] = true;
            self::$lastModified[$lang] = filemtime(BUTTON_FILE);

            LogHandler::info(
                "ButtonManager::load → buttons loaded for lang: {$lang}",
                ['file' => BUTTON_FILE, 'total_buttons' => count(self::$cache[$lang])]
            );

        } catch (\Throwable $e) {
            LogHandler::error("ButtonManager::load → exception: " . $e->getMessage());
        }
    }

    private static function fileChanged(string $lang): bool
    {
        $current = filemtime(BUTTON_FILE);

        if (!isset(self::$lastModified[$lang]) || self::$lastModified[$lang] !== $current) {
            LogHandler::debug("ButtonManager::fileChanged → file updated, cache invalidated for lang: {$lang}");
            return true;
        }

        return false;
    }

    public static function get(string $name, array $replace = []): ?array
    {
        self::load();

        $lang = Language::getInstance()->getCurrentLanguage();
        LogHandler::warning("Btn ".print_r(self::$cache[$lang][$name],true), ['name' => $name]);

        if (!isset(self::$cache[$lang][$name])) {
            LogHandler::warning("ButtonManager::get → button not found", ['name' => $name]);
            return null;
        }

        $button = self::$cache[$lang][$name];

        if (!empty($replace)) {
            $button = self::applyReplace($button, $replace);
            LogHandler::debug("ButtonManager::get → replacements applied", ['button' => $name, 'replace' => $replace]);
        }

        LogHandler::info("ButtonManager::get → button returned", ['name' => $name]);
        return $button;
    }

    private static function applyReplace(array $button, array $replace): array
    {
        $json = json_encode($button);

        foreach ($replace as $key => $value) {
            $json = str_replace("{" . $key . "}", $value, $json);
        }

        return json_decode($json, true);
    }
}
