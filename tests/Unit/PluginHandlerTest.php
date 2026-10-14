<?php

declare(strict_types=1);

use alirezax5\TelegramBase\App\Cache\CacheManager;
use alirezax5\TelegramBase\App\Plugin\PluginHandler;

/**
 * Tests the plugin handler's update-type detection and dispatch logic.
 *
 * Uses reflection to exercise private internals without modifying the
 * production class, plus a seeded subclass to test the dispatch path.
 */
class PluginHandlerTest extends TgBaseTestCase
{
    private function invokeDetectType(PluginHandler $handler, $update): ?string
    {
        $ref = new ReflectionClass($handler);

        // detectType relies on $updateTypesFlip which is normally set by
        // the constructor. Seed it manually so the bypassed-constructor
        // instance can still detect types.
        $flipProp = $ref->getProperty('updateTypesFlip');
        $flipProp->setAccessible(true);
        $flipProp->setValue($handler, array_fill_keys([
            'message', 'edited_message', 'channel_post', 'edited_channel_post',
            'business_connection', 'business_message', 'edited_business_message',
            'deleted_business_messages', 'message_reaction', 'message_reaction_count',
            'inline_query', 'chosen_inline_result', 'callback_query',
            'shipping_query', 'pre_checkout_query', 'purchased_paid_media',
            'poll', 'poll_answer', 'my_chat_member', 'chat_member',
            'chat_join_request', 'chat_boost', 'removed_chat_boost',
        ], true));

        $method = $ref->getMethod('detectType');
        $method->setAccessible(true);
        return $method->invoke($handler, $update);
    }

    public function test_detect_type_for_message_update(TgAssert $a): void
    {
        // Bypass the file-scan constructor with reflection.
        $handler = (new ReflectionClass(PluginHandler::class))->newInstanceWithoutConstructor();

        $update = (object)[
            'update_id' => 1,
            'message' => (object)['text' => 'hi', 'chat' => (object)['type' => 'private']],
        ];

        $a->equals('message', $this->invokeDetectType($handler, $update));
    }

    public function test_detect_type_for_callback_query(TgAssert $a): void
    {
        $handler = (new ReflectionClass(PluginHandler::class))->newInstanceWithoutConstructor();

        $update = (object)[
            'update_id' => 2,
            'callback_query' => (object)['data' => 'btn_x', 'from' => (object)['id' => 1]],
        ];

        $a->equals('callback_query', $this->invokeDetectType($handler, $update));
    }

    public function test_detect_type_returns_null_for_unknown(TgAssert $a): void
    {
        $handler = (new ReflectionClass(PluginHandler::class))->newInstanceWithoutConstructor();

        $update = (object)['update_id' => 3, 'unknown_field' => 'foo'];
        $a->true($this->invokeDetectType($handler, $update) === null);
    }

    public function test_run_all_invokes_registered_plugin_method(TgAssert $a): void
    {
        // Construct a PluginHandler without scanning files, then seed
        // its private $plugins and $instances via reflection.
        $handler = (new ReflectionClass(PluginHandler::class))->newInstanceWithoutConstructor();

        $fakePlugin = new class implements \alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface {
            public bool $onMessageCalled = false;
            public ?object $receivedUpdate = null;

            public function getPriority(): int { return 1; }

            public function onMessage($update, \telegramBotApiPhp\Telegram $tg): void
            {
                $this->onMessageCalled = true;
                $this->receivedUpdate = $update;
            }
        };

        $ref = new ReflectionClass($handler);

        $pluginsProp = $ref->getProperty('plugins');
        $pluginsProp->setAccessible(true);
        $pluginsProp->setValue($handler, [
            'onMessage' => [['class' => 'fake', 'priority' => 1, 'chatTypes' => []]],
        ]);

        $instancesProp = $ref->getProperty('instances');
        $instancesProp->setAccessible(true);
        $instancesProp->setValue($handler, ['fake' => $fakePlugin]);

        $lastReloadProp = $ref->getProperty('lastReloadTime');
        $lastReloadProp->setAccessible(true);
        $lastReloadProp->setValue($handler, time());

        $reloadIntervalProp = $ref->getProperty('reloadInterval');
        $reloadIntervalProp->setAccessible(true);
        $reloadIntervalProp->setValue($handler, 999999);

        // Seed updateTypesFlip so detectType() can recognize 'message'.
        $flipProp = $ref->getProperty('updateTypesFlip');
        $flipProp->setAccessible(true);
        $flipProp->setValue($handler, array_fill_keys([
            'message', 'edited_message', 'channel_post', 'edited_channel_post',
            'callback_query', 'inline_query', 'poll', 'my_chat_member', 'chat_member',
        ], true));

        $tg = new \telegramBotApiPhp\Telegram('123456789:AAEhBP0fakefakefakefakefake_TfAKE');
        $update = (object)[
            'update_id' => 1,
            'message' => (object)[
                'message_id' => 100,
                'text' => 'hello',
                'chat' => (object)['id' => 42, 'type' => 'private'],
                'from' => (object)['id' => 42],
            ],
        ];

        $handler->runAll($update, $tg);

        $a->true($fakePlugin->onMessageCalled, 'onMessage must be invoked for message updates');
        $a->true($fakePlugin->receivedUpdate !== null);
        $a->equals('hello', $fakePlugin->receivedUpdate->text);
    }

    public function test_get_stats_returns_shape(TgAssert $a): void
    {
        $handler = (new ReflectionClass(PluginHandler::class))->newInstanceWithoutConstructor();

        $ref = new ReflectionClass($handler);
        $pluginsProp = $ref->getProperty('plugins');
        $pluginsProp->setAccessible(true);
        $pluginsProp->setValue($handler, [
            'onMessage' => [
                ['class' => 'A', 'priority' => 1, 'chatTypes' => []],
                ['class' => 'B', 'priority' => 2, 'chatTypes' => []],
            ],
            'before' => [
                ['class' => 'A', 'priority' => 1, 'chatTypes' => []],
            ],
        ]);

        $stats = $handler->getStats();

        $a->true(isset($stats['plugins']));
        $a->true(isset($stats['handlers']));
        $a->true(isset($stats['events']));
        $a->equals(2, $stats['plugins'], 'two distinct classes registered');
        $a->equals(3, $stats['handlers'], 'three handler entries across events');
    }

    // ------------------------------------------------ plugin cache validity

    /**
     * Temp plugin dir with: one good plugin (implements BOTH contracts),
     * one fallback-only class (violates the documented contract), and one
     * non-plugin helper under Support/. Fixture is created ONCE per process
     * — the scanner require_once's the files, so a second fixture with the
     * same class names would fatal with "cannot redeclare class".
     *
     * @return array{dir: string, files: array<int,string>, good: string, only: string}
     */
    private function makeCacheFixture(): array
    {
        static $fx = null;
        if ($fx !== null) {
            return $fx;
        }

        $dir = sys_get_temp_dir() . '/tgbase_plcache_' . uniqid();
        @mkdir($dir . '/Support', 0777, true);

        // Namespace MUST mirror the file path — pathToClass() derives the
        // FQCN from the location under the scanned dir.
        $good = 'CacheGoodPlugin' . bin2hex(random_bytes(3));
        $only = 'CacheFallbackOnly' . bin2hex(random_bytes(3));

        file_put_contents($dir . '/' . $good . '.php', '<?php
namespace alirezax5\TelegramBase\Plugin;

use alirezax5\TelegramBase\App\Attributes\ChatType;
use alirezax5\TelegramBase\App\Plugin\Contract\FallbackInterface;
use alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface;
use alirezax5\TelegramBase\App\Update\UpdateState;
use telegramBotApiPhp\Telegram;

#[ChatType([\'private\'])]
class ' . $good . ' implements PluginInterface, FallbackInterface
{
    public function getPriority(): int { return 10; }
    public function onMessage($msg, Telegram $tg): void { UpdateState::markHandled(); }
    public function onFallback($data, Telegram $tg): void { }
}
');

        file_put_contents($dir . '/' . $only . '.php', '<?php
namespace alirezax5\TelegramBase\Plugin;

use alirezax5\TelegramBase\App\Plugin\Contract\FallbackInterface;
use telegramBotApiPhp\Telegram;

class ' . $only . ' implements FallbackInterface
{
    public function onFallback($data, Telegram $tg): void { }
}
');

        file_put_contents($dir . '/Support/CacheHelper.php', '<?php
namespace alirezax5\TelegramBase\Plugin\Support;

class CacheHelper
{
    public static function ping(): string { return \'pong\'; }
}
');

        $files = [];
        foreach (scandir($dir) !== false ? array_merge([$dir], glob($dir . '/*.php') ?: [], glob($dir . '/Support/*.php') ?: []) : [] as $f) {
            if (is_file($f)) {
                $files[] = $f;
            }
        }

        $fx = [
            'dir' => $dir,
            'files' => $files,
            'good' => '\\alirezax5\\TelegramBase\\Plugin\\' . $good,
            'only' => '\\alirezax5\\TelegramBase\\Plugin\\' . $only,
        ];

        return $fx;
    }

    /**
     * Handler pointed at a temp dir WITHOUT running the constructor (the
     * real one reads the process-global Config::plugins()->path).
     */
    private function handlerFor(string $dir): PluginHandler
    {
        $ref = new ReflectionClass(PluginHandler::class);
        $handler = $ref->newInstanceWithoutConstructor();

        foreach ([
            'pluginsDir' => $dir,
            'enableCache' => true,
            'cacheTtl' => 300,
            'reloadInterval' => 60,
            'lastReloadTime' => 0,
            'updateTypesFlip' => array_fill_keys(['message', 'callback_query'], true),
        ] as $prop => $value) {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($handler, $value);
        }

        return $handler;
    }

    public function test_fallback_only_class_is_skipped_with_warning(TgAssert $a): void
    {
        // Regression (2026-10-13, production BankSticker deployment): a class
        // implementing ONLY FallbackInterface was silently counted as
        // "skipped" — the bot logged "0 fallback(s)" and unknown messages
        // got no answer. The contract (FallbackInterface docblock) requires
        // BOTH interfaces; the compliant class registers, the violator does
        // not (now with a loud warning in the log).
        $fx = $this->makeCacheFixture();
        CacheManager::forget('plugin_handler:plugins_index');

        try {
            $h = $this->handlerFor($fx['dir']);
            $h->loadPlugins();

            $ref = new ReflectionClass($h);

            $inst = $ref->getProperty('instances');
            $inst->setAccessible(true);
            $instances = $inst->getValue($h);

            $a->equals(true, isset($instances[$fx['good']]));
            $a->equals(false, isset($instances[$fx['only']]));

            $fb = $ref->getProperty('fallbacks');
            $fb->setAccessible(true);
            $fallbacks = $fb->getValue($h);

            $a->equals(1, count($fallbacks));
            $a->equals($fx['good'], $fallbacks[0]['class']);
        } finally {
            CacheManager::forget('plugin_handler:plugins_index');
        }
    }

    public function test_plugin_cache_survives_non_plugin_helper_files(TgAssert $a): void
    {
        // Regression (2026-10-13, production BankSticker deployment): cache
        // validity compared count(scanned files) against count(loaded-plugin
        // hashes). With helper classes under Plugin/ (e.g. Support/User.php)
        // that comparison could never match, so every single request logged
        // "Plugin set changed -> cache invalid" and rescanned from scratch.
        $fx = $this->makeCacheFixture();
        $key = 'plugin_handler:plugins_index';

        try {
            CacheManager::forget($key);

            $h1 = $this->handlerFor($fx['dir']);
            $h1->loadPlugins();

            $cached = CacheManager::get($key);
            $a->equals(true, is_array($cached));
            // The scan list includes the helper file; hashes stay per-plugin.
            $a->equals(count($fx['files']), count($cached['files'] ?? []));
            $a->equals(1, count($cached['hashes'] ?? []));

            // Poison the index with a sentinel map but keep files+hashes
            // intact: a VALID cache must be restored verbatim. Under the old
            // count-vs-count check this payload was always rejected.
            $cached['plugins'] = ['__cache_restore_sentinel__' => [
                ['class' => 'x', 'priority' => 1, 'chatTypes' => []],
            ]];
            CacheManager::put($key, $cached, 300);

            $h2 = $this->handlerFor($fx['dir']);
            $h2->loadPlugins();

            $ref = new ReflectionClass($h2);
            $plugins = $ref->getProperty('plugins');
            $plugins->setAccessible(true);
            $value = $plugins->getValue($h2);

            $a->equals(true, isset($value['__cache_restore_sentinel__']));
        } finally {
            CacheManager::forget($key);
        }
    }
}
