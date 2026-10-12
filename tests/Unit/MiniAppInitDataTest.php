<?php

declare(strict_types=1);

use alirezax5\TelegramBase\App\MiniApp\InitData;

/**
 * Mini App initData validation (App\MiniApp\InitData) — offline tests.
 *
 * The fixed token/initData pair below was generated INDEPENDENTLY in Python
 * with the official algorithm (secret = HMAC_SHA256("WebAppData", token)),
 * so these tests also prove the PHP HMAC argument order is correct — the
 * classic silent failure is hashing with key/message swapped.
 */
class MiniAppInitDataTest extends TgBaseTestCase
{
    private const TOKEN = '7008765432:AAH9f4KqZtV7L2mN8pQx3yW5eR1tU0iO';

    /** Python-generated valid initData (auth_date=1700000000 — always pass max_age=0). */
    private const VECTOR = 'query_id=AAFtest123&user=%7B%22id%22%3A555000111%2C%22first_name%22%3A%22Ali%22%2C%22username%22%3A%22ali_test%22%7D&auth_date=1700000000&start_param=promo_1&hash=9006d71f71bcf0806531ed4fe5c44c39d7a81bc9950b09099c0d39375fbbb2c9';

    private const VECTOR_HASH = '9006d71f71bcf0806531ed4fe5c44c39d7a81bc9950b09099c0d39375fbbb2c9';

    public function test_fixed_reference_vector_is_valid(TgAssert $a): void
    {
        $r = InitData::validate(self::VECTOR, self::TOKEN, ['max_age' => 0]);

        $a->true($r['ok']);
        $a->equals(555000111, $r['user']['id'] ?? null);
        $a->equals('Ali', $r['user']['first_name'] ?? null);
        $a->equals('promo_1', $r['start_param']);
        $a->equals(1700000000, $r['auth_date']);
        $a->equals('AAFtest123', $r['fields']['query_id'] ?? null);
        $a->equals(self::VECTOR_HASH, $r['hash']);
    }

    public function test_build_validate_roundtrip_with_fresh_auth_date(TgAssert $a): void
    {
        $initData = InitData::build([
            'query_id'     => 'AAFround99',
            'user'         => ['id' => 42, 'first_name' => 'کاربر', 'username' => 'u42'],
            'start_param'  => 'ref_7',
        ], self::TOKEN);

        // Fresh auth_date + default max_age must pass.
        $r = InitData::validate($initData, self::TOKEN);

        $a->true($r['ok']);
        $a->equals(42, $r['user']['id'] ?? null);
        $a->equals('کاربر', $r['user']['first_name'] ?? null);
        $a->equals('ref_7', $r['start_param']);
    }

    public function test_tampered_hash_fails(TgAssert $a): void
    {
        $tampered = str_replace('hash=' . self::VECTOR_HASH, 'hash=' . str_repeat('0', 64), self::VECTOR);
        $a->notEquals(self::VECTOR, $tampered);

        $r = InitData::validate($tampered, self::TOKEN, ['max_age' => 0]);

        $a->false($r['ok']);
        $a->equals('bad_signature', $r['error']);
    }

    public function test_tampered_payload_fails(TgAssert $a): void
    {
        // Same hash, different user id → signature must not match.
        $evil = str_replace('555000111', '1', self::VECTOR);

        $r = InitData::validate($evil, self::TOKEN, ['max_age' => 0]);

        $a->false($r['ok']);
        $a->equals('bad_signature', $r['error']);
    }

    public function test_wrong_token_fails(TgAssert $a): void
    {
        $r = InitData::validate(self::VECTOR, '123456:OTHER_TOKEN', ['max_age' => 0]);

        $a->false($r['ok']);
        $a->equals('bad_signature', $r['error']);
    }

    public function test_expired_auth_date_fails(TgAssert $a): void
    {
        // build() accepts an explicit auth_date — two hours in the past here.
        $old = InitData::build(['user' => ['id' => 1], 'auth_date' => (string) (time() - 7200)], self::TOKEN);

        $r = InitData::validate($old, self::TOKEN, ['max_age' => 3600]);

        $a->false($r['ok']);
        $a->equals('auth_date_expired', $r['error']);
    }

    public function test_missing_hash_and_empty_input(TgAssert $a): void
    {
        $r1 = InitData::validate('query_id=AAF&auth_date=' . time(), self::TOKEN);
        $a->false($r1['ok']);
        $a->equals('hash_missing', $r1['error']);

        $r2 = InitData::validate('', self::TOKEN);
        $a->false($r2['ok']);
        $a->equals('empty_init_data', $r2['error']);

        $r3 = InitData::validate(self::VECTOR, '   ');
        $a->false($r3['ok']);
        $a->equals('bot_token_missing', $r3['error']);
    }

    public function test_token_autodetect_from_env(TgAssert $a): void
    {
        $_ENV['BOT_TOKEN'] = self::TOKEN;

        $r = InitData::validate(self::VECTOR, null, ['max_age' => 0]);

        $a->true($r['ok']);
        $a->equals(555000111, $r['user']['id'] ?? null);
    }

    public function test_helpers_valid_and_user(TgAssert $a): void
    {
        $a->true(InitData::valid(self::VECTOR, self::TOKEN, ['max_age' => 0]));
        $a->false(InitData::valid(self::VECTOR, '123456:WRONG', ['max_age' => 0]));

        $u = InitData::user(self::VECTOR, self::TOKEN, ['max_age' => 0]);
        $a->equals(555000111, $u['id'] ?? null);

        $a->true(InitData::user(self::VECTOR, '123456:WRONG', ['max_age' => 0]) === null);
    }
}
