<?php

declare(strict_types=1);

use alirezax5\TelegramBase\App\Update\WebhookDispatcher;
use alirezax5\TelegramBase\App\Update\WebhookResponse;
use alirezax5\TelegramBase\App\Update\Processor;
use alirezax5\TelegramBase\App\Plugin\PluginHandler;
use alirezax5\TelegramBase\App\Environment\EnvHandler;

class WebhookDispatcherTest extends TgBaseTestCase
{
    private function makeDispatcher(): WebhookDispatcher
    {
        // A processor with no plugin handler is enough — we're not
        // dispatching real updates in these tests, only validating
        // the dispatcher's request-level gates.
        $tg = new \telegramBotApiPhp\Telegram('123456789:AAEhBP0fakefakefakefakefake_TfAKE');
        $processor = new Processor(null, $tg);
        return new WebhookDispatcher($processor);
    }

    public function test_empty_body_returns_400(TgAssert $a): void
    {
        EnvHandler::clearCache();
        putenv('WEBHOOK_CHECK_IP=0');
        $_ENV['WEBHOOK_CHECK_IP'] = '0';
        putenv('BOT_WEBHOOK_SECRET=');
        $_ENV['BOT_WEBHOOK_SECRET'] = '';

        $d = $this->makeDispatcher();
        $resp = $d->dispatchIntoResponse('', '127.0.0.1', '', 'webhook_direct');

        $a->equals(400, $resp->status);
        $a->equals('empty', $resp->body);
    }

    public function test_oversize_body_returns_413(TgAssert $a): void
    {
        EnvHandler::clearCache();
        putenv('WEBHOOK_CHECK_IP=0');
        $_ENV['WEBHOOK_CHECK_IP'] = '0';
        putenv('BOT_WEBHOOK_SECRET=');
        $_ENV['BOT_WEBHOOK_SECRET'] = '';

        $d = $this->makeDispatcher();
        $huge = str_repeat('x', 1_048_577); // 1 byte over the 1 MB limit
        $resp = $d->dispatchIntoResponse($huge, '127.0.0.1', '', 'webhook_direct');

        $a->equals(413, $resp->status);
    }

    public function test_unknown_ip_rejected(TgAssert $a): void
    {
        EnvHandler::clearCache();
        putenv('WEBHOOK_CHECK_IP=1');
        $_ENV['WEBHOOK_CHECK_IP'] = '1';
        putenv('BOT_WEBHOOK_SECRET=');
        $_ENV['BOT_WEBHOOK_SECRET'] = '';

        $d = $this->makeDispatcher();
        $payload = json_encode(['update_id' => 1, 'message' => ['text' => 'hi']]);
        $resp = $d->dispatchIntoResponse($payload, '8.8.8.8', '', 'webhook_direct');

        $a->equals(403, $resp->status);
    }

    public function test_telegram_ip_allowed(TgAssert $a): void
    {
        EnvHandler::clearCache();
        putenv('WEBHOOK_CHECK_IP=1');
        $_ENV['WEBHOOK_CHECK_IP'] = '1';
        putenv('BOT_WEBHOOK_SECRET=');
        $_ENV['BOT_WEBHOOK_SECRET'] = '';

        $d = $this->makeDispatcher();
        $a->true($d->isInTelegramNetwork('149.154.167.197')); // well-known Telegram IP
        $a->false($d->isInTelegramNetwork('8.8.8.8'));
    }

    public function test_secret_mismatch_returns_403(TgAssert $a): void
    {
        EnvHandler::clearCache();
        putenv('WEBHOOK_CHECK_IP=0');
        $_ENV['WEBHOOK_CHECK_IP'] = '0';
        putenv('BOT_WEBHOOK_SECRET=expected_secret');
        $_ENV['BOT_WEBHOOK_SECRET'] = 'expected_secret';

        $d = $this->makeDispatcher();
        $payload = json_encode(['update_id' => 1, 'message' => ['text' => 'hi']]);
        $resp = $d->dispatchIntoResponse($payload, '127.0.0.1', 'wrong_secret', 'webhook_direct');

        $a->equals(403, $resp->status);
    }

    public function test_secret_match_proceeds(TgAssert $a): void
    {
        EnvHandler::clearCache();
        putenv('WEBHOOK_CHECK_IP=0');
        $_ENV['WEBHOOK_CHECK_IP'] = '0';
        putenv('BOT_WEBHOOK_SECRET=expected_secret');
        $_ENV['BOT_WEBHOOK_SECRET'] = 'expected_secret';

        $d = $this->makeDispatcher();
        $payload = json_encode(['update_id' => 1, 'message' => ['text' => 'hi']]);
        $resp = $d->dispatchIntoResponse($payload, '127.0.0.1', 'expected_secret', 'webhook_direct');

        $a->equals(200, $resp->status);
        $a->equals('ok', $resp->body);
    }

    public function test_bad_json_returns_400(TgAssert $a): void
    {
        EnvHandler::clearCache();
        putenv('WEBHOOK_CHECK_IP=0');
        $_ENV['WEBHOOK_CHECK_IP'] = '0';
        putenv('BOT_WEBHOOK_SECRET=');
        $_ENV['BOT_WEBHOOK_SECRET'] = '';

        $d = $this->makeDispatcher();
        $resp = $d->dispatchIntoResponse('not-json', '127.0.0.1', '', 'webhook_direct');

        $a->equals(400, $resp->status);
        $a->equals('bad payload', $resp->body);
    }

    public function test_json_without_update_id_returns_400(TgAssert $a): void
    {
        EnvHandler::clearCache();
        putenv('WEBHOOK_CHECK_IP=0');
        $_ENV['WEBHOOK_CHECK_IP'] = '0';
        putenv('BOT_WEBHOOK_SECRET=');
        $_ENV['BOT_WEBHOOK_SECRET'] = '';

        $d = $this->makeDispatcher();
        $resp = $d->dispatchIntoResponse('{"foo":"bar"}', '127.0.0.1', '', 'webhook_direct');

        $a->equals(400, $resp->status);
    }

    public function test_valid_payload_returns_200(TgAssert $a): void
    {
        EnvHandler::clearCache();
        putenv('WEBHOOK_CHECK_IP=0');
        $_ENV['WEBHOOK_CHECK_IP'] = '0';
        putenv('BOT_WEBHOOK_SECRET=');
        $_ENV['BOT_WEBHOOK_SECRET'] = '';

        $d = $this->makeDispatcher();
        $payload = json_encode(['update_id' => 1, 'message' => ['text' => 'hi']]);
        $resp = $d->dispatchIntoResponse($payload, '127.0.0.1', '', 'webhook_direct');

        $a->equals(200, $resp->status);
        $a->equals('ok', $resp->body);
        $a->equals(0, $resp->exitCode);
    }

    public function test_invalid_ip_format_rejected(TgAssert $a): void
    {
        $d = $this->makeDispatcher();
        $a->false($d->isInTelegramNetwork('not-an-ip'));
        $a->false($d->isInTelegramNetwork(''));
        $a->false($d->isInTelegramNetwork('::1')); // IPv6 not in Telegram ranges
    }

    public function test_response_dto_shape(TgAssert $a): void
    {
        $r = new WebhookResponse(418, 'teapot', 0);
        $a->equals(418, $r->status);
        $a->equals('teapot', $r->body);
        $a->equals(0, $r->exitCode);
    }
}
