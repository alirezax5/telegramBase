<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Update;

use alirezax5\TelegramBase\App\Config\Config;
use alirezax5\TelegramBase\App\Environment\EnvHandler;
use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Plugin\PluginHandler;
use alirezax5\TelegramBase\App\Queue\RetryQueue;
use alirezax5\TelegramBase\App\Queue\RetryableException;
use telegramBotApiPhp\Telegram;

class Processor
{
    private ?PluginHandler $pluginHandler;
    private Telegram $Telegram;
    private ?RetryQueue $retryQueue = null;
    private bool $retryEnabled;

    public function __construct(?PluginHandler $pluginHandler, Telegram $Telegram)
    {
        $this->pluginHandler = $pluginHandler;
        $this->Telegram = $Telegram;
        $this->retryEnabled = (bool)EnvHandler::get('RETRY_ENABLED', true);
    }

    /**
     * Handle a batch of updates sequentially.
     *
     * Used by PollingLoop and queue worker.
     *
     * @param iterable    $updates  Collection of updates
     * @param callable|null $afterEach Optional callback invoked after each update
     */
    public function handleBatch(iterable $updates, ?callable $afterEach = null): void
    {
        foreach ($updates as $update) {

            $this->handle($update);

            if ($afterEach !== null) {
                $afterEach($update);
            }
        }
    }

    /**
     * Handle a single update: set input data and dispatch to plugins.
     *
     * @param mixed $update Telegram update (object or raw array)
     */
    public function handle(mixed $update): void
    {
        if (empty($update)) {
            return;
        }

        if (is_array($update)) {
            $update = $this->toObject($update);
        }

        try {

            $this->Telegram->setInputData($update);
            $this->runPlugins($update);

        } catch (\Throwable $e) {
            LogHandler::error('Update failed', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),

                'update_id' => $update->update_id ?? null,
            ]);

            // Transient failures (rate limit, 5xx, timeouts) go to the
            // retry queue so the update is not lost permanently.
            if ($this->retryEnabled && RetryableException::isRetryable($e)) {
                $this->retryQueue()?->push($update, $e->getMessage());
            }
        }
    }

    /**
     * Convert an associative array to a stdClass object (recursively).
     *
     * @param mixed $data Raw update payload
     * @return object Converted object
     */
    private function toObject(mixed $data): object
    {
        if (is_object($data)) {
            return $data;
        }

        if (!is_array($data)) {
            return (object)[];
        }

        $result = [];

        foreach ($data as $key => $value) {
            $result[$key] = is_array($value) ? $this->toObject($value) : $value;
        }

        return (object)$result;
    }

    /**
     * Dispatch the update to all matching plugins.
     *
     * @param object $update Telegram update object
     */
    private function runPlugins(object $update): void
    {
        if ($this->pluginHandler === null) {
            return;
        }

        $debug = Config::bot()->appDebug;

        if ($debug) {
            $start = microtime(true);
        }

        $this->pluginHandler->runAll($update, $this->Telegram);

        if ($debug) {
            $duration = (microtime(true) - $start) * 1000;

            if ($duration > 300) {
                LogHandler::warning('⚠️ Slow update detected: ' . round($duration, 2) . 'ms', [
                    'update_id' => $update->update_id ?? null
                ]);
            }
        }
    }

    /**
     * Lazy-init the retry queue.
     */
    private function retryQueue(): ?RetryQueue
    {
        if ($this->retryQueue === null) {
            try {
                $this->retryQueue = new RetryQueue();
            } catch (\Throwable) {
                $this->retryQueue = null;
            }
        }
        return $this->retryQueue;
    }

    /**
     * Handle webhook input directly.
     */
    public function handleWebhook(): void
    {
        $input = $this->Telegram->getInputData();

        if (empty($input)) {
            LogHandler::warning('⚠️ Empty webhook payload');
            return;
        }

        $this->handle($input);
    }
}
