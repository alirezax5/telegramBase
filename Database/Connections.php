<?php

use alirezax5\TelegramBase\App\Config\Config;
use Symfony\Component\Filesystem\Path;
use alirezax5\TelegramBase\App\Environment\EnvHandler;

return [
    'default' => 'main',
    'connections' => [
        'main' => [
            'driver' => 'mysql',
            'host' => EnvHandler::get('DB_HOST', '127.0.0.1'),
            'port' => EnvHandler::get('DB_PORT', 3306),
            'database' => EnvHandler::get('DB_DATABASE'),
            'username' => EnvHandler::get('DB_USERNAME'),
            'password' => EnvHandler::get('DB_PASSWORD'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]
    ]


];