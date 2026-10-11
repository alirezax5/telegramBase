<?php

declare(strict_types=1);

use alirezax5\TelegramBase\App\Button\Keyboard;

class KeyboardTest extends TgBaseTestCase
{
    public function test_inline_button_layout(TgAssert $a): void
    {
        $kb = Keyboard::inline()
            ->button('A', 'btn_a')
            ->button('B', 'btn_b')
            ->row()
            ->button('Close', 'btn_close')
            ->toArray();

        $a->equals(
            [
                'inline_keyboard' => [
                    [['text' => 'A', 'callback_data' => 'btn_a'], ['text' => 'B', 'callback_data' => 'btn_b']],
                    [['text' => 'Close', 'callback_data' => 'btn_close']],
                ],
            ],
            $kb
        );
    }

    public function test_inline_button_without_value(TgAssert $a): void
    {
        $kb = Keyboard::inline()->button('Just text')->toArray();
        $a->equals(
            ['inline_keyboard' => [[['text' => 'Just text']]]],
            $kb
        );
    }

    public function test_reply_keyboard_with_options(TgAssert $a): void
    {
        $kb = Keyboard::reply()
            ->button('Yes')
            ->button('No')
            ->resize()
            ->oneTime()
            ->selective()
            ->toArray();

        $a->equals(
            [
                'keyboard' => [[['text' => 'Yes'], ['text' => 'No']]],
                'resize_keyboard' => true,
                'one_time_keyboard' => true,
                'selective' => true,
            ],
            $kb
        );
    }

    public function test_inline_button_starts_new_row_implicitly(TgAssert $a): void
    {
        // No row() called before first button — Keyboard should auto-create row 0.
        $kb = Keyboard::inline()->button('Solo')->toArray();
        $a->true(isset($kb['inline_keyboard'][0][0]['text']));
        $a->equals('Solo', $kb['inline_keyboard'][0][0]['text']);
    }

    public function test_empty_rows_filtered_in_toarray(TgAssert $a): void
    {
        // Calling row() twice in a row creates an empty intermediate row.
        $kb = Keyboard::inline()
            ->button('A', 'a')
            ->row()
            ->row()
            ->button('B', 'b')
            ->toArray();

        $a->equals(2, count($kb['inline_keyboard']), 'empty middle row should be filtered');
    }

    public function test_url_button_type(TgAssert $a): void
    {
        $kb = Keyboard::inline()
            ->button('Open', 'https://example.com', Keyboard::URL)
            ->toArray();

        $a->equals(
            ['inline_keyboard' => [[['text' => 'Open', 'url' => 'https://example.com']]]],
            $kb
        );
    }

    public function test_tojson_returns_valid_json(TgAssert $a): void
    {
        $json = Keyboard::inline()
            ->button('A', 'a')
            ->toJson();

        $decoded = json_decode($json, true);
        $a->true(is_array($decoded));
        $a->true(isset($decoded['inline_keyboard']));
    }
}
