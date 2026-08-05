<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Queue;

use alirezax5\TelegramBase\App\Config\Config;
use alirezax5\TelegramBase\App\Logger\LogHandler;

/**
 * Retry queue for updates that failed with transient errors.
 *
 * Failed updates are re-queued with exponential backoff and a bounded
 * number of attempts. After the last attempt the update is dropped and
 * logged - the queue can never grow without limit.
 */
final class RetryQueue
{
    private QueueInterface $driver;
    private int $maxAttempts;
    private int $baseDelay;
    private int $maxSize;

    public function __construct(?RetryConfig $config = null)
    {
        $config ??= Config::retry();

        $this->maxAttempts = max(1, $config->maxAttempts);
        $this->baseDelay = max(1, $config->baseDelay);
        $this->maxSize = max(0, $config->maxSize);

        $this->driver = $this->createDriver($config);
    }

    private function createDriver(RetryConfig $config): QueueInterface
    {
        try {
            return match ($config->driver) {
                'redis' => new \alirezax5\TelegramBase\App\Queue\Drivers\RedisQueue(
                    array_merge($config->redis, ['key' => $config->redisKey])
                ),
                'memcached' => new \alirezax5\TelegramBase\App\Queue\Drivers\MemcachedQueue([
                    'host' => (string)($config->redis['host'] ?? '127.0.0.1'),
                    'port' => (int)($config->redis['port'] ?? 11211),
                    'key' => $config->redisKey,
                ]),
                'rabbitmq' => new \alirezax5\TelegramBase\App\Queue\Drivers\RabbitQueue([
                    'queue' => 'bot_retry_queue',
                ]),
                default => new \alirezax5\TelegramBase\App\Queue\Drivers\JsonQueue(
                    $config->path
                ),
            };
        } catch (\Throwable $e) {
            LogHandler::warning("Retry queue fallback to json: {$e->getMessage()}");
            return new \alirezax5\TelegramBase\App\Queue\Drivers\JsonQueue($config->path);
        }
    }

    /**
     * Enqueue an update for retry.
     *
     * @param object|array $update Telegram update
     * @param string       $reason Human-readable failure reason
     */
    public function push(object|array $update, string $reason = ''): bool
    {
        if ($this->maxSize > 0 && $this->count() >= $this->maxSize) {
            LogHandler::warning("⛔ Retry queue full ({$this->maxSize}), update dropped: {$reason}");
            return false;
        }

        $payload = [
            'update' => $update,
            'attempts' => 0,
            'last_error' => $reason,
            'next_retry_at' => time(),
        ];

        return $this->driver->push($payload);
    }

    /**
     * Process all due retries. Returns number of updates processed.
     *
     * @param callable|null $handler Receives the update; default throws
     *                               RetryableException when retryable.
     */
    public function process(?callable $handler = null): int
    {
        $processed = 0;

        while (($item = $this->driver->pop()) !== null) {

            if (!is_array($item) || !isset($item['update'])) {
                continue;
            }

            $nextRetryAt = (int)($item['next_retry_at'] ?? 0);
            $attempts = (int)($item['attempts'] ?? 0);

            // Not due yet - push back and stop scanning (FIFO).
            if ($nextRetryAt > time()) {
                $this->driver->push($item);
                break;
            }

            $attempts++;
            $item['attempts'] = $attempts;

            try {
                $handler !== null
                    ? $handler($item['update'])
                    : $this->defaultHandle($item['update']);

                LogHandler::info("✅ Retry #{$attempts} succeeded");
                $processed++;

            } catch (\Throwable $e) {

                if ($attempts >= $this->maxAttempts) {
                    LogHandler::error(
                        "⛔ Update dropped after {$attempts} attempts: {$e->getMessage()}"
                    );
                    continue;
                }

                $delay = $this->baseDelay * (2 ** $attempts);
                $item['next_retry_at'] = time() + $delay;
                $item['last_error'] = $e->getMessage();

                $this->driver->push($item);
                $processed++;
            }
        }

        return $processed;
    }

    /**
     * Default handler: re-throw RetryableException for the caller to catch,
     * or silently pass - plugins decide retryability.
     */
    private function defaultHandle(object|array $update): void
    {
        // No-op - the actual dispatch is injected by the caller.
        // Keeping this here so RetryQueue works standalone for testing.
    }

    public function count(): int
    {
        return $this->driver->count();
    }

    public function clear(): int
    {
        return $this->driver->clear();
    }

    public function isConnected(): bool
    {
        return $this->driver->isConnected();
    }
}
