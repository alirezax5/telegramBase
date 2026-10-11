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

        // P-REL-B05: safeLoad() instead of load() — a deployment that
        // configures exclusively through real environment variables
        // (systemd Environment=, Docker -e, k8s env) has no .env file,
        // and load() used to throw "Unable to read any of the environment
        // file(s)" and kill the boot. safeLoad() loads .env when present
        // and is a no-op when absent; process env was always authoritative.
        $dotenv->safeLoad();
    }
}