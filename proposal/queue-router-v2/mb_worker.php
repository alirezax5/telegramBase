<?php
/**
 * mb_worker.php — PROPOSAL (queue worker, companion of mb_router_queue.php)
 *
 * Consumes the SAME queue the router fills, with AT-LEAST-ONCE semantics:
 *   - Redis Streams: XREADGROUP (PEL holds deliveries until XACK AFTER processing)
 *                    + XAUTOCLAIM reclaims entries from dead workers (min-idle 60s)
 *                    + 3 failed attempts -> dead stream (DLQ)
 *   - File queue:    new -> processing (atomic rename) -> done | failed (DLQ)
 *
 * Idempotency: per-bot monotonic update_id check (Telegram update_id is
 * monotonic per bot) -> retried/redelivered duplicates are skipped.
 *
 * Usage:
 *   php mb_worker.php            # all bots
 *   php mb_worker.php 7343754237 # one bot (file mode filter)
 * Run under supervisord/systemd/BT process manager. Graceful: SIGINT/SIGTERM
 * finish the current update, then exit 0.
 *
 * Integration point: handle_update() is the single place where your bot logic
 * (or a telegramBase plugin dispatch) plugs in. Tokens are resolved by bot_id
 * from a registry/DB — never stored in the queue payload.
 */

declare(strict_types=1);

const STREAM_NAME = 'tg:updates';
const GROUP_NAME  = 'mbg';
const BASE_DIR    = __DIR__ . '/data';
const MAX_ATTEMPT = 3;

// ---------------------------------------------------------------------------
// Bot registry (id -> token). PRODUCTION: read from DB/encrypted store instead.
// ---------------------------------------------------------------------------
const BOTS = [
    // 7343754237 => ['token' => 'PASTE_TOKEN_A', 'name' => 'main'],
    // 398172019  => ['token' => 'PASTE_TOKEN_B', 'name' => 'second'],
];

function api(string $token, string $method, array $params = []): array {
    $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($params, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $res = curl_exec($ch);
    $dec = json_decode((string)$res, true);
    curl_close($ch);
    return is_array($dec) ? $dec : ['ok' => false, 'description' => 'curl_error'];
}

function wlog(string $event, array $row = []): void {
    $row['ts'] = date('c'); $row['event'] = $event;
    echo json_encode($row, JSON_UNESCAPED_UNICODE), "\n";
}

/** Idempotency guard: true = new (process), false = duplicate (skip). */
function isNew(int $botId, string $updateId, ?object $r): bool {
    if ($r) {
        return (bool)$r->set("done:b{$botId}:{$updateId}", '1', ['nx', 'ex' => 172800]);
    }
    $f = BASE_DIR . "/mb_state/last_b{$botId}.json";
    @mkdir(dirname($f), 0775, true);
    $st = is_file($f) ? json_decode((string)file_get_contents($f), true) : ['last' => 0];
    if ((int)$updateId <= (int)($st['last'] ?? 0)) return false;   // monotonic guard
    file_put_contents($f, json_encode(['last' => (int)$updateId]));
    return true;
}

// ---------------------------------------------------------------------------
// >>> YOUR BOT LOGIC PLUGS IN HERE (single integration point) <<<
// Example: echo /start; reply to callback queries. Replace with framework
// dispatch (TelegramBase plugin pipeline) or any handler you like.
// ---------------------------------------------------------------------------
function handle_update(array $update, array $bot): void {
    $token = $bot['token'];
    if (isset($update['message']['text'])) {
        $chatId = $update['message']['chat']['id'];
        $text   = $update['message']['text'];
        if ($text === '/start') {
            api($token, 'sendMessage', [
                'chat_id' => $chatId,
                'text'    => "queued-router online — identity: {$bot['name']} (bot {$bot['id']})",
            ]);
        }
        // TODO: framework integration — build Core/CoreMode and dispatch $update
    }
    if (isset($update['callback_query'])) {
        $cq = $update['callback_query'];
        api($token, 'answerCallbackQuery', ['callback_query_id' => $cq['id']]);
    }
    // managed_bot updates: extract $update['managed_bot']['bot']['id'] ->
    // getManagedBotToken(bot.id) -> register child with zero-on-disk token.
}

// ---------------------------------------------------------------------------
// Redis Streams mode
// ---------------------------------------------------------------------------
function runRedis(object $r, bool $running): void {
    try { $r->xGroup('CREATE', STREAM_NAME, GROUP_NAME, '0', true); } // MKSTREAM
    catch (\Throwable $e) { /* BUSYGROUP: fine */ }

    $consumer = 'w-' . gethostname() . '-' . getmypid();
    $idleMs   = 60000;
    wlog('worker_start', ['mode' => 'redis-stream', 'consumer' => $consumer]);

    $tick = 0;
    while ($running) {
        // Every ~30 s: reclaim deliveries abandoned by dead workers.
        if ($tick++ % 30 === 0) {
            $claimed = $r->xAutoClaim(STREAM_NAME, GROUP_NAME, $consumer, $idleMs, '0', 10);
            foreach ((array)($claimed[1] ?? []) as $entry) {
                processEntry($r, (string)$entry[0], (array)($entry[1] ?? []), $entry[2] ?? 1);
            }
        }
        $rows = $r->xReadGroup(GROUP_NAME, $consumer, [STREAM_NAME => '>'], 10, 5.0);
        foreach ((array)$rows as $stream => $entries) {
            foreach ($entries as $id => $fields) processEntry($r, (string)$id, $fields);
        }
        if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();
    }
    wlog('worker_stop');
}

function processEntry(object $r, string $id, array $fields, int $attempts = 0): void {
    $botId    = (int)($fields['bot_id'] ?? 0);
    $updateId = (string)($fields['update_id'] ?? '');
    $payload  = (string)($fields['payload'] ?? '');
    $upd = json_decode($payload, true);

    if (!isset(BOTS[$botId])) {
        wlog('skip_unknown_bot', ['bot' => $botId, 'id' => $id]);
        $r->xAck(STREAM_NAME, GROUP_NAME, [$id]);          // poison, don't loop
        return;
    }
    try {
        if (is_array($upd) && isNew($botId, $updateId, $r)) {
            handle_update($upd, BOTS[$botId] + ['id' => $botId]);
        }
        $r->xAck(STREAM_NAME, GROUP_NAME, [$id]);          // ACK AFTER processing
    } catch (\Throwable $e) {
        wlog('fail', ['bot' => $botId, 'upd' => $updateId, 'try' => $attempts + 1,
                      'err' => mb_substr($e->getMessage(), 0, 160)]);
        if ($attempts + 1 >= MAX_ATTEMPT) {
            $r->xAdd('tg:updates:dead', '*', $fields + ['error' => $e->getMessage()]);
            $r->xAck(STREAM_NAME, GROUP_NAME, [$id]);       // DLQ
            wlog('to_dlq', ['bot' => $botId, 'upd' => $updateId]);
        }
        // else: NOT acked -> XAUTOCLAIM redelivers after idle window.
    }
}

// ---------------------------------------------------------------------------
// File queue mode (atomic renames; same at-least-once + DLQ semantics)
// ---------------------------------------------------------------------------
function runFiles(array $onlyBots, bool $running): void {
    wlog('worker_start', ['mode' => 'file', 'bots' => $onlyBots ?: 'all']);
    $bots = $onlyBots ?: array_map(fn($id) => (int)$id, array_keys(BOTS));
    while ($running) {
        foreach ($bots as $botId) {
            if (!isset(BOTS[$botId])) continue;
            $new = BASE_DIR . "/mb_queue/b{$botId}/new";
            $prc = BASE_DIR . "/mb_queue/b{$botId}/processing";
            $dne = BASE_DIR . "/mb_queue/b{$botId}/done";
            $fal = BASE_DIR . "/mb_queue/b{$botId}/failed";
            foreach ([$prc, $dne, $fal] as $d) if (!is_dir($d)) @mkdir($d, 0775, true);
            foreach (glob("$new/*.json") ?: [] as $f) {
                if (!$running) break 2;
                $t = "$prc/" . basename($f);
                if (!@rename($f, $t)) continue;            // atomic claim
                $meta = pathinfo($t) + ['attempts' => 0];
                try {
                    $upd = json_decode((string)file_get_contents($t), true);
                    if (is_array($upd) && isNew($botId, (string)$upd['update_id'], null)) {
                        handle_update($upd, BOTS[$botId] + ['id' => $botId]);
                    }
                    @rename($t, "$dne/" . basename($t));
                } catch (\Throwable $e) {
                    $n = (int)file_get_contents("$t.att") ?: 0; @unlink("$t.att");
                    $meta['attempts'] = $n + 1;
                    file_put_contents("$t.att", (string)($n + 1));
                    wlog('fail', ['bot' => $botId, 'file' => basename($t),
                                  'try' => $n + 1, 'err' => mb_substr($e->getMessage(), 0, 160)]);
                    if ($n + 1 >= MAX_ATTEMPT) { @rename($t, "$fal/" . basename($t)); wlog('to_dlq', ['file' => basename($t)]); }
                    else { @rename($t, $f); }               // retry later from new/
                }
            }
            // Retention: drop done/ older than 24h.
            foreach (glob("$dne/*.json") ?: [] as $old)
                if (filemtime($old) < time() - 86400) @unlink($old);
        }
        if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();
        usleep(300000);
    }
    wlog('worker_stop');
}

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
$running = true;
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT,  function () use (&$running) { $running = false; });
    pcntl_signal(SIGTERM, function () use (&$running) { $running = false; });
}

$only = array_map('intval', array_slice($argv, 1));
$r = null;
if (class_exists('Redis')) {
    try {
        $r = new \Redis();
        $r->connect(getenv('MB_REDIS_HOST') ?: '127.0.0.1', (int)(getenv('MB_REDIS_PORT') ?: 6379), 1.5);
        $pw = getenv('MB_REDIS_PASS'); if ($pw) $r->auth($pw);
    } catch (\Throwable $e) { $r = null; }
}

$r ? runRedis($r, $running) : runFiles($only, $running);
