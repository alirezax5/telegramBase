<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Button;

use JsonException;

/**
 * Fluent keyboard builder for Telegram reply markup.
 *
 * Produces the exact array shape the Telegram Bot API expects for either
 * a reply (custom) keyboard or an inline keyboard. Compatible with the
 * static btn() definitions - use Keyboard for dynamic, code-built menus
 * and keep btn() for fixed, file-defined menus.
 *
 * Examples:
 *   Keyboard::inline()
 *       ->button('✅ تایید', 'confirm')
 *       ->button('❌ انصراف', 'cancel')
 *       ->row()
 *       ->button('📄 بعدی', 'page_2')
 *       ->toArray();
 *
 *   Keyboard::reply()
 *       ->button('🔍 جستجو')
 *       ->button('💳 خرید')
 *       ->row()
 *       ->resize()
 *       ->toArray();
 */
final class Keyboard
{
    public const CALLBACK = 'callback_data';
    public const URL = 'url';
    public const SWITCH_INLINE = 'switch_inline_query';
    public const SWITCH_INLINE_CURRENT = 'switch_inline_query_current_chat';

    /** @var array<int, array<int, array<string, mixed>>> */
    private array $rows = [];

    private bool $inline;

    /** @var array<string, mixed> */
    private array $global = [];

    public function __construct(bool $inline = true)
    {
        $this->inline = $inline;
    }

    /**
     * Build an inline keyboard (callback buttons).
     */
    public static function inline(): self
    {
        return new self(true);
    }

    /**
     * Build a reply (custom) keyboard - plain text buttons.
     */
    public static function reply(): self
    {
        return new self(false);
    }

    /**
     * Add a button to the current row.
     *
     * @param string $text  Visible label.
     * @param string $value Payload (callback data, url, query, ...).
     * @param string $type  One of the Keyboard::* type constants.
     */
    public function button(string $text, string $value = '', string $type = self::CALLBACK): self
    {
        $btn = ['text' => $text];

        if ($value !== '') {
            $btn[$type] = $value;
        }

        $rowIndex = max(0, array_key_last($this->rows) ?? 0);

        if (!isset($this->rows[$rowIndex])) {
            $this->rows[$rowIndex] = [];
        }

        $this->rows[$rowIndex][] = $btn;

        return $this;
    }

    /**
     * Start a new row.
     */
    public function row(): self
    {
        $this->rows[] = [];
        return $this;
    }

    /**
     * Reply keyboard only: resize to fit content.
     */
    public function resize(bool $v = true): self
    {
        $this->global['resize_keyboard'] = $v;
        return $this;
    }

    /**
     * Reply keyboard only: show once then hide.
     */
    public function oneTime(bool $v = true): self
    {
        $this->global['one_time_keyboard'] = $v;
        return $this;
    }

    /**
     * Reply keyboard only: show keyboard only to mentioned/specific users.
     */
    public function selective(bool $v = true): self
    {
        $this->global['selective'] = $v;
        return $this;
    }

    /**
     * Inline only: placeholder for the inline query input field.
     */
    public function inputPlaceholder(string $text): self
    {
        if ($this->inline) {
            $this->global['input_field_placeholder'] = $text;
        }
        return $this;
    }

    /**
     * Final keyboard array - ready to pass as 'reply_markup'.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $rows = array_values(
            array_filter($this->rows, static fn ($row) => !empty($row))
        );

        $key = $this->inline ? 'inline_keyboard' : 'keyboard';

        return array_merge([$key => $rows], $this->global);
    }

    /**
     * JSON-encoded keyboard, for use where a string is expected.
     */
    public function toJson(): string
    {
        try {
            return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            return '{}';
        }
    }
}