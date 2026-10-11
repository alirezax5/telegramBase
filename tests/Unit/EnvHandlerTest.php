<?php

declare(strict_types=1);

use alirezax5\TelegramBase\App\Environment\EnvHandler;

class EnvHandlerTest extends TgBaseTestCase
{
    public function test_get_returns_default_when_unset(TgAssert $a): void
    {
        EnvHandler::clearCache();
        $a->equals('fallback', EnvHandler::get('TGBASE_NONEXISTENT_KEY_' . uniqid(), 'fallback'));
    }

    public function test_get_reads_from_env(TgAssert $a): void
    {
        putenv('TGBASE_TEST_FOO=bar');
        $_ENV['TGBASE_TEST_FOO'] = 'bar';
        EnvHandler::clearCache();
        $a->equals('bar', EnvHandler::get('TGBASE_TEST_FOO'));
        putenv('TGBASE_TEST_FOO');
        unset($_ENV['TGBASE_TEST_FOO']);
        EnvHandler::clearCache();
    }

    public function test_bool_recognizes_common_true_literals(TgAssert $a): void
    {
        foreach (['1', 'true', 'TRUE', 'yes', 'on', 'On'] as $v) {
            putenv("TGBASE_BOOL_TEST={$v}");
            $_ENV['TGBASE_BOOL_TEST'] = $v;
            EnvHandler::clearCache();
            $a->true(EnvHandler::bool('TGBASE_BOOL_TEST', false), "literal '{$v}' should be true");
        }
        putenv('TGBASE_BOOL_TEST');
        unset($_ENV['TGBASE_BOOL_TEST']);
        EnvHandler::clearCache();
    }

    public function test_bool_recognizes_common_false_literals(TgAssert $a): void
    {
        foreach (['0', 'false', 'FALSE', 'no', 'off', 'Off', ''] as $v) {
            putenv("TGBASE_BOOL_TEST={$v}");
            $_ENV['TGBASE_BOOL_TEST'] = $v;
            EnvHandler::clearCache();
            $a->false(EnvHandler::bool('TGBASE_BOOL_TEST', true), "literal '{$v}' should be false");
        }
        putenv('TGBASE_BOOL_TEST');
        unset($_ENV['TGBASE_BOOL_TEST']);
        EnvHandler::clearCache();
    }

    public function test_int_parses_integers(TgAssert $a): void
    {
        putenv('TGBASE_INT_TEST=42');
        $_ENV['TGBASE_INT_TEST'] = '42';
        EnvHandler::clearCache();
        $a->equals(42, EnvHandler::int('TGBASE_INT_TEST', 0));
        putenv('TGBASE_INT_TEST');
        unset($_ENV['TGBASE_INT_TEST']);
        EnvHandler::clearCache();
    }

    public function test_string_returns_default_for_unset(TgAssert $a): void
    {
        EnvHandler::clearCache();
        $a->equals('def', EnvHandler::string('TGBASE_UNSET', 'def'));
    }

    public function test_cache_is_persistent_within_request(TgAssert $a): void
    {
        putenv('TGBASE_CACHE_TEST=v1');
        $_ENV['TGBASE_CACHE_TEST'] = 'v1';
        EnvHandler::clearCache();

        $a->equals('v1', EnvHandler::get('TGBASE_CACHE_TEST'));

        // Change env after first read — cached value should win.
        putenv('TGBASE_CACHE_TEST=v2');
        $_ENV['TGBASE_CACHE_TEST'] = 'v2';

        $a->equals('v1', EnvHandler::get('TGBASE_CACHE_TEST'), 'cache should shadow new env value');

        // After clearCache, the new value is read.
        EnvHandler::clearCache();
        $a->equals('v2', EnvHandler::get('TGBASE_CACHE_TEST'));

        putenv('TGBASE_CACHE_TEST');
        unset($_ENV['TGBASE_CACHE_TEST']);
        EnvHandler::clearCache();
    }
}
