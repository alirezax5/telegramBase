<?php
/**
 * mb_router_queue.php — PROPOSAL (queue mode router, v2)
 * Multi-bot on ONE webhook file: validate secret -> enqueue -> 200 fast.
 *
 * Proven basis (2026-10-11, live traffic):
 *   - secret_token routing: 7/7 correct identity (see NOTES-2026-10-11.md)
 *   - webhook must ONLY validate + enqueue + 200 (<50 ms). Inline processing
 *     inside the webhook was killed by nginx (499) after ~60-90 s.
 *
 * Queue backends (auto-detected, first available wins):
 *   1. Redis Streams   (ext-redis)  -> stream "tg:updates", consumer group "mbg"
 *      At-least-once: message stays in the PEL until the worker XACKs it.
 *   2. Atomic file queue (always available)
 *      data/mb_queue/b{bot_id}/new -> processing -> done|failed (rename = atomic)
 *
 * Rules encoded here:
 *   - unknown secret            -> 403 (hash_equals, timing-safe)
 *   - body > 1 MiB              -> 413 (Telegram webhook limit is 1 MB)
 *   - authenticated Telegram    -> always 200 {"ok":true} (5xx makes Telegram retry)
 *   - NO token is ever logged   -> log carries secret prefix only
 *
 * Worker companion: mb_worker.php (same folder).
 */

header('Content-Type: application/json; charset=utf-8');

// ---------------------------------------------------------------------------
// 1) Registry: secret_token -> bot identity.  Keep OUT of git (or read .env).
//    Secret rules: unique per bot, 32+ chars [A-Za-z0-9_-], rotation = setWebhook.
// ---------------------------------------------------------------------------
$REGISTRY = [
    // 'PUT_RANDOM_SECRET_A' => ['id' => 7343754237, 'name' => 'main'],
    // 'PUT_RANDOM_SECRET_B' => ['id' => 398172019,  'name' => 'second'],
];

// Generate:  php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"   (48 hex chars)
const STREAM_NAME   = 'tg:updates';
const GROUP_NAME    = 'mbg';
const MAX_BODY      = 1048576;          // Telegram webhook hard limit: 1 MB
const BASE_DIR      = __DIR__ . '/data';

function respond(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function mblog(array $row): void {
    @file_put_contents(__DIR__ . '/mb_router_log.jsonl',
        json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
}

/** @var \Redis|null */
function redis(): ?object {
    if (!class_exists('Redis')) return null;               // ext-redis missing
    try {
        $r = new \Redis();
        $host = getenv('MB_REDIS_HOST') ?: '127.0.0.1';
        $port = (int)(getenv('MB_REDIS_PORT') ?: 6379);
        if (!$r->connect($host, $port, 1.5)) return null;
        $pw = getenv('MB_REDIS_PASS');
        if ($pw) $r->auth($pw);
        return $r;
    } catch (\Throwable $e) {
        return null;                                       // fall back to files
    }
}

function enqueueFile(int $botId, string $updateId, string $raw): bool {
    $dir = BASE_DIR . "/mb_queue/b{$botId}/new";
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return false;
    $file = sprintf('%s/%d_%s.json', $dir, time(), bin2hex(random_bytes(6)));
    $tmp  = $file . '.part';
    if (@file_put_contents($tmp, $raw) === false) return false;
    return @rename($tmp, $file);                           // atomic
}

function enqueueRedis(object $r, int $botId, string $updateId, string $raw): bool {
    // Idempotency: SETNX per (bot, update_id). Webhook retries become no-ops.
    $key = "seen:b{$botId}:{$updateId}";
    if (!$r->set($key, '1', ['nx', 'ex' => 86400])) return true;   // dup -> swallowed
    $r->xAdd(STREAM_NAME, '*', [
        'bot_id'    => (string)$botId,
        'update_id' => $updateId,
        'payload'   => $raw,
        'src'       => 'webhook',
    ]);
    return true;
}

// ---------------------------------------------------------------------------
// 2) Ingress
// ---------------------------------------------------------------------------
$secret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
$bot    = null;
foreach ($REGISTRY as $s => $cfg) {
    if (hash_equals($s, $secret)) { $bot = $cfg; break; }
}
if ($bot === null) {
    mblog(['event' => 'reject', 'why' => 'unknown_secret', 'sp' => substr($secret, 0, 8)]);
    respond(403, ['ok' => false, 'error' => 'forbidden']);
}

$raw = file_get_contents('php://input');
if (strlen($raw) > MAX_BODY) {
    mblog(['event' => 'reject', 'why' => 'too_large', 'len' => strlen($raw)]);
    respond(413, ['ok' => false, 'error' => 'payload too large']);
}

$upd = json_decode($raw, true);
if (!is_array($upd)) {
    // Authenticated but unusable: ACK 200 so Telegram does not retry forever.
    mblog(['event' => 'drop', 'why' => 'bad_json', 'bot' => $bot['id']]);
    respond(200, ['ok' => true]);
}

$type = 'unknown';
foreach (['message','edited_message','channel_post','callback_query','inline_query',
          'managed_bot','guest_message','chat_join_request','business_message'] as $t) {
    if (isset($upd[$t])) { $type = $t; break; }
}
$updateId = (string)($upd['update_id'] ?? 0);

// ---------------------------------------------------------------------------
// 3) Enqueue (Redis Streams preferred, atomic files as fallback), then 200 FAST.
//    No bot token is used or logged here; the worker resolves tokens by bot_id.
// ---------------------------------------------------------------------------
$r = redis();
$ok = $r ? enqueueRedis($r, (int)$bot['id'], $updateId, $raw)
         : enqueueFile((int)$bot['id'], $updateId, $raw);

mblog([
    'event' => 'in', 'bot' => $bot['id'], 'name' => $bot['name'],
    'type' => $type, 'upd' => $updateId, 'len' => strlen($raw),
    'q' => $r ? 'redis-stream' : 'file', 'enqueued' => $ok,
]);

// Always 200 to authenticated Telegram traffic (even if enqueue hiccuped —
// the update_id is in the log for replay; 5xx would trigger Telegram retries).
respond(200, ['ok' => true]);
