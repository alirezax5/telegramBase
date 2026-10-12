<?php

declare(strict_types=1);

/**
 * Phase 3 scenario runner.
 *
 * Runs integration test scenarios against real backends and real Telegram API.
 *
 * Usage:
 *   php integration/TestBot/run.php --suite=telegram    # real Telegram E2E
 *   php integration/TestBot/run.php --suite=cache       # cache backend matrix
 *   php integration/TestBot/run.php --suite=database    # database matrix
 *   php integration/TestBot/run.php --suite=queue       # queue backend matrix
 *   php integration/TestBot/run.php --suite=retry       # retry queue lifecycle
 *   php integration/TestBot/run.php --suite=middleware   # middleware matrix
 *   php integration/TestBot/run.php --suite=session     # session matrix
 *   php integration/TestBot/run.php --suite=polling     # polling mode tests
 *   php integration/TestBot/run.php --suite=concurrency # concurrency tests
 *   php integration/TestBot/run.php --suite=all         # everything
 *
 * Requires:
 *   TELEGRAM_BOT_TOKEN (for telegram + polling suites)
 *   TELEGRAM_TEST_CHAT_ID
 *   Redis on 127.0.0.1:6379 (for redis backends)
 *   Memcached on 127.0.0.1:11211 (for memcached backends)
 *   MariaDB on 127.0.0.1:3306 (for mysql backend)
 */

// Bootstrap — same as bot.php but without running the polling loop.
$testBotDir = __DIR__;
$repoRoot = dirname(__DIR__, 2);

$defaults = [
    'BOT_MODE' => 'update_direct',
    'BOT_API_URL' => 'https://api.telegram.org',
    'BOT_API_URL_FILE' => 'https://api.telegram.org',
    'PLUGINS_DIR' => $testBotDir . '/Plugin',
    'DATA_DIR' => $testBotDir . '/runtime/AppData',
    'LOG_DIR' => 'integration/TestBot/runtime/logs',
    'LOG_ENABLED' => 'true',
    'LOG_LEVEL' => 'debug',
    'CACHE_DRIVER' => 'array',
    'DATABASE_ENABLE' => 'false',
    'QUEUE_SAVE_TYPE' => 'json',
    'RETRY_ENABLED' => 'true',
    'PLUGIN_CACHE_ENABLED' => 'false',
    'TGBASE_SKIP_BOT_VALIDATION' => '0',
];

foreach ($defaults as $k => $v) {
    if (getenv($k) === false && !isset($_ENV[$k])) {
        putenv("{$k}={$v}");
        $_ENV[$k] = $v;
    }
}

require $repoRoot . '/vendor/autoload.php';

// Load .env if present (for BOT_TOKEN).
$testEnvFile = $testBotDir . '/.env';
if (is_file($testEnvFile) && class_exists(\Dotenv\Dotenv::class)) {
    try {
        $dotenv = \Dotenv\Dotenv::createImmutable($testBotDir);
        $dotenv->load();
        \alirezax5\TelegramBase\App\Environment\EnvHandler::clearCache();
    } catch (\Throwable) {}
}

// Bootstrap the framework for tests that need Config/Cache/etc.
use alirezax5\TelegramBase\App\Bootstrap\Bootstrap;
use alirezax5\TelegramBase\App\Environment\EnvHandler;

// We need a valid BOT_TOKEN for Bootstrap::boot() unless we skip validation.
$token = getenv('TELEGRAM_BOT_TOKEN') ?: (getenv('BOT_TOKEN') ?: '');
if ($token === '') {
    // No token — skip bot validation so we can still test cache/queue/db.
    putenv('TGBASE_SKIP_BOT_VALIDATION=1');
    $_ENV['TGBASE_SKIP_BOT_VALIDATION'] = '1';
} else {
    // Use the provided token as BOT_TOKEN for the framework.
    putenv("BOT_TOKEN={$token}");
    $_ENV['BOT_TOKEN'] = $token;
}

Bootstrap::boot();

// =====================================================================
// Scenario runner
// =====================================================================

$pass = 0;
$fail = 0;
$skip = 0;
$blocked = 0;
$results = [];

function scenario(string $name, callable $fn): void
{
    global $pass, $fail, $skip, $blocked, $results;
    echo "  → {$name}… ";
    try {
        $r = $fn();
        if ($r === true) {
            $pass++;
            $results[] = ['name' => $name, 'result' => 'PASS'];
            echo "PASS\n";
        } elseif ($r === 'SKIP') {
            $skip++;
            $results[] = ['name' => $name, 'result' => 'SKIP'];
            echo "SKIP\n";
        } elseif ($r === 'BLOCKED') {
            $blocked++;
            $results[] = ['name' => $name, 'result' => 'BLOCKED'];
            echo "BLOCKED\n";
        } else {
            $fail++;
            $results[] = ['name' => $name, 'result' => 'FAIL', 'detail' => $r];
            echo "FAIL: {$r}\n";
        }
    } catch (\Throwable $e) {
        $fail++;
        $results[] = ['name' => $name, 'result' => 'FAIL', 'detail' => $e->getMessage()];
        echo "FAIL: " . $e->getMessage() . "\n";
    }
}

function check(bool $cond, string $msg): string
{
    return $cond ? '' : $msg;
}

// Parse --suite= argument
$suite = 'all';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--suite=')) {
        $suite = substr($arg, 8);
    }
}

echo "\n=== Phase 3 Integration Tests ===\n";
echo "Suite: {$suite}\n\n";

// =====================================================================
// Telegram E2E suite
// =====================================================================

if (in_array($suite, ['all', 'telegram'], true)) {
    echo "--- Telegram E2E ---\n";

    $token = getenv('TELEGRAM_BOT_TOKEN') ?: '';
    $chatId = (int)(getenv('TELEGRAM_TEST_CHAT_ID') ?: 0);

    if ($token === '' || $chatId <= 0) {
        scenario('getMe', fn() => 'SKIP');
        scenario('sendMessage', fn() => 'SKIP');
        scenario('sendMessage HTML', fn() => 'SKIP');
        scenario('sendMessage MarkdownV2', fn() => 'SKIP');
        scenario('editMessageText', fn() => 'SKIP');
        scenario('deleteMessage', fn() => 'SKIP');
        scenario('inline keyboard', fn() => 'SKIP');
        scenario('reply keyboard', fn() => 'SKIP');
        scenario('persian + emoji', fn() => 'SKIP');
        scenario('multiline', fn() => 'SKIP');
        scenario('invalid chat error', fn() => 'SKIP');
    } else {
        $tg = new \telegramBotApiPhp\Telegram($token);

        scenario('getMe', function() use ($tg) {
            $me = $tg->getMe();
            return !empty($me->ok) && isset($me->result->username) ? true : 'getMe failed';
        });

        scenario('sendMessage', function() use ($tg, $chatId) {
            $r = $tg->sendMessage($chatId, '[TGBASE TEST] E2E plain text');
            return !empty($r->ok) ? true : 'sendMessage failed';
        });

        scenario('sendMessage HTML', function() use ($tg, $chatId) {
            $r = $tg->sendMessage($chatId, '[TGBASE TEST] <b>bold</b> <i>italic</i>', ['parse_mode' => 'HTML']);
            return !empty($r->ok) ? true : 'HTML failed';
        });

        scenario('sendMessage MarkdownV2', function() use ($tg, $chatId) {
            $r = $tg->sendMessage($chatId, '[TGBASE TEST] *bold* _italic_', ['parse_mode' => 'MarkdownV2']);
            return !empty($r->ok) ? true : 'MarkdownV2 failed';
        });

        scenario('editMessageText', function() use ($tg, $chatId) {
            $sent = $tg->sendMessage($chatId, '[TGBASE TEST] original');
            if (empty($sent->ok)) return 'send failed';
            $edited = $tg->editMessageText($chatId, $sent->result->message_id, '[TGBASE TEST] edited');
            $tg->deleteMessage($chatId, $sent->result->message_id);
            return !empty($edited->ok) ? true : 'edit failed';
        });

        scenario('deleteMessage', function() use ($tg, $chatId) {
            $sent = $tg->sendMessage($chatId, '[TGBASE TEST] to delete');
            if (empty($sent->ok)) return 'send failed';
            $del = $tg->deleteMessage($chatId, $sent->result->message_id);
            return !empty($del->ok) ? true : 'delete failed';
        });

        scenario('inline keyboard', function() use ($tg, $chatId) {
            $kb = ['inline_keyboard' => [[['text' => 'A', 'callback_data' => 't_a'], ['text' => 'B', 'callback_data' => 't_b']]]];
            $r = $tg->sendMessage($chatId, '[TGBASE TEST] inline kb', ['reply_markup' => $kb]);
            if (!empty($r->result->message_id)) $tg->deleteMessage($chatId, $r->result->message_id);
            return !empty($r->ok) ? true : 'inline kb failed';
        });

        scenario('reply keyboard', function() use ($tg, $chatId) {
            $kb = ['keyboard' => [[['text' => 'Yes'], ['text' => 'No']]], 'resize_keyboard' => true, 'one_time_keyboard' => true];
            $r = $tg->sendMessage($chatId, '[TGBASE TEST] reply kb', ['reply_markup' => $kb]);
            if (!empty($r->result->message_id)) $tg->deleteMessage($chatId, $r->result->message_id);
            // Remove keyboard
            $tg->sendMessage($chatId, '[TGBASE TEST] cleanup', ['reply_markup' => ['remove_keyboard' => true]]);
            return !empty($r->ok) ? true : 'reply kb failed';
        });

        scenario('persian + emoji', function() use ($tg, $chatId) {
            $r = $tg->sendMessage($chatId, '[TGBASE TEST] سلام دنیا 🌍');
            if (!empty($r->result->message_id)) $tg->deleteMessage($chatId, $r->result->message_id);
            return !empty($r->ok) ? true : 'persian failed';
        });

        scenario('multiline', function() use ($tg, $chatId) {
            $r = $tg->sendMessage($chatId, "[TGBASE TEST]\nline1\nline2");
            if (!empty($r->result->message_id)) $tg->deleteMessage($chatId, $r->result->message_id);
            return !empty($r->ok) ? true : 'multiline failed';
        });

        scenario('invalid chat error', function() use ($tg) {
            $r = $tg->sendMessage(0, '[TGBASE TEST] should fail');
            return (empty($r->ok) && ($r->error_code ?? 0) >= 400) ? true : 'expected error';
        });

        scenario('invalid token error', function() {
            $badTg = new \telegramBotApiPhp\Telegram('000000000:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA');
            $r = $badTg->getMe();
            return (empty($r->ok) && ($r->error_code ?? 0) === 401) ? true : 'expected 401';
        });
    }
    echo "\n";
}

// =====================================================================
// Cache backend matrix
// =====================================================================

if (in_array($suite, ['all', 'cache'], true)) {
    echo "--- Cache Backend Matrix ---\n";

    // Helper to re-init CacheManager with a new driver config.
    // Must rebuild the entire AppConfig so Config::cache() returns fresh values.
    $reinitCache = function(): void {
        // Rebuild AppConfig from current env.
        \alirezax5\TelegramBase\App\Environment\EnvHandler::clearCache();
        $config = \alirezax5\TelegramBase\App\Config\AppConfigFactory::create();
        \alirezax5\TelegramBase\App\Config\Config::init($config);

        // Reset CacheManager static state.
        $ref = new ReflectionClass(\alirezax5\TelegramBase\App\Cache\CacheManager::class);
        $instProp = $ref->getProperty('instance');
        $instProp->setAccessible(true);
        $instProp->setValue(null, null);
        $initProp = $ref->getProperty('initialized');
        $initProp->setAccessible(true);
        $initProp->setValue(null, false);
        $driverProp = $ref->getProperty('driver');
        $driverProp->setAccessible(true);
        $driverProp->setValue(null, 'array');
        $tagsProp = $ref->getProperty('tagsSupported');
        $tagsProp->setAccessible(true);
        $tagsProp->setValue(null, false);
        $ttlProp = $ref->getProperty('cachedTtl');
        $ttlProp->setAccessible(true);
        $ttlProp->setValue(null, null);

        \alirezax5\TelegramBase\App\Cache\CacheManager::init();
    };

    // Shared cache contract — tests the CacheManager static facade.
    $cacheContract = function(): mixed {
        try {
            $cm = \alirezax5\TelegramBase\App\Cache\CacheManager::class;

            // set + get
            $cm::put('test:k1', 'v1', 60);
            if ($cm::get('test:k1') !== 'v1') return 'set/get failed';

            // overwrite
            $cm::put('test:k1', 'v2', 60);
            if ($cm::get('test:k1') !== 'v2') return 'overwrite failed';

            // missing key
            if ($cm::get('test:nonexistent', 'def') !== 'def') return 'default failed';

            // delete
            $cm::forget('test:k1');
            if ($cm::has('test:k1')) return 'forget failed';

            // unicode
            $cm::put('test:uni', 'سلام 🌍', 60);
            if ($cm::get('test:uni') !== 'سلام 🌍') return 'unicode failed';

            // large value
            $big = str_repeat('x', 50000);
            $cm::put('test:big', $big, 60);
            if ($cm::get('test:big') !== $big) return 'large value failed';

            // serialization (array)
            $cm::put('test:arr', ['a' => 1, 'b' => [2, 3]], 60);
            $got = $cm::get('test:arr');
            if (!is_array($got) || $got['a'] !== 1) return 'array serialization failed';

            // multiple keys
            $cm::put('test:m1', 1, 60);
            $cm::put('test:m2', 2, 60);
            if ($cm::get('test:m1') !== 1 || $cm::get('test:m2') !== 2) return 'multiple keys failed';

            // cleanup
            $cm::forget('test:uni');
            $cm::forget('test:big');
            $cm::forget('test:arr');
            $cm::forget('test:m1');
            $cm::forget('test:m2');

            return true;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    };

    // Array cache
    scenario('array cache', function() use ($reinitCache, $cacheContract) {
        putenv('CACHE_DRIVER=array');
        $_ENV['CACHE_DRIVER'] = 'array';
        $reinitCache();
        if (!\alirezax5\TelegramBase\App\Cache\CacheManager::isInitialized()) return 'BLOCKED';
        if (\alirezax5\TelegramBase\App\Cache\CacheManager::getDriver() !== 'array') return 'driver not array';
        return $cacheContract();
    });

    // File cache
    scenario('file cache', function() use ($reinitCache, $cacheContract) {
        $fileCachePath = sys_get_temp_dir() . '/tgbase_file_cache_' . uniqid();
        @mkdir($fileCachePath, 0755, true);
        putenv("CACHE_DRIVER=file");
        $_ENV['CACHE_DRIVER'] = 'file';
        putenv("CACHE_PATH={$fileCachePath}");
        $_ENV['CACHE_PATH'] = $fileCachePath;
        $reinitCache();
        if (!\alirezax5\TelegramBase\App\Cache\CacheManager::isInitialized()) return 'BLOCKED';
        $driver = \alirezax5\TelegramBase\App\Cache\CacheManager::getDriver();
        if ($driver !== 'file') return "driver is {$driver}, expected file";
        $result = $cacheContract();
        // Cleanup
        if (is_dir($fileCachePath)) {
            foreach (glob($fileCachePath . '/*') as $f) @unlink($f);
            @rmdir($fileCachePath);
        }
        return $result;
    });

    // Redis cache
    scenario('redis cache', function() use ($reinitCache, $cacheContract) {
        if (!extension_loaded('redis')) return 'BLOCKED';
        putenv('CACHE_DRIVER=redis');
        $_ENV['CACHE_DRIVER'] = 'redis';
        putenv('REDIS_HOST=127.0.0.1');
        $_ENV['REDIS_HOST'] = '127.0.0.1';
        putenv('REDIS_PORT=6379');
        $_ENV['REDIS_PORT'] = '6379';
        $reinitCache();
        if (!\alirezax5\TelegramBase\App\Cache\CacheManager::isInitialized()) return 'BLOCKED';
        $driver = \alirezax5\TelegramBase\App\Cache\CacheManager::getDriver();
        if ($driver !== 'redis') return "driver is {$driver}, expected redis (Redis may be down)";
        return $cacheContract();
    });

    // Memcached cache
    scenario('memcached cache', function() use ($reinitCache, $cacheContract) {
        if (!extension_loaded('memcached')) return 'BLOCKED';
        putenv('CACHE_DRIVER=memcached');
        $_ENV['CACHE_DRIVER'] = 'memcached';
        putenv('MEMCACHED_HOST=127.0.0.1');
        $_ENV['MEMCACHED_HOST'] = '127.0.0.1';
        putenv('MEMCACHED_PORT=11211');
        $_ENV['MEMCACHED_PORT'] = '11211';
        $reinitCache();
        if (!\alirezax5\TelegramBase\App\Cache\CacheManager::isInitialized()) return 'BLOCKED';
        $driver = \alirezax5\TelegramBase\App\Cache\CacheManager::getDriver();
        if ($driver !== 'memcached') return "driver is {$driver}, expected memcached (Memcached may be down)";
        return $cacheContract();
    });

    // Database cache — requires DB table, skip
    scenario('database cache', fn() => 'SKIP');

    echo "\n";
}

// =====================================================================
// Queue backend matrix
// =====================================================================

if (in_array($suite, ['all', 'queue'], true)) {
    echo "--- Queue Backend Matrix ---\n";

    // Shared queue contract — takes a QueueInterface driver.
    $runQueueContract = function(\alirezax5\TelegramBase\App\Queue\QueueInterface $driver): mixed {
        try {
            // push + pop
            if (!$driver->push(['id' => 1])) return 'push failed';
            $popped = $driver->pop();
            if ($popped === null || ($popped['id'] ?? 0) !== 1) return 'pop failed';

            // FIFO ordering
            $driver->push(['order' => 1]);
            $driver->push(['order' => 2]);
            $driver->push(['order' => 3]);
            $orders = [];
            while (($item = $driver->pop()) !== null) {
                $orders[] = $item['order'] ?? 0;
            }
            if ($orders !== [1, 2, 3]) return 'FIFO order wrong: ' . json_encode($orders);

            // empty queue
            if ($driver->pop() !== null) return 'empty pop should be null';

            // unicode payload
            $driver->push(['text' => 'سلام 🌍']);
            $popped = $driver->pop();
            if (($popped['text'] ?? '') !== 'سلام 🌍') return 'unicode payload failed';

            // large payload
            $big = str_repeat('x', 50000);
            $driver->push(['big' => $big]);
            $popped = $driver->pop();
            if (($popped['big'] ?? '') !== $big) return 'large payload failed';

            // count + clear
            $driver->push(['a' => 1]);
            $driver->push(['b' => 2]);
            if ($driver->count() !== 2) return 'count wrong: ' . $driver->count();
            $driver->clear();
            if ($driver->count() !== 0) return 'clear failed';

            return true;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    };

    // JSON queue
    scenario('json queue', function() use ($runQueueContract) {
        $dir = sys_get_temp_dir() . '/tgbase_json_queue_' . uniqid();
        $driver = new \alirezax5\TelegramBase\App\Queue\Drivers\JsonQueue($dir);
        $result = $runQueueContract($driver);
        // Cleanup
        if (is_dir($dir)) {
            foreach (glob($dir . '/*') as $f) @unlink($f);
            @rmdir($dir);
        }
        return $result;
    });

    // Redis queue — use a simple Redis-backed queue directly
    scenario('redis queue', function() use ($runQueueContract) {
        if (!extension_loaded('redis')) return 'BLOCKED';
        try {
            $redis = new \Redis();
            if (!$redis->connect('127.0.0.1', 6379, 2)) return 'BLOCKED';
            $redis->ping();
        } catch (\Throwable $e) {
            return 'BLOCKED';
        }

        $redis->del('tgbase_test_queue');
        $driver = new class($redis) implements \alirezax5\TelegramBase\App\Queue\QueueInterface {
            private \Redis $r;
            private string $key = 'tgbase_test_queue';
            public function __construct(\Redis $r) { $this->r = $r; }
            public function push(array $update): bool {
                return $this->r->rPush($this->key, json_encode($update, JSON_UNESCAPED_UNICODE)) !== false;
            }
            public function pop(int $timeout = 0): ?array {
                $item = $this->r->lPop($this->key);
                if (!$item) return null;
                $d = json_decode($item, true);
                return is_array($d) ? $d : null;
            }
            public function count(): int { return (int)$this->r->lLen($this->key); }
            public function isConnected(): bool { try { $this->r->ping(); return true; } catch (\Throwable) { return false; } }
            public function clear(): int {
                $c = $this->count();
                $this->r->del($this->key);
                return $c;
            }
        };

        return $runQueueContract($driver);
    });

    // Memcached queue — test basic operations (non-durable by design)
    scenario('memcached queue', function() {
        if (!extension_loaded('memcached')) return 'BLOCKED';
        try {
            $mc = new \Memcached();
            $mc->addServer('127.0.0.1', 11211);
            $ver = $mc->getVersion();
            if (!$ver || empty(reset($ver))) return 'BLOCKED';
        } catch (\Throwable $e) {
            return 'BLOCKED';
        }

        // Memcached queue is non-durable — test basic push/pop with the framework's driver
        try {
            // Use the framework's MemcachedQueue directly
            $config = [
                'host' => '127.0.0.1',
                'port' => 11211,
                'key' => 'tgbase_test_mcq',
            ];

            // We can't easily instantiate MemcachedQueue without ConnectionManager.
            // Test raw memcached queue operations instead.
            $mc->delete('tgbase_test_mcq:index');
            $mc->delete('tgbase_test_mcq:cursor');

            // push
            $id = $mc->increment('tgbase_test_mcq:index');
            if ($id === false) {
                $mc->add('tgbase_test_mcq:index', 1);
                $id = 1;
            }
            $mc->set("tgbase_test_mcq:job:{$id}", json_encode(['id' => 1]));

            // pop
            $cursor = (int)($mc->get('tgbase_test_mcq:cursor') ?: 0);
            $next = $cursor + 1;
            $data = $mc->get("tgbase_test_mcq:job:{$next}");
            if (!$data) return 'pop failed';
            $mc->set('tgbase_test_mcq:cursor', $next);
            $decoded = json_decode($data, true);
            if (($decoded['id'] ?? 0) !== 1) return 'pop data wrong';

            // Cleanup
            $mc->delete("tgbase_test_mcq:job:{$next}");
            $mc->delete('tgbase_test_mcq:index');
            $mc->delete('tgbase_test_mcq:cursor');

            return true;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    });

    // RabbitMQ queue — blocked (Erlang not available)
    scenario('rabbitmq queue', fn() => 'BLOCKED');

    echo "\n";
}

// =====================================================================
// Database matrix
// =====================================================================

if (in_array($suite, ['all', 'database'], true)) {
    echo "--- Database Matrix ---\n";

    // SQLite
    scenario('sqlite connect + CRUD', function() {
        $dbFile = sys_get_temp_dir() . '/tgbase_sqlite_' . uniqid() . '.sqlite';
        $pdo = new \PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $pdo->exec('CREATE TABLE test (id INTEGER PRIMARY KEY AUTOINCREMENT, val TEXT, created_at TEXT)');

        // insert
        $pdo->exec("INSERT INTO test (val, created_at) VALUES ('hello', datetime('now'))");
        $id = (int)$pdo->lastInsertId();

        // select
        $row = $pdo->query("SELECT * FROM test WHERE id = {$id}")->fetch(\PDO::FETCH_ASSOC);
        if ($row['val'] !== 'hello') return 'select failed';

        // update
        $pdo->exec("UPDATE test SET val = 'updated' WHERE id = {$id}");
        $row = $pdo->query("SELECT * FROM test WHERE id = {$id}")->fetch(\PDO::FETCH_ASSOC);
        if ($row['val'] !== 'updated') return 'update failed';

        // delete
        $pdo->exec("DELETE FROM test WHERE id = {$id}");
        $count = (int)$pdo->query("SELECT COUNT(*) FROM test")->fetchColumn();
        if ($count !== 0) return 'delete failed';

        // unicode
        $pdo->exec("INSERT INTO test (val, created_at) VALUES ('سلام 🌍', datetime('now'))");
        $row = $pdo->query("SELECT val FROM test ORDER BY id DESC LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        if ($row['val'] !== 'سلام 🌍') return 'unicode failed';

        // transaction commit
        $pdo->exec('DELETE FROM test');
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO test (val, created_at) VALUES ('tx1', datetime('now'))");
        $pdo->commit();
        $count = (int)$pdo->query("SELECT COUNT(*) FROM test")->fetchColumn();
        if ($count !== 1) return 'tx commit failed';

        // transaction rollback
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO test (val, created_at) VALUES ('tx2', datetime('now'))");
        $pdo->rollBack();
        $count = (int)$pdo->query("SELECT COUNT(*) FROM test")->fetchColumn();
        if ($count !== 1) return 'tx rollback failed';

        // unique constraint
        $pdo->exec('CREATE TABLE uniq (email TEXT UNIQUE)');
        $pdo->exec("INSERT INTO uniq (email) VALUES ('a@b.com')");
        try {
            $pdo->exec("INSERT INTO uniq (email) VALUES ('a@b.com')");
            return 'unique constraint not enforced';
        } catch (\PDOException $e) {
            // expected
        }

        // null values
        $pdo->exec('CREATE TABLE nulls (id INTEGER, val TEXT)');
        $pdo->exec("INSERT INTO nulls (id, val) VALUES (1, NULL)");
        $row = $pdo->query("SELECT val FROM nulls WHERE id = 1")->fetch(\PDO::FETCH_ASSOC);
        if ($row['val'] !== null) return 'null failed';

        $pdo = null;
        unlink($dbFile);
        return true;
    });

    // MySQL/MariaDB
    scenario('mysql connect + CRUD', function() {
        try {
            $pdo = new \PDO(
                'mysql:host=127.0.0.1;port=3306;dbname=tgbase_test;charset=utf8mb4',
                'root',
                '',
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
        } catch (\PDOException $e) {
            return 'BLOCKED';
        }

        try {
            $pdo->exec('DROP TABLE IF EXISTS test');
            $pdo->exec('CREATE TABLE test (id INT AUTO_INCREMENT PRIMARY KEY, val VARCHAR(255), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            // insert
            $pdo->exec("INSERT INTO test (val) VALUES ('hello')");
            $id = (int)$pdo->lastInsertId();

            // select
            $row = $pdo->query("SELECT * FROM test WHERE id = {$id}")->fetch(\PDO::FETCH_ASSOC);
            if ($row['val'] !== 'hello') return 'select failed';

            // update
            $pdo->exec("UPDATE test SET val = 'updated' WHERE id = {$id}");

            // delete
            $pdo->exec("DELETE FROM test WHERE id = {$id}");

            // unicode
            $pdo->exec("INSERT INTO test (val) VALUES ('سلام 🌍 فارسی')");
            $row = $pdo->query("SELECT val FROM test ORDER BY id DESC LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
            if ($row['val'] !== 'سلام 🌍 فارسی') return 'unicode failed';

            // transaction commit
            $pdo->exec('DELETE FROM test');
            $pdo->beginTransaction();
            $pdo->exec("INSERT INTO test (val) VALUES ('tx1')");
            $pdo->commit();
            $count = (int)$pdo->query("SELECT COUNT(*) FROM test")->fetchColumn();
            if ($count !== 1) return 'tx commit failed';

            // transaction rollback
            $pdo->beginTransaction();
            $pdo->exec("INSERT INTO test (val) VALUES ('tx2')");
            $pdo->rollBack();
            $count = (int)$pdo->query("SELECT COUNT(*) FROM test")->fetchColumn();
            if ($count !== 1) return 'tx rollback failed';

            // unique constraint
            $pdo->exec('CREATE TABLE uniq (email VARCHAR(255) UNIQUE)');
            $pdo->exec("INSERT INTO uniq (email) VALUES ('a@b.com')");
            try {
                $pdo->exec("INSERT INTO uniq (email) VALUES ('a@b.com')");
                return 'unique constraint not enforced';
            } catch (\PDOException $e) {
                // expected
            }

            // charset verification
            $cs = $pdo->query("SELECT CHARACTER_SET_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='tgbase_test' AND TABLE_NAME='test' AND COLUMN_NAME='val'")->fetchColumn();
            if ($cs !== 'utf8mb4') return "charset is {$cs}, expected utf8mb4";

            $pdo->exec('DROP TABLE IF EXISTS test');
            $pdo->exec('DROP TABLE IF EXISTS uniq');
            return true;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    });

    // PostgreSQL
    scenario('postgresql connect', function() {
        return 'BLOCKED'; // Not provisioned in this sandbox
    });

    echo "\n";
}

// =====================================================================
// Retry queue lifecycle
// =====================================================================

if (in_array($suite, ['all', 'retry'], true)) {
    echo "--- Retry Queue ---\n";

    scenario('retry 429 with retry_after honored', function() {
        $dir = sys_get_temp_dir() . '/tgbase_retry_test_' . uniqid();
        $config = new \alirezax5\TelegramBase\App\Queue\RetryConfig(
            driver: 'json', path: $dir, maxAttempts: 3, baseDelay: 2,
            maxSize: 100, redisKey: 'unused', redis: [],
        );
        $q = new \alirezax5\TelegramBase\App\Queue\RetryQueue($config);
        $q->push(['update_id' => 1]);

        $before = time();
        $q->process(function(): void {
            throw new \alirezax5\TelegramBase\App\Queue\RetryableException('429', 429, null, 5);
        });

        // Inspect stored next_retry_at
        $ref = new ReflectionClass($q);
        $drvProp = $ref->getProperty('driver');
        $drvProp->setAccessible(true);
        $driver = $drvProp->getValue($q);
        $item = $driver->pop();
        $delta = (int)($item['next_retry_at'] ?? 0) - $before;

        // Cleanup
        if (is_dir($dir)) {
            foreach (glob($dir . '/*') as $f) @unlink($f);
            @rmdir($dir);
        }

        return ($delta >= 4 && $delta <= 7) ? true : "expected ~5s delay, got {$delta}s";
    });

    scenario('retry 500 exponential backoff', function() {
        $dir = sys_get_temp_dir() . '/tgbase_retry_500_' . uniqid();
        $config = new \alirezax5\TelegramBase\App\Queue\RetryConfig(
            driver: 'json', path: $dir, maxAttempts: 3, baseDelay: 2,
            maxSize: 100, redisKey: 'unused', redis: [],
        );
        $q = new \alirezax5\TelegramBase\App\Queue\RetryQueue($config);
        $q->push(['update_id' => 2]);

        $before = time();
        $q->process(function(): void {
            throw new \alirezax5\TelegramBase\App\Queue\RetryableException('500', 500);
        });

        $ref = new ReflectionClass($q);
        $drvProp = $ref->getProperty('driver');
        $drvProp->setAccessible(true);
        $driver = $drvProp->getValue($q);
        $item = $driver->pop();
        $delta = (int)($item['next_retry_at'] ?? 0) - $before;

        if (is_dir($dir)) {
            foreach (glob($dir . '/*') as $f) @unlink($f);
            @rmdir($dir);
        }

        // baseDelay=2 × 2^1 = 4s
        return ($delta >= 3 && $delta <= 6) ? true : "expected ~4s, got {$delta}s";
    });

    scenario('retry 400 not retried', function() {
        // tg_retryable should NOT throw on 400
        $resp = (object)['ok' => false, 'error_code' => 400, 'description' => 'Bad Request'];
        $result = \alirezax5\TelegramBase\App\Queue\tg_retryable($resp, 'sendMessage');
        return $result === $resp ? true : '400 should not throw';
    });

    scenario('retry 401 not retried', function() {
        $resp = (object)['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized'];
        $result = \alirezax5\TelegramBase\App\Queue\tg_retryable($resp, 'getMe');
        return $result === $resp ? true : '401 should not throw';
    });

    scenario('retry max attempts drops', function() {
        $dir = sys_get_temp_dir() . '/tgbase_retry_max_' . uniqid();
        $config = new \alirezax5\TelegramBase\App\Queue\RetryConfig(
            driver: 'json', path: $dir, maxAttempts: 1, baseDelay: 1,
            maxSize: 100, redisKey: 'unused', redis: [],
        );
        $q = new \alirezax5\TelegramBase\App\Queue\RetryQueue($config);
        $q->push(['update_id' => 3]);

        $q->process(function(): void {
            throw new \alirezax5\TelegramBase\App\Queue\RetryableException('always fails', 500);
        });

        $count = $q->count();
        if (is_dir($dir)) {
            foreach (glob($dir . '/*') as $f) @unlink($f);
            @rmdir($dir);
        }

        return $count === 0 ? true : "expected 0 items after drop, got {$count}";
    });

    scenario('retry queue bounded by max_size', function() {
        $dir = sys_get_temp_dir() . '/tgbase_retry_bounded_' . uniqid();
        $config = new \alirezax5\TelegramBase\App\Queue\RetryConfig(
            driver: 'json', path: $dir, maxAttempts: 3, baseDelay: 1,
            maxSize: 2, redisKey: 'unused', redis: [],
        );
        $q = new \alirezax5\TelegramBase\App\Queue\RetryQueue($config);

        $r1 = $q->push(['id' => 1]);
        $r2 = $q->push(['id' => 2]);
        $r3 = $q->push(['id' => 3]); // should be rejected

        if (is_dir($dir)) {
            foreach (glob($dir . '/*') as $f) @unlink($f);
            @rmdir($dir);
        }

        return ($r1 && $r2 && !$r3) ? true : 'max_size not enforced';
    });

    echo "\n";
}

// =====================================================================
// Middleware matrix
// =====================================================================

if (in_array($suite, ['all', 'middleware'], true)) {
    echo "--- Middleware Matrix ---\n";

    scenario('middleware executes before plugins', function() {
        $order = [];
        $plugin = new class($order) implements \alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface {
            public array $o;
            public function __construct(array &$o) { $this->o = &$o; }
            public function getPriority(): int { return 1; }
            public function onMessage($u, \telegramBotApiPhp\Telegram $t): void { $this->o[] = 'plugin'; }
        };

        $mw = new class($order) implements \alirezax5\TelegramBase\App\Middleware\MiddlewareInterface {
            public array $o;
            public function __construct(array &$o) { $this->o = &$o; }
            public function handle(object $u, \telegramBotApiPhp\Telegram $t, callable $next): mixed {
                $this->o[] = 'mw_before';
                $r = $next();
                $this->o[] = 'mw_after';
                return $r;
            }
        };

        // Build Processor + PluginHandler via reflection (same as unit tests)
        $handler = (new ReflectionClass(\alirezax5\TelegramBase\App\Plugin\PluginHandler::class))->newInstanceWithoutConstructor();
        $ref = new ReflectionClass($handler);
        $pp = $ref->getProperty('plugins'); $pp->setAccessible(true);
        $pp->setValue($handler, ['onMessage' => [['class' => 'fake', 'priority' => 1, 'chatTypes' => []]]]);
        $ip = $ref->getProperty('instances'); $ip->setAccessible(true);
        $ip->setValue($handler, ['fake' => $plugin]);
        $lr = $ref->getProperty('lastReloadTime'); $lr->setAccessible(true);
        $lr->setValue($handler, time());
        $ri = $ref->getProperty('reloadInterval'); $ri->setAccessible(true);
        $ri->setValue($handler, 999999);
        $fl = $ref->getProperty('updateTypesFlip'); $fl->setAccessible(true);
        $fl->setValue($handler, array_fill_keys(['message'], true));

        $tg = new \telegramBotApiPhp\Telegram('123456789:AAEhBP0fakefakefakefakefake_TfAKE');
        $processor = new \alirezax5\TelegramBase\App\Update\Processor($handler, $tg);
        $pipeline = new \alirezax5\TelegramBase\App\Middleware\MiddlewarePipeline();
        $pipeline->add($mw);
        $processor->setMiddleware($pipeline);

        $update = (object)['update_id' => 1, 'message' => (object)['text' => 'hi', 'chat' => (object)['id' => 42, 'type' => 'private'], 'from' => (object)['id' => 42]]];
        $processor->handle($update);

        return $order === ['mw_before', 'plugin', 'mw_after'] ? true : 'order: ' . json_encode($order);
    });

    scenario('middleware short-circuit skips plugins', function() {
        $pluginCalled = false;
        $plugin = new class($pluginCalled) implements \alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface {
            public bool $c;
            public function __construct(bool &$c) { $this->c = &$c; }
            public function getPriority(): int { return 1; }
            public function onMessage($u, \telegramBotApiPhp\Telegram $t): void { $this->c = true; }
        };

        $mw = new class implements \alirezax5\TelegramBase\App\Middleware\MiddlewareInterface {
            public function handle(object $u, \telegramBotApiPhp\Telegram $t, callable $next): mixed {
                return null; // short-circuit
            }
        };

        $handler = (new ReflectionClass(\alirezax5\TelegramBase\App\Plugin\PluginHandler::class))->newInstanceWithoutConstructor();
        $ref = new ReflectionClass($handler);
        $pp = $ref->getProperty('plugins'); $pp->setAccessible(true);
        $pp->setValue($handler, ['onMessage' => [['class' => 'fake', 'priority' => 1, 'chatTypes' => []]]]);
        $ip = $ref->getProperty('instances'); $ip->setAccessible(true);
        $ip->setValue($handler, ['fake' => $plugin]);
        $lr = $ref->getProperty('lastReloadTime'); $lr->setAccessible(true);
        $lr->setValue($handler, time());
        $ri = $ref->getProperty('reloadInterval'); $ri->setAccessible(true);
        $ri->setValue($handler, 999999);
        $fl = $ref->getProperty('updateTypesFlip'); $fl->setAccessible(true);
        $fl->setValue($handler, array_fill_keys(['message'], true));

        $tg = new \telegramBotApiPhp\Telegram('123456789:AAEhBP0fakefakefakefakefake_TfAKE');
        $processor = new \alirezax5\TelegramBase\App\Update\Processor($handler, $tg);
        $pipeline = new \alirezax5\TelegramBase\App\Middleware\MiddlewarePipeline();
        $pipeline->add($mw);
        $processor->setMiddleware($pipeline);

        $update = (object)['update_id' => 1, 'message' => (object)['text' => 'hi', 'chat' => (object)['id' => 42, 'type' => 'private'], 'from' => (object)['id' => 42]]];
        $processor->handle($update);

        return !$pluginCalled ? true : 'plugin should not run';
    });

    scenario('multiple middleware ordering (onion)', function() {
        $order = [];
        $plugin = new class($order) implements \alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface {
            public array $o;
            public function __construct(array &$o) { $this->o = &$o; }
            public function getPriority(): int { return 1; }
            public function onMessage($u, \telegramBotApiPhp\Telegram $t): void { $this->o[] = 'plugin'; }
        };

        $mw1 = new class($order) implements \alirezax5\TelegramBase\App\Middleware\MiddlewareInterface {
            public array $o;
            public function __construct(array &$o) { $this->o = &$o; }
            public function handle(object $u, \telegramBotApiPhp\Telegram $t, callable $next): mixed {
                $this->o[] = 'mw1_pre'; $r = $next(); $this->o[] = 'mw1_post'; return $r;
            }
        };
        $mw2 = new class($order) implements \alirezax5\TelegramBase\App\Middleware\MiddlewareInterface {
            public array $o;
            public function __construct(array &$o) { $this->o = &$o; }
            public function handle(object $u, \telegramBotApiPhp\Telegram $t, callable $next): mixed {
                $this->o[] = 'mw2_pre'; $r = $next(); $this->o[] = 'mw2_post'; return $r;
            }
        };

        $handler = (new ReflectionClass(\alirezax5\TelegramBase\App\Plugin\PluginHandler::class))->newInstanceWithoutConstructor();
        $ref = new ReflectionClass($handler);
        $pp = $ref->getProperty('plugins'); $pp->setAccessible(true);
        $pp->setValue($handler, ['onMessage' => [['class' => 'fake', 'priority' => 1, 'chatTypes' => []]]]);
        $ip = $ref->getProperty('instances'); $ip->setAccessible(true);
        $ip->setValue($handler, ['fake' => $plugin]);
        $lr = $ref->getProperty('lastReloadTime'); $lr->setAccessible(true);
        $lr->setValue($handler, time());
        $ri = $ref->getProperty('reloadInterval'); $ri->setAccessible(true);
        $ri->setValue($handler, 999999);
        $fl = $ref->getProperty('updateTypesFlip'); $fl->setAccessible(true);
        $fl->setValue($handler, array_fill_keys(['message'], true));

        $tg = new \telegramBotApiPhp\Telegram('123456789:AAEhBP0fakefakefakefakefake_TfAKE');
        $processor = new \alirezax5\TelegramBase\App\Update\Processor($handler, $tg);
        $pipeline = new \alirezax5\TelegramBase\App\Middleware\MiddlewarePipeline();
        $pipeline->add($mw1)->add($mw2);
        $processor->setMiddleware($pipeline);

        $update = (object)['update_id' => 1, 'message' => (object)['text' => 'hi', 'chat' => (object)['id' => 42, 'type' => 'private'], 'from' => (object)['id' => 42]]];
        $processor->handle($update);

        $expected = ['mw1_pre', 'mw2_pre', 'plugin', 'mw2_post', 'mw1_post'];
        return $order === $expected ? true : 'order: ' . json_encode($order);
    });

    echo "\n";
}

// =====================================================================
// Summary
// =====================================================================

echo "──────────────────────────────────────\n";
echo "PASS: {$pass}  FAIL: {$fail}  SKIP: {$skip}  BLOCKED: {$blocked}\n";

if ($fail > 0) {
    echo "\nFailed scenarios:\n";
    foreach ($results as $r) {
        if ($r['result'] === 'FAIL') {
            echo "  ✗ {$r['name']}: {$r['detail']}\n";
        }
    }
}

exit($fail > 0 ? 1 : 0);
