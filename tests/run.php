<?php

declare(strict_types=1);

/**
 * TelegramBase lightweight test runner.
 *
 * Each test file in tests/Unit/ or tests/Integration/ returns a callable
 * that receives a TgAssert instance and registers assertions via its
 * methods. The runner discovers them, executes them in order, and
 * prints a pass/fail summary.
 *
 * Live tests that hit the real Telegram Bot API are skipped unless
 * RUN_TELEGRAM_LIVE_TESTS=1 and TELEGRAM_BOT_TOKEN /
 * TELEGRAM_TEST_CHAT_ID are set.
 *
 * Usage:
 *   php tests/run.php                 # run all unit + integration (offline) tests
 *   RUN_TELEGRAM_LIVE_TESTS=1 \
 *     TELEGRAM_BOT_TOKEN=… \
 *     TELEGRAM_TEST_CHAT_ID=… \
 *     php tests/run.php               # also run live Telegram tests
 *   php tests/run.php --filter=Keyboard   # run only tests whose name contains "Keyboard"
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/TgBaseTestCase.php';

// =====================================================================
// Bootstrap a minimal but valid environment for offline tests.
// Tests that need a real bot token load it from env themselves.
// =====================================================================

date_default_timezone_set('UTC');

// Sensible defaults so EnvHandler doesn't blow up in unit tests that
// exercise Config / Bootstrap.
$defaults = [
    'BOT_TOKEN' => '123456789:AAEhBP0fakefakefakefakefake_TfAKE',
    'BOT_MODE' => 'update_direct',
    'BOT_API_URL' => 'https://api.telegram.org',
    'BOT_API_URL_FILE' => 'https://api.telegram.org',
    'LOG_DIR' => './logs',
    'LOG_ENABLED' => 'false',
    'DATA_DIR' => 'AppData',
    'CACHE_DRIVER' => 'array',
    'DATABASE_ENABLE' => 'false',
    'QUEUE_SAVE_TYPE' => 'json',
    'RETRY_QUEUE_DRIVER' => 'json',
    'TGBASE_SKIP_BOT_VALIDATION' => '0',
];

foreach ($defaults as $k => $v) {
    if (getenv($k) === false && !isset($_ENV[$k])) {
        putenv("{$k}={$v}");
        $_ENV[$k] = $v;
    }
}

// Bootstrap the framework so Config, Cache, Paths are usable. This
// mirrors what real bot.php does, except we point DATA_DIR at a
// throwaway temp dir to avoid polluting the repo.
$testDataDir = sys_get_temp_dir() . '/tgbase_testdata_' . getmypid();
@mkdir($testDataDir . '/queue', 0755, true);
@mkdir($testDataDir . '/cron-slots', 0755, true);
putenv("DATA_DIR={$testDataDir}");
$_ENV['DATA_DIR'] = $testDataDir;

// Bootstrap requires Paths::initialize + EnvironmentBootstrap + EnvironmentValidator.
use alirezax5\TelegramBase\App\Bootstrap\Bootstrap;
use alirezax5\TelegramBase\App\Paths;
use alirezax5\TelegramBase\App\Environment\EnvHandler;

try {
    Bootstrap::boot();
} catch (\Throwable $e) {
    fwrite(STDERR, "Bootstrap failed during test setup: " . $e->getMessage() . "\n");
    // Continue anyway — individual tests will report their own failures.
}

// Register a shutdown function to clean up the temp data dir.
register_shutdown_function(static function () use ($testDataDir): void {
    if (!is_dir($testDataDir)) {
        return;
    }
    foreach (glob($testDataDir . '/*') as $f) {
        if (is_dir($f)) {
            foreach (glob($f . '/*') as $sub) {
                @unlink($sub);
            }
            @rmdir($f);
        } else {
            @unlink($f);
        }
    }
    @rmdir($testDataDir);
});

// =====================================================================
// Test runner
// =====================================================================

final class TgAssert
{
    public int $pass = 0;
    public int $fail = 0;
    public int $skip = 0;
    private string $current = '';

    public function setCurrent(string $name): void
    {
        $this->current = $name;
    }

    public function pass(string $message = ''): void
    {
        $this->pass++;
        echo "  ✓ {$this->current}" . ($message !== '' ? " — {$message}" : "") . "\n";
    }

    public function fail(string $message): void
    {
        $this->fail++;
        echo "  ✗ {$this->current} — {$message}\n";
    }

    public function skip(string $reason): void
    {
        $this->skip++;
        echo "  ⊘ {$this->current} — skipped: {$reason}\n";
    }

    public function true(mixed $v, string $message = 'expected true'): void
    {
        if ($v === true) {
            $this->pass();
        } else {
            $this->fail($message . ' (got ' . var_export($v, true) . ')');
        }
    }

    public function false(mixed $v, string $message = 'expected false'): void
    {
        if ($v === false) {
            $this->pass();
        } else {
            $this->fail($message . ' (got ' . var_export($v, true) . ')');
        }
    }

    public function equals(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected === $actual) {
            $this->pass();
        } else {
            $this->fail(
                ($message !== '' ? "{$message} — " : '') .
                'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
            );
        }
    }

    public function notEquals(mixed $unexpected, mixed $actual, string $message = ''): void
    {
        if ($unexpected !== $actual) {
            $this->pass();
        } else {
            $this->fail(($message !== '' ? "{$message} — " : '') . 'unexpectedly got ' . var_export($actual, true));
        }
    }

    public function contains(string $needle, string $haystack, string $message = ''): void
    {
        if (str_contains($haystack, $needle)) {
            $this->pass();
        } else {
            $this->fail(
                ($message !== '' ? "{$message} — " : '') .
                "expected '{$needle}' in '{$haystack}'"
            );
        }
    }

    public function instanceOf(string $class, mixed $object, string $message = ''): void
    {
        if ($object instanceof $class) {
            $this->pass();
        } else {
            $this->fail(
                ($message !== '' ? "{$message} — " : '') .
                'expected ' . $class . ', got ' . (is_object($object) ? get_class($object) : gettype($object))
            );
        }
    }

    public function throws(string $expectedClass, callable $fn, string $message = ''): void
    {
        try {
            $fn();
            $this->fail(($message !== '' ? "{$message} — " : '') . "expected {$expectedClass} but nothing was thrown");
        } catch (\Throwable $e) {
            if ($e instanceof $expectedClass) {
                $this->pass();
            } else {
                $this->fail(
                    ($message !== '' ? "{$message} — " : '') .
                    "expected {$expectedClass}, got " . get_class($e) . ': ' . $e->getMessage()
                );
            }
        }
    }
}

// =====================================================================
// Discover & run
// =====================================================================

$assert = new TgAssert();
$filter = $argv[1] ?? '';
if (str_starts_with($filter, '--filter=')) {
    $filter = substr($filter, 9);
}

$dirs = [
    __DIR__ . '/Unit',
    __DIR__ . '/Integration',
    __DIR__ . '/Live',
];

$totalStart = microtime(true);

foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        continue;
    }

    $kind = basename($dir);
    echo "\n=== {$kind} tests ===\n";

    foreach (glob($dir . '/*Test.php') as $file) {
        require_once $file;

        // File tests/Unit/FooBarTest.php → class FooBarTest
        $class = basename($file, '.php');

        if (!class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();

            if (!str_starts_with($name, 'test_')) {
                continue;
            }

            $fullName = "{$class}::{$name}";

            if ($filter !== '' && !str_contains($fullName, $filter)) {
                continue;
            }

            $assert->setCurrent($fullName);

            try {
                $instance = new $class($assert);
                $instance->$name($assert);
            } catch (\Throwable $e) {
                $assert->fail("uncaught exception: " . get_class($e) . ': ' . $e->getMessage());
            }
        }
    }
}

$elapsed = round((microtime(true) - $totalStart) * 1000, 1);

echo "\n";
echo "──────────────────────────────────────\n";
echo "Pass: {$assert->pass}  Fail: {$assert->fail}  Skip: {$assert->skip}  ({$elapsed} ms)\n";
exit($assert->fail > 0 ? 1 : 0);
