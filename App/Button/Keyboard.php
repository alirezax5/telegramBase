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

        // Append to the current row. If no row exists yet, start one
        // implicitly so callers can write Keyboard::inline()->button(...).
        $lastKey = array_key_last($this->rows);

        if ($lastKey === null) {
            $this->rows[] = [];
            $lastKey = array_key_last($this->rows);
        }

        $this->rows[$lastKey][] = $btn;

        return $this;
    }

    /**
     * Reply keyboard only: ask the user to create and share a MANAGED bot
     * (Bot API 9.6, KeyboardButtonRequestManagedBot).
     *
     * Requires the manager bot to have "management of other bots" enabled in
     * the @BotFather Mini App, and works in private chats only. When the user
     * completes the flow the framework dispatches a `managed_bot` update
     * (plugin hook `onManagedBot`) plus a message carrying `managed_bot_created`.
     *
     * @param string $text              Visible button label.
     * @param int    $requestId         Signed 32-bit id, unique within the message.
     * @param string $suggestedName     Optional suggested bot name.
     * @param string $suggestedUsername Optional suggested bot username (no @).
     */
    public function requestManagedBot(
        string $text,
        int $requestId,
        string $suggestedName = '',
        string $suggestedUsername = ''
    ): self {
        $req = ['request_id' => $requestId];
        if ($suggestedName !== '') {
            $req['suggested_name'] = $suggestedName;
        }
        if ($suggestedUsername !== '') {
            $req['suggested_username'] = $suggestedUsername;
        }

        $lastKey = array_key_last($this->rows);

        if ($lastKey === null) {
            $this->rows[] = [];
            $lastKey = array_key_last($this->rows);
        }

        $this->rows[$lastKey][] = [
            'text' => $text,
            'request_managed_bot' => $req,
        ];

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

    // ==================================================================
    // Request-shaped keyboards (ready payloads, not builders)
    // ==================================================================

    /**
     * remove_keyboard payload — attach as reply_markup when a custom
     * keyboard must disappear (leaving a flow, closing a panel).
     *
     * TM-25 contract: never leave a dead keyboard behind. When the flow's
     * keyboard shape changes or is abandoned, the next message must carry
     * this payload.
     *
     *   $tg->sendMessage([
     *       'chat_id'      => $chatId,
     *       'text'         => 'پایان گفتگو',
     *       'reply_markup' => json_encode(Keyboard::remove()),
     *   ]);
     */
    public static function remove(bool $selective = false): array
    {
        return $selective
            ? ['remove_keyboard' => true, 'selective' => true]
            : ['remove_keyboard' => true];
    }

    /**
     * force_reply payload — the user's next message arrives as a reply.
     * Request-shaped like remove(); not a chained builder.
     */
    public static function forceReply(
        bool $selective = false,
        ?string $placeholder = null
    ): array {
        $out = ['force_reply' => true];

        if ($selective) {
            $out['selective'] = true;
        }

        if ($placeholder !== null && $placeholder !== '') {
            $out['input_field_placeholder'] = $placeholder;
        }

        return $out;
    }

    /**
     * Mini App button (KeyboardButtonWebApp) — opens a Telegram Mini App
     * when tapped. Valid on reply keyboards in PRIVATE chats and on inline
     * keyboards; the Mini App receives WebApp.initData for server-side
     * validation.
     *
     *   Keyboard::reply()->webApp('🛍 فروشگاه', 'https://shop.example.com')
     */
    public function webApp(string $text, string $url): self
    {
        $lastKey = array_key_last($this->rows);

        if ($lastKey === null) {
            $this->rows[] = [];
            $lastKey = array_key_last($this->rows);
        }

        $this->rows[$lastKey][] = [
            'text' => $text,
            'web_app' => ['url' => $url],
        ];

        return $this;
    }
}