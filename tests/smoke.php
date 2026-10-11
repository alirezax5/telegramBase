<?php
/**
 * Quick smoke test for bootstrap + config validation.
 * Run: php tests/smoke.php
 *
 * Exit code 0 = all cases behaved as expected, 1 = at least one did not.
 */
require __DIR__ . '/../vendor/autoload.php';

use alirezax5\TelegramBase\App\Bootstrap\Bootstrap;
use alirezax5\TelegramBase\App\Environment\EnvHandler;

/**
 * Each case sets env vars, boots the framework, and asserts the result.
 *
 * `expect` semantics:
 *   - 'OK'                     : bootstrap must complete without throwing
 *   - '/SomeExceptionClass/'   : bootstrap must throw an instance of this class
 *   - 'SomeExceptionClass'     : same, convenience shorthand
 *   - any other string         : the exception message must contain it (case-insensitive)
 */
$tests = [
    'bad token' => [
        'env' => ['BOT_TOKEN' => 'not-a-token', 'BOT_MODE' => 'update_direct'],
        'expect' => 'InvalidArgumentException',
    ],
    'empty token' => [
        'env' => ['BOT_TOKEN' => '', 'BOT_MODE' => 'update_direct'],
        'expect' => 'InvalidArgumentException',
    ],
    'log traversal' => [
        'env' => [
            'BOT_TOKEN' => '123456789:AAEhBP0fakefakefakefakefake_TfAKE',
            'BOT_MODE' => 'update_direct',
            'LOG_DIR' => '../../etc',
        ],
        'expect' => 'InvalidArgumentException',
    ],
    'log absolute' => [
        'env' => [
            'BOT_TOKEN' => '123456789:AAEhBP0fakefakefakefakefake_TfAKE',
            'BOT_MODE' => 'update_direct',
            'LOG_DIR' => '/var/log',
        ],
        'expect' => 'InvalidArgumentException',
    ],
    'bad mode' => [
        'env' => [
            'BOT_TOKEN' => '123456789:AAEhBP0fakefakefakefakefake_TfAKE',
            'BOT_MODE' => 'bogus_mode',
        ],
        'expect' => 'InvalidArgumentException',
    ],
    'valid boot' => [
        'env' => [
            'BOT_TOKEN' => '123456789:AAEhBP0fakefakefakefakefake_TfAKE',
            'BOT_MODE' => 'update_direct',
            'LOG_DIR' => './logs',
        ],
        'expect' => 'OK',
    ],
];

$fail = 0;
$pass = 0;

foreach ($tests as $name => $test) {
    foreach ($test['env'] as $k => $v) {
        putenv("{$k}={$v}");
        $_ENV[$k] = $v;
    }

    // The static cache in EnvHandler would otherwise leak between cases.
    EnvHandler::clearCache();

    $result = 'OK';
    $caught = null;

    try {
        Bootstrap::boot();
    } catch (\Throwable $e) {
        $caught = $e;
        $result = get_class($e) . ': ' . $e->getMessage();
    }

    $passMatch = matchExpectation($test['expect'], $result, $caught);

    if ($passMatch) {
        echo "PASS | {$name}\n";
        $pass++;
    } else {
        echo "FAIL | {$name}: expected '" . $test['expect'] . "', got '{$result}'\n";
        $fail++;
    }
}

echo "\n" . ($fail === 0
    ? "All {$pass} cases passed."
    : "{$pass} passed, {$fail} failed.") . "\n";

exit($fail > 0 ? 1 : 0);

/**
 * Decide whether the actual result matches the expected pattern.
 *
 * @param string       $expect  Class name, /regex/, substring, or 'OK'
 * @param string       $result  Either 'OK' or 'Class: message'
 * @param \Throwable|null $e    The caught exception (if any)
 */
function matchExpectation(string $expect, string $result, ?\Throwable $e): bool
{
    if ($expect === 'OK') {
        return $result === 'OK' && $e === null;
    }

    if ($e === null) {
        return false;
    }

    // /regex/ form
    if (str_starts_with($expect, '/') && str_ends_with($expect, '/') && strlen($expect) > 2) {
        return preg_match($expect, $result) === 1;
    }

    // Bare class name → must be that class (or subclass)
    if (class_exists($expect) && $e instanceof $expect) {
        return true;
    }

    // Otherwise treat as substring match on the full result string
    return stripos($result, $expect) !== false;
}
