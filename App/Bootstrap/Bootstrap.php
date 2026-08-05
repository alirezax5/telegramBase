<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Bootstrap;

use alirezax5\TelegramBase\App\Cache\CacheManager;
use alirezax5\TelegramBase\App\Config\Config;
use alirezax5\TelegramBase\App\Database\DatabaseManager;
use alirezax5\TelegramBase\App\Environment\EnvironmentValidator;
use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Paths;
use alirezax5\TelegramBase\App\Config\AppConfigFactory;
use Symfony\Component\Filesystem\Path;
use alirezax5\TelegramBase\App\Language\Language;

final class Bootstrap
{
    public static function boot(): void
    {
        Paths::initialize(dirname(__DIR__, 2));
        Paths::ensureDirectories();
        EnvironmentBootstrap::boot();

        // EnvironmentValidator ALWAYS runs — not gated on APP_DEBUG.
        // Missing/invalid BOT_TOKEN or BOT_MODE is a runtime error regardless.
        (new EnvironmentValidator())->validate();

        $config = AppConfigFactory::create();
        Config::init($config);
        LogHandler::init();
        LogHandler::info('log & Config & Env init');
        CacheManager::init();
        DatabaseManager::boot();
        Language::getInstance()->setLanguageDir(
            Path::join(Paths::base(), Config::language()->dir)
        );
        QueueBootstrap::boot();
    }
}