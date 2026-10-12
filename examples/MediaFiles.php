<?php

declare(strict_types=1);

/**
 * Scenario 5 — Media in / out (Media helper).
 *
 * Non-text bots (logo makers, converters, receipts…) all need the same two
 * primitives that used to be hand-rolled:
 *
 *   IN : download($tg, $fileId)  → local path  (getFile + HTTP fetch + save)
 *   OUT: send($tg, $type, ...)   → file_id OR local path (CURLFile wrapped)
 *
 * ⚠️  To activate:
 *   1. Copy to Plugin/MediaDemo.php and change the namespace
 *   2. Restart; make sure storage/downloads is writable by the web user
 *   3. Send /media or a photo in private
 *
 * Demonstrates:
 *   - Media::download() — biggest photo size saved to storage/downloads
 *   - Media::send()     — echo the photo back (by file_id, no disk round-trip)
 *   - Media::send()     — send a local file as document
 */

namespace alirezax5\TelegramBase\Examples;

use alirezax5\TelegramBase\App\Attributes\ChatType;
use alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface;
use alirezax5\TelegramBase\App\Shared\Media;
use alirezax5\TelegramBase\App\Update\UpdateState;
use telegramBotApiPhp\Telegram;

class MediaDemo implements PluginInterface
{
    public function getPriority(): int
    {
        return 10;
    }

    #[ChatType(['private'])]
    public function onMessage($message, Telegram $tg): void
    {
        $chatId = $tg->chatId();
        $text = trim((string)($message->text ?? ''));

        // ---- OUT #1: send a LOCAL file as document ----
        if ($text === '/media') {
            $local = sys_get_temp_dir() . '/hello_media.txt';
            file_put_contents($local, "ساخته‌شده در " . date('Y-m-d H:i:s'));

            $sent = Media::send($tg, 'document', $chatId, $local, [
                'caption' => '📄 فایل ساخته‌شده روی سرور',
            ]);

            if ($sent !== null) {
                UpdateState::markHandled();
            }

            return;
        }

        // ---- IN + OUT #2: download the photo, then re-send by file_id ----
        $photos = $message->photo ?? null;

        if (is_array($photos) && $photos !== []) {
            // photo sizes are ordered small→large; take the largest
            $best = end($photos);
            $fileId = (string)($best->file_id ?? '');

            $path = Media::download($tg, $fileId);

            if ($path === null) {
                $tg->callRaw('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => '❌ دانلود فایل ناموفق بود (لاگ را ببین).',
                ]);
                return;
            }

            // do something with the local file (resize, watermark, save to DB…)
            $size = filesize($path);

            $tg->callRaw('sendMessage', [
                'chat_id' => $chatId,
                'text' => "✅ ذخیره شد:\n{$path}\nحجم: {$size} بایت",
            ]);

            // OUT by file_id — Telegram re-serves it; no disk round-trip
            Media::send($tg, 'photo', $chatId, $fileId, ['caption' => '🔁 همانی که فرستادی']);

            UpdateState::markHandled();
        }
    }
}
