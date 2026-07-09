<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Queue\Drivers;

use Memcached;
use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Queue\QueueInterface;
use alirezax5\TelegramBase\App\Connection\ConnectionManager;

class MemcachedQueue implements QueueInterface
{
    protected ?Memcached $memcached;
    protected string $prefix;
    protected int $jobTtl;

    public function __construct(array $config)
    {
        $this->prefix = $config['key'] ?? 'bot_queue';
        $this->jobTtl = (int)($config['job_ttl'] ?? 86400);
        $this->memcached = ConnectionManager::getInstance()->getMemcached();

        if (!$this->memcached) {
            LogHandler::error("❌ MemcachedQueue: connection missing");
        }
    }

    private function key(string $name): string
    {
        return "{$this->prefix}:{$name}";
    }

    public function push( $update): bool
    {
        if (!$this->isConnected()) return false;

        $keyIndex = $this->key('index');

        // atomic increment fallback-safe
        $id = $this->memcached->increment($keyIndex);

        if ($id === false) {
            if (!$this->memcached->add($keyIndex, 1)) {
                $id = $this->memcached->increment($keyIndex);
            } else {
                $id = 1;
            }
        }

        $payload = json_encode($update, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->memcached->set(
            $this->key("job:$id"),
            $payload,
            $this->jobTtl
        );
    }

    public function pop(int $timeout = 0): ?array
    {
        if (!$this->isConnected()) return null;

        $lockKey = $this->key('lock');

        if (!$this->memcached->add($lockKey, 1, 1)) {
            return null;
        }

        try {
            $cursorKey = $this->key('cursor');

            $cursor = (int)($this->memcached->get($cursorKey) ?: 0);
            $next = $cursor + 1;

            $data = $this->memcached->get($this->key("job:$next"));

            if (!$data) {
                return null;
            }

            $this->memcached->set($cursorKey, $next);

            return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            LogHandler::error("MemcachedQueue pop error: " . $e->getMessage());
            return null;
        } finally {
            $this->memcached->delete($lockKey);
        }
    }

    public function count(): int
    {
        if (!$this->isConnected()) return 0;

        $index = (int)($this->memcached->get($this->key('index')) ?: 0);
        $cursor = (int)($this->memcached->get($this->key('cursor')) ?: 0);

        return max(0, $index - $cursor);
    }

    /**
     * CLEAR - reset cursor and delete all job keys
     */
    public function clear(): int
    {
        if (!$this->isConnected()) return 0;

        $index = (int)($this->memcached->get($this->key('index')) ?: 0);
        $cursor = (int)($this->memcached->get($this->key('cursor')) ?: 0);
        $count = max(0, $index - $cursor);

        for ($i = $cursor + 1; $i <= $index; $i++) {
            $this->memcached->delete($this->key("job:$i"));
        }

        $this->memcached->set($this->key('index'), 0);
        $this->memcached->set($this->key('cursor'), 0);

        return $count;
    }

    public function isConnected(): bool
    {
        return $this->memcached instanceof Memcached
            && $this->memcached->getServerList() !== [];
    }
}