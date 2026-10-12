<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\Cli;

use alirezax5\TelegramBase\App\Bootstrap\PluginsBootstrap;
use alirezax5\TelegramBase\App\Core;
use alirezax5\TelegramBase\App\Enum\CoreMode;
use alirezax5\TelegramBase\App\Update\Processor;
use alirezax5\TelegramBase\App\Update\UpdateState;

/**
 * Fire one (or many) saved update fixtures through the REAL pipeline.
 *
 *   php bin/tgbase simulate tests/Fixtures/updates/start.json
 *   php bin/tgbase simulate tests/Fixtures/updates/            # every *.json
 *   php bin/tgbase simulate fixture.json --dry                 # parse only
 *
 * Without --dry the update is dispatched exactly like a webhook/polling
 * update — plugins run and the bot may REALLY send messages. Use it against
 * a test bot, or add --dry to only validate fixtures (JSON shape + which
 * plugin hook would fire) without contacting Telegram at all.
 *
 * Fixture format: the raw Telegram update object, exactly as Telegram posts
 * it to webhook.php:
 *
 *     {"update_id": 1, "message": {"message_id": 1, "date": 0,
 *      "chat": {"id": 1, "type": "private", "first_name": "T"},
 *      "from": {"id": 1, "is_bot": false, "first_name": "T"},
 *      "text": "/start"}}
 *
 * Sample fixtures ship in tests/Fixtures/updates/ (start, edited_message,
 * callback_query) and examples/fixtures/.
 */
final class Simulate
{
    /** Update-type list kept in sync with PluginHandler::UPDATE_TYPES. */
    private const UPDATE_TYPES = [
        'message', 'edited_message', 'channel_post', 'edited_channel_post',
        'business_connection', 'business_message', 'edited_business_message',
        'deleted_business_messages', 'message_reaction', 'message_reaction_count',
        'inline_query', 'chosen_inline_result', 'callback_query',
        'shipping_query', 'pre_checkout_query', 'purchased_paid_media',
        'poll', 'poll_answer', 'my_chat_member', 'chat_member',
        'chat_join_request', 'chat_boost', 'removed_chat_boost',
        'managed_bot', 'guest_message',
    ];

    public static function run(array $args): int
    {
        $dry = in_array('--dry', $args, true);
        $paths = array_values(array_filter(
            $args,
            static fn ($a) => $a !== '--dry'
        ));

        if ($paths === []) {
            echo "Usage: php bin/tgbase simulate <fixture.json|dir> [--dry]\n";
            return 1;
        }

        $files = [];
        foreach ($paths as $path) {
            if (is_dir($path)) {
                foreach (glob(rtrim($path, '/') . '/*.json') ?: [] as $f) {
                    $files[] = $f;
                }
            } elseif (is_file($path)) {
                $files[] = $path;
            } else {
                echo "✘ not found: {$path}\n";
                return 1;
            }
        }

        if ($files === []) {
            echo "✘ no .json fixtures found\n";
            return 1;
        }

        sort($files);

        if ($dry) {
            echo "=== DRY RUN (no dispatch, no API calls) ===\n";
        }

        $processor = null;

        if (!$dry) {
            $core = new Core(CoreMode::FULL);
            $processor = new Processor(
                PluginsBootstrap::getPlugins(),
                $core->Telegram
            );
        }

        $pass = 0;
        $fail = 0;

        foreach ($files as $file) {
            $label = basename($file);
            $raw = file_get_contents($file);
            $update = json_decode((string)$raw, false);

            if (!is_object($update) || !isset($update->update_id)) {
                echo "✘ {$label}: not a valid update object (needs update_id)\n";
                $fail++;
                continue;
            }

            $type = self::detectType($update);

            if ($type === null) {
                echo "✘ {$label}: no known update type key inside (message, callback_query, edited_message, ...)\n";
                $fail++;
                continue;
            }

            if ($dry) {
                echo "✔ {$label}: type={$type} update_id={$update->update_id}"
                    . " -> hook on" . self::studly($type) . "()\n";
                $pass++;
                continue;
            }

            UpdateState::reset();
            $processor->handle($update);

            $handled = UpdateState::isHandled();

            echo ($handled ? '✔' : '•') . " {$label}: dispatched type={$type}"
                . " update_id={$update->update_id}"
                . ' handled=' . ($handled ? 'yes' : 'NO (fallback ran)');

            if ($by = UpdateState::handledBy()) {
                echo " by {$by}";
            }

            echo "\n";
            $pass++;
        }

        echo "\nRESULT: {$pass} ok, {$fail} failed\n";

        return $fail === 0 ? 0 : 1;
    }

    /**
     * Same detection rule as PluginHandler::detectType() — first matching
     * known update-type key wins.
     */
    private static function detectType(object $update): ?string
    {
        foreach ($update as $key => $_) {
            if (in_array($key, self::UPDATE_TYPES, true)) {
                return $key;
            }
        }

        return null;
    }

    private static function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $value)));
    }
}
