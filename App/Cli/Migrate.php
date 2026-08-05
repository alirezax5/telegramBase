<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\Cli;

use alirezax5\TelegramBase\App\Bootstrap\Bootstrap;
use alirezax5\TelegramBase\App\Config\Config;
use alirezax5\TelegramBase\App\Database\MigrationRunner;

/**
 * CLI wrappers for the migration runner.
 */
final class Migrate
{
    /**
     * Boot the app and ensure DB is configured.
     */
    private static function boot(): MigrationRunner
    {
        Bootstrap::boot();

        if (!Config::database()->enable) {
            throw new \RuntimeException('Database is disabled. Set DATABASE_ENABLE=true in .env');
        }

        return new MigrationRunner();
    }

    public static function run(): void
    {
        $runner = self::boot();
        $runner->up();
    }

    public static function rollback(): void
    {
        $runner = self::boot();
        $runner->down();
    }

    public static function status(): void
    {
        $runner = self::boot();

        $rows = $runner->status();

        echo str_pad('Migration', 45) . "Status\n";
        echo str_repeat('-', 55) . "\n";

        foreach ($rows as $row) {
            $state = $row['applied'] ? 'applied' : 'pending';
            echo str_pad($row['file'], 45) . $state . "\n";
        }
    }
}
