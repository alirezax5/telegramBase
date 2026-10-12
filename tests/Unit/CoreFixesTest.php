<?php

declare(strict_types=1);

use alirezax5\TelegramBase\App\Environment\EnvHandler;
use alirezax5\TelegramBase\App\Language\Language;
use alirezax5\TelegramBase\App\Logger\LoggerConfig;
use alirezax5\TelegramBase\App\Middleware\Middlewares\RoleMiddleware;
use alirezax5\TelegramBase\App\Paths;
use alirezax5\TelegramBase\App\Plugin\PluginConfig;
use alirezax5\TelegramBase\App\Plugin\PluginHandler;
use alirezax5\TelegramBase\App\Plugin\Contract\FallbackInterface;
use alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface;
use alirezax5\TelegramBase\App\Update\UpdateState;
use Database\Users;
use telegramBotApiPhp\Telegram;

/**
 * Core-fixes test suite.
 *
 * Covers the framework gaps fixed after the 4-scenario demo:
 *   1. UpdateState handled-flag + FallbackInterface dispatch
 *   2. PluginConfig PLUGINS_DIR absolute-path footgun (auto-resolve)
 *   3. LOG_ENABLED defaulting to enabled
 *   4. Language: flat {lang}.json main file + :key replacement syntax
 *   5. Users role hierarchy (pure ranking part; DB parts stay offline)
 */
class CoreFixesTest extends TgBaseTestCase
{
    protected function setUp(): void
    {
        UpdateState::reset();
    }

    protected function tearDown(): void
    {
        UpdateState::reset();
    }

    // -----------------------------------------------------------------
    // 1. UpdateState
    // -----------------------------------------------------------------

    public function test_update_state_lifecycle(TgAssert $a): void
    {
        $a->false(UpdateState::isHandled(), 'fresh state must not be handled');
        $a->false(UpdateState::handledBy() !== null);

        UpdateState::markHandled('SomePlugin');
        $a->true(UpdateState::isHandled());
        $a->equals('SomePlugin', UpdateState::handledBy());

        UpdateState::reset();
        $a->false(UpdateState::isHandled(), 'reset() must clear the flag');
    }

    public function test_update_state_mark_without_id(TgAssert $a): void
    {
        UpdateState::markHandled();
        $a->true(UpdateState::isHandled());
        $a->false(UpdateState::handledBy() !== null, 'no id given -> handledBy() null');
    }

    // -----------------------------------------------------------------
    // 2. Fallback dispatch in PluginHandler
    // -----------------------------------------------------------------

    /**
     * Seed a constructor-less PluginHandler with a main-phase plugin and
     * (optionally) a fallback plugin, mirroring PluginHandlerTest style.
     */
    private function seededHandler(object $mainPlugin, ?object $fallbackPlugin): PluginHandler
    {
        $handler = (new ReflectionClass(PluginHandler::class))->newInstanceWithoutConstructor();

        $ref = new ReflectionClass($handler);

        $plugins = [];
        $plugins['onMessage'] = [
            ['class' => 'main', 'priority' => 1, 'chatTypes' => []],
        ];

        $pluginsProp = $ref->getProperty('plugins');
        $pluginsProp->setAccessible(true);
        $pluginsProp->setValue($handler, $plugins);

        $instances = ['main' => $mainPlugin];
        if ($fallbackPlugin !== null) {
            $instances['fallback'] = $fallbackPlugin;
        }
        $instancesProp = $ref->getProperty('instances');
        $instancesProp->setAccessible(true);
        $instancesProp->setValue($handler, $instances);

        if ($fallbackPlugin !== null) {
            $fallbacksProp = $ref->getProperty('fallbacks');
            $fallbacksProp->setAccessible(true);
            $fallbacksProp->setValue($handler, [
                ['class' => 'fallback', 'priority' => 1000, 'chatTypes' => []],
            ]);
        }

        $lastReloadProp = $ref->getProperty('lastReloadTime');
        $lastReloadProp->setAccessible(true);
        $lastReloadProp->setValue($handler, time());

        $reloadIntervalProp = $ref->getProperty('reloadInterval');
        $reloadIntervalProp->setAccessible(true);
        $reloadIntervalProp->setValue($handler, 999999);

        $flipProp = $ref->getProperty('updateTypesFlip');
        $flipProp->setAccessible(true);
        $flipProp->setValue($handler, array_fill_keys(['message', 'callback_query'], true));

        return $handler;
    }

    private function sampleMessageUpdate(): object
    {
        return (object)[
            'update_id' => 9100,
            'message' => (object)[
                'message_id' => 9101,
                'text' => '/unknown_cmd',
                'chat' => (object)['id' => 42, 'type' => 'private'],
                'from' => (object)['id' => 42],
                'date' => time(),
            ],
        ];
    }

    public function test_fallback_runs_when_nothing_marked_handled(TgAssert $a): void
    {
        $main = new class implements PluginInterface {
            public function getPriority(): int { return 1; }
            public function onMessage($update, Telegram $tg): void
            {
                // plugin ignores the update — does NOT mark handled
            }
        };

        $fallback = new class implements PluginInterface, FallbackInterface {
            public bool $called = false;
            public $receivedPayload = null;
            public function getPriority(): int { return 1000; }
            public function onMessage($update, Telegram $tg): void {}
            public function onFallback($data, Telegram $tg): void
            {
                $this->called = true;
                $this->receivedPayload = $data;
            }
        };

        $handler = $this->seededHandler($main, $fallback);
        $update = $this->sampleMessageUpdate();

        $tg = new Telegram('123456789:AAEhBP0fakefakefakefakefake_TfAKE');
        $handler->runAll($update, $tg);

        $a->true($fallback->called, 'onFallback must run when nothing marked handled');
        $a->equals('/unknown_cmd', $fallback->receivedPayload->text ?? null,
            'fallback receives the same payload as onMessage');
    }

    public function test_fallback_skips_when_marked_handled(TgAssert $a): void
    {
        $main = new class implements PluginInterface {
            public function getPriority(): int { return 1; }
            public function onMessage($update, Telegram $tg): void
            {
                if (str_starts_with((string)($update->text ?? ''), '/known')) {
                    UpdateState::markHandled('CoreFixesTest');
                }
            }
        };

        $fallback = new class implements PluginInterface, FallbackInterface {
            public bool $called = false;
            public function getPriority(): int { return 1000; }
            public function onMessage($update, Telegram $tg): void {}
            public function onFallback($data, Telegram $tg): void
            {
                $this->called = true;
            }
        };

        $handler = $this->seededHandler($main, $fallback);
        $tg = new Telegram('123456789:AAEhBP0fakefakefakefakefake_TfAKE');

        // handled branch
        $handledUpdate = $this->sampleMessageUpdate();
        $handledUpdate->message->text = '/known';
        $handler->runAll($handledUpdate, $tg);
        $a->false($fallback->called, 'onFallback must NOT run after markHandled()');
        $a->equals('CoreFixesTest', UpdateState::handledBy());

        // not-handled branch on the same handler instance (state reset inside runAll)
        UpdateState::reset();
        $fallback->called = false;
        $unknownUpdate = $this->sampleMessageUpdate();
        $handler->runAll($unknownUpdate, $tg);
        $a->true($fallback->called, 'onFallback must run again for the next unhandled update');
    }

    public function test_run_all_resets_state_between_updates(TgAssert $a): void
    {
        UpdateState::markHandled('stale');
        $main = new class implements PluginInterface {
            public function getPriority(): int { return 1; }
            public function onMessage($update, Telegram $tg): void {}
        };
        $handler = $this->seededHandler($main, null);
        $tg = new Telegram('123456789:AAEhBP0fakefakefakefakefake_TfAKE');

        $handler->runAll($this->sampleMessageUpdate(), $tg);

        $a->false(UpdateState::isHandled(), 'runAll() must reset the handled flag');
    }

    public function test_get_stats_includes_fallbacks(TgAssert $a): void
    {
        $handler = (new ReflectionClass(PluginHandler::class))->newInstanceWithoutConstructor();
        $ref = new ReflectionClass($handler);

        $pluginsProp = $ref->getProperty('plugins');
        $pluginsProp->setAccessible(true);
        $pluginsProp->setValue($handler, [
            'onMessage' => [['class' => 'A', 'priority' => 1, 'chatTypes' => []]],
        ]);

        $fallbacksProp = $ref->getProperty('fallbacks');
        $fallbacksProp->setAccessible(true);
        $fallbacksProp->setValue($handler, [
            ['class' => 'F', 'priority' => 1000, 'chatTypes' => []],
        ]);

        $stats = $handler->getStats();
        $a->true(isset($stats['fallbacks']), 'getStats() must report fallbacks');
        $a->equals(1, $stats['fallbacks']);
    }

    // -----------------------------------------------------------------
    // 3. PluginConfig — PLUGINS_DIR resolution
    // -----------------------------------------------------------------

    private function withEnv(string $key, ?string $value, callable $fn): void
    {
        $oldEnv = $_ENV[$key] ?? null;
        $oldGet = getenv($key);

        if ($value === null) {
            putenv($key);
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
        EnvHandler::clearCache();

        try {
            $fn();
        } finally {
            if ($oldEnv !== null) {
                $_ENV[$key] = $oldEnv;
            } else {
                unset($_ENV[$key]);
            }
            if ($oldGet !== false) {
                putenv("{$key}={$oldGet}");
            } else {
                putenv($key);
            }
            EnvHandler::clearCache();
        }
    }

    public function test_plugin_config_relative_path_joins_base(TgAssert $a): void
    {
        $this->withEnv('PLUGINS_DIR', 'Plugin', function () use ($a): void {
            $cfg = PluginConfig::fromEnv();
            $a->equals(rtrim(Paths::base(), '/') . '/Plugin', $cfg->path);
        });
    }

    public function test_plugin_config_absolute_footgun_resolves_to_base(TgAssert $a): void
    {
        // "/Plugin/" does not exist at filesystem root, but does exist
        // inside the project -> must resolve to the project path.
        $this->withEnv('PLUGINS_DIR', '/Plugin/', function () use ($a): void {
            $cfg = PluginConfig::fromEnv();
            $a->equals(rtrim(Paths::base(), '/') . '/Plugin', $cfg->path);
        });
    }

    public function test_plugin_config_keeps_existing_absolute_path(TgAssert $a): void
    {
        $existing = rtrim(Paths::base(), '/') . '/Plugin';
        $this->withEnv('PLUGINS_DIR', $existing, function () use ($a, $existing): void {
            $cfg = PluginConfig::fromEnv();
            $a->equals($existing, $cfg->path,
                'a real absolute path must be kept as-is');
        });
    }

    public function test_plugin_config_keeps_missing_absolute_path(TgAssert $a): void
    {
        $this->withEnv('PLUGINS_DIR', '/definitely-not-here-xyz/', function () use ($a): void {
            $cfg = PluginConfig::fromEnv();
            $a->equals('/definitely-not-here-xyz', $cfg->path,
                'missing absolute path is preserved (no silent guessing)');
        });
    }

    // -----------------------------------------------------------------
    // 4. LoggerConfig — LOG_ENABLED defaults to enabled
    // -----------------------------------------------------------------

    public function test_log_enabled_defaults_to_true(TgAssert $a): void
    {
        $this->withEnv('LOG_ENABLED', null, function () use ($a): void {
            $cfg = LoggerConfig::fromEnv();
            $a->true($cfg->enabled, 'logging must be ON by default (silent-loss fix)');
        });
    }

    public function test_log_enabled_false_still_respected(TgAssert $a): void
    {
        $this->withEnv('LOG_ENABLED', 'false', function () use ($a): void {
            $cfg = LoggerConfig::fromEnv();
            $a->false($cfg->enabled, 'explicit LOG_ENABLED=false must stay off');
        });
    }

    // -----------------------------------------------------------------
    // 5. Language — replacements + flat file
    // -----------------------------------------------------------------

    public function test_replacements_support_hash_and_colon_syntax(TgAssert $a): void
    {
        $lang = Language::getInstance();
        $ref = new ReflectionClass($lang);
        $method = $ref->getMethod('applyReplacements');
        $method->setAccessible(true);

        $out = $method->invoke($lang, 'Hi #name and :name!', ['name' => 'Ali']);
        $a->equals('Hi Ali and Ali!', $out,
            'both "#key" and ":key" replacement syntaxes must work');

        $out2 = $method->invoke($lang, 'no placeholders here', ['name' => 'Ali']);
        $a->equals('no placeholders here', $out2);
    }

    public function test_flat_json_language_file_is_loaded(TgAssert $a): void
    {
        $tmp = sys_get_temp_dir() . '/tgbase_lang_' . getmypid() . '_' . bin2hex(random_bytes(3));
        if (!mkdir($tmp, 0777, true)) {
            $a->true(false, 'failed to create temp language dir');
            return;
        }

        try {
            file_put_contents($tmp . '/en.json', json_encode([
                'greeting' => 'Hello from flat json',
            ]));

            $lang = Language::getInstance();
            $lang->setLanguageDir($tmp);
            $lang->clearCache('en');
            $lang->setLanguage('en');

            $a->equals('Hello from flat json', $lang->get('greeting'),
                'driver=json must load the flat {lang}.json main file');
        } finally {
            $lang = Language::getInstance();
            $lang->clearCache('en');
            @unlink($tmp . '/en.json');
            @rmdir($tmp);
        }
    }

    // -----------------------------------------------------------------
    // 6. Users — role hierarchy (pure, no DB)
    // -----------------------------------------------------------------

    public function test_role_hierarchy_ordering(TgAssert $a): void
    {
        $a->true(Users::rankOf('user') < Users::rankOf('moderator'));
        $a->true(Users::rankOf('moderator') < Users::rankOf('admin'));
        $a->true(Users::rankOf('admin') < Users::rankOf('superadmin'));
        $a->equals(-1, Users::rankOf('not-a-role'), 'unknown roles rank below user');
        $a->equals('user', Users::ROLE_USER);
        $a->equals('superadmin', Users::ROLE_SUPERADMIN);
    }

    public function test_role_middleware_default_min_role(TgAssert $a): void
    {
        $mw = new RoleMiddleware();
        $ref = new ReflectionClass($mw);
        $prop = $ref->getProperty('minRole');
        $prop->setAccessible(true);
        $a->equals('admin', $prop->getValue($mw),
            'RoleMiddleware must default to the admin tier');
    }
}
