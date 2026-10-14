<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\Cli;

use alirezax5\TelegramBase\App\Environment\EnvHandler;

/**
 * Webhook management command.
 *
 *   php bin/tgbase webhook:info
 *   php bin/tgbase webhook:set --url=https://example.com/webhook.php
 *                            [--token=...]            (default: BOT_TOKEN env)
 *                            [--secret=...]           (default: BOT_WEBHOOK_SECRET env)
 *                            [--updates=message,edited_message,callback_query]
 *                            [--max-connections=40]
 *                            [--drop-pending]
 *   php bin/tgbase webhook:del [--drop-pending]
 *
 * The default --updates list is the framework's recommended set: it includes
 * `edited_message` so plugins implementing onEditedMessage() actually receive
 * edits (Telegram only sends update types explicitly allowed at setWebhook
 * time — a webhook registered without it will NEVER deliver edits, which is
 * the single most common "my relay doesn't sync edits" support question).
 *
 * Uses plain curl against BOT_API_URL (default https://api.telegram.org),
 * mirroring Doctor's connectivity check — no framework boot required.
 */
final class Webhook
{
    /** Framework-recommended allowed_updates when --updates is omitted. */
    public const DEFAULT_UPDATES = 'message,edited_message,callback_query,my_chat_member,chat_member';

    public static function run(array $args): int
    {
        $sub = $args[0] ?? '';
        $flags = self::parseFlags(array_slice($args, 1));

        switch ($sub) {
            case 'set':
                return self::set($flags);
            case 'del':
            case 'delete':
                return self::del($flags);
            case 'info':
                return self::info($flags);
            default:
                self::help();
                return 1;
        }
    }

    private static function set(array $flags): int
    {
        $url = (string)($flags['url'] ?? EnvHandler::get('BOT_WEBHOOK_URL', ''));
        $token = self::resolveToken($flags);

        if ($token === null) {
            return 1;
        }

        if ($url === '' || !str_starts_with($url, 'https://')) {
            echo "A HTTPS --url is required (Telegram refuses plain http).\n";
            echo "  php bin/tgbase webhook:set --url=https://example.com/webhook.php\n";
            return 1;
        }

        $params = [
            'url' => $url,
            'max_connections' => (int)($flags['max-connections'] ?? 40),
            // Resolution order (TM-40 fix): --updates flag > ALLOWED_UPDATES
            // env (the deployment's declared contract) > framework default.
            // webhook:set previously ignored the env entirely, so every
            // re-registration silently reverted the bot's subscription to
            // the framework default — dropping types the deployment relies
            // on (e.g. pre_checkout_query for Telegram Stars payments).
            'allowed_updates' => json_encode(
                array_values(array_filter(array_map(
                    'trim',
                    explode(',', (string)($flags['updates']
                        ?? EnvHandler::get('ALLOWED_UPDATES', self::DEFAULT_UPDATES)))
                ), static fn ($v) => $v !== ''))
            ),
        ];

        $secret = (string)($flags['secret'] ?? EnvHandler::get('BOT_WEBHOOK_SECRET', ''));
        if ($secret !== '') {
            $params['secret_token'] = $secret;
            echo "secret_token: set (" . strlen($secret) . " chars, from "
                . (isset($flags['secret']) ? '--secret' : 'BOT_WEBHOOK_SECRET env') . ")\n";
        }

        if (isset($flags['drop-pending'])) {
            $params['drop_pending_updates'] = 'true';
        }

        echo "setWebhook → {$url}\n";
        echo "allowed_updates: {$params['allowed_updates']}\n";

        $resp = self::api($token, 'setWebhook', $params);

        return self::printResult($resp);
    }

    private static function del(array $flags): int
    {
        $token = self::resolveToken($flags);

        if ($token === null) {
            return 1;
        }

        $params = [];
        if (isset($flags['drop-pending'])) {
            $params['drop_pending_updates'] = 'true';
        }

        echo "deleteWebhook\n";
        $resp = self::api($token, 'deleteWebhook', $params);

        return self::printResult($resp);
    }

    private static function info(array $flags = []): int
    {
        $token = self::resolveToken($flags);

        if ($token === null) {
            return 1;
        }

        $resp = self::api($token, 'getWebhookInfo', []);

        if ($resp === null || empty($resp['ok'])) {
            return self::printResult($resp);
        }

        $r = $resp['result'];

        echo "url:               " . ($r['url'] ?: '(none — bot is silent)') . "\n";
        echo "allowed_updates:   " . (isset($r['allowed_updates'])
            ? implode(',', $r['allowed_updates'])
            : '(all)') . "\n";
        echo "pending_updates:   " . ($r['pending_update_count'] ?? 0) . "\n";

        if (!empty($r['last_error_message'])) {
            echo "last_error:        {$r['last_error_message']}"
                . " @ " . date('Y-m-d H:i:s', (int)($r['last_error_date'] ?? 0)) . "\n";
        }

        if (empty($r['url'])) {
            echo "\nhint: use `php bin/tgbase webhook:set --url=...` to activate the bot.\n";
        } elseif (isset($r['allowed_updates']) && !in_array('edited_message', $r['allowed_updates'], true)) {
            // Warn ONLY on an explicit list that omits edited_message. When
            // allowed_updates is absent Telegram delivers ALL update types —
            // including edits — so warning there was a false positive.
            echo "\nwarning: allowed_updates does NOT include edited_message —"
                . " onEditedMessage() hooks will never fire.\n";
        }

        return 0;
    }

    // ------------------------------------------------------------------

    /**
     * Resolve the bot token for a subcommand.
     *
     * Precedence: explicit --token flag first, then BOT_TOKEN env (the
     * project .env is loaded by Console before dispatch). On success the
     * source and length are printed — never the value itself. Returns null
     * (after printing a fix-it hint) when no token is available.
     *
     * @param array<string,string|true> $flags
     */
    private static function resolveToken(array $flags): ?string
    {
        $flagToken = trim((string)($flags['token'] ?? ''));

        if ($flagToken !== '') {
            echo 'token: set (' . strlen($flagToken) . " chars, from --token flag)\n";
            return $flagToken;
        }

        $token = trim((string)EnvHandler::get('BOT_TOKEN', ''));

        if ($token !== '') {
            echo 'token: set (' . strlen($token) . " chars, from BOT_TOKEN env)\n";
            return $token;
        }

        echo "BOT_TOKEN is not set — pass --token=… or set BOT_TOKEN in .env.\n";

        return null;
    }

    private static function api(string $token, string $method, array $params): ?array
    {
        $apiUrl = rtrim((string)EnvHandler::get('BOT_API_URL', 'https://api.telegram.org'), '/');
        $ch = curl_init("{$apiUrl}/bot{$token}/{$method}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        // No curl_close() — deprecated since PHP 8.5, no-op since 8.0; the
        // handle is released when $ch goes out of scope (same as Doctor).

        if ($response === false) {
            echo "curl error: {$error}\n";
            return null;
        }

        $decoded = json_decode((string)$response, true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function printResult(?array $resp): int
    {
        if ($resp === null) {
            return 1;
        }

        if (!empty($resp['ok'])) {
            echo "✔ " . json_encode($resp['result'] ?? true, JSON_UNESCAPED_SLASHES) . "\n";
            return 0;
        }

        echo "✘ " . ($resp['description'] ?? 'unknown error') . "\n";
        return 1;
    }

    /** @return array<string,string|true> */
    private static function parseFlags(array $argv): array
    {
        $flags = [];

        foreach ($argv as $arg) {
            if (!str_starts_with($arg, '--')) {
                continue;
            }

            $arg = substr($arg, 2);

            if (str_contains($arg, '=')) {
                [$k, $v] = explode('=', $arg, 2);
                $flags[$k] = $v;
            } else {
                $flags[$arg] = true;
            }
        }

        return $flags;
    }

    private static function help(): void
    {
        echo "Usage:\n";
        echo "  php bin/tgbase webhook:info\n";
        echo "  php bin/tgbase webhook:set --url=https://host/webhook.php [options]\n";
        echo "  php bin/tgbase webhook:del [--drop-pending]\n";
        echo "\nOptions:\n";
        echo "  --url=...            HTTPS webhook URL (required for set)\n";
        echo "  --token=...          bot token (default: BOT_TOKEN env / .env)\n";
        echo "  --secret=...         secret token (default: BOT_WEBHOOK_SECRET env)\n";
        echo "  --updates=a,b,c      allowed_updates (default: " . self::DEFAULT_UPDATES . ")\n";
        echo "  --max-connections=N  default 40\n";
        echo "  --drop-pending       drop pending updates\n";
    }
}
