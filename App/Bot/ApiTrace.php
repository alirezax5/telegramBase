<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Bot;

use alirezax5\TelegramBase\App\Environment\EnvHandler;
use alirezax5\TelegramBase\App\Logger\LogHandler;
use telegramBotApiPhp\Telegram;

/**
 * LOG_API_TRACE — delivery-line logging for every outgoing Bot API call.
 *
 * The framework's app log historically recorded only boot lines, which made
 * delivery debugging painful: when a message "didn't arrive" there was no
 * server-side trace of the API call at all. TgTrace-style file hooks solved
 * that per-bot, but the framework shipped no first-class switch.
 *
 * Set in .env:
 *
 *     LOG_API_TRACE=1
 *
 * and every Bot API call (ALL bots, including BOT_EXTRA_BOTS instances)
 * gets one compact log line via the library's Telegram::onApiCall() hook:
 *
 *     [2026-10-12 17:41:02] [INFO]: 📡 sendMessage → ok 128ms chat 82267967
 *     [2026-10-12 17:41:09] [ERROR]: 📡 sendMessage → FAIL 91ms chat 12345 | 403 Forbidden: bot was blocked by the user
 *
 * Requires telegram-bot-php ^3.4 (the release that introduced the static
 * onApiCall() hook). On older library installs the switch degrades to a
 * single boot warning instead of a fatal — tracing must never be able to
 * break delivery OR boot.
 *
 * Privacy note: payloads are deliberately NOT logged — only method, chat_id
 * (when present in the request), duration, ok/fail and Telegram's error
 * summary. Full request/response debugging stays the job of a dedicated
 * trace file (see INSTALL docs).
 *
 * The callback never throws into the API call path — tracing must never
 * be able to break delivery.
 */
final class ApiTrace
{
    private static bool $installed = false;

    /** Lines produced for getUpdates long polls are noisy — collapsed by default. */
    private const QUIET_METHODS = ['getUpdates'];

    public static function install(): void
    {
        if (self::$installed) {
            return;
        }

        if (!EnvHandler::bool('LOG_API_TRACE', false)) {
            return;
        }

        self::$installed = true;

        // The static onApiCall() hook ships with telegram-bot-php 3.4+.
        // On an older install (e.g. a lock still pinned to 3.3.0) the call
        // would be a fatal "undefined method" at boot — degrade instead.
        if (!method_exists(Telegram::class, 'onApiCall')) {
            LogHandler::warning(
                '📡 LOG_API_TRACE ignored: telegram-bot-php ^3.4 required (onApiCall hook missing)'
            );
            return;
        }

        Telegram::onApiCall(static function (array $info): void {
            // Collapse the long-poll heartbeat unless explicitly wanted.
            if (
                in_array($info['method'], self::QUIET_METHODS, true)
                && !EnvHandler::bool('LOG_API_TRACE_POLL', false)
            ) {
                return;
            }

            $line = "📡 {$info['method']} → "
                . ($info['ok'] ? 'ok' : 'FAIL')
                . " {$info['duration_ms']}ms";

            if (isset($info['chat_id']) && $info['chat_id'] !== '') {
                $line .= " chat {$info['chat_id']}";
            }

            if (!$info['ok'] && ($info['description'] ?? '') !== '') {
                $line .= " | {$info['error_code']} {$info['description']}";

                if (isset($info['retry_after'])) {
                    $line .= " (retry_after {$info['retry_after']}s)";
                }
            }

            $info['ok']
                ? LogHandler::info($line)
                : LogHandler::error($line);
        });

        LogHandler::info('📡 API trace enabled (LOG_API_TRACE=1)');
    }
}
