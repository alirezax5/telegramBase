<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Conversation;

use alirezax5\TelegramBase\App\Environment\EnvHandler;
use alirezax5\TelegramBase\App\Language\Language;
use alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface;
use alirezax5\TelegramBase\App\Update\UpdateState;
use telegramBotApiPhp\Telegram;

/**
 * Conversation — کلاس پایهٔ «گفتگوی چندمرحله‌ای».
 *
 * گفتگو = زنجیره‌ای از Stepها که پاسخ‌هایشان در جدول conversations
 * (به ازای هر chat) ذخیره می‌شود و بین updateها ماندگار است — برخلاف
 * SharedManagement که state per-process است و با پایان همان آپدیت می‌میرد.
 *
 * تقسیم کار:
 *   • این کلاس: مشخصات فلو (steps/flowKey/trigger/triggers/timeout) + onFinish
 *   • ConversationManager (اولویت ۲): موتور اجرا — رهگیری ورودی کاربر،
 *     اعتبارسنجی، پیشروی مرحله‌ها و لغو/مهلت
 *   • خود پلاگین فرزند: فقط «تریگر شروع» (دستور یا متن دکمه) را هندل
 *     می‌کند و با ConversationManager::begin() فلو را باز می‌کند.
 *
 * چرخهٔ کامل یک فلو:
 *   begin → sendAsk(step1) → [کاربر پاسخ می‌دهد] → اعتبارسنجی → ثبت
 *   → sendAsk(step2) → ... → onFinish(answers)
 *   و در هر لحظه با /cancel قابل لغو است؛ با /start فلو لغو و منو باز می‌شود.
 *
 * TM-16 — ناوبری داخل گفتگو:
 *   هر Step با backable()/menu() دکمهٔ «بازگشت/منوی اصلی» می‌گیرد (اینلاین
 *   یا Reply Keyboard). برگشت = پرش به مرحلهٔ ورودی قبلیِ مسیر مؤثر
 *   (بعد از فیلتر when و رد شدن از مرحله‌های display)، پاک کردن پاسخ هدف
 *   و همهٔ مراحل بعدش، و پرسیدن دوبارهٔ سؤال. متن دکمهٔ برگشت را
 *   navBackTexts() معرفی می‌کند.
 */
abstract class Conversation implements PluginInterface
{
    /**
     * شناسهٔ یکتای فلو (در جدول conversations و در callback_data دکمه‌ها
     * به‌صورت conv:{flowKey}:{step}:{value} ذخیره می‌شود).
     */
    abstract public function flowKey(): string;

    /**
     * نقشهٔ مراحل: stepKey => Step (ترتیب تعریف = ترتیب اجرا).
     *
     * @return array<string,Step>
     */
    abstract public function steps(): array;

    /**
     * پایان فلو — همهٔ پاسخ‌ها جمع شده‌اند؛ نتیجه را ذخیره/اعلام کن.
     *
     * @param array<string,mixed> $answers
     */
    abstract public function onFinish(array $answers, Telegram $tg, int $chatId): void;

    /**
     * دستور شروع این فلو (بدون اسلش). null = فلو فقط از راه دکمهٔ منو باز می‌شود.
     * Manager وقتی فلو فعال است و کاربر همین دستور را بفرستد، فلو را لغو
     * می‌کند و آپدیت را عادی عبور می‌دهد تا همین پلاگین، فلو را ری‌استارت کند.
     */
    public function triggerCommand(): ?string
    {
        return null;
    }

    /**
     * تریگرهای «متنی» شروع فلو (علاوه بر دستور) — TM-15.
     *
     * دکمه‌های Reply Keyboard متنِ ساده می‌فرستند (نه command و نه
     * callback_query)؛ اینجا کلید ترجمهٔ برچسب هر دکمه را معرفی می‌کنیم تا
     * در همهٔ زبان‌ها کار کند. هر فلو فقط متنِ خودش را می‌پذیرد — پس کلیدها
     * باید بین فلوها یکتا باشند.
     *
     * مثال: return ['main.menu.register']; یعنی برچسب دکمهٔ «ثبت‌نام» منو.
     *
     * @return string[] فهرست کلیدهای ترجمه یا متن خام
     */
    public function triggers(): array
    {
        return [];
    }

    /**
     * متن‌های «خروج از گفتگو» — TM-15.
     *
     * حین فلو اگر کاربر یکی از این متن‌ها را بفرستد (معمولاً دکمهٔ
     * «منوی اصلی» که روی Reply Keyboard مانده)، Manager گفتگو را لغو
     * می‌کند و آپدیت را «بدون markHandled» عادی عبور می‌دهد تا پلاگین منو
     * همزمان ناوبری را انجام دهد — یعنی یک تپ، هم خروج هم منو.
     *
     * @return string[] فهرست کلیدهای ترجمه یا متن خام
     */
    public function exitTexts(): array
    {
        return ['main.menu.home'];
    }

    /**
     * متن‌های «برگشت به مرحلهٔ قبل» — TM-16.
     *
     * حین فلو اگر کاربر یکی از این متن‌ها را بفرستد (دکمهٔ متنی «بازگشت»
     * که خود Manager روی Reply Keyboardِ مراحل backable() رندر می‌کند، یا
     * تایپ دستی همان متن)، Manager یک مرحله به عقب برمی‌گردد: پاسخ مرحلهٔ
     * هدف (و همهٔ مراحل بعدش) پاک و سؤال دوباره پرسیده می‌شود. برگشت از
     * مرحله‌های display می‌پرد (محاسبه در مسیر جلو با پاسخ تازه تازه
     * می‌شود) و روی اولین مرحلهٔ ورودی، مثل exitTexts رفتار می‌کند
     * (خروج به منو).
     *
     * @return string[] فهرست کلیدهای ترجمه یا متن خام
     */
    public function navBackTexts(): array
    {
        return ['conv.nav_back'];
    }

    /**
     * تطبیق متن ورودی با فهرست کلیدهای ترجمه/متن خام (trim + بی‌توجه به
     * بزرگی/کوچکی حروف). متنِ خام هم پذیرفته می‌شود چون __() هر ورودی که
     * کلید ترجمه نباشد را عیناً برمی‌گرداند.
     */
    public static function textMatches(array $keysOrTexts, string $text): bool
    {
        $t = mb_strtolower(trim($text));
        if ($t === '') {
            return false;
        }
        foreach ($keysOrTexts as $k) {
            if (mb_strtolower(trim(__((string) $k))) === $t) {
                return true;
            }
        }
        return false;
    }

    /** دقیقه‌های بی‌تحرکی مجاز؛ بعد از آن Manager فلو را با پیام timeout می‌بندد */
    public function timeoutMinutes(): int
    {
        return 15;
    }

    /** اولویت پیش‌فرض پلاگین‌های گفتگو (فقط برای هوک تریگر؛ موتور، Manager است) */
    public function getPriority(): int
    {
        return 20;
    }

    /** شروع/ری‌استارت فلو برای یک چت */
    public static function begin(self $flow, Telegram $tg, int $chatId): void
    {
        ConversationManager::begin($flow, $tg, $chatId);
    }

    /**
     * حل‌کنندهٔ اختیاری زبان کاربر — fn(int $chatId): ?string.
     *
     * هستهٔ فریمورک به لایهٔ دادهٔ اپ وابسته نیست؛ اپی که زبان هر کاربر را
     * در DB نگه می‌دارد (مثل دموها با Users::langOf) این هوک را در bootstrap
     * ست می‌کند:
     *     Conversation::$langResolver = fn(int $c) => Users::langOf($c);
     * اگر null بماند، زبان پیش‌فرض DEFAULT_LANG استفاده می‌شود.
     *
     * @var callable|null
     */
    public static $langResolver = null;

    /**
     * اعمال زبان کاربر روی مترجمِ همین پروسه (Language singleton per-process
     * است و باید در هر آپدیت دوباره ست شود — درس مستندشدهٔ دموی ۴ سناریو).
     */
    public static function applyLang(int $chatId): void
    {
        $lang = null;
        if (self::$langResolver !== null) {
            $lang = (self::$langResolver)($chatId);
        } elseif (class_exists(\Database\Users::class) && method_exists(\Database\Users::class, 'langOf')) {
            /** @noinspection PhpUndefinedMethodInspection — guarded by method_exists */
            $lang = \Database\Users::langOf($chatId);
        }
        Language::getInstance()->setLanguage(
            ($lang !== null && $lang !== '')
                ? $lang
                : (string) EnvHandler::get('DEFAULT_LANG', 'fa')
        );
    }

    /** نمایش «این آپدیت جواب گرفته» برای غیرفعال شدن فاز Fallback (TM-13) */
    protected static function handled(string $by): void
    {
        UpdateState::markHandled($by);
    }
}
