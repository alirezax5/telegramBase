<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Update;

use alirezax5\TelegramBase\App\Config\Config;
use alirezax5\TelegramBase\App\Environment\EnvHandler;
use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Middleware\MiddlewarePipeline;
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
    private ?MiddlewarePipeline $middleware = null;

    public function __construct(?PluginHandler $pluginHandler, Telegram $Telegram)
    {
        $this->pluginHandler = $pluginHandler;
        $this->Telegram = $Telegram;
        $this->retryEnabled = EnvHandler::bool('RETRY_ENABLED', true);
    }

    /**
     * Attach a middleware pipeline.
     *
     * When set, every update passes through the pipeline before reaching
     * plugin dispatch. Middlewares may short-circuit (return without
     * calling $next) to reject an update. When the pipeline is empty or
     * not set, behavior is identical to having no middleware.
     *
     * Backward compatibility: existing callers that never call this
     * method see no change in behavior.
     */
    public function setMiddleware(?MiddlewarePipeline $pipeline): void
    {
        $this->middleware = $pipeline;
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
     * Drain any due items from the retry queue and re-dispatch them.
     *
     * Called by long-running workers (PollingLoop, QueueWorker) on
     * their maintenance tick. Each due item is passed back through
     * {@see handle()}; if it fails again with a retryable error it is
     * re-queued with exponential backoff. After maxAttempts the item
     * is dropped and logged.
     *
     * Returns the number of retry items processed (success or fail).
     */
    public function processRetries(): int
    {
        $queue = $this->retryQueue();
        if ($queue === null) {
            return 0;
        }

        return $queue->process(function ($update): void {
            // Re-dispatch through handle(). If the update fails again
            // with a retryable error, handle()'s catch block will push
            // it back into the queue — BUT that would double-count
            // attempts. To avoid that, we re-throw here so RetryQueue's
            // own process() loop handles the attempt counting and
            // backoff scheduling.
            try {
                if (is_array($update)) {
                    $update = $this->toObject($update);
                }
                $this->Telegram->setInputData($update);
                $this->runPlugins($update);
            } catch (\Throwable $e) {
                // Re-throw so RetryQueue::process() handles backoff.
                throw $e;
            }
        });
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

        $normalized = $this->normalizeTree($data);

        return $normalized instanceof \stdClass ? $normalized : (object)$normalized;
    }

    /**
     * P3.3.1-F007: shape-preserving normalization.
     *
     * LIST arrays (numeric-keyed, e.g. photo sizes, entities,
     * new_chat_members, inline_keyboard rows) MUST stay PHP arrays — the
     * Bot API models them as arrays and plugins rely on is_array()/foreach
     * over them. Associative maps become objects (as before). Scalars pass
     * through unchanged.
     */
    private function normalizeTree(mixed $data): mixed
    {
        if (is_array($data)) {
            if (array_is_list($data)) {
                return array_map([$this, 'normalizeTree'], $data);
            }

            $obj = [];
            foreach ($data as $key => $value) {
                $obj[$key] = $this->normalizeTree($value);
            }

            return (object)$obj;
        }

        return $data;
    }

    /**
     * Dispatch the update to all matching plugins, optionally through
     * the middleware pipeline if one is attached.
     *
     * @param object $update Telegram update object
     */
    private function runPlugins(object $update): void
    {
        if ($this->pluginHandler === null) {
            return;
        }

        $debug = Config::bot()->appDebug;
        $start = $debug ? microtime(true) : 0.0;

        // If a middleware pipeline is attached and non-empty, route the
        // update through it. The $final callable runs the actual plugin
        // dispatch. Middlewares may short-circuit by not calling $next.
        if ($this->middleware !== null && !$this->middleware->isEmpty()) {
            $this->middleware->run(
                $update,
                $this->Telegram,
                fn(object $u, Telegram $tg) => $this->pluginHandler->runAll($u, $tg)
            );
        } else {
            $this->pluginHandler->runAll($update, $this->Telegram);
        }

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
