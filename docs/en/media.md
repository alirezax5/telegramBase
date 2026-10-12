# Media — download & send files

Practical file handling for non-text bots: save what users send, and send
local files back. The `App\Shared\Media` helper wraps the two primitives
every media bot ends up hand-rolling (getFile + HTTP fetch + CURLFile).

## Downloading a file (IN)

```php
use alirezax5\TelegramBase\App\Shared\Media;

// $message->photo is ordered small→large — take the largest
$best = end($message->photo);
$path = Media::download($tg, (string) $best->file_id);

if ($path === null) {
    // failure is already logged — decide your fallback
}
```

What happens inside:

1. `getFile` asks Telegram for a `file_path`.
2. The file URL is built with the library's `getFileUrl()` (respects
   `BOT_API_URL_FILE` / local Bot API servers).
3. The bytes are streamed to the destination directory.

### Destination directory

Default `{base}/storage/downloads` (created on demand). Override:

- per call: `Media::download($tg, $fileId, '/var/data/inbox')`
- globally: `MEDIA_DOWNLOAD_DIR=/var/data/inbox` in `.env`

Filenames follow Telegram's basename; when the target exists and no
explicit name was given, a `f_<uniq>_` prefix avoids silent overwrites.
Pass your own name as the 4th argument for full control.

## Sending a file (OUT)

`Media::send()` accepts **either** a Telegram `file_id` (no disk round-trip
— Telegram re-serves it) **or** an absolute local path (wrapped in
`CURLFile` automatically):

```php
// by file_id
Media::send($tg, 'photo', $chatId, $fileId, ['caption' => '🔁 همانی که فرستادی']);

// by local path
Media::send($tg, 'document', $chatId, '/tmp/report.pdf', ['caption' => 'گزارش']);
```

Supported types: `photo`, `video`, `audio`, `voice`, `video_note`,
`animation`, `document`, `sticker`. Unknown types throw — fail fast, not
silently. The method returns the sent `Message` object, or `null` on
failure (already logged).

## Edits and media

Telegram does not allow editing stickers / video notes / voices (and raw
forwards). If your relay needs live edit sync, remember: when calling
`editMessageText()` / `editMessageCaption()`, pass the original
`reply_markup` back — an edit **without** `reply_markup` strips the
message's inline keyboard (see [buttons.md](buttons.md)).

## Working example

See [examples/MediaFiles.php](../../examples/MediaFiles.php) — download the
largest photo size, save it, then echo it back both by file_id and as a
generated local document.
