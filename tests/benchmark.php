<?php

declare(strict_types=1);

/**
 * Phase 2 performance + memory benchmark.
 *
 * Measures:
 *   - plugin resolution + dispatch throughput (updates/sec)
 *   - middleware overhead
 *   - memory growth over a long run
 *
 * Usage:
 *   php tests/benchmark.php [update_count]
 *
 * Default: 10,000 updates.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/TgBaseTestCase.php';

date_default_timezone_set('UTC');

$defaults = [
    'BOT_TOKEN' => '123456789:AAEhBP0fakefakefakefakefake_TfAKE',
    'BOT_MODE' => 'update_direct',
    'BOT_API_URL' => 'https://api.telegram.org',
    'BOT_API_URL_FILE' => 'https://api.telegram.org',
    'LOG_DIR' => './logs',
    'LOG_ENABLED' => 'false',
    'DATA_DIR' => sys_get_temp_dir() . '/tgbase_bench_' . getmypid(),
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

@mkdir($_ENV['DATA_DIR'] . '/queue', 0755, true);
@mkdir($_ENV['DATA_DIR'] . '/cron-slots', 0755, true);

use alirezax5\TelegramBase\App\Bootstrap\Bootstrap;
use alirezax5\TelegramBase\App\Plugin\PluginHandler;
use alirezax5\TelegramBase\App\Update\Processor;
use alirezax5\TelegramBase\App\Middleware\MiddlewarePipeline;
use telegramBotApiPhp\Telegram;

Bootstrap::boot();

$count = (int)($argv[1] ?? 10000);
echo "Benchmarking {$count} updates…\n\n";

// === Build a fake plugin handler with one plugin that does minimal work ===

$fakePlugin = new class implements \alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface {
    public int $calls = 0;
    public function getPriority(): int { return 1; }
    public function onMessage($u, Telegram $t): void { $this->calls++; }
};

$handler = (new ReflectionClass(PluginHandler::class))->newInstanceWithoutConstructor();
$ref = new ReflectionClass($handler);

$pluginsProp = $ref->getProperty('plugins');
$pluginsProp->setAccessible(true);
$pluginsProp->setValue($handler, ['onMessage' => [['class' => 'fake', 'priority' => 1, 'chatTypes' => []]]]);

$instancesProp = $ref->getProperty('instances');
$instancesProp->setAccessible(true);
$instancesProp->setValue($handler, ['fake' => $fakePlugin]);

$lastReloadProp = $ref->getProperty('lastReloadTime');
$lastReloadProp->setAccessible(true);
$lastReloadProp->setValue($handler, time());

$reloadIntervalProp = $ref->getProperty('reloadInterval');
$reloadIntervalProp->setAccessible(true);
$reloadIntervalProp->setValue($handler, 999999);

$flipProp = $ref->getProperty('updateTypesFlip');
$flipProp->setAccessible(true);
$flipProp->setValue($handler, array_fill_keys(['message'], true));

$tg = new Telegram('123456789:AAEhBP0fakefakefakefakefake_TfAKE');

$sampleUpdate = (object)[
    'update_id' => 1,
    'message' => (object)[
        'message_id' => 1,
        'text' => 'hello',
        'chat' => (object)['id' => 42, 'type' => 'private'],
        'from' => (object)['id' => 42],
        'date' => time(),
    ],
];

// === Benchmark 1: no middleware ===

$processor = new Processor($handler, $tg);

$memStart = memory_get_usage(true);
$peakStart = memory_get_peak_usage(true);
$start = microtime(true);

for ($i = 0; $i < $count; $i++) {
    $sampleUpdate->update_id = $i;
    $processor->handle($sampleUpdate);
}

$elapsed = microtime(true) - $start;
$memEnd = memory_get_usage(true);
$peakEnd = memory_get_peak_usage(true);

$ups = $count / $elapsed;

printf("=== No middleware ===\n");
printf("  Updates:     %d\n", $count);
printf("  Elapsed:     %.3f s\n", $elapsed);
printf("  Throughput:  %.0f updates/sec\n", $ups);
printf("  Mem delta:   %.2f MB\n", ($memEnd - $memStart) / 1024 / 1024);
printf("  Peak delta:  %.2f MB\n", ($peakEnd - $peakStart) / 1024 / 1024);
printf("  Plugin calls: %d\n\n", $fakePlugin->calls);

// === Benchmark 2: with one passthrough middleware ===

$fakePlugin->calls = 0;

$pipeline = new MiddlewarePipeline();
$pipeline->add(new class implements \alirezax5\TelegramBase\App\Middleware\MiddlewareInterface {
    public function handle(object $update, Telegram $tg, callable $next): mixed
    {
        return $next();
    }
});

$processor2 = new Processor($handler, $tg);
$processor2->setMiddleware($pipeline);

$memStart = memory_get_usage(true);
$peakStart = memory_get_peak_usage(true);
$start = microtime(true);

for ($i = 0; $i < $count; $i++) {
    $sampleUpdate->update_id = $i;
    $processor2->handle($sampleUpdate);
}

$elapsed2 = microtime(true) - $start;
$memEnd = memory_get_usage(true);
$peakEnd = memory_get_peak_usage(true);
$ups2 = $count / $elapsed2;

printf("=== With 1 passthrough middleware ===\n");
printf("  Updates:     %d\n", $count);
printf("  Elapsed:     %.3f s\n", $elapsed2);
printf("  Throughput:  %.0f updates/sec\n", $ups2);
printf("  Mem delta:   %.2f MB\n", ($memEnd - $memStart) / 1024 / 1024);
printf("  Peak delta:  %.2f MB\n", ($peakEnd - $peakStart) / 1024 / 1024);
printf("  Plugin calls: %d\n\n", $fakePlugin->calls);

printf("Middleware overhead: %.1f%%\n", (($elapsed2 - $elapsed) / $elapsed) * 100);

// === Benchmark 3: long-run memory growth (batches of 1000) ===

printf("\n=== Long-run memory growth (10 batches of 1000) ===\n");
$fakePlugin->calls = 0;
$processor3 = new Processor($handler, $tg);

printf("  %-8s  %-12s  %-12s  %-12s\n", 'Batch', 'Mem (MB)', 'Peak (MB)', 'Delta (KB)');
$prevMem = memory_get_usage(true);

for ($batch = 1; $batch <= 10; $batch++) {
    for ($i = 0; $i < 1000; $i++) {
        $sampleUpdate->update_id = $i;
        $processor3->handle($sampleUpdate);
    }

    // Force GC every batch to match PollingLoop maintenance behavior.
    if ($batch % 50 === 0) {
        gc_collect_cycles();
    }

    $mem = memory_get_usage(true);
    $peak = memory_get_peak_usage(true);
    $delta = ($mem - $prevMem) / 1024;
    printf("  %-8d  %-12.2f  %-12.2f  %-12.1f\n", $batch, $mem / 1024 / 1024, $peak / 1024 / 1024, $delta);
    $prevMem = $mem;
}

// Cleanup
$dir = $_ENV['DATA_DIR'] ?? '';
if ($dir !== '' && is_dir($dir)) {
    foreach (glob($dir . '/*') as $f) {
        if (is_dir($f)) {
            foreach (glob($f . '/*') as $sub) @unlink($sub);
            @rmdir($f);
        } else {
            @unlink($f);
        }
    }
    @rmdir($dir);
}

echo "\nDone.\n";
