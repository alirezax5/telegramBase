<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Conversation;

use Illuminate\Database\Eloquent\Model;

/**
 * ConversationStore — ردیف گفتگوی فعال هر چت (مدل Eloquent).
 *
 * جدول conversations: هر ردیف = یک گفتگوی باز یا بسته‌شده.
 *   status = active    → گفتگو در جریان است (فقط همین برای رهگیری خوانده می‌شود)
 *   status = done      → onFinish اجرا شده؛ ردیف برای بازبینی/گزارش نگه داشته می‌شود
 *   status = cancelled → با /cancel یا /start یا تریگر دوباره یا timeout بسته شده
 *
 * نکتهٔ فارم: برای ایزوله‌سازی کامل بین بات‌ها ستون bot_id در اسکیما هست
 * (پیش‌فرض default). در دموی تک‌باتی بی‌اثر است؛ در فارم مولتی‌بات مقدار آن
 * را از BotManager/شناسهٔ بات پر کنید.
 */
class ConversationStore extends Model
{
    protected $table = 'conversations';
    protected $primaryKey = 'id';
    public $timestamps = false;
    protected $connection = 'main';

    protected $fillable = [
        'bot_id', 'chatid', 'flow', 'flow_class', 'step',
        'answers', 'status', 'created_at', 'updated_at',
    ];

    protected $casts = [
        'answers' => 'array',
    ];

    public const STATUS_ACTIVE    = 'active';
    public const STATUS_DONE      = 'done';
    public const STATUS_CANCELLED = 'cancelled';

    /** باز کردن فلو برای چت (فرض: ردیف فعال قبلی توسط dropActive حذف شده) */
    public static function open(
        int $chatId,
        string $flow,
        string $flowClass,
        string $step,
        array $answers
    ): self {
        $now = date('Y-m-d H:i:s');

        return static::create([
            'bot_id'     => (string) (\alirezax5\TelegramBase\App\Environment\EnvHandler::get('TGBASE_BOT_ID', 'default')),
            'chatid'     => $chatId,
            'flow'       => $flow,
            'flow_class' => $flowClass,
            'step'       => $step,
            'answers'    => $answers,
            'status'     => self::STATUS_ACTIVE,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** گفتگوی فعال این چت (یا null) */
    public static function active(int $chatId): ?self
    {
        /** @var self|null $row */
        $row = static::where('chatid', $chatId)
            ->where('status', self::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->first();

        return $row;
    }

    /** بستن هر فلو فعال این چت (قبل از شروع فلو تازه) */
    public static function dropActive(int $chatId): void
    {
        static::where('chatid', $chatId)
            ->where('status', self::STATUS_ACTIVE)
            ->update(['status' => self::STATUS_CANCELLED, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    public function close(string $status): void
    {
        $this->status = $status;
        $this->updated_at = date('Y-m-d H:i:s');
        $this->save();
    }

    public function cancel(): void
    {
        $this->close(self::STATUS_CANCELLED);
    }

    public function finish(): void
    {
        $this->close(self::STATUS_DONE);
    }

    /** ذخیرهٔ پیشروی: مرحلهٔ جاری + پاسخ‌ها + زمان (برای timeout) */
    public function advance(string $step, array $answers): void
    {
        $this->step = $step;
        $this->answers = $answers;
        $this->updated_at = date('Y-m-d H:i:s');
        $this->save();
    }

    /** آیا از آخرین فعالیت کاربر بیشتر از این دقیقه‌ها گذشته؟ */
    public function isTimedOut(int $minutes): bool
    {
        $ts = strtotime((string) $this->updated_at);

        return $ts !== false && (time() - $ts) > ($minutes * 60);
    }
}
