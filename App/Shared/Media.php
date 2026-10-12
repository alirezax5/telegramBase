<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Shared;

use alirezax5\TelegramBase\App\Environment\EnvHandler;
use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Paths;
use CURLFile;
use telegramBotApiPhp\Telegram;

/**
 * Media helper — download Telegram files to disk and send local files back.
 *
 * The library already accepts local paths / CURLFile in send* methods and
 * exposes getFile() + getFileUrl(). This helper wraps the two flows every
 * non-text bot ends up hand-rolling:
 *
 *   1. IN  — download($tg, $fileId)           → local absolute path (or null)
 *   2. OUT — send($tg, 'photo', $chatId, ...) → file_id OR local path, both OK
 *
 * Examples:
 *
 *   // IN: save a photo a user sent
 *   $path = Media::download($tg, $message->photo[2]->file_id);
 *   if ($path !== null) { DB::save('avatar', $path); }
 *
 *   // OUT: send a generated file from disk
 *   Media::send($tg, 'document', $chatId, '/tmp/report.pdf', ['caption' => 'گزارش']);
 *
 *   // OUT: re-send by Telegram file_id (no disk round-trip)
 *   Media::send($tg, 'photo', $chatId, $fileId);
 *
 * Destination directory defaults to {base}/storage/downloads (created on
 * demand, 0775) and can be overridden per call or via MEDIA_DOWNLOAD_DIR.
 */
final class Media
{
    /** Map helper type → library send method + parameter name. */
    private const TYPES = [
        'photo'     => ['sendPhoto', 'photo'],
        'video'     => ['sendVideo', 'video'],
        'audio'     => ['sendAudio', 'audio'],
        'voice'     => ['sendVoice', 'voice'],
        'video_note'=> ['sendVideoNote', 'video_note'],
        'animation' => ['sendAnimation', 'animation'],
        'document'  => ['sendDocument', 'document'],
        'sticker'   => ['sendSticker', 'sticker'],
    ];

    /**
     * Download a file by file_id to a local directory.
     *
     * @param Telegram    $tg      Bot API instance owning the file
     * @param string      $fileId  Telegram file_id
     * @param string|null $destDir Directory (default {base}/storage/downloads)
     * @param string|null $destName Target filename (default: Telegram's basename)
     * @return string|null Absolute local path, or null on failure (logged)
     */
    public static function download(
        Telegram $tg,
        string $fileId,
        ?string $destDir = null,
        ?string $destName = null
    ): ?string {
        if ($fileId === '') {
            return null;
        }

        // returnedFile shape: {ok, error_code, description, result: File}
        $resp = $tg->getFile($fileId);

        $filePath = $resp->result->file_path ?? null;
        if (!is_string($filePath) || $filePath === '') {
            LogHandler::error("Media::download — getFile returned no file_path for {$fileId}");
            return null;
        }

        $dir = $destDir
            ?? (string)EnvHandler::get('MEDIA_DOWNLOAD_DIR', '')
            ?: Paths::base() . '/storage/downloads';

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            LogHandler::error("Media::download — cannot create dir: {$dir}");
            return null;
        }

        $name = $destName ?? basename(parse_url($filePath, PHP_URL_PATH) ?: $filePath);
        $target = rtrim($dir, '/') . '/' . $name;

        // Distinct filenames: Telegram reuses "file_N" basenames per media
        // group — prefix with a short uniq when the target already exists
        // and no explicit name was requested.
        if ($destName === null && is_file($target)) {
            $target = rtrim($dir, '/') . '/' . uniqid('f_') . '_' . $name;
        }

        $url = $tg->getFileUrl($filePath);
        $bytes = @file_get_contents($url);

        if ($bytes === false) {
            LogHandler::error("Media::download — fetch failed: {$url}");
            return null;
        }

        if (@file_put_contents($target, $bytes) === false) {
            LogHandler::error("Media::download — cannot write: {$target}");
            return null;
        }

        LogHandler::info("Media::download — saved " . strlen($bytes) . "B → {$target}");

        return $target;
    }

    /**
     * Send a media file — accepts a Telegram file_id OR an absolute local
     * path (wrapped in CURLFile automatically).
     *
     * @param Telegram            $tg      Bot API instance
     * @param string              $type    photo|video|audio|voice|video_note|animation|document|sticker
     * @param int|string          $chatId  Target chat
     * @param string              $source  file_id or local path
     * @param array<string,mixed> $opts    Extra params (caption, parse_mode, reply_markup, ...)
     * @return object|null The sent Message object (result), or null on failure
     */
    public static function send(
        Telegram $tg,
        string $type,
        int|string $chatId,
        string $source,
        array $opts = []
    ): ?object {
        [$method, $param] = self::TYPES[$type]
            ?? throw new \InvalidArgumentException("Media::send — unknown type '{$type}'");

        $payload = array_merge(['chat_id' => $chatId], $opts);
        $payload[$param] = is_file($source) ? new CURLFile($source) : $source;

        $resp = $tg->callRaw($method, $payload);

        if (empty($resp->ok)) {
            $desc = $resp->description ?? ($resp->error ?? 'unknown error');
            LogHandler::error("Media::send — {$method} failed: {$desc}");
            return null;
        }

        return $resp->result ?? null;
    }

    /**
     * True when the given source is an existing LOCAL file (as opposed to
     * a Telegram file_id) — handy for logging/branching before send().
     */
    public static function isLocal(string $source): bool
    {
        return is_file($source);
    }
}
