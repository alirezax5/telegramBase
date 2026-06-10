<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Cache;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\MemcachedStore;
use Illuminate\Cache\Repository;
use Illuminate\Cache\TaggedCache;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Container\Container;
use Illuminate\Redis\RedisManager;
use Illuminate\Cache\RedisStore;
use alirezax5\TelegramBase\App\Config\Config;
use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Connection\ConnectionManager;

final class CacheManager
{
    private static ?Repository $instance = null;
    private static bool $initialized = false;
    private static string $driver = 'array';
    private static bool $tagsSupported = false;

    // -------------------------
    // INIT
    // -------------------------
    public static function init(): void
    {
        if (self::$initialized && self::$instance) {
            return;
        }

        try {
            $config = Config::Cache();

            $store = match ($config->driver) {
                'memcached' => self::createMemcachedStore($config),
                'redis'     => self::createRedisStore($config),
                'file'      => self::createFileStore($config),
                default     => self::createArrayStore(),
            };

            if (!$store) {
                LogHandler::warning("⚠️ Cache fallback to array (driver: {$config->driver})");
                $store = self::createArrayStore();
                self::$driver = 'array';
            } else {
                self::$driver = $config->driver;
            }

            self::$instance = $store;
            self::$initialized = true;

            self::healthCheck();

            LogHandler::info("✅ Cache initialized: " . self::$driver);
            LogHandler::info("🏷️ Tags support: " . (self::$tagsSupported ? 'yes' : 'no'));

        } catch (\Throwable $e) {
            LogHandler::error("❌ Cache init error: {$e->getMessage()}");

            self::$instance = self::createArrayStore();
            self::$driver = 'array';
            self::$initialized = true;
            self::$tagsSupported = false;
        }
    }

    // -------------------------
    // STORES
    // -------------------------
    private static function createArrayStore(): Repository
    {
        self::$tagsSupported = false;
        return new Repository(new ArrayStore());
    }

    private static function createFileStore(CacheConfig $config): ?Repository
    {
        try {
            if (!is_dir($config->path)) {
                mkdir($config->path, 0755, true);
            }

            self::$tagsSupported = false;

            return new Repository(
                new FileStore(new Filesystem(), $config->path)
            );

        } catch (\Throwable $e) {
            LogHandler::warning("⚠️ File cache failed: {$e->getMessage()}");
            return null;
        }
    }

    private static function createMemcachedStore(CacheConfig $config): ?Repository
    {
        try {
            if (!extension_loaded('memcached')) {
                return null;
            }

            $memcached = ConnectionManager::getInstance()->getMemcached();
            if (!$memcached) {
                return null;
            }

            self::$tagsSupported = true;

            return new Repository(
                new MemcachedStore($memcached, $config->prefix)
            );

        } catch (\Throwable $e) {
            LogHandler::warning("⚠️ Memcached failed: {$e->getMessage()}");
            return null;
        }
    }

    private static function createRedisStore(CacheConfig $config): ?Repository
    {
        try {

            $redisManager = new RedisManager(
                new Container(),
                extension_loaded('redis') ? 'phpredis' : 'predis',
                [
                    'default' => [
                        'host' => $config->host,
                        'port' => $config->port,
                        'database' => $config->database,
                        'password' => $config->password ?: null,
                    ],
                ]
            );

            $redisManager->connection()->ping();

            self::$tagsSupported = true;

            return new Repository(
                new RedisStore(
                    $redisManager,
                    $config->prefix,
                    'default'
                )
            );

        } catch (\Throwable $e) {
            LogHandler::warning("⚠️ Redis cache failed: {$e->getMessage()}");
            return null;
        }
    }
    // -------------------------
    // CORE STORE ACCESS
    // -------------------------
    public static function store(): Repository
    {
        return self::$instance ??= (function () {
            self::init();
            return self::$instance;
        })();
    }

    // -------------------------
    // BASIC API
    // -------------------------
    public static function get(string $key, mixed $default = null): mixed
    {
        return self::store()->get($key, $default);
    }

    public static function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        return self::store()->put($key, $value, $ttl ?? self::ttl());
    }

    public static function remember(string $key, callable $cb, ?int $ttl = null): mixed
    {
        return self::store()->remember($key, $ttl ?? self::ttl(), $cb);
    }

    public static function has(string $key): bool
    {
        $store = self::store();

        var_dump($store->get($key));
        var_dump($store->has($key));

        return $store->has($key);
    }

    public static function forget(string $key): bool
    {
        return self::store()->forget($key);
    }

    public static function flush(): bool
    {
        return self::store()->flush();
    }

    public static function increment(string $key, int $value = 1): int|false
    {
        return self::store()->increment($key, $value);
    }

    public static function decrement(string $key, int $value = 1): int|false
    {
        return self::store()->decrement($key, $value);
    }

    // -------------------------
    // TAGS SUPPORT
    // -------------------------
    public static function tagsSupported(): bool
    {
        return self::$tagsSupported;
    }

    public static function tags($tags): TaggedCache
    {
        if (!self::$tagsSupported) {
            throw new \RuntimeException(
                "Tags not supported on driver: " . self::$driver
            );
        }

        return self::store()->tags($tags);
    }

    public static function putWithTags($tags, string $key, mixed $value, ?int $ttl = null): bool
    {
        if (!self::$tagsSupported) {
            return self::put(self::tagKey($tags, $key), $value, $ttl);
        }

        return self::tags($tags)->put($key, $value, $ttl ?? self::ttl());
    }

    public static function getWithTags($tags, string $key, mixed $default = null): mixed
    {
        if (!self::$tagsSupported) {
            return self::get(self::tagKey($tags, $key), $default);
        }

        return self::tags($tags)->get($key, $default);
    }

    public static function rememberWithTags($tags, string $key, callable $cb, ?int $ttl = null): mixed
    {
        if (!self::$tagsSupported) {
            return self::remember(self::tagKey($tags, $key), $cb, $ttl);
        }

        return self::tags($tags)->remember($key, $ttl ?? self::ttl(), $cb);
    }

    public static function flushTags($tags): bool
    {
        if (!self::$tagsSupported) {
            return false;
        }

        try {
            self::tags($tags)->flush();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function tagKey($tags, string $key): string
    {
        return 'tags:' . (is_array($tags) ? implode(':', $tags) : $tags) . ':' . $key;
    }

    // -------------------------
    // INTERNALS
    // -------------------------
    private static function ttl(): int
    {
        return Config::Cache()->ttl;
    }

    private static function healthCheck(): void
    {
        try {
            self::$instance?->put('__cache_test__', 1, 1);
            self::$instance?->forget('__cache_test__');
        } catch (\Throwable $e) {
            LogHandler::warning("⚠️ Cache health check failed: {$e->getMessage()}");
        }
    }

    public static function isInitialized(): bool
    {
        return self::$initialized && self::$instance !== null;
    }

    public static function getDriver(): string
    {
        return self::$driver;
    }
    public static function isTagsSupported(): bool
    {
        return self::$tagsSupported;
    }
}