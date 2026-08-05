<?php

declare(strict_types=1);

/**
 * Example schedule file — register your tasks here.
 * Loaded by bin/scheduler.php on every cron tick.
 *
 * Usage:
 *   $scheduler->everyMinutes(5, function () { ... }, 'heartbeat');
 *   $scheduler->dailyAt('09:00', function () { ... }, 'daily-report');
 */

use alirezax5\TelegramBase\App\Scheduler\Scheduler;

/** @var Scheduler $scheduler */

// Example: heartbeat every 5 minutes
// $scheduler->everyMinutes(5, function () {
//     file_put_contents(__DIR__ . '/../AppData/heartbeat.txt', date('c'), LOCK_EX);
// }, 'heartbeat');

// Example: daily cleanup at 03:00
// $scheduler->dailyAt('03:00', function () {
//     // cleanup stale sessions / temp files
// }, 'cleanup');
