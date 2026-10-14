<?php

declare(strict_types=1);

use alirezax5\TelegramBase\App\Button\Keyboard;
use alirezax5\TelegramBase\App\Environment\EnvHandler;
use alirezax5\TelegramBase\App\Paths;
use alirezax5\TelegramBase\Cli\Console;
use alirezax5\TelegramBase\Cli\Simulate;
use alirezax5\TelegramBase\Cli\Webhook;
use alirezax5\TelegramBase\App\Conversation\MenuFlow;
use alirezax5\TelegramBase\App\Language\Language;
use alirezax5\TelegramBase\App\Shared\Media;
use telegramBotApiPhp\Telegram;

class BaseUpgradeTest extends TgBaseTestCase
{
    // ------------------------------------------------ Keyboard additions

    public function test_keyboard_remove_payload(TgAssert $a): void
    {
        $a->equals(['remove_keyboard' => true], Keyboard::remove());
        $a->equals(
            ['remove_keyboard' => true, 'selective' => true],
            Keyboard::remove(true)
        );
    }

    public function test_keyboard_force_reply_payload(TgAssert $a): void
    {
        $a->equals(['force_reply' => true], Keyboard::forceReply());
        $a->equals(
            [
                'force_reply' => true,
                'selective' => true,
                'input_field_placeholder' => 'بنویس…',
            ],
            Keyboard::forceReply(true, 'بنویس…')
        );
    }

    public function test_keyboard_web_app_button(TgAssert $a): void
    {
        $kb = Keyboard::reply()
            ->button('فقط متن')
            ->webApp('🛍 فروشگاه', 'https://shop.example.com')
            ->toArray();

        $a->equals(
            [
                'keyboard' => [
                    [['text' => 'فقط متن'], ['text' => '🛍 فروشگاه', 'web_app' => ['url' => 'https://shop.example.com']]],
                ],
            ],
            $kb
        );

        // inline keyboards accept web_app buttons too
        $inline = Keyboard::inline()->webApp('open', 'https://app.example')->toArray();
        $a->equals(
            'https://app.example',
            $inline['inline_keyboard'][0][0]['web_app']['url']
        );
    }

    // ------------------------------------------------ Language lazy load

    public function test_language_lazy_subfile_load(TgAssert $a): void
    {
        $dir = sys_get_temp_dir() . '/tglazy_' . uniqid();
        @mkdir($dir . '/en', 0777, true);
        file_put_contents(
            $dir . '/en/shop.php',
            '<?php return ["open" => "Shop open", "closed" => "Shop closed"];'
        );

        $lang = Language::getInstance();
        $lang->setLanguageDir($dir);
        $lang->setLanguage('en');

        // The subfile was added AFTER load() — the lazy loader must pick it
        // up on first miss without any reload() call.
        $a->equals('Shop open', __('shop.open'));
        $a->equals('Shop closed', __('shop.closed'));

        // Unknown prefix falls back to the default (no crash, no retry loop).
        $a->equals('fallback.value', __('fallback.value', 'fallback.value'));

        // Cleanup
        @unlink($dir . '/en/shop.php');
        @rmdir($dir . '/en');
        @rmdir($dir);
    }

    public function test_language_flat_main_file_and_subdir_coexist(TgAssert $a): void
    {
        // Regression (2026-10-13, production BankSticker deployment): a
        // project with ONLY a {lang}/ subdir and no {lang}.php main file
        // got every subfile key loaded as filename.key, so unprefixed
        // lookups like __('btn_wallet') missed and reply-keyboard buttons
        // showed raw keys. Flat main files and subfile dot-keys must
        // coexist in one map.
        $dir = sys_get_temp_dir() . '/tgflat_' . uniqid();
        @mkdir($dir . '/fa', 0777, true);
        file_put_contents($dir . '/fa.php', "<?php return ['hello_flat' => 'سلام دنیا'];\n");
        file_put_contents($dir . '/fa/sub.php', "<?php return ['greet' => 'درود'];\n");

        $lang = Language::getInstance();

        $refDir = new ReflectionProperty($lang, 'languageDir');
        $refDir->setAccessible(true);
        $prevDir = $refDir->getValue($lang);
        $prevLang = $lang->getCurrentLanguage();

        try {
            $lang->setLanguageDir($dir);
            // Drop any boot-loaded cache for fa, reload from the temp dir,
            // and make fa CURRENT (reloadLanguage alone does not switch the
            // active language — a previous test may have left 'en' active).
            $lang->clearCache('fa');
            $lang->reloadLanguage('fa');
            $lang->setLanguage('fa');

            $a->equals('سلام دنیا', $lang->get('hello_flat'));
            $a->equals('درود', $lang->get('sub.greet'));
        } finally {
            $lang->clearCache('fa');
            if (is_string($prevDir)) {
                $refDir->setValue($lang, $prevDir);
            }
            $lang->reloadLanguage($prevLang);
            $lang->setLanguage($prevLang);

            @unlink($dir . '/fa.php');
            @unlink($dir . '/fa/sub.php');
            @rmdir($dir . '/fa');
            @rmdir($dir);
        }
    }

    // ------------------------------------------------ MenuFlow basics

    public function test_menuflow_definition_and_stateless_routing(TgAssert $a): void
    {
        $flow = MenuFlow::make('test.panel')
            ->screen('main', ['text' => 'panel'])
            ->screen('block', ['parent' => 'main', 'text' => 'send id', 'parse' => true])
            ->nav('🚫 بلاک', 'block');

        $a->equals(MenuFlow::class, $flow::class);

        // Stateless chat: empty text is never consumed, unknown labels fall
        // through (false) so the plugin keeps its own handling.
        $msg = (object)[
            'chat' => (object)['id' => 424242, 'type' => 'private'],
            'text' => '',
        ];
        $a->equals(false, $flow->handle($msg, self::offlineTelegram()));

        $msg->text = 'nothing matching here';
        $a->equals(false, $flow->handle($msg, self::offlineTelegram()));
    }

    // ------------------------------------------------ Simulate (dry)

    public function test_simulate_dry_run_detects_types(TgAssert $a): void
    {
        $fixtures = __DIR__ . '/../Fixtures/updates';

        ob_start();
        $rc = Simulate::run([$fixtures, '--dry']);
        $out = ob_get_clean();

        $a->equals(0, $rc);
        $a->equals(true, str_contains($out, 'type=edited_message'));
        $a->equals(true, str_contains($out, 'onEditedMessage()'));
        $a->equals(true, str_contains($out, 'type=callback_query'));
        $a->equals(true, str_contains($out, '3 ok, 0 failed'));
    }

    public function test_simulate_rejects_missing_path(TgAssert $a): void
    {
        ob_start();
        $rc = Simulate::run(['/nonexistent/fixture.json']);
        ob_end_clean();

        $a->equals(1, $rc);
    }

    // ------------------------------------------------ Webhook command (offline paths)

    public function test_webhook_help_returns_error_code(TgAssert $a): void
    {
        ob_start();
        $rc = Webhook::run([]);
        $out = ob_get_clean();

        $a->equals(1, $rc);
        $a->equals(true, str_contains($out, 'Usage:'));
    }

    public function test_webhook_set_requires_https_url(TgAssert $a): void
    {
        ob_start();
        $rc = Webhook::run(['set']);
        $out = ob_get_clean();

        $a->equals(1, $rc);
        $a->equals(true, str_contains($out, 'HTTPS --url is required'));
    }

    public function test_webhook_set_without_token_hints_at_flag_and_env(TgAssert $a): void
    {
        $prev = self::dropBotTokenEnv();

        try {
            ob_start();
            $rc = Webhook::run(['set', '--url=https://example.dev/hook']);
            $out = ob_get_clean();

            $a->equals(1, $rc);
            $a->equals(true, str_contains($out, 'BOT_TOKEN is not set'));
            $a->equals(true, str_contains($out, '--token'));
            $a->equals(true, str_contains($out, '.env'));
        } finally {
            self::restoreBotTokenEnv($prev);
        }
    }

    public function test_webhook_del_without_token_fails_before_network(TgAssert $a): void
    {
        $prev = self::dropBotTokenEnv();

        try {
            ob_start();
            $rc = Webhook::run(['del']);
            $out = ob_get_clean();

            $a->equals(1, $rc);
            $a->equals(true, str_contains($out, 'BOT_TOKEN is not set'));
        } finally {
            self::restoreBotTokenEnv($prev);
        }
    }

    public function test_webhook_set_accepts_token_flag_and_reports_source(TgAssert $a): void
    {
        // BOT_API_URL is pinned to a port that refuses connections so the
        // command can never reach Telegram; everything asserted here is
        // printed BEFORE the curl call (token source line included).
        $prev = self::pinEnv('BOT_API_URL', 'http://127.0.0.1:1');

        try {
            ob_start();
            $rc = Webhook::run(['set', '--url=https://example.dev/hook', '--token=123456:ABCdef']);
            $out = ob_get_clean();

            $a->equals(true, str_contains($out, 'from --token flag'));
            $a->equals(true, str_contains($out, 'setWebhook → https://example.dev/hook'));
            $a->equals(1, $rc); // curl to 127.0.0.1:1 fails → error path
        } finally {
            self::restoreEnv('BOT_API_URL', $prev);
        }
    }

    public function test_console_boots_project_env_before_commands(TgAssert $a): void
    {
        // Root cause regression test: bin/tgbase used to dispatch commands
        // WITHOUT loading the project .env, so webhook:*/migrate/simulate all
        // reported "BOT_TOKEN is not set." on correctly configured projects.
        $envFile = Paths::base() . '/.env';

        if (is_file($envFile)) {
            // Real deployment checkout — never touch a live .env. The CI run
            // covers this path because no .env is committed to the repo.
            return;
        }

        file_put_contents($envFile, "TGBASE_CLI_ENV_PROBE=hello_cli_env\n");
        $prev = self::dropEnv('TGBASE_CLI_ENV_PROBE');

        try {
            EnvHandler::clearCache();

            $console = new Console(['tgbase', 'list']);
            ob_start();
            $rc = $console->run();
            ob_end_clean();

            $a->equals(0, $rc);
            $a->equals('hello_cli_env', EnvHandler::get('TGBASE_CLI_ENV_PROBE', ''));
        } finally {
            @unlink($envFile);
            self::restoreEnv('TGBASE_CLI_ENV_PROBE', $prev);
        }
    }

    /**
     * Temporarily remove BOT_TOKEN from $_ENV and the real environment.
     *
     * @return array{env: mixed, real: string|false} previous values
     */
    private static function dropBotTokenEnv(): array
    {
        return self::dropEnv('BOT_TOKEN');
    }

    /**
     * @param array{env: mixed, real: string|false} $prev
     */
    private static function restoreBotTokenEnv(array $prev): void
    {
        self::restoreEnv('BOT_TOKEN', $prev);
    }

    /**
     * Remove a variable from $_ENV + real env and clear EnvHandler's cache.
     *
     * @return array{env: mixed, real: string|false} previous values
     */
    private static function dropEnv(string $key): array
    {
        $prev = [
            'env' => $_ENV[$key] ?? null,
            'real' => getenv($key),
        ];

        unset($_ENV[$key]);
        putenv($key);
        EnvHandler::clearCache();

        return $prev;
    }

    /**
     * Pin a variable to a value in $_ENV + real env (previous state captured).
     *
     * @return array{env: mixed, real: string|false} previous values
     */
    private static function pinEnv(string $key, string $value): array
    {
        $prev = self::dropEnv($key);
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
        EnvHandler::clearCache();

        return $prev;
    }

    /**
     * Restore a variable's previous state exactly (remove when it was absent).
     *
     * @param array{env: mixed, real: string|false} $prev
     */
    private static function restoreEnv(string $key, array $prev): void
    {
        if ($prev['env'] !== null) {
            $_ENV[$key] = $prev['env'];
        } else {
            unset($_ENV[$key]);
        }

        if ($prev['real'] !== false) {
            putenv("{$key}={$prev['real']}");
        } else {
            putenv($key);
        }

        EnvHandler::clearCache();
    }

    // ------------------------------------------------ ApiTrace hook wiring

    public function test_api_trace_callback_receives_compact_info(TgAssert $a): void
    {
        $tg = self::offlineTelegram();

        $captured = null;
        Telegram::onApiCall(function (array $info) use (&$captured): void {
            $captured = $info;
        });

        // Fire the private tracer directly — no network involved.
        $m = new ReflectionMethod(Telegram::class, 'traceApiCall');
        $m->setAccessible(true);

        $fakeFail = (object)[
            'ok' => false,
            'error_code' => 403,
            'description' => 'Forbidden: bot was blocked by the user',
        ];

        $m->invoke($tg, 'sendMessage', ['chat_id' => 42, 'text' => 'x'], microtime(true), $fakeFail);

        $a->equals(true, is_array($captured));
        $a->equals('sendMessage', $captured['method']);
        $a->equals(false, $captured['ok']);
        $a->equals(42, $captured['chat_id']);
        $a->equals(403, $captured['error_code']);
        $a->equals(true, str_contains((string)$captured['description'], 'blocked'));

        // Unregister so other tests stay silent.
        Telegram::onApiCall(null);

        $captured = null;
        $m->invoke($tg, 'sendMessage', ['chat_id' => 1], microtime(true), $fakeFail);
        $a->equals(null, $captured);
    }

    // ------------------------------------------------ Media

    public function test_media_is_local_discriminator(TgAssert $a): void
    {
        $a->equals(true, Media::isLocal(__FILE__));
        $a->equals(false, Media::isLocal('AgACAgIAAx0kAbcdef-file-id'));
    }

    // ------------------------------------------------ helper

    private static function offlineTelegram(): Telegram
    {
        return new Telegram('123456:TEST_OFFLINE_TOKEN_______');
    }
}
