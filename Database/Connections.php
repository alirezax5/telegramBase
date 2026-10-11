<?php

use alirezax5\TelegramBase\App\Environment\EnvHandler;

/**
 * Database connection definitions for Eloquent.
 *
 * Loaded by AppConfigFactory::createDatabaseConfig() when present.
 * The 'main' connection is the default used by Models.
 */
return [
    'default' => 'main',
    'connections' => [
        'main' => [
            // P-REL-B03: honor DB_DRIVER (documented in configuration.md as
            // "any PDO driver, sqlite for local dev") instead of hardcoding
            // mysql. Default unchanged — existing deployments are unaffected.
            'driver'    => EnvHandler::get('DB_DRIVER', 'mysql'),
            'host'      => EnvHandler::get('DB_HOST', '127.0.0.1'),
            'port'      => EnvHandler::get('DB_PORT', 3306),
            'database'  => EnvHandler::get('DB_DATABASE'),
            'username'  => EnvHandler::get('DB_USERNAME'),
            'password'  => EnvHandler::get('DB_PASSWORD'),
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
        ],
    ],
];