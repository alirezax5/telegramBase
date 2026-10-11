<?php

declare(strict_types=1);

/**
 * Live Telegram Bot API tests.
 *
 * These tests hit the real Telegram API. They are skipped by default
 * — set RUN_TELEGRAM_LIVE_TESTS=1 along with TELEGRAM_BOT_TOKEN and
 * TELEGRAM_TEST_CHAT_ID to enable them.
 *
 * All test messages are tagged with `[telegramBase automated test]`
 * and deleted after verification where possible. Tests are
 * non-destructive — they never delete messages they did not create.
 */

use alirezax5\TelegramBase\App\Queue\RetryableException;

class LiveTelegramTest extends TgBaseTestCase
{
    private ?\telegramBotApiPhp\Telegram $tg = null;
    private array $createdMessageIds = [];

    private function skipIfNotEnabled(TgAssert $a): bool
    {
        if (!getenv('RUN_TELEGRAM_LIVE_TESTS')) {
            $a->skip('RUN_TELEGRAM_LIVE_TESTS not set');
            return false;
        }

        $token = getenv('TELEGRAM_BOT_TOKEN');
        $chat = (int)(getenv('TELEGRAM_TEST_CHAT_ID') ?: 0);

        if (!$token || $chat <= 0) {
            $a->skip('TELEGRAM_BOT_TOKEN / TELEGRAM_TEST_CHAT_ID not set');
            return false;
        }

        if ($this->tg === null) {
            $this->tg = new \telegramBotApiPhp\Telegram($token);
        }

        return true;
    }

    public function tearDown(): void
    {
        // Best-effort cleanup of any messages we created during the test.
        $chat = (int)(getenv('TELEGRAM_TEST_CHAT_ID') ?: 0);
        if ($chat > 0 && $this->tg !== null) {
            foreach ($this->createdMessageIds as $id) {
                try {
                    $this->tg->deleteMessage($chat, $id);
                } catch (\Throwable) {
                    // best-effort
                }
            }
        }
        $this->createdMessageIds = [];
    }

    private function track(int $msgId): void
    {
        if ($msgId > 0) {
            $this->createdMessageIds[] = $msgId;
        }
    }

    public function test_get_me(TgAssert $a): void
    {
        if (!$this->skipIfNotEnabled($a)) return;

        $me = $this->tg->getMe();
        $a->true($me->ok ?? false, 'getMe must return ok=true');
        $a->true(isset($me->result->username));
        $a->true(isset($me->result->id));
        $a->true(isset($me->result->first_name));
        $a->true(($me->result->is_bot ?? false) === true);
    }

    public function test_send_and_delete_message(TgAssert $a): void
    {
        if (!$this->skipIfNotEnabled($a)) return;
        $chat = (int)getenv('TELEGRAM_TEST_CHAT_ID');

        $sent = $this->tg->sendMessage($chat, '[telegramBase automated test] send + delete');
        $a->true($sent->ok ?? false);
        $a->true(isset($sent->result->message_id));

        $this->track($sent->result->message_id ?? 0);

        $del = $this->tg->deleteMessage($chat, $sent->result->message_id);
        $a->true($del->ok ?? false);

        // Already deleted from tracking — pop it so we don't double-delete.
        array_pop($this->createdMessageIds);
    }

    public function test_html_parse_mode(TgAssert $a): void
    {
        if (!$this->skipIfNotEnabled($a)) return;
        $chat = (int)getenv('TELEGRAM_TEST_CHAT_ID');

        $sent = $this->tg->sendMessage(
            $chat,
            '[telegramBase automated test] <b>bold</b> <i>italic</i> <code>code</code>',
            ['parse_mode' => 'HTML']
        );
        $a->true($sent->ok ?? false, 'HTML parse mode must succeed');
        $this->track($sent->result->message_id ?? 0);
    }

    public function test_markdown_v2_parse_mode(TgAssert $a): void
    {
        if (!$this->skipIfNotEnabled($a)) return;
        $chat = (int)getenv('TELEGRAM_TEST_CHAT_ID');

        // MarkdownV2 requires escaping; keep payload simple.
        $sent = $this->tg->sendMessage(
            $chat,
            '[telegramBase automated test] *bold* _italic_',
            ['parse_mode' => 'MarkdownV2']
        );
        $a->true($sent->ok ?? false, 'MarkdownV2 parse mode must succeed');
        $this->track($sent->result->message_id ?? 0);
    }

    public function test_edit_message_text(TgAssert $a): void
    {
        if (!$this->skipIfNotEnabled($a)) return;
        $chat = (int)getenv('TELEGRAM_TEST_CHAT_ID');

        $sent = $this->tg->sendMessage($chat, '[telegramBase automated test] original');
        $msgId = $sent->result->message_id ?? 0;
        $this->track($msgId);

        $edited = $this->tg->editMessageText($chat, $msgId, '[telegramBase automated test] edited');
        $a->true($edited->ok ?? false);
    }

    public function test_inline_keyboard(TgAssert $a): void
    {
        if (!$this->skipIfNotEnabled($a)) return;
        $chat = (int)getenv('TELEGRAM_TEST_CHAT_ID');

        $kb = [
            'inline_keyboard' => [
                [['text' => 'A', 'callback_data' => 'tgbase_test_a'], ['text' => 'B', 'callback_data' => 'tgbase_test_b']],
                [['text' => 'Close', 'callback_data' => 'tgbase_test_close']],
            ],
        ];

        $sent = $this->tg->sendMessage($chat, '[telegramBase automated test] inline kb', ['reply_markup' => $kb]);
        $a->true($sent->ok ?? false);
        $this->track($sent->result->message_id ?? 0);
    }

    public function test_reply_keyboard(TgAssert $a): void
    {
        if (!$this->skipIfNotEnabled($a)) return;
        $chat = (int)getenv('TELEGRAM_TEST_CHAT_ID');

        $rk = [
            'keyboard' => [[['text' => 'Yes'], ['text' => 'No']]],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ];

        $sent = $this->tg->sendMessage($chat, '[telegramBase automated test] reply kb', ['reply_markup' => $rk]);
        $a->true($sent->ok ?? false);
        $this->track($sent->result->message_id ?? 0);
    }

    public function test_unicode_and_emoji(TgAssert $a): void
    {
        if (!$this->skipIfNotEnabled($a)) return;
        $chat = (int)getenv('TELEGRAM_TEST_CHAT_ID');

        $sent = $this->tg->sendMessage($chat, '[telegramBase automated test] سلام دنیا 🌍 Привет');
        $a->true($sent->ok ?? false);
        $this->track($sent->result->message_id ?? 0);
    }

    public function test_multiline(TgAssert $a): void
    {
        if (!$this->skipIfNotEnabled($a)) return;
        $chat = (int)getenv('TELEGRAM_TEST_CHAT_ID');

        $sent = $this->tg->sendMessage($chat, "[telegramBase automated test]\nline 1\nline 2\nline 3");
        $a->true($sent->ok ?? false);
        $this->track($sent->result->message_id ?? 0);
    }

    public function test_invalid_chat_returns_error(TgAssert $a): void
    {
        if (!$this->skipIfNotEnabled($a)) return;

        // chat_id=0 is invalid for sendMessage → 400 Bad Request.
        $resp = $this->tg->sendMessage(0, '[telegramBase automated test] should fail');
        $a->false($resp->ok ?? true);
        $a->true(($resp->error_code ?? 0) >= 400);
    }

    public function test_invalid_token_returns_unauthorized(TgAssert $a): void
    {
        if (!$this->skipIfNotEnabled($a)) return;

        $badTg = new \telegramBotApiPhp\Telegram('000000000:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA');
        $resp = $badTg->getMe();
        $a->false($resp->ok ?? true);
        $a->equals(401, $resp->error_code ?? 0);
    }

    public function test_retryable_exception_from_429(TgAssert $a): void
    {
        if (!$this->skipIfNotEnabled($a)) return;

        // Build a fake 429 response and verify our RetryableException
        // parser handles it correctly. We don't actually trigger a 429
        // (that would risk getting the test bot rate-limited).
        $fake = (object)[
            'ok' => false,
            'error_code' => 429,
            'description' => 'Too Many Requests: retry after 3',
            'parameters' => (object)['retry_after' => 3],
        ];

        $e = RetryableException::fromTelegramResponse($fake, 'sendMessage');
        $a->equals(429, $e->getCode());
        $a->equals(3, $e->retryAfter);
        $a->true(RetryableException::isRetryable($e));
    }
}
