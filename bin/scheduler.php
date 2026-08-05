<?php

declare(strict_types=1);

/**
 * Scheduler entry point — run from cron every minute:
 *
 *   * * * * * php /path/to/bin/scheduler.php
 *
 * Boots the app, registers tasks from bin/tasks.php (or the SCHEDULE_FILE
 * env path), runs the due ones, and exits. No daemon required.
 */

use alirezax5\TelegramBase\App\Bootstrap\Bootstrap;
use alirezax5\TelegramBase\App\Scheduler\Scheduler;

require __DIR__ . '/../vendor/autoload.php';

try {
    Bootstrap::boot();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Scheduler boot failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$scheduler = new Scheduler();

$scheduleFile = getenv('SCHEDULE_FILE') ?: __DIR__ . '/tasks.php';

if (!is_file($scheduleFile)) {
    fwrite(STDERR, 'No schedule file at: ' . $scheduleFile . PHP_EOL);
    exit(1);
}

require $scheduleFile;

$ran = $scheduler->run();

echo '[' . date('Y-m-d H:i:s') . "] scheduler ran {$ran} task(s)" . PHP_EOL;
exit(0);
