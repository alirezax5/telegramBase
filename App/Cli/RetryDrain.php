<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\Cli;

use alirezax5\TelegramBase\App\Bootstrap\Bootstrap;
use alirezax5\TelegramBase\App\Bootstrap\PluginsBootstrap;
use alirezax5\TelegramBase\App\Bot\BotManager;
use alirezax5\TelegramBase\App\Config\Config;
use alirezax5\TelegramBase\App\Environment\EnvHandler;
use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Queue\RetryQueue;
use alirezax5\TelegramBase\App\Update\Processor;

/**
 * CLI command: php bin/tgbase retry:drain
 *
 * Drains due items from the retry queue. Designed for webhook-only
 * deployments where no long-running worker calls processRetries()
 * automatically.
 *
 * Intended for cron:
 *   * * * * * cd /path/to/project && php bin/tgbase retry:drain
 *
 * Exit codes:
 *   0 = drain completed (even if some retries failed and were rescheduled)
 *   1 = bootstrap or configuration failure
 */
final class RetryDrain
{
    public static function run(): int
    {
        // Migration/doctor/retry commands don't need a live bot token
        // for the drain itself, but the Processor needs a Telegram instance
        // to re-dispatch updates. We DO need BOT_TOKEN for that.
        // Bootstrap normally — if BOT_TOKEN is missing, the validator will
        // reject it, which is correct behavior (retry drain needs the bot).
        try {
            Bootstrap::boot();
        } catch (\Throwable $e) {
            fwrite(STDERR, "Bootstrap failed: " . $e->getMessage() . "\n");
            return 1;
        }

        // Load plugins so retried updates go through the full pipeline.
        PluginsBootstrap::boot();
        $pluginHandler = PluginsBootstrap::getPlugins();

        $tg = BotManager::getInstance()->get();
        $processor = new Processor($pluginHandler, $tg);

        // Get retry queue stats before drain.
        $retryQueue = null;
        try {
            $retryQueue = new RetryQueue();
        } catch (\Throwable $e) {
            fwrite(STDERR, "RetryQueue init failed: " . $e->getMessage() . "\n");
            return 1;
        }

        $beforeCount = $retryQueue->count();

        // Drain due retries.
        $processed = 0;
        try {
            $processed = $processor->processRetries();
        } catch (\Throwable $e) {
            // processRetries has its own try/catch internally, but
            // guard against unexpected errors.
            fwrite(STDERR, "Retry drain error: " . $e->getMessage() . "\n");
        }

        $afterCount = $retryQueue->count();
        $rescheduled = $afterCount; // Items still in queue = rescheduled or not yet due
        $droppedOrSucceeded = $beforeCount - $afterCount;

        echo "Retry drain complete.\n\n";
        echo "Queue before:  {$beforeCount}\n";
        echo "Processed:     {$processed}\n";
        echo "Remaining:     {$afterCount}\n";
        echo "  (rescheduled or not yet due)\n";
        echo "Removed:       {$droppedOrSucceeded}\n";
        echo "  (succeeded or dropped after max attempts)\n";

        return 0;
    }
}
