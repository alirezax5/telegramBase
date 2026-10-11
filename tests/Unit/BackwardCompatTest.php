<?php

declare(strict_types=1);

use alirezax5\TelegramBase\App\Plugin\PluginHandler;
use alirezax5\TelegramBase\App\Update\Processor;
use alirezax5\TelegramBase\App\Middleware\MiddlewarePipeline;
use telegramBotApiPhp\Telegram;

require_once __DIR__ . '/../Fixtures/OldStylePlugin.php';

/**
 * Backward compatibility test.
 *
 * Verifies that a plugin written for the pre-Phase-2 architecture
 * still loads, registers, handles updates, and accesses the Telegram
 * instance correctly — WITHOUT using any Phase 2 features.
 *
 * If this test fails, Phase 2 broke backward compatibility and the
 * change must be reworked.
 */
class BackwardCompatTest extends TgBaseTestCase
{
    private function sampleUpdate(): object
    {
        return (object)[
            'update_id' => 7001,
            'message' => (object)[
                'message_id' => 8001,
                'text' => '/start',
                'chat' => (object)['id' => 42, 'type' => 'private'],
                'from' => (object)['id' => 42, 'first_name' => 'Test'],
                'date' => time(),
            ],
        ];
    }

    public function test_old_style_plugin_loads_and_handles(TgAssert $a): void
    {
        // Reset static state.
        \alirezax5\TelegramBase\Plugin\Fixtures\OldStylePlugin::$onMessageCalled = false;
        \alirezax5\TelegramBase\Plugin\Fixtures\OldStylePlugin::$receivedUpdate = null;
        \alirezax5\TelegramBase\Plugin\Fixtures\OldStylePlugin::$receivedTelegram = null;

        // Build a PluginHandler with the old-style plugin seeded directly,
        // bypassing the file-scan constructor (same pattern as PluginHandlerTest).
        $handler = (new ReflectionClass(PluginHandler::class))->newInstanceWithoutConstructor();
        $ref = new ReflectionClass($handler);

        $pluginsProp = $ref->getProperty('plugins');
        $pluginsProp->setAccessible(true);
        $pluginsProp->setValue($handler, [
            'onMessage' => [[
                'class' => \alirezax5\TelegramBase\Plugin\Fixtures\OldStylePlugin::class,
                'priority' => 5,
                'chatTypes' => ['private'],
            ]],
        ]);

        $instancesProp = $ref->getProperty('instances');
        $instancesProp->setAccessible(true);
        $instancesProp->setValue($handler, [
            \alirezax5\TelegramBase\Plugin\Fixtures\OldStylePlugin::class
                => new \alirezax5\TelegramBase\Plugin\Fixtures\OldStylePlugin(),
        ]);

        $lastReloadProp = $ref->getProperty('lastReloadTime');
        $lastReloadProp->setAccessible(true);
        $lastReloadProp->setValue($handler, time());

        $reloadIntervalProp = $ref->getProperty('reloadInterval');
        $reloadIntervalProp->setAccessible(true);
        $reloadIntervalProp->setValue($handler, 999999);

        $flipProp = $ref->getProperty('updateTypesFlip');
        $flipProp->setAccessible(true);
        $flipProp->setValue($handler, array_fill_keys(['message', 'callback_query'], true));

        // Build a Processor with NO middleware (old-style behavior).
        $tg = new Telegram('123456789:AAEhBP0fakefakefakefakefake_TfAKE');
        $processor = new Processor($handler, $tg);

        $processor->handle($this->sampleUpdate());

        $a->true(
            \alirezax5\TelegramBase\Plugin\Fixtures\OldStylePlugin::$onMessageCalled,
            'old-style plugin onMessage must be called'
        );
        $a->true(
            \alirezax5\TelegramBase\Plugin\Fixtures\OldStylePlugin::$receivedUpdate !== null,
            'old-style plugin must receive the update object'
        );
        $a->true(
            \alirezax5\TelegramBase\Plugin\Fixtures\OldStylePlugin::$receivedTelegram !== null,
            'old-style plugin must receive the Telegram instance'
        );
        $a->equals(
            '/start',
            \alirezax5\TelegramBase\Plugin\Fixtures\OldStylePlugin::$receivedUpdate->text,
            'old-style plugin must see message text via $update->text'
        );
    }

    public function test_old_style_plugin_works_with_middleware_present(TgAssert $a): void
    {
        // Even when Phase 2 middleware is wired up, an old-style plugin
        // that doesn't know about middleware must still run. The middleware
        // is a passthrough in this case (it calls $next).
        \alirezax5\TelegramBase\Plugin\Fixtures\OldStylePlugin::$onMessageCalled = false;

        $handler = (new ReflectionClass(PluginHandler::class))->newInstanceWithoutConstructor();
        $ref = new ReflectionClass($handler);

        $pluginsProp = $ref->getProperty('plugins');
        $pluginsProp->setAccessible(true);
        $pluginsProp->setValue($handler, [
            'onMessage' => [[
                'class' => \alirezax5\TelegramBase\Plugin\Fixtures\OldStylePlugin::class,
                'priority' => 5,
                'chatTypes' => [],
            ]],
        ]);

        $instancesProp = $ref->getProperty('instances');
        $instancesProp->setAccessible(true);
        $instancesProp->setValue($handler, [
            \alirezax5\TelegramBase\Plugin\Fixtures\OldStylePlugin::class
                => new \alirezax5\TelegramBase\Plugin\Fixtures\OldStylePlugin(),
        ]);

        $lastReloadProp = $ref->getProperty('lastReloadTime');
        $lastReloadProp->setAccessible(true);
        $lastReloadProp->setValue($handler, time());

        $reloadIntervalProp = $ref->getProperty('reloadInterval');
        $reloadIntervalProp->setAccessible(true);
        $reloadIntervalProp->setValue($handler, 999999);

        $flipProp = $ref->getProperty('updateTypesFlip');
        $flipProp->setAccessible(true);
        $flipProp->setValue($handler, array_fill_keys(['message'], true));

        $tg = new Telegram('123456789:AAEhBP0fakefakefakefakefake_TfAKE');
        $processor = new Processor($handler, $tg);

        // Attach a passthrough middleware (Phase 2 feature).
        $pipeline = new MiddlewarePipeline();
        $pipeline->add(new class implements \alirezax5\TelegramBase\App\Middleware\MiddlewareInterface {
            public function handle(object $update, Telegram $tg, callable $next): mixed
            {
                return $next(); // passthrough
            }
        });
        $processor->setMiddleware($pipeline);

        $processor->handle($this->sampleUpdate());

        $a->true(
            \alirezax5\TelegramBase\Plugin\Fixtures\OldStylePlugin::$onMessageCalled,
            'old-style plugin must still run when Phase 2 middleware is present'
        );
    }

    public function test_old_style_plugin_chattype_filter_respected(TgAssert $a): void
    {
        // A plugin restricted to ['private'] must NOT run for group updates.
        \alirezax5\TelegramBase\Plugin\Fixtures\OldStylePlugin::$onMessageCalled = false;

        $handler = (new ReflectionClass(PluginHandler::class))->newInstanceWithoutConstructor();
        $ref = new ReflectionClass($handler);

        $pluginsProp = $ref->getProperty('plugins');
        $pluginsProp->setAccessible(true);
        $pluginsProp->setValue($handler, [
            'onMessage' => [[
                'class' => \alirezax5\TelegramBase\Plugin\Fixtures\OldStylePlugin::class,
                'priority' => 5,
                'chatTypes' => ['private'], // restricted
            ]],
        ]);

        $instancesProp = $ref->getProperty('instances');
        $instancesProp->setAccessible(true);
        $instancesProp->setValue($handler, [
            \alirezax5\TelegramBase\Plugin\Fixtures\OldStylePlugin::class
                => new \alirezax5\TelegramBase\Plugin\Fixtures\OldStylePlugin(),
        ]);

        $lastReloadProp = $ref->getProperty('lastReloadTime');
        $lastReloadProp->setAccessible(true);
        $lastReloadProp->setValue($handler, time());

        $reloadIntervalProp = $ref->getProperty('reloadInterval');
        $reloadIntervalProp->setAccessible(true);
        $reloadIntervalProp->setValue($handler, 999999);

        $flipProp = $ref->getProperty('updateTypesFlip');
        $flipProp->setAccessible(true);
        $flipProp->setValue($handler, array_fill_keys(['message'], true));

        $tg = new Telegram('123456789:AAEhBP0fakefakefakefakefake_TfAKE');
        $processor = new Processor($handler, $tg);

        // Dispatch a GROUP update — plugin restricted to 'private' must skip.
        $groupUpdate = (object)[
            'update_id' => 7002,
            'message' => (object)[
                'message_id' => 8002,
                'text' => '/start',
                'chat' => (object)['id' => -100, 'type' => 'group'],
                'from' => (object)['id' => 42],
                'date' => time(),
            ],
        ];

        $processor->handle($groupUpdate);

        $a->false(
            \alirezax5\TelegramBase\Plugin\Fixtures\OldStylePlugin::$onMessageCalled,
            'old-style plugin restricted to private must NOT run for group updates'
        );
    }
}
