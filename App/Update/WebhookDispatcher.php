<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Update;

use alirezax5\TelegramBase\App\Bootstrap\QueueBootstrap;
use alirezax5\TelegramBase\App\Environment\EnvHandler;
use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Queue\QueueManager;

/**
 * Validates and dispatches incoming Telegram webhook requests.
 *
 * Enforces Telegram's known IP ranges, an optional secret token, a size
 * limit and valid JSON before handing the update to the processor (sync
 * direct) or the queue manager (async).
 *
 * The dispatcher is split into two layers:
 *   - dispatch()    : top-level entry point, calls dispatchIntoResponse()
 *                     and exits (the original behaviour, used by webhook.php)
 *   - dispatchIntoResponse() : performs all the validation/routing and
 *                     returns a WebhookResponse DTO. Does NOT exit.
 *
 * The split lets unit tests exercise every code path (IP reject, secret
 * mismatch, oversize, bad JSON, queue vs direct) without `exit()` getting
 * in the way.
 */
final class WebhookDispatcher
{
    /**
     * Telegram Bot API server subnets.
     *
     * Source: official Telegram docs (https://core.telegram.org/bots/webhooks)
     * @var string[]
     */
    private const TELEGRAM_RANGES = [
        '149.154.160.0/20',
        '91.108.4.0/22',
        '91.108.8.0/22',
        '91.108.56.0/22',
        '91.108.16.0/22',
        '95.161.64.0/20',
    ];

    private const MAX_SIZE = 1_048_576; // 1 MB — Telegram never sends larger payloads

    private Processor $processor;
    private bool $checkIp;

    public function __construct(Processor $processor)
    {
        $this->processor = $processor;
        $this->checkIp = EnvHandler::bool('WEBHOOK_CHECK_IP', true);
    }

    /**
     * Top-level entry: validate the incoming request, dispatch, send HTTP
     * status, and terminate. This is what webhook.php calls.
     *
     * For tests, prefer dispatchIntoResponse() which returns a DTO instead
     * of exiting.
     */
    public function dispatch(): void
    {
        $response = $this->dispatchIntoResponse(
            body: file_get_contents('php://input') ?: '',
            remoteIp: $_SERVER['REMOTE_ADDR'] ?? '',
            secretHeader: $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '',
            botMode: EnvHandler::get('BOT_MODE', 'webhook_direct')
        );

        http_response_code($response->status);
        echo $response->body;
        exit($response->exitCode);
    }

    /**
     * Validate and route an incoming webhook payload without side effects
     * on HTTP status or process exit. Used by dispatch() and by tests.
     *
     * @param string $body         Raw request body
     * @param string $remoteIp     Client IP (REMOTE_ADDR)
     * @param string $secretHeader X-Telegram-Bot-Api-Secret-Token header
     * @param string $botMode      Current BOT_MODE env value
     */
    public function dispatchIntoResponse(
        string $body,
        string $remoteIp,
        string $secretHeader,
        string $botMode = 'webhook_direct',
    ): WebhookResponse {
        // --- Size limit ---
        if ($body === '') {
            return new WebhookResponse(400, 'empty', 0);
        }

        if (strlen($body) > self::MAX_SIZE) {
            LogHandler::warning('⛔ Webhook payload too large');
            return new WebhookResponse(413, 'payload too large', 0);
        }

        // --- IP whitelist ---
        if ($this->checkIp && !$this->isInTelegramNetwork($remoteIp)) {
            // Actionable on purpose: getWebhookInfo only ever reports
            // "Wrong response from the webhook: 403 Forbidden" — this line is
            // the ONLY place that names the fix (real production outage,
            // 2026-10-12: a proxied install lost every real delivery to this
            // exact check and the bare warning gave no hint).
            LogHandler::warning("⛔ Webhook from unknown IP: {$remoteIp} — if this bot runs behind Cloudflare/CDN/reverse proxy, set WEBHOOK_CHECK_IP=false in .env and rely on BOT_WEBHOOK_SECRET (verify with getWebhookInfo)");
            return new WebhookResponse(403, 'forbidden', 0);
        }

        // --- Secret token ---
        $secret = EnvHandler::get('BOT_WEBHOOK_SECRET', '');

        if ($secret !== '') {
            if (!hash_equals($secret, $secretHeader)) {
                // Actionable on purpose: the sneakiest failure mode is SECRET
                // DRIFT — .env unchanged and correct, but a later setWebhook
                // call (e.g. to add an allowed_updates type) went out without
                // the original secret_token. Telegram then gets 403 on every
                // real delivery while code and .env both look perfect.
                LogHandler::warning('⛔ Webhook secret token mismatch — the secret_token registered via setWebhook() differs from BOT_WEBHOOK_SECRET in .env; re-run setWebhook() with the exact .env value (every future setWebhook call must carry secret_token=… — Telegram sees 403 Forbidden until they match)');
                return new WebhookResponse(403, 'forbidden', 0);
            }
        }

        // --- Parse JSON ---
        $update = json_decode($body, false);

        if (!is_object($update) || !isset($update->update_id)) {
            return new WebhookResponse(400, 'bad payload', 0);
        }

        // --- Route ---
        if (str_ends_with($botMode, '_queue')) {
            try {
                $this->enqueueUpdate($update);
            } catch (\Throwable $e) {
                LogHandler::error('⛔ Failed to enqueue webhook update: ' . $e->getMessage());
                return new WebhookResponse(500, 'queue error', 1);
            }
        } else {
            $this->processor->handle($update);
        }

        return new WebhookResponse(200, 'ok', 0);
    }

    /**
     * Queue an update for async processing via the shared QueueBootstrap
     * singleton (was previously constructing a fresh QueueManager —
     * that re-read config and bypassed the shared connection state).
     */
    private function enqueueUpdate(object $update): void
    {
        $queue = QueueBootstrap::getQueue();

        if ($queue instanceof QueueManager) {
            $queue->push(json_decode(json_encode($update), true));
            return;
        }

        // Fallback: build a fresh manager if bootstrap didn't (e.g.
        // BOT_MODE was changed at runtime). This preserves the original
        // behaviour without making it the common path.
        (new QueueManager(\alirezax5\TelegramBase\App\Config\Config::queue()))
            ->push(json_decode(json_encode($update), true));
    }

    /**
     * CIDR check — is $ip within any Telegram subnet?
     */
    public function isInTelegramNetwork(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }

        // Reject obviously malformed IPs early — ip2long returns false
        // for them but we want a clear boundary.
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        foreach (self::TELEGRAM_RANGES as $range) {
            if ($this->ipInRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    private function ipInRange(string $ip, string $cidr): bool
    {
        [$subnet, $maskBits] = array_pad(explode('/', $cidr, 2), 2, '32');
        $mask = (int)$maskBits;

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        // Force unsigned semantics — ip2long can return negatives on 32-bit PHP.
        $ipLong = $ipLong & 0xFFFFFFFF;
        $subnetLong = $subnetLong & 0xFFFFFFFF;

        $maskFull = $mask === 0 ? 0 : (0xFFFFFFFF << (32 - $mask)) & 0xFFFFFFFF;

        return ($ipLong & $maskFull) === ($subnetLong & $maskFull);
    }
}
