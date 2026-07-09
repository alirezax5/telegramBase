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

    private int $retryCount = 0;

    private const DRIVER_MAP = [
        'redis' => RedisQueue::class,
        'rabbitmq' => RabbitQueue::class,
        'memcached' => MemcachedQueue::class,
        'json' => JsonQueue::class,
    ];

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

        $this->maxRetries = $config->maxRetries;
        $this->retryDelay = $config->retryDelay;

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
     * PUSH (optimized retry)
     */
    public function push(mixed $update): bool
    {
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
     * Unified retry engine (IMPORTANT improvement)
     */
    private function executeWithRetry(callable $callback, string $action,  $context = []): mixed
    {
        $attempt = 0;

        while ($attempt <= $this->maxRetries) {
            try {
                $result = $callback();

                $this->retryCount = 0;
                return $result;

            } catch (\Throwable $e) {
                $attempt++;
                $this->retryCount = $attempt;

                LogHandler::warning("⚠️ {$action} failed (try {$attempt}/{$this->maxRetries})");

                if ($attempt > $this->maxRetries) {
                    LogHandler::error("⛔ {$action} max retries reached", [
                        'driver' => $this->driverType,
                        'context' => $context,
                        'error' => $e->getMessage()
                    ]);

                    return $action === 'pop' ? null : false;
                }

                usleep($this->retryDelay * 1_000_000);
            }
        }

        return $action === 'pop' ? null : false;
    }

    /**
     * Batch push (optimized)
     */
    public function pushBatch(array $updates): int
    {
        $success = 0;

        foreach ($updates as $i => $update) {
            if ($this->push($update)) {
                $success++;
            }

            if ($i % 100 === 0 && $i > 0) {
                LogHandler::debug("📦 batch progress: {$i}");
            }
        }

        LogHandler::info("📤 batch push: {$success}/" . count($updates));

        return $success;
    }

    /**
     * Batch pop (optimized, no retry spam)
     */
    public function popBatch(int $limit = 10): array
    {
        $items = [];

        for ($i = 0; $i < $limit; $i++) {
            $item = $this->pop();

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