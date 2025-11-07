<?php

namespace alirezax5\TelegramBase\App\Button;

use alirezax5\TelegramBase\App\Logger\LogHandler;

class ButtonManager
{
    private static array $cache = [];
    private static int $lastModified = 0;
    private static bool $loaded = false;

    private static function load(): void
    {
        try {
            if (self::$loaded && !self::fileChanged()) {
                LogHandler::debug("ButtonManager::load → cache used");
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
            self::$cache = $buttons;
            self::$loaded = true;
            self::$lastModified = filemtime(BUTTON_FILE);
            LogHandler::info("ButtonManager::load → buttons loaded from file", ['file' => BUTTON_FILE, 'total_buttons' => count(self::$cache)]);
        } catch (\Throwable $e) {
            LogHandler::error("ButtonManager::load → exception: " . $e->getMessage());
        }
    }

    private static function fileChanged(): bool
    {
        $changed = self::$lastModified !== filemtime(BUTTON_FILE);
        if ($changed) {
            LogHandler::debug("ButtonManager::fileChanged → file updated, cache invalidated");
        }
        return $changed;
    }

    public static function get(string $name, array $replace = []): ?array
    {
        self::load();
        if (!isset(self::$cache[$name])) {
            LogHandler::warning("ButtonManager::get → button not found", ['name' => $name]);
            return null;
        }
        $button = self::$cache[$name];
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