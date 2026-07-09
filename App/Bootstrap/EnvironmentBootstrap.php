<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Bootstrap;

use Dotenv\Dotenv;
use alirezax5\TelegramBase\App\Paths;

final class EnvironmentBootstrap
{
    public static function boot(): void
    {
        $dotenv = Dotenv::createImmutable(
            Paths::base()
        );

        $dotenv->load();
    }
}