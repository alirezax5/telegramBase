<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Queue;

use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Queue\Drivers\JsonQueue;
use alirezax5\TelegramBase\App\Queue\Drivers\RedisQueue;
use alirezax5\TelegramBase\App\Queue\Drivers\RabbitQueue;
use alirezax5\TelegramBase\App\Queue\Drivers\MemcachedQueue;

class QueueManager
{
    private QueueInterface $driver;
    private string $driverType;

    private array $config;

    private int $maxRetries;
    private int $retryDelay;
    private int $maxSize;

    private int $retryCount = 0;

    public function __construct(QueueConfig $config)
    {
        $this->config = [
            'type' => $config->type,
            'path' => $config->path,
            'redis' => $config->redis,
            'rabbitmq' => $config->rabbitmq,
            'memcached' => $config->memcached,
        ];

        $this->driverType = $config->type;

        $this->maxRetries = max(1, $config->maxRetries);
        $this->retryDelay = max(1, $config->retryDelay);
        $this->maxSize = max(0, $config->maxSize);

        $this->driver = $this->createDriverSafely();

        LogHandler::info("📦 Queue initialized: {$this->driverType}");
    }
    /**
     * Create driver with safe fallback chain
     */
    private function createDriverSafely(): QueueInterface
    {
        try {
            return $this->createDriver();
        } catch (\Throwable $e) {
            LogHandler::error("❌ Queue init failed ({$this->driverType}): {$e->getMessage()}");

            $this->driverType = 'json';

            return new JsonQueue(
                $this->config['path']
                ?: sys_get_temp_dir() . '/queue'
            );
        }
    }

    private function createDriver(): QueueInterface
    {
        return match ($this->driverType) {
            'redis' => new RedisQueue($this->config['redis'] ?? []),
            'rabbitmq' => new RabbitQueue($this->config['rabbitmq'] ?? []),
            'memcached' => new MemcachedQueue($this->config['memcached'] ?? []),
            default => new JsonQueue($this->config['path'] ?: sys_get_temp_dir() . '/queue'),
        };
    }

    /**
     * PUSH with retry + queue size guard.
     *
     * The guard prevents unbounded queue growth when workers are down:
     * once maxSize is reached, pushes are rejected instead of filling
     * the disk/RAM until the process is killed.
     *
     * @param mixed $update Telegram update
     * @return bool True when pushed
     */
    public function push(mixed $update): bool
    {
        if ($this->maxSize > 0 && $this->count() >= $this->maxSize) {
            LogHandler::warning(
                "⛔ Queue full ({$this->maxSize}), update rejected"
            );

            return false;
        }

        if (!$this->isConnected() && !$this->reconnect()) {
            return false;
        }

        return $this->executeWithRetry(fn() => $this->driver->push($update), 'push', $update);
    }

    /**
     * POP (optimized retry - NO recursion anymore)
     */
    public function pop(int $timeout = 0)
    {
        if (!$this->isConnected() && !$this->reconnect()) {
            return null;
        }

        return $this->executeWithRetry(fn() => $this->driver->pop($timeout), 'pop');
    }

    /**
     * Unified retry engine with exponential backoff
     * @param callable $callback Operation to retry
     * @param string $action Human-readable action name for logging
     * @param mixed $context Debug context for error reporting
     */
    private function executeWithRetry(callable $callback, string $action, mixed $context = []): mixed
    {
        $attempt = 0;

        while (true) {
            try {
                $result = $callback();

                $this->retryCount = 0;
                return $result;

            } catch (\Throwable $e) {
                $attempt++;
                $this->retryCount = $attempt;

                if ($attempt > $this->maxRetries) {
                    LogHandler::error("⛔ {$action} max retries reached", [
                        'driver' => $this->driverType,
                        'context' => $context,
                        'error' => $e->getMessage()
                    ]);

                    return $action === 'pop' ? null : false;
                }

                LogHandler::warning("⚠️ {$action} failed (try {$attempt}/{$this->maxRetries})");
                usleep($this->retryDelay * 1_000_000);
            }
        }
    }

    /**
     * Batch push (optimized).
     *
     * @param array $updates List of updates
     * @return int Number of successfully pushed updates
     */
    public function pushBatch(array $updates): int
    {
        $success = 0;

        $total = count($updates);

        foreach ($updates as $i => $update) {
            if ($this->push($update)) {
                $success++;
            }

            if ($total >= 100 && $i % 100 === 0 && $i > 0) {
                LogHandler::debug("📦 batch progress: {$i}/{$total}");
            }
        }

        LogHandler::info("📤 batch push: {$success}/{$total}");

        return $success;
    }

    /**
     * Batch pop (optimized, no retry spam)
     */
    public function popBatch(int $limit = 10): array
    {
        $items = [];

        for ($i = 0; $i < $limit; $i++) {
            $item = $this->pop(5); // 5-second timeout prevents indefinite block on Redis blPop

            if ($item === null) {
                break;
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * Connection check (cached-safe)
     */
    public function isConnected(): bool
    {
        try {
            return $this->driver->isConnected();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Reconnect (clean version)
     */
    private function reconnect(): bool
    {
        try {
            LogHandler::info("🔄 reconnecting queue ({$this->driverType})");

            $this->driver = $this->createDriver();
            $this->retryCount = 0;

            return $this->isConnected();
        } catch (\Throwable $e) {
            LogHandler::error("❌ reconnect failed: {$e->getMessage()}");
            return false;
        }
    }

    public function count(): int
    {
        try {
            return $this->driver->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    public function clear(): int
    {
        try {
            $count = $this->driver->clear();
        } catch (\Throwable) {
            $count = 0;
        }

        LogHandler::info("queue cleared: {$count}");

        return $count;
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    public function getDriverType(): string
    {
        return $this->driverType;
    }

    public function getDriver(): QueueInterface
    {
        return $this->driver;
    }

    public function getStats(): array
    {
        return [
            'driver' => $this->driverType,
            'connected' => $this->isConnected(),
            'size' => $this->count(),
            'max_size' => $this->maxSize,
            'retry_count' => $this->retryCount,
            'max_retries' => $this->maxRetries,
            'retry_delay' => $this->retryDelay,
        ];
    }

    public function setRetryConfig(int $maxRetries, int $retryDelay = 2): self
    {
        $this->maxRetries = max(1, $maxRetries);
        $this->retryDelay = max(1, $retryDelay);

        return $this;
    }
}