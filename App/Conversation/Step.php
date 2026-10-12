<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Conversation;

/**
 * Step — تعریف یک مرحله از گفتگو (Conversation).
 *
 * هر استپ سه چیز را مشخص می‌کند:
 *   ۱) نوع ورودی مورد انتظار: متن (text) / عکس (photo) / دکمهٔ شیشه‌ای
 *      (callback) / فقط-نمایش (display — TM-16)
 *   ۲) چه چیزی و چطور پرسیده شود (ask + withAsk برای جایگذاری پاسخ‌های قبلی)
 *   ۳) ورودی چطور اعتبارسنجی شود (pattern / validate + retry برای پیام خطا)
 *
 * قابلیت‌های اختیاری:
 *   - optional()   : مرحله با /skip رد می‌شود (مقدار null ثبت می‌گردد)
 *   - when()       : مرحله فقط وقتی اجرا می‌شود که شرطِ پاسخ‌های قبلی true باشد
 *   - buttons()    : بسته به نوع Step دو رفتار دارد (TM-15):
 *       • Step::callback() → Inline Keyboard با callback_data نشانی‌دار
 *         conv:{flow}:{step}:{value} — جواب از callback_query می‌آید.
 *       • Step::text() → Reply Keyboard (متن ساده!) — سؤال با کیبورد
 *         پایین صفحه پرسیده می‌شود و برچسب تپ‌شده به‌صورت message.text
 *         برمی‌گردد؛ Manager با reverse-map از طریق مترجم، برچسب را به
 *         value تبدیل و ذخیره می‌کند (پس در DB همیشه value canonical است
 *         مستقل از زبان کاربر). متنِ خارج از فهرست → پیام retry.
 *       TM-16: به‌جای آرایهٔ ثابت، یک closure هم پذیرفته می‌شود
 *       (fn(array $answers): array) تا «فهرست پویا» ساخت — مثلاً از DB
 *       یا از پاسخ‌های قبلی. value => label؛ برچسب می‌تواند کلید ترجمه
 *       یا متن خام باشد (در لحظهٔ رندر با __() می‌گذرد).
 *   - columns()    : تعداد ستون‌های کیبورد (Reply Keyboard؛ و از TM-16
 *       برای Inline هم اگر columns(>1) ست شده باشد — پیش‌فرض inline =
 *       همهٔ دکمه‌ها در یک ردیف مثل قبل)
 *   - onDone()     : هوک بعد از ثبت موفق این مرحله (دریافت همهٔ answers)
 *
 * TM-16 — ناوبری داخل گفتگو:
 *   - backable()   : دکمهٔ «بازگشت» به این مرحله اضافه می‌شود. اینلاین
 *       (callback_data = conv:{flow}:__nav__:back) یا Reply Keyboard
 *       (برچسب متنی conv.nav_back). Manager با برگشت، پاسخ مرحلهٔ هدف و
 *       همهٔ مراحل بعدش را پاک و سؤال را دوباره می‌پرسد؛ از مرحله‌های
 *       display می‌پرد. اگر هدفی نباشد (اولین مرحله)، مثل «منو» خروج
 *       می‌کند. دکمهٔ برگشت وقتی هدفی نیست اصلاً رندر نمی‌شود.
 *   - menu()       : دکمهٔ «منوی اصلی» به این مرحله اضافه می‌شود (هم اینلاین
 *       conv:{flow}:__nav__:menu، هم Reply Keyboard با برچسب exitTexts).
 *       تپ آن = خروج از گفتگو + نمایش منوی اصلی.
 *
 * TM-16 — مرحلهٔ فقط-نمایش/محاسبه:
 *   - Step::display() + compute(fn): منتظر هیچ ورودی‌ای نمی‌ماند؛ متنش
 *       از compute(array $answers) ساخته می‌شود (خروجی: رشتهٔ خام یا
 *       [کلید ترجمه, جایگذاری‌ها]) و موتور خودکار به مرحلهٔ بعد می‌رود.
 *       در answers ثبت نمی‌شود — محاسبهٔ نهایی در onFinish تازه انجام
 *       می‌شود. مناسب «جمع سفارش»، «خلاصهٔ تا الان»، «فهرست موجودی».
 */
final class Step
{
    public const TEXT     = 'text';
    public const PHOTO    = 'photo';
    public const CALLBACK = 'callback';
    public const DISPLAY  = 'display';

    public string $type = self::TEXT;

    /** کلید ترجمهٔ متن سؤال */
    public ?string $askKey = null;

    /** @var callable|null fn(array $answers): array — جایگذاری‌های متن سؤال */
    public $askWith = null;

    /** @var string|null پترن regex برای ورودی متنی */
    public ?string $pattern = null;

    /** @var callable|null fn(string $value, array $answers): bool — آرگومان دوم اختیاری (TM-16) */
    public $validate = null;

    /** کلید ترجمهٔ پیام ورودی نامعتبر */
    public ?string $retryKey = null;

    /** قابل رد شدن با /skip (مقدار null ثبت می‌شود) */
    public bool $optional = false;

    /** @var callable|null fn(array $answers): bool — شرط اجرای این مرحله */
    public $when = null;

    /** @var array<string,string> value => label (کلید ترجمه یا متن خام) */
    public array $buttons = [];

    /** @var callable|null fn(array $answers): array<string,string> — دکمه‌های پویا (TM-16) */
    public $buttonsFn = null;

    /** تعداد ستون‌های کیبورد (Reply Keyboard؛ و Inline اگر > 1) */
    public int $columns = 1;

    /** @var callable|null fn(array $answers): void — بعد از ثبت این مرحله */
    public $onDone = null;

    /** دکمهٔ «بازگشت» روی کیبورد این مرحله (TM-16) */
    public bool $withBack = false;

    /** دکمهٔ «منوی اصلی» روی کیبورد این مرحله (TM-16) */
    public bool $withMenu = false;

    /** @var callable|null fn(array $answers): string|array — متن مرحلهٔ display (TM-16) */
    public $compute = null;

    public static function text(): self
    {
        $s = new self();
        $s->type = self::TEXT;
        return $s;
    }

    public static function photo(): self
    {
        $s = new self();
        $s->type = self::PHOTO;
        return $s;
    }

    public static function callback(): self
    {
        $s = new self();
        $s->type = self::CALLBACK;
        return $s;
    }

    /** مرحلهٔ فقط-نمایش: محاسبه از پاسخ‌ها + پرش خودکار به مرحلهٔ بعد (TM-16) */
    public static function display(): self
    {
        $s = new self();
        $s->type = self::DISPLAY;
        return $s;
    }

    public function ask(string $key): self
    {
        $this->askKey = $key;
        return $this;
    }

    /** @param callable(array $answers): array $fn */
    public function withAsk(callable $fn): self
    {
        $this->askWith = $fn;
        return $this;
    }

    public function pattern(string $regex): self
    {
        $this->pattern = $regex;
        return $this;
    }

    /**
     * اعتبارسنجی ورودی — آگاه از پاسخ‌های قبلی (TM-16).
     * امضای پیشنهادی: fn(string $value, array $answers): bool
     * (کلوژرهای قدیمیِ تک‌آرگومانی هم کار می‌کنند.)
     *
     * @param callable(string $value, array $answers=): bool $fn
     */
    public function validate(callable $fn): self
    {
        $this->validate = $fn;
        return $this;
    }

    public function retry(string $key): self
    {
        $this->retryKey = $key;
        return $this;
    }

    public function optional(bool $on = true): self
    {
        $this->optional = $on;
        return $this;
    }

    /** @param callable(array $answers): bool $fn */
    public function when(callable $fn): self
    {
        $this->when = $fn;
        return $this;
    }

    /**
     * نقشهٔ دکمه‌ها: value => label.
     * برای Step::callback → Inline Keyboard؛ برای Step::text → Reply Keyboard
     * (برچسب تپ‌شده با reverse-map به value تبدیل می‌شود).
     * TM-16: closure هم می‌پذیرد — fn(array $answers): array<string,string>
     *
     * @param array<string,string>|callable $buttons
     */
    public function buttons(array|callable $buttons): self
    {
        if (is_callable($buttons)) {
            $this->buttonsFn = $buttons;
        } else {
            $this->buttons = $buttons;
        }
        return $this;
    }

    /** تعداد ستون‌های کیبورد (۱ = Reply Keyboard هر دکمه یک ردیف / Inline همه در یک ردیف) */
    public function columns(int $n): self
    {
        $this->columns = max(1, $n);
        return $this;
    }

    /** @param callable(array $answers): void $fn */
    public function onDone(callable $fn): self
    {
        $this->onDone = $fn;
        return $this;
    }

    /** افزودن دکمهٔ «بازگشت» به کیبورد این مرحله (TM-16) */
    public function backable(bool $on = true): self
    {
        $this->withBack = $on;
        return $this;
    }

    /** افزودن دکمهٔ «منوی اصلی» به کیبورد این مرحله (TM-16) */
    public function menu(bool $on = true): self
    {
        $this->withMenu = $on;
        return $this;
    }

    /**
     * متن مرحلهٔ display — خروجی closure:
     *   • رشته → متن خام
     *   • [کلید ترجمه, جایگذاری‌ها] → متن ترجمه‌شده
     *
     * @param callable(array $answers): string|array $fn
     */
    public function compute(callable $fn): self
    {
        $this->compute = $fn;
        return $this;
    }
}
