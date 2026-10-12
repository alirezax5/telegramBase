<?php

declare(strict_types=1);

/**
 * Scenario 2 — User ↔ Admin support relay WITH live edit sync (TM-25).
 *
 * Users write to the bot in private; every message is copied into a relay
 * group so admins answer by replying there. The TM-25 second requirement:
 * if the user EDITS their message, the copy in the group must update too
 * — and if an admin edits their group reply, the user's copy must update.
 *
 * The whole feature stands on two framework pieces:
 *
 *   1. PluginHandler routes `edited_message` to onEditedMessage($msg, $tg)
 *      automatically (library 3.3 exposes typed editedMessage() accessors
 *      and chatType() works on edits).
 *   2. The webhook MUST include edited_message in allowed_updates — that is
 *      the default of `php bin/tgbase webhook:set` (see webhook.md). A
 *      webhook registered without it NEVER receives edits.
 *
 * The user↔group id map is persisted as JSON in DATA_DIR (small, atomic
 * writes) — for high-volume bots swap it for a DB table, the shape is the
 * same.
 *
 * ⚠️  To activate:
 *   1. Copy to Plugin/RelayEditSyncBot.php and change the namespace
 *   2. Set RELAY_GROUP_ID="-100xxxxxxxxxx" and DATA_DIR in .env
 *   3. Add the bot to the group, give it delete/edit rights
 *   4. Ensure webhook: `php bin/tgbase webhook:info` shows edited_message
 *
 * Product contract (matches the payam bot): private = relay out; group =
 * REPLY-ONLY admin answers; group messages that are not replies are ignored.
 */

namespace alirezax5\TelegramBase\Examples;

use alirezax5\TelegramBase\App\Attributes\ChatType;
use alirezax5\TelegramBase\App\Environment\EnvHandler;
use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface;
use alirezax5\TelegramBase\App\Update\UpdateState;
use telegramBotApiPhp\Telegram;

class RelayEditSyncBot implements PluginInterface
{
    public function getPriority(): int
    {
        return 10;
    }

    // ------------------------------------------------------------------
    // New messages
    // ------------------------------------------------------------------

    public function onMessage($message, Telegram $tg): void
    {
        $chatType = $message->chat->type ?? '';

        if ($chatType === 'private') {
            $this->relayUserMessage($message, $tg);
            return;
        }

        if (in_array($chatType, ['group', 'supergroup'], true)) {
            $this->handleGroupReply($message, $tg, isEdit: false);
        }
    }

    // ------------------------------------------------------------------
    // Edits — the TM-25 sync
    // ------------------------------------------------------------------

    public function onEditedMessage($message, Telegram $tg): void
    {
        $chatType = $message->chat->type ?? '';

        if ($chatType === 'private') {
            $this->relayUserEdit($message, $tg);
            return;
        }

        if (in_array($chatType, ['group', 'supergroup'], true)) {
            $this->handleGroupReply($message, $tg, isEdit: true);
        }
    }

    // ------------------------------------------------------------------
    // User side (private): copy into the relay group
    // ------------------------------------------------------------------

    private function relayUserMessage($message, Telegram $tg): void
    {
        $groupId = (string)EnvHandler::get('RELAY_GROUP_ID', '');
        if ($groupId === '') {
            return;
        }

        $text = trim((string)($message->text ?? ''));
        if ($text === '' || $text === '/start') {
            return;
        }

        $userId = (int)($message->from->id ?? 0);
        $name = $message->from->first_name ?? 'کاربر';

        $sent = $tg->callRaw('sendMessage', [
            'chat_id' => $groupId,
            'text' => "📩 {$name} ({$userId}):\n{$text}",
        ]);

        if (empty($sent->ok)) {
            return;
        }

        $groupMsgId = (int)$sent->result->message_id;
        $userMsgId = (int)$message->message_id;

        // forward: user_msg_id → group copy id (for user edits)
        self::mapPut((string)$userMsgId, ['group' => $groupMsgId]);
        // reverse: group copy id → {chat, user} (for admin replies/edits)
        self::mapPut('r' . $groupMsgId, [
            'chat' => $userId,
            'user' => $userMsgId,
        ]);

        UpdateState::markHandled('examples.relay.user');
    }

    private function relayUserEdit($message, Telegram $tg): void
    {
        $groupId = (string)EnvHandler::get('RELAY_GROUP_ID', '');
        if ($groupId === '') {
            return;
        }

        $groupMsgId = self::mapGet((string)$message->message_id)['group'] ?? null;
        if ($groupMsgId === null) {
            return; // original was never relayed
        }

        $text = trim((string)($message->text ?? ''));
        if ($text === '') {
            return; // media edits: re-send policy is app-specific
        }

        $userId = (int)($message->from->id ?? 0);
        $name = $message->from->first_name ?? 'کاربر';

        $tg->callRaw('editMessageText', [
            'chat_id' => $groupId,
            'message_id' => $groupMsgId,
            'text' => "📩 {$name} ({$userId}) (ویرایش‌شده):\n{$text}",
        ]);

        UpdateState::markHandled('examples.relay.user_edit');
    }

    // ------------------------------------------------------------------
    // Admin side (group): reply goes back to the user, edits follow
    // ------------------------------------------------------------------

    private function handleGroupReply($message, Telegram $tg, bool $isEdit): void
    {
        $groupChatId = (string)($message->chat->id ?? '');
        $groupId = (string)EnvHandler::get('RELAY_GROUP_ID', '');

        if ($groupId === '' || $groupChatId !== $groupId) {
            return; // some other group — not ours
        }

        $text = trim((string)($message->text ?? ''));
        if ($text === '') {
            return;
        }

        $adminName = $message->from->first_name ?? 'پشتیبانی';

        if (!$isEdit) {
            $replyTo = (int)($message->reply_to_message->message_id ?? 0);
            if ($replyTo === 0) {
                return; // group relay is reply-only (product contract)
            }

            // replied-to copy → which user chat does it belong to?
            $reverse = self::mapGet('r' . $replyTo);
            $userChatId = $reverse['chat'] ?? null;

            if ($userChatId === null) {
                return; // not a relayed copy
            }

            $sent = $tg->callRaw('sendMessage', [
                'chat_id' => $userChatId,
                'text' => "💬 پشتیبانی ({$adminName}):\n{$text}",
            ]);

            if (!empty($sent->ok)) {
                // group_msg_id → user-side copy of THIS admin reply, so a
                // later edit of the group reply edits the user copy too
                self::mapPut('a' . $message->message_id, [
                    'user_chat' => $userChatId,
                    'user_msg' => $sent->result->message_id,
                ]);
                UpdateState::markHandled('examples.relay.admin');
            }

            return;
        }

        // ---- edit path ----
        $groupMsgId = (int)($message->message_id ?? 0);

        // 1) was it an admin reply we already copied to the user?
        $copy = self::mapGet('a' . $groupMsgId);

        if (($copy['user_chat'] ?? null) !== null) {
            $tg->callRaw('editMessageText', [
                'chat_id' => $copy['user_chat'],
                'message_id' => $copy['user_msg'],
                'text' => "💬 پشتیبانی ({$adminName}) (ویرایش‌شده):\n{$text}",
            ]);
            UpdateState::markHandled('examples.relay.admin_edit');
            return;
        }

        // 2) otherwise the edited message IS a relayed user copy — but its
        //    owner edits from private, which is handled by relayUserEdit();
        //    nothing else to do here.
    }

    // ------------------------------------------------------------------
    // Tiny JSON id-map store (DATA_DIR/relay_map.json)
    // ------------------------------------------------------------------

    private static function mapFile(): string
    {
        $dir = (string)EnvHandler::get('DATA_DIR', sys_get_temp_dir());
        return rtrim($dir, '/') . '/relay_map.json';
    }

    private static function mapGet(string $key): ?array
    {
        if (!is_file(self::mapFile())) {
            return null;
        }

        $all = json_decode((string)@file_get_contents(self::mapFile()), true);
        return is_array($all) ? ($all[$key] ?? null) : null;
    }

    private static function mapPut(string $key, array $value): void
    {
        $file = self::mapFile();
        $all = [];

        if (is_file($file)) {
            $decoded = json_decode((string)@file_get_contents($file), true);
            if (is_array($decoded)) {
                $all = $decoded;
            }
        }

        $all[$key] = $value;

        // keep the map bounded — swap for a DB table on high-volume bots
        if (count($all) > 5000) {
            $all = array_slice($all, -5000, null, true);
        }

        @file_put_contents($file, json_encode($all, JSON_UNESCAPED_UNICODE), LOCK_EX);

        LogHandler::debug("relay map put: {$key}");
    }
}
