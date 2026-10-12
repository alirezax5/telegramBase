<?php

declare(strict_types=1);

use alirezax5\TelegramBase\App\Button\Keyboard;
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
