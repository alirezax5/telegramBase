<?php

use alirezax5\TelegramBase\App\Environment\EnvHandler;
use alirezax5\TelegramBase\App\Language\Language;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

// --- init Filesystem ---
$fs = new Filesystem();

// --- init .env ---
$dotenv = Dotenv\Dotenv::createImmutable(Path::normalize(Path::getDirectory(__DIR__, '..')));
$dotenv->load();

$basePath = Path::canonicalize(Path::getDirectory(__DIR__, '..'));
$buttonFile = Path::canonicalize(
    Path::join($basePath, EnvHandler::get('BUTTONS_FILE', 'btn.php'))
);

// اگر مسیر وجود نداشت، ارور نده (خودت مدیریت کن)
if (!$fs->exists($buttonFile)) {
    throw new RuntimeException("Button file not found: {$buttonFile}");
}

// --- تعریف ثابت‌ها ---
define('APP_BASE_PATH', $basePath);
define('BUTTON_FILE', $buttonFile);

// --- زبان ---
Language::getInstance()->setLanguageDir(
    Path::join(APP_BASE_PATH, EnvHandler::get('LANG_DIR'))
);
Language::getInstance()->setLanguage(
    EnvHandler::get('DEFAULT_LANG', 'fa')
);

// --- دیتابیس ---
$configs = [
    'mysql' => [
        'driver' => 'mysql',
        'host' => EnvHandler::get('DB_HOST', '127.0.0.1'),
        'port' => EnvHandler::get('DB_PORT', 3306),
        'database' => EnvHandler::get('DB_DATABASE'),
        'username' => EnvHandler::get('DB_USERNAME'),
        'password' => EnvHandler::get('DB_PASSWORD'),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
    ],
    'sqlite' => [
        'driver' => 'sqlite',
        'database' => Path::join(APP_BASE_PATH, EnvHandler::get('DB_FILE')),
        'prefix' => '',
    ],
    'pgsql' => [
        'driver' => 'pgsql',
        'host' => EnvHandler::get('DB_HOST', '127.0.0.1'),
        'port' => EnvHandler::get('DB_PORT', 5432),
        'database' => EnvHandler::get('DB_DATABASE'),
        'username' => EnvHandler::get('DB_USERNAME'),
        'password' => EnvHandler::get('DB_PASSWORD'),
        'charset' => 'utf8',
        'schema' => 'public',
        'prefix' => '',
    ]
];

$capsule = new Capsule;
$capsule->addConnection($configs[$_ENV['DB_DRIVER']]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

// --- Container & Facade ---
$app = new Container();
$app->instance('db', $capsule);
Facade::setFacadeApplication($app);
