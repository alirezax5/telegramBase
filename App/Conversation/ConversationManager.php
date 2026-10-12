<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Conversation;

use alirezax5\TelegramBase\App\Attributes\ChatType;
use alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface;
use alirezax5\TelegramBase\App\Update\UpdateState;
use telegramBotApiPhp\Telegram;

/**
 * ConversationManager — موتور اجرای گفتگوها (اولویت ۲ = قبل از همهٔ
 * پلاگین‌های معمولی، بعد از LangLoader در اولویت ۰).
 *
 * وظیفه: اگر برای این چت گفتگوی فعالی وجود داشته باشد، ورودی کاربر به
 * «مرحلهٔ جاری همان گفتگو» هدایت می‌شود نه به بقیهٔ پلاگین‌ها:
 *
 *   • /cancel                  → لغو + پیام تأیید (markHandled)
 *   • /start                   → لغو بی‌صدا و عبور عادی (منو باز می‌شود)
 *   • متن خروج (exitTexts)     → لغو بی‌صدا و عبور عادی (TM-15: دکمهٔ
 *                                «منوی اصلی» Reply Keyboard حین فلو)
 *   • تریگر خود فلو (دستور یا متن دکمه) → لغو و عبور → فلو ری‌استارت (TM-15)
 *   • متن «بازگشت» (navBackTexts) → برگشت به مرحلهٔ ورودی قبلی؛ پاسخ هدف و
 *                                مراحل بعدش پاک و سؤال دوباره پرسیده
 *                                می‌شود؛ روی اولین مرحله = خروج به منو (TM-16)
 *   • هر دستور دیگر            → راهنمایی «گفتگوی فعال داری» (markHandled)
 *   • ورودی معتبر              → ثبت + سؤال مرحلهٔ بعد یا onFinish (markHandled)
 *   • ورودی نامعتبر            → پیام retry و ماندن در همین مرحله (markHandled)
 *   • بی‌تحرکی بیشتر از timeoutMinutes → لغو خودکار + پیام مهلت
 *
 * مراحل متنیِ «دکمه‌ای» (Step::text()->buttons()) با Reply Keyboard پرسیده
 * می‌شوند و برچسب تپ‌شده (message.text) با reverse-map مترجم به value
 * canonical تبدیل می‌شود — TM-15.
 *
 * TM-16:
 *   • مرحله‌های display: موتور موقع رسیدن به آنها، پیام «محاسبه‌شده از
 *     پاسخ‌ها» را می‌فرستد و خودکار به مرحلهٔ بعد می‌پرد (بدون ثبت ورودی).
 *     در مسیر برگشت از آنها رد می‌شود و در مسیر جلو دوباره رندر می‌شوند.
 *   • دکمه‌های پویا: buttons() یک closure هم می‌پذیرد که در «لحظهٔ رندر و
 *     تطبیق» با پاسخ‌های جاری صدا زده می‌شود (فهرست از DB/پاسخ‌ها).
 *   • validate دو آرگومانی: fn($value, $answers) — اعتبارسنجی آگاه از
 *     پاسخ‌های قبلی (کلوژر تک‌آرگومانی قدیمی هم کار می‌کند).
 *   • دکمه‌های ناوبری: callback_data = conv:{flow}:__nav__:{back|menu}
 *     برای کیبوردهای اینلاین؛ برچسب‌های متنی navBackTexts()/exitTexts()
 *     برای Reply Keyboard. تپ «منو» = خروج + رندر منوی اصلی.
 *
 * callbackها فقط وقتی برداشته می‌شوند که با پیشوند conv: باشند؛ سایر
 * callbackها عادی عبور می‌کنند تا پلاگین‌های دیگر هندلشان کنند.
 */
#[ChatType(['private'])]
final class ConversationManager implements PluginInterface
{
    /** پیشوند callback_data دکمه‌های داخل گفتگو: conv:{flowKey}:{stepKey}:{value} */
    public const CB_PREFIX = 'conv:';

    /** stepKey مجازیِ دکمه‌های ناوبری: conv:{flow}:__nav__:{back|menu} (TM-16) */
    public const NAV_STEP = '__nav__';

    /** reply_markup برای جمع کردن Reply Keyboard */
    private const REMOVE_RK = ['remove_keyboard' => true, 'selective' => false];

    /**
     * markup اختیاری «منوی اصلی» هنگام خروج از گفتگو — fn(): ?array.
     *
     * وقتی کاربر با دکمهٔ ناوبری «منوی اصلی» یا برگشت روی اولین مرحله از
     * فلو خارج می‌شود، Manager پیام خروج می‌فرستد؛ اپ می‌تواند با این هوک
     * markup منوی خودش (inline یا Reply Keyboard) را وصل کند:
     *     ConversationManager::$mainMenuMarkup = fn() => ['inline_keyboard' => ...];
     * اگر null بماند، پیام بدون کیبورد می‌رود.
     *
     * @var callable|null
     */
    public static $mainMenuMarkup = null;

    /** markup منوی اصلی از هوک اپ (یا null) */
    private static function mainMenuMarkup(): ?array
    {
        if (self::$mainMenuMarkup === null) {
            return null;
        }
        $out = (self::$mainMenuMarkup)();
        return is_array($out) ? $out : null;
    }

    public function getPriority(): int
    {
        return 2;
    }

    // ─────────────────────────── Message hook ───────────────────────────

    public function onMessage($msg, Telegram $tg): void
    {
        $chatId = (int) ($msg->chat->id ?? 0);
        if ($chatId === 0) {
            return;
        }

        $row = ConversationStore::active($chatId);
        if ($row === null) {
            return; // گفتگوی فعالی نیست → هیچ؛ پلاگین‌های عادی کار کنند
        }

        $flow = $this->resolve($row);
        if ($flow === null) {
            // کلاس فلو دیگر وجود ندارد (کد عوض شده) → ردیف را ببند
            $row->cancel();
            return;
        }

        Conversation::applyLang($chatId);

        $text = trim((string) ($msg->text ?? ''));

        // مهلت گفتگو تمام شده؟ → لغو + پیام؛ آپدیت جاری «عبور عادی» می‌کند
        if ($row->isTimedOut($flow->timeoutMinutes())) {
            $row->cancel();
            $trigger = $flow->triggerCommand();
            $tg->sendMessage($chatId, __('conv.timeout', null, [
                'trigger' => '/' . (($trigger !== null && $trigger !== '') ? $trigger : 'start'),
            ]), self::REMOVE_RK);
            return; // بدون markHandled
        }

        // نقشهٔ مرحلهٔ جاری (برای گارد /cancel هم لازم است)
        $steps   = $flow->steps();
        $stepKey = (string) $row->step;
        $step    = $steps[$stepKey] ?? null;

        // /cancel — لغو صریح (اگر مرحلهٔ جاری Reply Keyboard دارد، جمع می‌شود)
        if ($text === '/cancel') {
            $row->cancel();
            $params = ($step !== null && $step->type === Step::TEXT && self::buttonsOf($step, (array) ($row->answers ?? [])) !== [])
                ? ['reply_markup' => self::REMOVE_RK]
                : [];
            $tg->sendMessage($chatId, __('conv.cancelled'), $params);
            UpdateState::markHandled('conv:cancel');
            return;
        }

        // /start — لغو بی‌صدا و عبور به پلاگین استارت (منو)
        if ($text === '/start') {
            $row->cancel();
            return;
        }

        // متن‌های خروج از گفتگو (مثلاً «منوی اصلی») — لغو و عبور عادی؛
        // بدون markHandled تا پلاگین منو همزمان ناوبری را انجام دهد (TM-15)
        if (Conversation::textMatches($flow->exitTexts(), $text)) {
            $row->cancel();
            return;
        }

        // تریگر خودِ همین فلو — دستور یا متن دکمه → ری‌استارت طبیعی:
        // عبور عادی تا پلاگین فلو begin کند (TM-15: تریگر متنی)
        $trigger = $flow->triggerCommand();
        if (($trigger !== null && $trigger !== '' && $text === '/' . $trigger)
            || Conversation::textMatches($flow->triggers(), $text)) {
            $row->cancel();
            return;
        }

        if ($step === null) {
            $row->cancel(); // نقشهٔ فلو عوض شده → ببند
            return;
        }

        $answers = (array) ($row->answers ?? []);

        // ── TM-16: «بازگشت» (دکمهٔ متنی Reply Keyboard یا تایپ دستی) ──
        // یک مرحله به عقب؛ اگر هدفی نیست (اولین مرحلهٔ ورودی) = خروج به منو
        if (Conversation::textMatches($flow->navBackTexts(), $text)) {
            if (self::goBack($flow, $row, $stepKey, $tg, $chatId)) {
                UpdateState::markHandled('conv:back');
            } else {
                $row->cancel(); // اولین مرحله → مثل «منوی اصلی» عبور عادی
            }
            return;
        }

        // /skip — باید «قبل از» گارد دستورات باشد چون با / شروع می‌شود
        if ($text === '/skip') {
            if (!$step->optional) {
                $tg->sendMessage($chatId, __('conv.skip_not_allowed'));
                UpdateState::markHandled('conv:skip_denied');
                return;
            }
            $this->storeAndAdvance($flow, $row, $stepKey, $step, null, $tg, $chatId);
            UpdateState::markHandled('conv:skip');
            return;
        }

        // سایر دستورات حین گفتگو — راهنمایی
        if ($text !== '' && $text[0] === '/') {
            $tg->sendMessage($chatId, __('conv.busy'));
            UpdateState::markHandled('conv:busy');
            return;
        }

        // مرحلهٔ دکمه‌ای شیشه‌ای با متن معمولی جواب نمی‌گیرد
        if ($step->type === Step::CALLBACK) {
            $tg->sendMessage($chatId, __('conv.use_buttons'));
            UpdateState::markHandled('conv:need_buttons');
            return;
        }

        if ($step->type === Step::PHOTO) {
            $value = null;
            $photos = isset($msg->photo) ? (array) $msg->photo : [];
            if ($photos !== []) {
                $largest = end($photos);
                $value = (string) ($largest->file_id ?? '');
            }
            if ($value === null || $value === '') {
                $tg->sendMessage($chatId, __($step->retryKey ?? 'conv.retry_default'));
                UpdateState::markHandled('conv:retry');
                return;
            }
            $this->storeAndAdvance($flow, $row, $stepKey, $step, $value, $tg, $chatId);
            UpdateState::markHandled('conv:step');
            return;
        }

        // مرحلهٔ متنیِ «دکمه‌ای» (Reply Keyboard) — برچسب تپ‌شده باید یکی از
        // دکمه‌های مرحله باشد؛ برچسب → value canonical (TM-15؛ پویا: TM-16)
        if ($step->type === Step::TEXT && self::buttonsOf($step, $answers) !== []) {
            $mapped = self::reverseMap(self::buttonsOf($step, $answers), $text);
            if ($mapped === null) {
                $tg->sendMessage($chatId, __($step->retryKey ?? 'conv.retry_default'));
                UpdateState::markHandled('conv:retry');
                return;
            }
            $this->storeAndAdvance($flow, $row, $stepKey, $step, $mapped, $tg, $chatId);
            UpdateState::markHandled('conv:step');
            return;
        }

        // مرحلهٔ متنی آزاد
        if ($text === '') {
            $tg->sendMessage($chatId, __($step->retryKey ?? 'conv.retry_default'));
            UpdateState::markHandled('conv:retry');
            return;
        }

        if ($step->pattern !== null && !preg_match($step->pattern, $text)) {
            $tg->sendMessage($chatId, __($step->retryKey ?? 'conv.retry_default'));
            UpdateState::markHandled('conv:retry');
            return;
        }

        // TM-16: validate دو آرگومانی — پاسخ‌های قبلی هم می‌آیند
        // (کلوژر تک‌آرگومانی قدیمی آرگومان دوم را نادیده می‌گیرد)
        if ($step->validate !== null && !($step->validate)($text, $answers)) {
            $tg->sendMessage($chatId, __($step->retryKey ?? 'conv.retry_default'));
            UpdateState::markHandled('conv:retry');
            return;
        }

        $this->storeAndAdvance($flow, $row, $stepKey, $step, $text, $tg, $chatId);
        UpdateState::markHandled('conv:step');
    }

    // ────────────────────────── CallbackQuery hook ──────────────────────

    public function onCallbackQuery($cb, Telegram $tg): void
    {
        $data = (string) ($cb->data ?? '');
        if ($data === '' || !str_starts_with($data, self::CB_PREFIX)) {
            return; // دکمهٔ گفتگویی نیست → پلاگین‌های دیگر
        }

        $chatId = (int) ($cb->message->chat->id ?? 0);
        $cbId   = (string) ($cb->id ?? '');

        if ($chatId === 0) {
            if ($cbId !== '') {
                $tg->answerCallbackQuery($cbId, __('conv.stale'));
            }
            UpdateState::markHandled('conv:stale');
            return;
        }

        Conversation::applyLang($chatId);

        $row = ConversationStore::active($chatId);
        if ($row === null) {
            if ($cbId !== '') {
                $tg->answerCallbackQuery($cbId, __('conv.stale'));
            }
            UpdateState::markHandled('conv:stale');
            return;
        }

        // conv:{flowKey}:{stepKey}:{value}
        $parts = explode(':', substr($data, strlen(self::CB_PREFIX)), 3);
        [$flowKey, $stepKey, $value] = array_pad($parts, 3, '');

        $flow = $this->resolve($row);

        // ── TM-16: دکمه‌های ناوبری روی پیام‌های گفتگو (بازگشت / منوی اصلی) ──
        if ($stepKey === self::NAV_STEP) {
            if ($flow === null || $flowKey !== $flow->flowKey()) {
                if ($cbId !== '') {
                    $tg->answerCallbackQuery($cbId, __('conv.stale'), true);
                }
                UpdateState::markHandled('conv:stale');
                return;
            }
            if ($cbId !== '') {
                $tg->answerCallbackQuery($cbId);
            }

            if ($value === 'back') {
                $curKey = (string) $row->step;
                if (self::goBack($flow, $row, $curKey, $tg, $chatId)) {
                    UpdateState::markHandled('conv:back');
                    return;
                }
                // هدفی نیست (اولین مرحله) → خروج به منو
                $row->cancel();
                $this->sendExitToMenu($tg, $chatId);
                UpdateState::markHandled('conv:nav_menu');
                return;
            }

            if ($value === 'menu') {
                $row->cancel();
                $this->sendExitToMenu($tg, $chatId);
                UpdateState::markHandled('conv:nav_menu');
                return;
            }

            if ($cbId !== '') {
                $tg->answerCallbackQuery($cbId, __('conv.stale'), true);
            }
            UpdateState::markHandled('conv:stale');
            return;
        }

        $steps = $flow !== null ? $flow->steps() : [];
        $step  = $steps[$stepKey] ?? null;
        $answers = (array) ($row->answers ?? []);
        $buttons = $step instanceof Step ? self::buttonsOf($step, $answers) : [];

        $valid = $flow !== null
            && $flowKey === $flow->flowKey()
            && $stepKey === (string) $row->step
            && $step instanceof Step
            && $step->type === Step::CALLBACK
            && isset($buttons[$value]);

        if (!$valid) {
            // دکمهٔ قدیمی/غریبه در حین گفتگو → toast و تمام
            if ($cbId !== '') {
                $tg->answerCallbackQuery($cbId, __('conv.stale'), true);
            }
            UpdateState::markHandled('conv:stale');
            return;
        }

        if ($cbId !== '') {
            $tg->answerCallbackQuery($cbId);
        }

        $this->storeAndAdvance($flow, $row, $stepKey, $step, $value, $tg, $chatId);
        UpdateState::markHandled('conv:step');
    }

    // ───────────────────────────── Engine API ───────────────────────────

    /**
     * شروع/ری‌استارت فلو: هر فلو فعال قبلی همین چت بسته و اولین مرحلهٔ
     * واجد شرایط (with when) پرسیده می‌شود. اگر مسیر از مرحله‌های display
     * رد شود، آنها درجا رندر و تا اولین مرحلهٔ ورودی پیش می‌رویم (TM-16).
     *
     * $clearRk در sendAsk یعنی اگر مرحلهٔ اول کیبورد خودش را ندارد، Reply
     * Keyboard قبلی (منوی اصلی) جمع شود — حالت «ویزارد متمرکز»: در حین
     * گفتگو کیبورد منو مزاحم پاسخ‌ها نمی‌شود (TM-15).
     */
    public static function begin(Conversation $flow, Telegram $tg, int $chatId): void
    {
        ConversationStore::dropActive($chatId);

        $answers = [];
        $first = self::nextStep($flow, $answers, '');

        if ($first === null) {
            // فلو بدون مرحلهٔ فعال → مستقیم تمام
            $flow->onFinish($answers, $tg, $chatId);
            return;
        }

        [$key, $step] = $first;
        $row = ConversationStore::open($chatId, $flow->flowKey(), get_class($flow), $key, $answers);
        Conversation::applyLang($chatId);

        $clearRk = true; // جمع کردن کیبورد منو اگر مرحلهٔ اول کیبورد ندارد

        while ($step->type === Step::DISPLAY) {
            self::renderDisplay($tg, $chatId, $flow, $key, $step, $answers, $clearRk);
            $clearRk = false; // پیام‌های display کیبورد RK نمی‌گذارند

            $after = self::nextStep($flow, $answers, $key);
            if ($after === null) {
                // فلو با display تمام می‌شود
                $row->answers = $answers;
                $row->finish();
                $flow->onFinish($answers, $tg, $chatId);
                return;
            }
            [$key, $step] = $after;
            $row->advance($key, $answers);
        }

        self::sendAsk($tg, $chatId, $flow, $key, $step, $answers, $clearRk);
    }

    /**
     * اولین مرحلهٔ بعد از afterKey که شرط when آن با پاسخ‌های فعلی برقرار است.
     * afterKey = '' یعنی از ابتدای نقشه.
     *
     * @return array{0:string,1:Step}|null
     */
    private static function nextStep(Conversation $flow, array $answers, string $afterKey): ?array
    {
        $seen = ($afterKey === '');
        foreach ($flow->steps() as $key => $step) {
            if (!$seen) {
                if ((string) $key === $afterKey) {
                    $seen = true;
                }
                continue;
            }
            if ($step->when !== null && !($step->when)($answers)) {
                continue;
            }
            return [(string) $key, $step];
        }
        return null;
    }

    /** ثبت پاسخ مرحلهٔ جاری و پیشروی (با رندر displayهای مسیر) یا پایان فلو */
    private static function storeAndAdvance(
        Conversation $flow,
        ConversationStore $row,
        string $stepKey,
        Step $step,
        $value,
        Telegram $tg,
        int $chatId
    ): void {
        $answers = (array) ($row->answers ?? []);
        $answers[$stepKey] = $value;

        if ($step->onDone !== null) {
            ($step->onDone)($answers);
        }

        $next = self::nextStep($flow, $answers, $stepKey);

        if ($next !== null) {
            [$nextKey, $nextStep] = $next;
            $row->advance($nextKey, $answers);
            // اگر مرحله‌ای که همین حالا جواب گرفت Reply Keyboard داشت و مرحلهٔ
            // بعدی کیبورد خودش را ندارد، کیبورد در پیام بعدی جمع می‌شود (TM-15)
            $clearRk = ($step->type === Step::TEXT && self::buttonsOf($step, $answers) !== []);

            while ($nextStep->type === Step::DISPLAY) {
                // TM-16: مرحلهٔ فقط-نمایش → رندر + پرش خودکار
                self::renderDisplay($tg, $chatId, $flow, $nextKey, $nextStep, $answers, $clearRk);
                $clearRk = false;

                $after = self::nextStep($flow, $answers, $nextKey);
                if ($after === null) {
                    $row->answers = $answers; // اسنپ‌شات کامل پاسخ‌ها در ردیف بماند
                    $row->finish();
                    $flow->onFinish($answers, $tg, $chatId);
                    return;
                }
                [$nextKey, $nextStep] = $after;
                $row->advance($nextKey, $answers);
            }

            self::sendAsk($tg, $chatId, $flow, $nextKey, $nextStep, $answers, $clearRk);
            return;
        }

        $row->answers = $answers; // اسنپ‌شات کامل پاسخ‌ها در ردیف بماند
        $row->finish();
        $flow->onFinish($answers, $tg, $chatId);
    }

    /**
     * TM-16 — برگشت به مرحلهٔ ورودی قبلی.
     *
     * مسیر مؤثر (بعد از فیلتر when با پاسخ‌های فعلی) از انتها به سمت
     * مرحلهٔ جاری اسکن می‌شود؛ اولین مرحلهٔ «ورودی» (غیر display) هدف است.
     * سپس پاسخ هدف و همهٔ پاسخ‌های بعد از آن (در ترتیب تعریف فلو) پاک
     * می‌شوند تا پاسخ‌های یتیمِ شاخه‌های when موقعیت را خراب نکنند و سؤال
     * هدف دوباره پرسیده شود.
     *
     * نکته: مرحلهٔ جاری با «کلیدش» معرفی می‌شود نه شیء Step — چون هر بار
     * steps() آبجکت‌های تازه می‌سازد و مقایسهٔ هویتی شیء همیشه false می‌شد.
     *
     * @return bool آیا برگشتی انجام شد؟ (false = هدفی نبود)
     */
    private static function goBack(
        Conversation $flow,
        ConversationStore $row,
        string $currentKey,
        Telegram $tg,
        int $chatId
    ): bool {
        $answers = (array) ($row->answers ?? []);
        $steps   = $flow->steps();
        $keys    = array_keys($steps);

        $idx = array_search($currentKey, $keys, true);
        if ($idx === false || $idx === 0) {
            return false;
        }

        $targetKey = null;
        for ($i = (int) $idx - 1; $i >= 0; $i--) {
            $s = $steps[$keys[$i]];
            if ($s->when !== null && !($s->when)($answers)) {
                continue;
            }
            if ($s->type === Step::DISPLAY) {
                continue; // نمایش‌ها در مسیر جلو دوباره رندر می‌شوند
            }
            $targetKey = (string) $keys[$i];
            break;
        }
        if ($targetKey === null) {
            return false;
        }

        // پاسخ هدف و همهٔ پاسخ‌های بعد از آن (ترتیب تعریف) پاک شوند
        $pruned = [];
        foreach ($steps as $k => $s) {
            if ((string) $k === $targetKey) {
                break;
            }
            if (array_key_exists((string) $k, $answers)) {
                $pruned[(string) $k] = $answers[(string) $k];
            }
        }

        $current = $steps[$currentKey];
        $clearRk = ($current->type === Step::TEXT && self::buttonsOf($current, $answers) !== []);
        $row->advance($targetKey, $pruned);
        self::sendAsk($tg, $chatId, $flow, $targetKey, $steps[$targetKey], $pruned, $clearRk);

        return true;
    }

    /** ارسال سؤال مرحله (متن + کیبورد مناسب نوع مرحله) */
    private static function sendAsk(
        Telegram $tg,
        int $chatId,
        Conversation $flow,
        string $stepKey,
        Step $step,
        array $answers,
        bool $clearRk = false
    ): void {
        $replacements = $step->askWith !== null ? (array) ($step->askWith)($answers) : [];
        $text = __($step->askKey ?? 'conv.retry_default', null, $replacements);

        $params = [];
        $markup = self::stepMarkup($flow, $stepKey, $step, $answers);
        if ($markup !== null) {
            $params['reply_markup'] = $markup;
        } elseif ($clearRk) {
            // مرحلهٔ قبلی Reply Keyboard داشت (منو یا استپ قبلی) و این مرحله
            // کیبورد ندارد → جمع کن تا ورودی متن آزاد باشد (TM-15)
            $params['reply_markup'] = self::REMOVE_RK;
        }

        $tg->sendMessage($chatId, $text, $params);
    }

    /**
     * TM-16 — رندر پیام مرحلهٔ display: متن از compute($answers) ساخته
     * می‌شود (خروجی: رشتهٔ خام یا [کلید ترجمه, جایگذاری‌ها])؛ کیبورد فقط
     * ردیف ناوبری (بازگشت/منو) اگر خواسته شده باشد.
     */
    private static function renderDisplay(
        Telegram $tg,
        int $chatId,
        Conversation $flow,
        string $stepKey,
        Step $step,
        array $answers,
        bool $clearRk = false
    ): void {
        $text = __('conv.retry_default');
        if ($step->compute !== null) {
            $out = ($step->compute)($answers);
            if (is_array($out)) {
                $key = (string) ($out[0] ?? '');
                if ($key !== '') {
                    $params = isset($out[1]) && is_array($out[1]) ? $out[1] : [];
                    $text = __($key, null, $params);
                }
            } elseif (is_string($out) && $out !== '') {
                $text = $out;
            }
        }

        $params = [];
        $markup = self::stepMarkup($flow, $stepKey, $step, $answers);
        if ($markup !== null) {
            $params['reply_markup'] = $markup;
        } elseif ($clearRk) {
            $params['reply_markup'] = self::REMOVE_RK;
        }

        $tg->sendMessage($chatId, $text, $params);
    }

    /**
     * markup مناسب مرحله — یا null (بدون کیبورد):
     *   • callback + buttons → Inline Keyboard با callback_data نشانی‌دار
     *     (+ ردیف ناوبری TM-16) — columns(>1) ردیف‌بندی می‌کند
     *   • text + buttons    → Reply Keyboard (برچسب‌ها از مترجم می‌گذرند)
     *     (+ ردیف متنی ناوبری TM-16)
     *   • PHOTO/DISPLAY     → فقط ردیف ناوبری اینلاین اگر backable/menu ست شده
     */
    private static function stepMarkup(
        Conversation $flow,
        string $stepKey,
        Step $step,
        array $answers
    ): ?array {
        $buttons = self::buttonsOf($step, $answers);

        // دکمهٔ برگشت فقط وقتی هدفی هست رندر می‌شود (TM-16)
        $hasBack = $step->withBack && self::hasBackTarget($flow, $stepKey, $answers);

        $navRow = [];
        if ($hasBack) {
            $navRow[] = [
                'text'          => __(self::navBackLabel($flow)),
                'callback_data' => self::CB_PREFIX . $flow->flowKey() . ':' . self::NAV_STEP . ':back',
            ];
        }
        if ($step->withMenu) {
            $navRow[] = [
                'text'          => __(self::navMenuLabel($flow)),
                'callback_data' => self::CB_PREFIX . $flow->flowKey() . ':' . self::NAV_STEP . ':menu',
            ];
        }

        if ($step->type === Step::CALLBACK) {
            $rows = [];
            if ($buttons !== []) {
                $flat = [];
                foreach ($buttons as $value => $label) {
                    $flat[] = [
                        'text'          => __((string) $label),
                        'callback_data' => self::CB_PREFIX . $flow->flowKey() . ':' . $stepKey . ':' . $value,
                    ];
                }
                if ($step->columns > 1) {
                    for ($i = 0, $n = count($flat); $i < $n; $i += $step->columns) {
                        $rows[] = array_slice($flat, $i, $step->columns);
                    }
                } else {
                    $rows[] = $flat; // رفتار قدیمی: همهٔ دکمه‌ها در یک ردیف
                }
            }
            if ($navRow !== []) {
                $rows[] = $navRow;
            }
            return $rows === [] ? null : ['inline_keyboard' => $rows];
        }

        if ($step->type === Step::TEXT && $buttons !== []) {
            // Reply Keyboard — value ها هرگز به تلگرام نمی‌روند؛ فقط برچسب‌ها.
            $flat = [];
            foreach ($buttons as $label) {
                $flat[] = ['text' => __((string) $label)];
            }
            $cols = max(1, $step->columns);
            $rows = [];
            for ($i = 0, $n = count($flat); $i < $n; $i += $cols) {
                $rows[] = array_slice($flat, $i, $cols);
            }
            $rkNav = [];
            if ($hasBack) {
                $rkNav[] = ['text' => __(self::navBackLabel($flow))];
            }
            if ($step->withMenu) {
                $rkNav[] = ['text' => __(self::navMenuLabel($flow))];
            }
            if ($rkNav !== []) {
                $rows[] = $rkNav;
            }
            return [
                'keyboard'        => $rows,
                'resize_keyboard' => true,
                'is_persistent'   => false, // کیبورد مرحله‌ای موقتی است (منوی اصلی با هوک mainMenuMarkup)
                'selective'       => false,
            ];
        }

        // PHOTO / DISPLAY / بدون دکمه — فقط ردیف ناوبری اینلاین اگر خواسته شده
        if ($navRow !== []) {
            return ['inline_keyboard' => [$navRow]];
        }
        return null;
    }

    /** پیام «خروج به منوی اصلی» — متن از conv.exit_menu + markup هوک اپ */
    private function sendExitToMenu(Telegram $tg, int $chatId): void
    {
        $params = [];
        $markup = self::mainMenuMarkup();
        if ($markup !== null) {
            $params['reply_markup'] = $markup;
        }
        $tg->sendMessage($chatId, __('conv.exit_menu'), $params);
    }

    /** برچسب دکمهٔ برگشت (اولین عضو navBackTexts) */
    private static function navBackLabel(Conversation $flow): string
    {
        $t = $flow->navBackTexts();
        return (string) ($t[0] ?? 'conv.nav_back');
    }

    /** برچسب دکمهٔ منو (اولین عضو exitTexts) */
    private static function navMenuLabel(Conversation $flow): string
    {
        $t = $flow->exitTexts();
        return (string) ($t[0] ?? 'main.menu.home');
    }

    /**
     * آیا از این مرحله جایی برای برگشت هست؟ (اولین مرحلهٔ ورودیِ مؤثر = نه)
     */
    private static function hasBackTarget(Conversation $flow, string $stepKey, array $answers): bool
    {
        $steps = $flow->steps();
        $keys  = array_keys($steps);
        $idx   = array_search($stepKey, $keys, true);
        if ($idx === false || $idx === 0) {
            return false;
        }
        for ($i = (int) $idx - 1; $i >= 0; $i--) {
            $s = $steps[$keys[$i]];
            if ($s->when !== null && !($s->when)($answers)) {
                continue;
            }
            if ($s->type === Step::DISPLAY) {
                continue;
            }
            return true;
        }
        return false;
    }

    /**
     * دکمه‌های مرحله — ثابت یا پویا (TM-16: خروجی closure با پاسخ‌های جاری).
     *
     * @return array<string,string>
     */
    private static function buttonsOf(Step $step, array $answers): array
    {
        if ($step->buttonsFn !== null) {
            $out = ($step->buttonsFn)($answers);
            return is_array($out) ? $out : [];
        }
        return $step->buttons;
    }

    /**
     * نگاشت معکوس برچسب دکمه → value canonical (TM-15).
     * خودِ value هم به‌عنوان ورودی پذیرفته می‌شود (مقاوم در برابر تایپ دستی).
     */
    private static function reverseMap(array $buttons, string $text): ?string
    {
        $t = mb_strtolower(trim($text));
        if ($t === '') {
            return null;
        }
        foreach ($buttons as $value => $label) {
            if ($t === mb_strtolower(trim(__((string) $label)))
                || $t === mb_strtolower(trim((string) $value))) {
                return (string) $value;
            }
        }
        return null;
    }

    /** نمونه‌سازی فلو از نام کلاس ذخیره‌شده در ردیف */
    private function resolve(ConversationStore $row): ?Conversation
    {
        $class = (string) $row->flow_class;
        if ($class === '' || !class_exists($class)) {
            return null;
        }
        $flow = new $class();
        return $flow instanceof Conversation ? $flow : null;
    }
}
