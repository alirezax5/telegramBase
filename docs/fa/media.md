# رسانه — دانلود و ارسال فایل

مدیریت عملی فایل برای ربات‌های غیرمتنی: ذخیرهٔ آنچه کاربر می‌فرستد و ارسال
فایل محلی به او. هلپر `App\Shared\Media` دو مقدمه‌ای را که هر ربات رسانه‌ای
دست‌ساز می‌نویسد (getFile + دریافت HTTP + CURLFile) یک‌جا بسته‌بندی می‌کند.

## دانلود فایل (ورودی)

```php
use alirezax5\TelegramBase\App\Shared\Media;

// آرایهٔ photo از کوچک به بزرگ است — بزرگ‌ترین را بردار
$best = end($message->photo);
$path = Media::download($tg, (string) $best->file_id);

if ($path === null) {
    // خطا قبلاً لاگ شده — تصمیم fallback با شماست
}
```

درون چه می‌گذرد:

1. `getFile` از تلگرام `file_path` می‌گیرد.
2. URL فایل با `getFileUrl()` کتابخانه ساخته می‌شود (سرور Local Bot API و
   `BOT_API_URL_FILE` را رعایت می‌کند).
3. بایت‌ها به پوشهٔ مقاله استریم می‌شوند.

### پوشهٔ مقصد

پیش‌فرض `{base}/storage/downloads` (در صورت نیاز ساخته می‌شود). تغییرش:

- در هر فراخوانی: `Media::download($tg, $fileId, '/var/data/inbox')`
- سراسری: `MEDIA_DOWNLOAD_DIR=/var/data/inbox` در `.env`

نام فایل از basename تلگرام می‌آید؛ اگر مقصد وجود داشته باشد و نام صریح
نداده باشید پیشوند `f_<uniq>_` مانع overwrite بی‌صدا می‌شود. برای کنترل
کامل، نام دلخواه را آرگومان چهارم بدهید.

## ارسال فایل (خروجی)

`Media::send()` هم `file_id` تلگرام را قبول می‌کند (بدون رفت‌وبرگشت دیسک —
تلگرام خودش سرو می‌کند) و هم مسیر مطلق فایل محلی (خودکار در `CURLFile`
پیچیده می‌شود):

```php
// با file_id
Media::send($tg, 'photo', $chatId, $fileId, ['caption' => '🔁 همانی که فرستادی']);

// با مسیر محلی
Media::send($tg, 'document', $chatId, '/tmp/report.pdf', ['caption' => 'گزارش']);
```

انواع پشتیبانی‌شده: `photo`، `video`، `audio`، `voice`، `video_note`،
`animation`، `document`، `sticker`. نوع ناشناخته Exception می‌دهد — شکست
روشن، نه بی‌صدا. خروجیِ موفق، آبجکت `Message` ارسال‌شده است و شکست `null`
(با لاگ).

## ویرایش و رسانه

تلگرام اجازهٔ ویرایش استیکر/ویدیونوت/ویس (و فروارد خام) را نمی‌دهد. اگر رلهٔ
شما سینک ویرایش دارد، یادتان باشد هنگام `editMessageText()` /
`editMessageCaption()` مقدار `reply_markup` قبلی را دوباره بدهید — ویرایش
**بدون** `reply_markup` کیبورد اینلاین پیام را حذف می‌کند (ببینید
[buttons.md](buttons.md)).

## نمونهٔ کامل

ببینید [examples/MediaFiles.php](../../examples/MediaFiles.php) — بزرگ‌ترین
سایز عکس را دانلود و ذخیره می‌کند و بعد هم با file_id و هم به‌صورت فایل محلی
برمی‌گرداند.
