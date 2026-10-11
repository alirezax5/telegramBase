<?php

declare(strict_types=1);

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
}
