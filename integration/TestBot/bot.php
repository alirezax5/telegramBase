<?php

declare(strict_types=1);

/**
 * Test Bot entry point — runs TelegramBase in polling mode with test plugins.
 *
 * Usage:
 *   export TELEGRAM_BOT_TOKEN='...'
 *   export TELEGRAM_TEST_CHAT_ID='82267967'
 *   php integration/TestBot/bot.php
 *
 * This bot consumes TelegramBase from the parent repo's vendor/.
 * It uses a separate Plugin/ directory and runtime/ for isolation.
 */

// Test Bot uses its own .env-like config via env vars set by the caller.
// We point PLUGINS_DIR at this Test Bot's Plugin/ directory BEFORE
// requiring the framework autoload, so the framework picks up test
// plugins instead of the production ones.

// Default env for the Test Bot (callers can override).
$testBotDir = __DIR__;
$repoRoot = dirname(__DIR__, 2); // up from integration/TestBot/ to repo root

// Point the framework at this Test Bot's Plugin/ directory.
// Plugin path is resolved relative to the repo base in PluginConfig::fromEnv().
// We set it as an absolute path so the framework uses it directly.
$pluginPath = $testBotDir . '/Plugin';

// Set sensible Test Bot defaults if not already set by caller.
$defaults = [
    'BOT_MODE' => 'update_direct',
    'BOT_API_URL' => 'https://api.telegram.org',
    'BOT_API_URL_FILE' => 'https://api.telegram.org',
    'PLUGINS_DIR' => $pluginPath,
    'DATA_DIR' => $testBotDir . '/runtime/AppData',
    'LOG_DIR' => 'integration/TestBot/runtime/logs',
    'LOG_ENABLED' => 'true',
    'LOG_LEVEL' => 'debug',
    'CACHE_DRIVER' => 'array',
    'DATABASE_ENABLE' => 'false',
    'QUEUE_SAVE_TYPE' => 'json',
    'RETRY_ENABLED' => 'true',
    'POLLING_LIMIT' => '100',
    'POLLING_TIMEOUT' => '10',
    'POLLING_RESTART_INTERVAL' => '0',  // disable self-restart for tests
    'PLUGIN_CACHE_ENABLED' => 'false',  // always scan fresh for test plugins
    'TGBASE_SKIP_BOT_VALIDATION' => '0',
];

foreach ($defaults as $k => $v) {
    if (getenv($k) === false && !isset($_ENV[$k])) {
        putenv("{$k}={$v}");
        $_ENV[$k] = $v;
    }
}

// Load the framework autoload.
require $repoRoot . '/vendor/autoload.php';

// Load .env if present (for BOT_TOKEN). The Test Bot has its own .env.
$testEnvFile = $testBotDir . '/.env';
if (is_file($testEnvFile) && class_exists(\Dotenv\Dotenv::class)) {
    try {
        $dotenv = \Dotenv\Dotenv::createImmutable($testBotDir);
        $dotenv->load();
    } catch (\Throwable $e) {
        fwrite(STDERR, "Warning: could not load TestBot .env: " . $e->getMessage() . "\n");
    }
}

// Allow TELEGRAM_BOT_TOKEN to override BOT_TOKEN (so we don't need to
// put the token in .env — it can be passed via environment only).
$tgToken = getenv('TELEGRAM_BOT_TOKEN') ?: '';
if ($tgToken !== '') {
    putenv("BOT_TOKEN={$tgToken}");
    $_ENV['BOT_TOKEN'] = $tgToken;
}

use alirezax5\TelegramBase\App\Core;

// Load Test Bot middleware (not in PSR-4 autoload path).
require __DIR__ . '/Middleware/LoggingMiddleware.php';
require __DIR__ . '/Middleware/ShortCircuitMiddleware.php';

$core = new Core();

// Register Test Bot middleware.
$core->middleware()->add(new \alirezax5\TelegramBase\TestBot\Middleware\LoggingMiddleware());

$core->run();
