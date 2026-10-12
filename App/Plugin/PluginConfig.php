<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Plugin;

use alirezax5\TelegramBase\App\Environment\EnvHandler;
use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Paths;

final  class PluginConfig
{
    public function __construct(
        public readonly string $path,
        public readonly int $reloadInterval,
        public readonly bool $cacheEnabled,
        public readonly bool $autoReload,
    ) {}

    public static function fromEnv(): self
    {
        // NOTE: the historical default was '/Plugin/' — a LEADING-SLASH
        // value. Because leading-slash values are treated as absolute
        // filesystem paths, a default install that did not set PLUGINS_DIR
        // scanned /Plugin at the filesystem ROOT (and found nothing). The
        // default is now the relative 'Plugin', and absolute values that
        // don't exist on disk are auto-resolved against the project base
        // (with a warning) when the project-relative twin DOES exist.
        $dir = EnvHandler::get('PLUGINS_DIR', 'Plugin');

        if (str_starts_with($dir, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $dir)) {
            // Absolute path — normalize trailing slash.
            $dir = rtrim($dir, '/\\');

            // Footgun guard: "/Plugin/" written as if project-relative.
            // If the absolute path does not exist but the same path inside
            // the project base does, resolve it there instead of silently
            // scanning a non-existent root directory.
            if (!is_dir($dir)) {
                $candidate = rtrim(Paths::base(), '/')
                    . '/' . ltrim($dir, '/\\');
                if (is_dir($candidate)) {
                    LogHandler::warning(
                        "PLUGINS_DIR '{$dir}' does not exist — " .
                        "using project-relative '{$candidate}' instead"
                    );
                    $dir = $candidate;
                }
            }
        } else {
            // Relative path — join against project base.
            $dir = rtrim(Paths::base(), '/') . '/' . ltrim($dir, '/');
        }

        return new self(
            path: $dir,
            reloadInterval: EnvHandler::int('PLUGINS_RELOAD_INTERVAL', 600),
            cacheEnabled: EnvHandler::bool('PLUGIN_CACHE_ENABLED', true),
            autoReload: true,
        );
    }
}