<?php

declare(strict_types=1);

use alirezax5\TelegramBase\App\Conversation\Conversation;
use alirezax5\TelegramBase\App\Conversation\ConversationManager;
use alirezax5\TelegramBase\App\Conversation\ConversationStore;
use alirezax5\TelegramBase\App\Conversation\Step;
use alirezax5\TelegramBase\App\Environment\EnvHandler;
use alirezax5\TelegramBase\App\Language\Language;
use alirezax5\TelegramBase\App\Plugin\PluginHandler;
use Illuminate\Database\Capsule\Manager as Capsule;
use telegramBotApiPhp\Telegram;

/**
 * Conversation-framework test suite (core promotion of the democon engine).
 *
 * NOTE: the lightweight runner does NOT call setUp()/tearDown() — every test
 * resets the static hooks it depends on via resetState() at its own start.
 *
 * Covers the offline parts:
 *   1. Step fluent API (types, buttons static/dynamic, columns, nav flags)
 *   2. Conversation::textMatches (translated keys + raw texts)
 *   3. Conversation::applyLang (langResolver hook + DEFAULT_LANG fallback)
 *   4. ConversationManager::mainMenuMarkup hook
 *   5. CORE_PLUGINS registration in PluginHandler (ConversationManager p2)
 *   6. ConversationStore shape + full lifecycle on in-memory sqlite
 *      (real conversations migration from Database/Migrations)
 */
class ConversationTest extends TgBaseTestCase
{
    private static bool $dbBooted = false;

    private function resetState(): void
    {
        Conversation::$langResolver = null;
        ConversationManager::$mainMenuMarkup = null;
        EnvHandler::clearCache();
    }

    /** Boot an in-memory sqlite capsule named 'main' (global, idempotent). */
    private function bootDb(): void
    {
        if (self::$dbBooted) {
            return;
        }

        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ], 'main');
        $capsule->getDatabaseManager()->setDefaultConnection('main');
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        self::$dbBooted = true;
    }

    /** Run the real conversations migration against the sqlite capsule. */
    private function migrateConversations(): void
    {
        $this->bootDb();
        $migration = require __DIR__ . '/../../Database/Migrations/2026_10_12_000003_create_conversations_table.php';
        $migration->up(Capsule::schema('main'));
    }

    private function withEnv(string $key, ?string $value, callable $fn): void
    {
        $oldEnv = $_ENV[$key] ?? null;
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
            if ($oldEnv === null) {
                unset($_ENV[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $oldEnv;
                putenv("{$key}={$oldEnv}");
            }
            EnvHandler::clearCache();
        }
    }

    // -----------------------------------------------------------------
    // 1. Step fluent API
    // -----------------------------------------------------------------

    public function test_step_types_and_defaults(TgAssert $a): void
    {
        $t = Step::text();
        $a->equals(Step::TEXT, $t->type);
        $a->false($t->optional);
        $a->equals(1, $t->columns);
        $a->false($t->withBack);
        $a->false($t->withMenu);
        $a->true($t->buttons === []);
        $a->true($t->buttonsFn === null);

        $a->equals(Step::PHOTO, Step::photo()->type);
        $a->equals(Step::CALLBACK, Step::callback()->type);
        $a->equals(Step::DISPLAY, Step::display()->type);
    }

    public function test_step_fluent_setters(TgAssert $a): void
    {
        $s = Step::text()
            ->ask('wiz.ask')
            ->pattern('#^\d+$#')
            ->validate(fn ($v) => true)
            ->retry('wiz.retry')
            ->optional()
            ->when(fn ($answers) => true)
            ->columns(3)
            ->onDone(fn ($answers) => null)
            ->backable()
            ->menu()
            ->compute(fn ($answers) => 'x');

        $a->equals('wiz.ask', $s->askKey);
        $a->equals('#^\d+$#', $s->pattern);
        $a->true(is_callable($s->validate));
        $a->equals('wiz.retry', $s->retryKey);
        $a->true($s->optional);
        $a->true(is_callable($s->when));
        $a->equals(3, $s->columns);
        $a->true(is_callable($s->onDone));
        $a->true($s->withBack);
        $a->true($s->withMenu);
        $a->true(is_callable($s->compute));

        // columns() clamps to >= 1
        $a->equals(1, Step::text()->columns(0)->columns);
        $a->equals(1, Step::text()->columns(-5)->columns);
    }

    public function test_step_buttons_static_and_dynamic(TgAssert $a): void
    {
        $static = Step::callback()->buttons(['yes' => 'wiz.yes', 'no' => 'wiz.no']);
        $a->equals(['yes' => 'wiz.yes', 'no' => 'wiz.no'], $static->buttons);
        $a->true($static->buttonsFn === null);

        $dynamic = Step::text()->buttons(fn (array $answers) => ['a' => 'btn.a']);
        $a->true($dynamic->buttons === []);
        $a->true(is_callable($dynamic->buttonsFn));
        $a->equals(['a' => 'btn.a'], ($dynamic->buttonsFn)([]));
    }

    // -----------------------------------------------------------------
    // 2. Conversation::textMatches
    // -----------------------------------------------------------------

    private function makeFlow(): Conversation
    {
        return new class extends Conversation {
            public function flowKey(): string { return 'testflow'; }
            public function steps(): array { return []; }
            public function onFinish(array $answers, Telegram $tg, int $chatId): void {}
        };
    }

    public function test_text_matches_raw_texts(TgAssert $a): void
    {
        $this->resetState();

        // Raw texts pass through __() unchanged when they are not keys.
        $a->true(Conversation::textMatches(['منوی اصلی', 'Main menu'], ' منوی اصلی '));
        $a->true(Conversation::textMatches(['Main Menu'], 'main MENU'));
        $a->false(Conversation::textMatches(['منوی اصلی'], 'چیز دیگر'));
        $a->false(Conversation::textMatches(['منوی اصلی'], ''));
    }

    public function test_text_matches_translated_key(TgAssert $a): void
    {
        $this->resetState();

        // conv.nav_back ships with the core language files.
        $flow = $this->makeFlow();
        $a->true($flow->navBackTexts() !== []);
        $a->true(Conversation::textMatches($flow->navBackTexts(), __('conv.nav_back')));
    }

    // -----------------------------------------------------------------
    // 3. Conversation::applyLang
    // -----------------------------------------------------------------

    public function test_apply_lang_uses_resolver_hook(TgAssert $a): void
    {
        $this->resetState();

        Conversation::$langResolver = fn (int $chatId) => $chatId === 111 ? 'en' : 'fa';
        Conversation::applyLang(111);
        $a->equals('en', Language::getInstance()->getCurrentLanguage());

        Conversation::applyLang(222);
        $a->equals('fa', Language::getInstance()->getCurrentLanguage());
    }

    public function test_apply_lang_falls_back_to_default(TgAssert $a): void
    {
        $this->resetState(); // resolver from the previous test must not leak

        $this->withEnv('DEFAULT_LANG', 'en', function () use ($a): void {
            Conversation::applyLang(999); // resolver null, no DB layer in tests
            $a->equals('en', Language::getInstance()->getCurrentLanguage());
        });
    }

    // -----------------------------------------------------------------
    // 4. ConversationManager::mainMenuMarkup hook
    // -----------------------------------------------------------------

    public function test_main_menu_markup_hook(TgAssert $a): void
    {
        $this->resetState();

        $ref = new ReflectionClass(ConversationManager::class);
        $method = $ref->getMethod('mainMenuMarkup');
        $method->setAccessible(true);

        // Default: no markup
        $a->true($method->invoke(null) === null);

        // Hook set: markup returned, non-array => null
        ConversationManager::$mainMenuMarkup = fn () => ['inline_keyboard' => [[['text' => 'M', 'callback_data' => 'm']]]];
        $out = $method->invoke(null);
        $a->true(is_array($out) && isset($out['inline_keyboard']));

        ConversationManager::$mainMenuMarkup = fn () => 'not-an-array';
        $a->true($method->invoke(null) === null);
    }

    // -----------------------------------------------------------------
    // 5. CORE_PLUGINS registration in PluginHandler
    // -----------------------------------------------------------------

    private function makeHandlerWithoutConstructor(): PluginHandler
    {
        $handler = (new ReflectionClass(PluginHandler::class))->newInstanceWithoutConstructor();

        $ref = new ReflectionClass($handler);

        // Bypassed-constructor instance needs a real plugins dir + cache off
        $dirProp = $ref->getProperty('pluginsDir');
        $dirProp->setAccessible(true);
        $dirProp->setValue($handler, realpath(__DIR__ . '/../../Plugin'));

        $cacheProp = $ref->getProperty('enableCache');
        $cacheProp->setAccessible(true);
        $cacheProp->setValue($handler, false);

        return $handler;
    }

    public function test_core_plugins_registered_from_env(TgAssert $a): void
    {
        $this->resetState();

        $this->withEnv('CORE_PLUGINS', ConversationManager::class, function () use ($a): void {
            $handler = $this->makeHandlerWithoutConstructor();

            $ref = new ReflectionClass($handler);
            $load = $ref->getMethod('loadFromFiles');
            $load->setAccessible(true);
            $load->invoke($handler);

            $plugins = $ref->getProperty('plugins');
            $plugins->setAccessible(true);
            $map = $plugins->getValue($handler);

            $a->true(isset($map['onMessage']), 'onMessage group must exist after loading core plugins');

            $found = false;
            foreach ($map['onMessage'] as $entry) {
                if ($entry['class'] === ConversationManager::class) {
                    $found = true;
                    $a->equals(2, $entry['priority'], 'ConversationManager must register at priority 2');
                }
            }
            $a->true($found, 'ConversationManager must be registered via CORE_PLUGINS');

            // Instances seeded for the core plugin
            $instances = $ref->getProperty('instances');
            $instances->setAccessible(true);
            $inst = $instances->getValue($handler);
            $a->true(isset($inst[ConversationManager::class]));
            $a->true($inst[ConversationManager::class] instanceof ConversationManager);
        });
    }

    public function test_core_plugins_env_parsing_skips_garbage(TgAssert $a): void
    {
        $this->resetState();

        $this->withEnv('CORE_PLUGINS', '  , \Class\Not\There ,, ', function () use ($a): void {
            $handler = $this->makeHandlerWithoutConstructor();

            $ref = new ReflectionClass($handler);
            $load = $ref->getMethod('loadFromFiles');
            $load->setAccessible(true);
            $load->invoke($handler); // must not throw

            $plugins = $ref->getProperty('plugins');
            $plugins->setAccessible(true);
            $map = $plugins->getValue($handler);

            $registered = [];
            foreach ($map as $group) {
                foreach ($group as $entry) {
                    $registered[] = $entry['class'];
                }
            }

            $a->true(!in_array('Class\Not\There', $registered, true), 'missing classes must be skipped with a warning');
        });
    }

    // -----------------------------------------------------------------
    // 6. ConversationStore (in-memory sqlite + real migration)
    // -----------------------------------------------------------------

    public function test_conversation_store_shape(TgAssert $a): void
    {
        $this->bootDb();

        $model = ConversationStore::make();
        $a->equals('conversations', $model->getTable());
        $a->equals('active', ConversationStore::STATUS_ACTIVE);
        $a->equals('done', ConversationStore::STATUS_DONE);
        $a->equals('cancelled', ConversationStore::STATUS_CANCELLED);

        foreach (['bot_id', 'chatid', 'flow', 'flow_class', 'step', 'answers', 'status', 'created_at', 'updated_at'] as $field) {
            $a->true(in_array($field, $model->getFillable(), true), "fillable must contain {$field}");
        }
    }

    public function test_conversation_store_lifecycle_on_sqlite(TgAssert $a): void
    {
        $this->migrateConversations();

        // open → active row exists
        $row = ConversationStore::open(777, 'register', 'Some\FlowClass', 'name', []);
        $a->equals(ConversationStore::STATUS_ACTIVE, $row->status);
        $a->equals('default', $row->bot_id);
        $a->true(ConversationStore::active(777) !== null);

        // advance persists step + answers
        $row->advance('phone', ['name' => 'Ali']);
        $fresh = ConversationStore::active(777);
        $a->equals('phone', $fresh->step);
        $a->equals('Ali', $fresh->answers['name']);

        // timeout basis is updated_at
        Capsule::table('conversations')
            ->where('id', $row->id)
            ->update(['updated_at' => date('Y-m-d H:i:s', time() - 7200)]);
        $a->true(ConversationStore::active(777)->isTimedOut(60));
        $a->false(ConversationStore::active(777)->isTimedOut(600));

        // finish closes the only active row
        $fresh->finish();
        $a->true(ConversationStore::active(777) === null);

        // dropActive cancels (used by begin() before reopening)
        $row2 = ConversationStore::open(778, 'survey', 'Some\Other', 'rate', []);
        ConversationStore::dropActive(778);
        $a->true(ConversationStore::active(778) === null);
        $a->equals(ConversationStore::STATUS_CANCELLED, $row2->fresh()->status);
    }
}
