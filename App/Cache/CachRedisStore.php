<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Cache;

use Illuminate\Contracts\Cache\Store;
use alirezax5\TelegramBase\App\Logger\LogHandler;

class CachRedisStore implements Store
{
    private $redis;
    private string $prefix;

    public function __construct($redis, string $prefix)
    {
        $this->redis = $redis;
        $this->prefix = $prefix;
    }

    private function key(string $key): string
    {
        return $this->prefix . $key;
    }

    public function get($key)
    {
        $value = $this->redis->get($this->key($key));

        if ($value === false || $value === null) {
            return null;
        }

        $result = @unserialize($value);

        if ($result === false && $value !== 'b:0;') {
            LogHandler::warning("Failed to unserialize cache key: {$key}");
            return null;
        }

        return $result;
    }

    public function many(array $keys): array
    {
        if (empty($keys)) {
            return [];
        }

        $prefixedKeys = [];
        foreach ($keys as $idx => $key) {
            $prefixedKeys[$idx] = $this->key($key);
        }

        $values = $this->redis->mGet(array_values($prefixedKeys));

        $results = [];
        foreach ($prefixedKeys as $idx => $pkey) {
            $val = $values[$idx] ?? false;
            if ($val === false || $val === null) {
                $results[$keys[$idx]] = null;
                continue;
            }

            $unserialized = @unserialize($val);
            $results[$keys[$idx]] = ($unserialized === false && $val !== 'b:0;') ? null : $unserialized;
        }

        return $results;
    }

    public function put($key, $value, $seconds): bool
    {
        return (bool) $this->redis->setex(
            $this->key((string) $key),
            (int) $seconds,
            serialize($value)
        );
    }

    public function putMany(array $values, $seconds): bool
    {
        if (empty($values)) {
            return true;
        }

        $ttl = (int) $seconds;

        $this->redis->multi();
        foreach ($values as $key => $value) {
            $this->redis->setex(
                $this->key((string) $key),
                $ttl,
                serialize($value)
            );
        }
        $result = $this->redis->exec();

        return !in_array(false, $result, true);
    }

    public function increment($key, $value = 1)
    {
        return $this->redis->incrBy($this->key((string) $key), (int) $value);
    }

    public function decrement($key, $value = 1)
    {
        return $this->redis->decrBy($this->key((string) $key), (int) $value);
    }

    public function forever($key, $value): bool
    {
        return (bool) $this->redis->set(
            $this->key((string) $key),
            serialize($value)
        );
    }

    public function forget($key): bool
    {
        return (bool) $this->redis->del($this->key((string) $key));
    }

    /**
     * Flush all keys with the current prefix (SCAN-based, production-safe)
     */
    public function flush(): bool
    {
        $pattern = $this->prefix . '*';
        $iterator = null;

        while ($keys = $this->redis->scan($iterator, $pattern, 1000)) {
            if (!empty($keys)) {
                $this->redis->unlink($keys);
            }
        }

        return true;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }
}