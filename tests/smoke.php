<?php
/**
 * Quick smoke test for bootstrap + config validation.
 * Run: php tests/smoke.php
 */
require __DIR__ . '/../vendor/autoload.php';

use alirezax5\TelegramBase\App\Bootstrap\Bootstrap;

$tests = [
    'bad token' => [
        'env' => ['BOT_TOKEN' => 'not-a-token', 'BOT_MODE' => 'update_direct'],
        'expect' => 'InvalidArgumentException',
    ],
    'log traversal' => [
        'env' => ['BOT_TOKEN' => '12345:ABCFake', 'BOT_MODE' => 'update_direct', 'LOG_DIR' => '../../etc'],
        'expect' => 'InvalidArgumentException',
    ],
    'valid boot' => [
        'env' => [
            'BOT_TOKEN' => '123456789:AAfake',
            'BOT_MODE' => 'update_direct',
            'LOG_DIR' => './logs',
        ],
        'expect' => 'OK',
    ],
];

$fail = 0;

foreach ($tests as $name => $test) {
    foreach ($test['env'] as $k => $v) {
        putenv("{$k}={$v}");
        $_ENV[$k] = $v;
    }

    alirezax5\TelegramBase\App\Environment\EnvHandler::clearCache();

    try {
        Bootstrap::boot();
        $result = 'OK';
    } catch (\Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage();
    }

    $pass = $test['expect'] === 'OK'
        ? $result === 'OK'
        : $result === $test['expect'];

    echo ($pass ? 'PASS' : 'FAIL') . " | {$name}: got {$result}\n";

    if (!$pass) {
        $fail++;
    }
}

exit($fail > 0 ? 1 : 0);
