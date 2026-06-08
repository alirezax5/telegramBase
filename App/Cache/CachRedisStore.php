<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Cache;

use Illuminate\Contracts\Cache\Store;

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

        return @unserialize($value) ?: null;
    }

    public function many(array $keys): array
    {
        if (empty($keys)) {
            return [];
        }

        $results = [];

        foreach ($keys as $key) {
            $results[$key] = $this->get($key);
        }

        return $results;
    }

    public function put($key, $value, $seconds)
    {
        $this->redis->setex(
            $this->key($key),
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

        foreach ($values as $key => $value) {
            $this->redis->setex(
                $this->key((string)$key),
                $ttl,
                serialize($value)
            );
        }

        return true;
    }

    public function increment($key, $value = 1)
    {
        return $this->redis->incrBy($this->key($key), (int) $value);
    }

    public function decrement($key, $value = 1)
    {
        return $this->redis->decrBy($this->key($key), (int) $value);
    }

    public function forever($key, $value)
    {
        $this->redis->set(
            $this->key($key),
            serialize($value)
        );
    }

    public function forget($key)
    {
        return (bool) $this->redis->del($this->key($key));
    }

    /**
     * ⚠️ مهم: حذف keys() چون در Redis production خطرناک و O(N) هست
     * جایگزین: اسکن سبک‌تر
     */
    public function flush()
    {
        $pattern = $this->prefix . '*';

        $iterator = null;
        $deleted = 0;

        // استفاده از SCAN به جای KEYS (بهینه و production-safe)
        while ($keys = $this->redis->scan($iterator, $pattern, 1000)) {
            if (!empty($keys)) {
                $this->redis->del($keys);
                $deleted += count($keys);
            }
        }

        return true;
    }

    public function getPrefix()
    {
        return $this->prefix;
    }
}