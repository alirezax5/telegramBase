<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Cache;

use Illuminate\Contracts\Cache\Store;
use alirezax5\TelegramBase\App\Logger\LogHandler;

class CacheRedisStore implements Store
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

        $result = json_decode($value, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            LogHandler::warning("Failed to decode cache key: {$key}");
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

            $decoded = json_decode($val, true);
            $results[$keys[$idx]] = (json_last_error() === JSON_ERROR_NONE) ? $decoded : null;
        }

        return $results;
    }

    public function put($key, $value, $seconds): bool
    {
        return (bool) $this->redis->setex(
            $this->key((string) $key),
            (int) $seconds,
            json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
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
                json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
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
            json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    public function forget($key): bool
    {
        return (bool) $this->redis->del($this->key((string) $key));
    }

    /**
     * Flush all keys with the current prefix (SCAN-based, production-safe).
     * Guarded: if prefix empty, refuses to avoid nuking entire Redis DB.
     */
    public function flush(): bool
    {
        if ($this->prefix === '') {
            LogHandler::warning('CacheRedisStore: flush denied — empty prefix would wipe entire Redis DB');
            return false;
        }

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