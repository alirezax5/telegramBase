<?php

declare(strict_types=1);

use alirezax5\TelegramBase\App\Middleware\MiddlewarePipeline;
use alirezax5\TelegramBase\App\Middleware\MiddlewareInterface;
use alirezax5\TelegramBase\App\Update\Processor;
use alirezax5\TelegramBase\App\Plugin\PluginHandler;
use telegramBotApiPhp\Telegram;

/**
 * Verifies that middleware is actually executed during update dispatch,
 * not just defined. This is the regression test for Phase 2 G1:
 * "Middleware pipeline is never executed during dispatch".
 */
class MiddlewareWiringTest extends TgBaseTestCase
{
    private function makeProcessorWithPluginAndMiddleware(
        object $fakePlugin,
        MiddlewareInterface $middleware,
    ): Processor {
        // Build a Processor with a seeded PluginHandler (reflection bypass).
        $handler = (new ReflectionClass(PluginHandler::class))->newInstanceWithoutConstructor();

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

        $flipProp = $ref->getProperty('updateTypesFlip');
        $flipProp->setAccessible(true);
        $flipProp->setValue($handler, array_fill_keys(['message', 'callback_query'], true));

        $tg = new Telegram('123456789:AAEhBP0fakefakefakefakefake_TfAKE');
        $processor = new Processor($handler, $tg);

        $pipeline = new MiddlewarePipeline();
        $pipeline->add($middleware);
        $processor->setMiddleware($pipeline);

        return $processor;
    }

    private function sampleUpdate(): object
    {
        return (object)[
            'update_id' => 1,
            'message' => (object)[
                'message_id' => 100,
                'text' => 'hello',
                'chat' => (object)['id' => 42, 'type' => 'private'],
                'from' => (object)['id' => 42],
            ],
        ];
    }

    public function test_middleware_runs_before_plugin(TgAssert $a): void
    {
        $order = [];

        $fakePlugin = new class ($order) implements \alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface {
            public array $order;
            public function __construct(array &$order) { $this->order = &$order; }
            public function getPriority(): int { return 1; }
            public function onMessage($u, Telegram $t): void { $this->order[] = 'plugin'; }
        };

        $middleware = new class ($order) implements MiddlewareInterface {
            public array $order;
            public function __construct(array &$order) { $this->order = &$order; }
            public function handle(object $update, Telegram $tg, callable $next): mixed {
                $this->order[] = 'middleware_before';
                $result = $next();
                $this->order[] = 'middleware_after';
                return $result;
            }
        };

        $processor = $this->makeProcessorWithPluginAndMiddleware($fakePlugin, $middleware);
        $processor->handle($this->sampleUpdate());

        $a->equals(['middleware_before', 'plugin', 'middleware_after'], $order,
            'middleware must wrap plugin dispatch in order');
    }

    public function test_middleware_short_circuit_skips_plugin(TgAssert $a): void
    {
        $pluginCalled = false;

        $fakePlugin = new class ($pluginCalled) implements \alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface {
            public bool $called;
            public function __construct(bool &$c) { $this->called = &$c; }
            public function getPriority(): int { return 1; }
            public function onMessage($u, Telegram $t): void { $this->called = true; }
        };

        $middleware = new class implements MiddlewareInterface {
            public function handle(object $update, Telegram $tg, callable $next): mixed {
                // Intentionally do NOT call $next() — short-circuit.
                return null;
            }
        };

        $processor = $this->makeProcessorWithPluginAndMiddleware($fakePlugin, $middleware);
        $processor->handle($this->sampleUpdate());

        $a->false($pluginCalled, 'plugin must NOT run when middleware short-circuits');
    }

    public function test_no_middleware_means_direct_dispatch(TgAssert $a): void
    {
        // Backward compatibility: when no middleware is set, Processor
        // must call plugins directly — same as Phase 1 behavior.
        $pluginCalled = false;

        $fakePlugin = new class ($pluginCalled) implements \alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface {
            public bool $called;
            public function __construct(bool &$c) { $this->called = &$c; }
            public function getPriority(): int { return 1; }
            public function onMessage($u, Telegram $t): void { $this->called = true; }
        };

        $handler = (new ReflectionClass(PluginHandler::class))->newInstanceWithoutConstructor();
        $ref = new ReflectionClass($handler);

        $pluginsProp = $ref->getProperty('plugins');
        $pluginsProp->setAccessible(true);
        $pluginsProp->setValue($handler, ['onMessage' => [['class' => 'fake', 'priority' => 1, 'chatTypes' => []]]]);

        $instancesProp = $ref->getProperty('instances');
        $instancesProp->setAccessible(true);
        $instancesProp->setValue($handler, ['fake' => $fakePlugin]);

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
        // Intentionally do NOT call setMiddleware — leave it null.

        $processor->handle($this->sampleUpdate());

        $a->true($pluginCalled, 'plugin must run when no middleware is set');
    }

    public function test_empty_middleware_pipeline_falls_through(TgAssert $a): void
    {
        // An empty pipeline (->add never called) should behave like no
        // middleware at all.
        $pluginCalled = false;

        $fakePlugin = new class ($pluginCalled) implements \alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface {
            public bool $called;
            public function __construct(bool &$c) { $this->called = &$c; }
            public function getPriority(): int { return 1; }
            public function onMessage($u, Telegram $t): void { $this->called = true; }
        };

        $handler = (new ReflectionClass(PluginHandler::class))->newInstanceWithoutConstructor();
        $ref = new ReflectionClass($handler);

        $pluginsProp = $ref->getProperty('plugins');
        $pluginsProp->setAccessible(true);
        $pluginsProp->setValue($handler, ['onMessage' => [['class' => 'fake', 'priority' => 1, 'chatTypes' => []]]]);

        $instancesProp = $ref->getProperty('instances');
        $instancesProp->setAccessible(true);
        $instancesProp->setValue($handler, ['fake' => $fakePlugin]);

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
        $processor->setMiddleware(new MiddlewarePipeline()); // empty pipeline

        $processor->handle($this->sampleUpdate());

        $a->true($pluginCalled, 'plugin must run when pipeline is empty');
    }

    public function test_multiple_middlewares_run_in_order(TgAssert $a): void
    {
        $order = [];

        $fakePlugin = new class ($order) implements \alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface {
            public array $order;
            public function __construct(array &$o) { $this->order = &$o; }
            public function getPriority(): int { return 1; }
            public function onMessage($u, Telegram $t): void { $this->order[] = 'plugin'; }
        };

        $mw1 = new class ($order) implements MiddlewareInterface {
            public array $order;
            public function __construct(array &$o) { $this->order = &$o; }
            public function handle(object $u, Telegram $t, callable $next): mixed {
                $this->order[] = 'mw1_before'; $r = $next(); $this->order[] = 'mw1_after'; return $r;
            }
        };
        $mw2 = new class ($order) implements MiddlewareInterface {
            public array $order;
            public function __construct(array &$o) { $this->order = &$o; }
            public function handle(object $u, Telegram $t, callable $next): mixed {
                $this->order[] = 'mw2_before'; $r = $next(); $this->order[] = 'mw2_after'; return $r;
            }
        };

        $handler = (new ReflectionClass(PluginHandler::class))->newInstanceWithoutConstructor();
        $ref = new ReflectionClass($handler);

        $pluginsProp = $ref->getProperty('plugins');
        $pluginsProp->setAccessible(true);
        $pluginsProp->setValue($handler, ['onMessage' => [['class' => 'fake', 'priority' => 1, 'chatTypes' => []]]]);

        $instancesProp = $ref->getProperty('instances');
        $instancesProp->setAccessible(true);
        $instancesProp->setValue($handler, ['fake' => $fakePlugin]);

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
        $pipeline = new MiddlewarePipeline();
        $pipeline->add($mw1)->add($mw2);
        $processor->setMiddleware($pipeline);

        $processor->handle($this->sampleUpdate());

        // Onion model: mw1_before → mw2_before → plugin → mw2_after → mw1_after
        $a->equals(
            ['mw1_before', 'mw2_before', 'plugin', 'mw2_after', 'mw1_after'],
            $order,
            'middlewares must wrap in onion order'
        );
    }
}
