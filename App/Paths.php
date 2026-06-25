<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App;

use alirezax5\TelegramBase\App\Environment\EnvHandler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final class Paths
{
    private static string $basePath;

    private static ?Filesystem $filesystem = null;

    public static function initialize(string $basePath): void
    {
        self::$basePath = Path::canonicalize($basePath);
        self::$filesystem = new Filesystem();
    }

    public static function base(): string
    {
        return self::$basePath;
    }

    // =========================================================
    // Directories
    // =========================================================

    public static function dataDirectory(): string
    {
        return Path::join(
            self::$basePath,
            EnvHandler::get('DATA_DIR', 'AppData')
        );
    }

    public static function queueDirectory(): string
    {
        return Path::join(self::dataDirectory(), 'queue');
    }

    public static function cronSlotsDirectory(): string
    {
        return Path::join(self::dataDirectory(), 'cron-slots');
    }

    public static function logDirectory(): string
    {
        return Path::join(
            self::$basePath,
            trim(EnvHandler::get('LOG_DIR', 'logs'), '/\\')
        );
    }

    // =========================================================
    // Files
    // =========================================================

    public static function lastUpdateFile(): string
    {
        return Path::join(
            self::dataDirectory(),
            EnvHandler::get('POLLING_STATE_FILE', 'lastupdate.txt')
        );
    }

    public static function databaseFile(): string
    {
        return Path::join(
            self::$basePath,
            EnvHandler::get('DB_FILE', 'database.sqlite')
        );
    }

    public static function buttonFile(): string
    {
        return Path::canonicalize(
            Path::join(
                self::$basePath,
                EnvHandler::get('BUTTONS_FILE', 'btn.php')
            )
        );
    }

    public static function databaseConnections(): string
    {
        return self::$basePath . '/Database/Connections.php';
    }

    // =========================================================
    // Ensure system
    // =========================================================

    public static function ensureDirectories(): void
    {
        $dirs = [
            self::dataDirectory(),
            self::queueDirectory(),
            self::cronSlotsDirectory(),
            self::logDirectory(),
        ];

        foreach ($dirs as $dir) {
            self::ensureDirectory($dir);
        }
    }

    private static function ensureDirectory(string $dir): void
    {
        if (!self::$filesystem) {
            self::$filesystem = new Filesystem();
        }

        if (!self::$filesystem->exists($dir)) {
            self::$filesystem->mkdir($dir, 0777);
        }
    }
}