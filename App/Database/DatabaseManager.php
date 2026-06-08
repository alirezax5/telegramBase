<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Database;

use alirezax5\TelegramBase\App\Config\Config;
use Illuminate\Database\Capsule\Manager as Capsule;

final class DatabaseManager
{
    private static ?Capsule $capsule = null;

    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        $config = Config::database();

        if (!$config->enable) {
            return;
        }

        self::$capsule = new Capsule();

        foreach ($config->connections as $name => $connection) {
            self::$capsule->addConnection($connection, $name);
        }

        self::$capsule->getDatabaseManager()->setDefaultConnection(
            $config->default
        );

        self::$capsule->setAsGlobal();
        self::$capsule->bootEloquent();

        self::$booted = true;
    }

    public static function connection(?string $name = null)
    {
        return self::$capsule
            ->getConnection($name);
    }

    public static function capsule(): Capsule
    {
        return self::$capsule;
    }
}