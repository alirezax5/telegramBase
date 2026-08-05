<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Update;

use alirezax5\TelegramBase\App\Environment\EnvHandler;
use alirezax5\TelegramBase\App\Logger\LogHandler;

/**
 * Validates and dispatches incoming Telegram webhook requests.
 *
 * Enforces Telegram's known IP ranges, an optional secret token, a size
 * limit and valid JSON before handing the update to the processor (sync
 * direct) or the queue manager (async).
 */
final class WebhookDispatcher
{
    /**
     * Telegram Bot API server subnets.
     *
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

    private const MAX_SIZE = 1_048_576; // 1 MB

    private Processor $processor;
    private bool $checkIp;

    public function __construct(Processor $processor)
    {
        $this->processor = $processor;
        $this->checkIp = (bool)EnvHandler::get('WEBHOOK_CHECK_IP', true);
    }

    /**
     * Validate request and dispatch the update.
     */
    public function dispatch(): void
    {
        // --- Size limit ---
        $body = file_get_contents('php://input');

        if ($body === false || $body === '') {
            http_response_code(400);
            exit('empty');
        }

        if (strlen($body) > self::MAX_SIZE) {
            LogHandler::warning('⛔ Webhook payload too large');
            http_response_code(413);
            exit('payload too large');
        }

        // --- IP whitelist ---
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        if ($this->checkIp && !$this->isInTelegramNetwork($ip)) {
            LogHandler::warning("⛔ Webhook from unknown IP: {$ip}");
            http_response_code(403);
            exit('forbidden');
        }

        // --- Secret token ---
        $secret = EnvHandler::get('BOT_WEBHOOK_SECRET', '');

        if ($secret !== '') {
            $given = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';

            if (!hash_equals($secret, $given)) {
                LogHandler::warning('⛔ Webhook secret token mismatch');
                http_response_code(403);
                exit('forbidden');
            }
        }

        // --- Parse JSON ---
        $update = json_decode($body, false);

        if (!is_object($update) || !isset($update->update_id)) {
            http_response_code(400);
            exit('bad payload');
        }

        // --- Route ---
        if (str_ends_with(EnvHandler::get('BOT_MODE', 'webhook_direct'), '_queue')) {
            $this->enqueueUpdate($update);
        } else {
            $this->processor->handle($update);
        }

        // --- Acknowledge immediately ---
        http_response_code(200);
        exit('ok');
    }

    /**
     * Queue an update for async processing.
     */
    private function enqueueUpdate(object $update): void
    {
        try {
            $manager = new \alirezax5\TelegramBase\App\Queue\QueueManager();
            $manager->push(json_decode(json_encode($update), true));
        } catch (\Throwable $e) {
            LogHandler::error('⛔ Failed to enqueue webhook update: ' . $e->getMessage());
            http_response_code(500);
            exit('queue error');
        }
    }

    /**
     * CIDR check — is $ip within any Telegram subnet?
     */
    private function isInTelegramNetwork(string $ip): bool
    {
        if ($ip === '') {
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

        $rangeStart = ($subnetLong >> (32 - $mask)) << (32 - $mask);
        $maskFull = $mask === 0 ? 0 : (0xFFFFFFFF << (32 - $mask)) & 0xFFFFFFFF;

        return ($ipLong & $maskFull) === ($subnetLong & $maskFull);
    }
}