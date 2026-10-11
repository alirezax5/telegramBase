<?php

declare(strict_types=1);

use alirezax5\TelegramBase\App\Environment\EnvHandler;
use alirezax5\TelegramBase\App\Environment\EnvironmentValidator;

class EnvironmentValidatorTest extends TgBaseTestCase
{
    private function setEnv(array $env): void
    {
        EnvHandler::clearCache();
        foreach ($env as $k => $v) {
            putenv("{$k}={$v}");
            $_ENV[$k] = $v;
        }
    }

    public function test_valid_token_passes(TgAssert $a): void
    {
        $this->setEnv([
            'BOT_TOKEN' => '123456789:AAEhBP0fakefakefakefakefake_TfAKE',
            'BOT_API_URL' => 'https://api.telegram.org',
            'BOT_MODE' => 'update_direct',
            'TGBASE_SKIP_BOT_VALIDATION' => '0',
        ]);

        try {
            (new EnvironmentValidator())->validate();
            $a->pass();
        } catch (\Throwable $e) {
            $a->fail('valid env was rejected: ' . $e->getMessage());
        }
    }

    public function test_missing_token_rejected(TgAssert $a): void
    {
        $this->setEnv(['BOT_TOKEN' => '', 'TGBASE_SKIP_BOT_VALIDATION' => '0']);
        $a->throws(\InvalidArgumentException::class, fn() => (new EnvironmentValidator())->validate());
    }

    public function test_malformed_token_rejected(TgAssert $a): void
    {
        $this->setEnv(['BOT_TOKEN' => 'not-a-token', 'TGBASE_SKIP_BOT_VALIDATION' => '0']);
        $a->throws(\InvalidArgumentException::class, fn() => (new EnvironmentValidator())->validate());
    }

    public function test_short_token_rejected(TgAssert $a): void
    {
        $this->setEnv(['BOT_TOKEN' => '12345:ABfake', 'TGBASE_SKIP_BOT_VALIDATION' => '0']);
        $a->throws(\InvalidArgumentException::class, fn() => (new EnvironmentValidator())->validate());
    }

    public function test_invalid_api_url_rejected(TgAssert $a): void
    {
        $this->setEnv([
            'BOT_TOKEN' => '123456789:AAEhBP0fakefakefakefakefake_TfAKE',
            'BOT_API_URL' => 'not-a-url',
            'TGBASE_SKIP_BOT_VALIDATION' => '0',
        ]);
        $a->throws(\InvalidArgumentException::class, fn() => (new EnvironmentValidator())->validate());
    }

    public function test_invalid_mode_rejected(TgAssert $a): void
    {
        $this->setEnv([
            'BOT_TOKEN' => '123456789:AAEhBP0fakefakefakefakefake_TfAKE',
            'BOT_API_URL' => 'https://api.telegram.org',
            'BOT_MODE' => 'bogus_mode',
            'TGBASE_SKIP_BOT_VALIDATION' => '0',
        ]);
        $a->throws(\InvalidArgumentException::class, fn() => (new EnvironmentValidator())->validate());
    }

    public function test_skip_flag_short_circuits(TgAssert $a): void
    {
        // Even with completely bogus values, validation passes when skip flag is set.
        $this->setEnv([
            'BOT_TOKEN' => 'not-a-token',
            'BOT_API_URL' => 'not-a-url',
            'BOT_MODE' => 'bogus',
            'TGBASE_SKIP_BOT_VALIDATION' => '1',
        ]);

        try {
            (new EnvironmentValidator())->validate();
            $a->pass();
        } catch (\Throwable $e) {
            $a->fail('skip flag was not honored: ' . $e->getMessage());
        }
    }
}
