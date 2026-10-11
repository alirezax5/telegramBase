<div dir="rtl">

# چندرباته — اجرای دو ربات در یک پروسه

TelegramBase عملیات چندرباته را در دو سطح پشتیبانی می‌کند: کتابخانهٔ پایه یعنی
[`telegramBotPhp`](https://github.com/alirezax5/telegramBotPhp) اجزای اولیه را فراهم می‌کند
(نمونه‌های مستقل، توکن‌استک، فراخوانی‌های خام)، و فریمورک یک رجیستری
**`BotManager`** اضافه می‌کند تا پلاگین‌ها بتوانند با نام به ربات‌های ثانویه دسترسی پیدا کنند.

هر دو سناریوی درخواستیِ مالک به‌صورت زنده (2026-10-11) با دو ربات واقعی تأیید شده‌اند:

| سناریو | چه اتفاقی می‌افتد | نتیجهٔ تأییدشده |
|---|---|---|
| **A — بررسی عضویت توسط ربات شمارهٔ 2** | کاربر از ربات 1 می‌پرسد؛ ربات 1 دستور `getChatMember()` را **با توکن ربات 2** اجرا می‌کند | `ok=true`، `status=member`، 13 میلی‌ثانیه |
| **B — ارسال تفویض‌شده** | کاربر از ربات 1 می‌پرسد؛ ربات 1 کاری می‌کند که **ربات 2 پیام را بفرستد**، همه داخل پروسهٔ ربات 1 | تحویل شد، 239 میلی‌ثانیه |

---

## ثبت ربات‌های اضافی: `BOT_EXTRA_BOTS`

این متغیر محیطی را قبل از بوت اضافه کنید (در `.env` یا محیط پروسه):

```dotenv
BOT_EXTRA_BOTS="second:398172019:AAxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
```

قواعد فرمت:

- ورودی‌ها **با کاما جدا می‌شوند**؛ هر ورودی `name:token` است؛
- جدا کردن فقط روی **اولین** دونقطه انجام می‌شود (خودِ توکن ربات یک دونقطه دارد)؛
- نام `main` برای ربات اصلی رزرو است و در این لیست نادیده گرفته می‌شود؛
- ورودی‌های ناقص لاگ می‌شوند (هشدار) و رد می‌شوند — بوت هرگز شکست نمی‌خورد.

`BotManager` یک سینگلتون است که در اولین استفاده ساخته می‌شود؛ ربات پیش‌فرض (`main`) از
`Config::bot()` ساخته می‌شود (یعنی از `BOT_TOKEN`) و بعد ربات‌های اضافی ثبت می‌شوند.

## استفاده از ربات‌ها از داخل یک پلاگین

```php
use alirezax5\TelegramBase\App\Bot\BotManager;
use alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface;
use telegramBotApiPhp\Telegram;

class MultiBotPlugin implements PluginInterface
{
    public function getPriority(): int { return 1; }

    public function onMessage($message, Telegram $tg): void
    {
        if ($message->text !== '/inchannel') {
            return;
        }

        $bm  = BotManager::getInstance();
        $userId = $tg->fromId();
        $chatId = $tg->chatId();

        // Scenario A — membership check executed by bot #2's identity
        if ($bm->has('second')) {
            $member = $bm->get('second')->getChatMember('@mychannel', $userId);
            $status = $member->result->status ?? 'left';   // member|administrator|creator|left|kicked
            $tg->sendMessage($chatId, "وضعیت عضویت شما (چک توسط ربات ۲): {$status}");
        } else {
            $tg->sendMessage($chatId, 'ربات دوم ثبت نشده — BOT_EXTRA_BOTS را تنظیم کنید');
        }

        // Scenario B — bot #1 sends AS bot #2 (token stack on the main instance)
        if ($bm->has('second')) {
            $tg->switchToken($bm->get('second')->getToken());
            try {
                $tg->sendMessage($chatId, 'این پیام با هویت ربات ۲ ارسال شد (داخل پروسه ربات ۱)');
            } finally {
                $tg->restoreToken();
            }
        }
    }
}
```

## API مربوط به `BotManager`

| متد | رفتار |
|---|---|
| `getInstance(): BotManager` | دسترسی به سینگلتون |
| `get(?string $name): Telegram` | ربات با نام مشخص را برمی‌گرداند؛ **نام‌های ناشناخته به `main` برمی‌گردند** (با هشدار لاگ‌شده) |
| `has(string $name): bool` | آیا این نام ثبت شده است؟ — **همیشه قبل از `get()` یک ربات غیرپیش‌فرض بررسی کنید** |
| `add(string $name, Telegram $bot): void` | ثبت/بازنویسی یک ربات در زمان اجرا (بازنویسی با هشدار لاگ می‌شود) |
| `names(): string[]` | همهٔ نام‌های ثبت‌شده (اول `main`) |
| `setDefaultToken(string $token): Telegram` | توکن ربات **اصلی** را به‌صورت سراسری بازنویسی می‌کند — روی همهٔ فراخوان‌ها اثر می‌گذارد |

## چرا `has()` مهم است

`get('typo-name')` استثنا پرتاب نمی‌کند — بی‌صدا ربات **اصلی** را برمی‌گرداند. در آن حالت
یک `getChatMember()` که برای ربات 2 در نظر گرفته شده بود با هویت ربات 1 اجرا می‌شود و
`chat member not found` برمی‌گرداند یا بدتر، پاسخی غلط اما ظاهراً معقول. هر
دسترسی غیرپیش‌فرض را با `has()` محافظت کنید.

## مدیریت توکن: چند ربات روی یک فایل

یک استقرار (یک فایل ورودی + یک `.env`) می‌تواند به هر تعداد هویت ربات
سرویس بدهد. سه راهبرد پشتیبانی می‌شود — به ترتیب میزان توصیه:

| # | راهبرد | توکن‌ها کجا زندگی می‌کنند | مناسب برای | تأییدشده |
|---|---|---|---|---|
| 1 | **رجیستری ایستا** — `BOT_TOKEN` + `BOT_EXTRA_BOTS` در `.env` | یک `.env`، جفت‌های `name:token` | مجموعهٔ ثابتی از ربات‌هایی که با هم مستقر می‌شوند | 23/23 بررسی (parse / identity / degrade / scale) |
| 2 | **رجیستری پویا** — `BotManager::add($name, new Telegram($token))` در زمان اجرا | پنل دیتابیس/پیکربندی شما؛ به پروسه پاس داده می‌شود | ربات‌های فراهم‌شده توسط یک پنل ادمین، ربات‌های مخصوص هر مستأجر | بررسی‌های identity + ارسال تفویض‌شده |
| 3 | **ربات‌های مدیریت‌شده** — توکن‌های فرزند به‌صورت زنده از تلگرام دریافت می‌شوند (بخش بعدی) | **هیچ‌جا روی دیسک** | ربات مدیر که فرزندها را به نمایندگی از کاربران می‌سازد | مجموعهٔ TM-2، 8/8 |

قواعدی که مجموعهٔ تست زنده اثبات می‌کند (2026-10-11، دو ربات واقعی + ورودی‌های مصنوعی):

- ورودی‌های ناقص `BOT_EXTRA_BOTS` لاگ می‌شوند و **رد می‌شوند — بوت هرگز شکست نمی‌خورد**؛
- یک توکن مرده/باطل‌شده تمیز شکست می‌خورد (`ok=false`، `Unauthorized`) و هرگز
  بقیهٔ ربات‌ها را مسموم نمی‌کند: `get('dead')` خطا می‌دهد، `get('alive')` و `main`
  سالم می‌مانند؛
- `main` رزرو است؛ نام‌های تکراری طوری حل می‌شوند که آخرین ورودی برنده باشد و هشداری لاگ شود؛
- دونقطهٔ خودِ توکن از جداسازی `name:token` جان سالم به در می‌برد (فقط اولین دونقطه)؛
- ایزولاسیون هویت: `getMe()` هر نمونهٔ ثبت‌شده با پیشوند شناسهٔ توکن خودش
  مطابقت دارد؛ توکن‌استک مخصوص هر نمونه است (switch/restore روی `main`
  `second` را دست‌نخورده می‌گذارد)؛
- جستجوهای رجیستری O(1) هستند: 10,000 فراخوانی `get()` با 32 ربات ثبت‌شده
  در حدود 1 میلی‌ثانیه.

نکتهٔ وب‌هوک: در حالت‌های `webhook_*` هر آپدیتی که به `webhook.php` می‌رسد، تحت
هویت ربات **اصلی** پردازش می‌شود (یک `BOT_WEBHOOK_SECRET` مشترک).
برای سرو کردن چندین ربات با وب‌هوک لازم نیست برای هر ربات یک دایرکتوری بسازید —
الگوی `secret_token` در بخش بعد **هر تعداد ربات را با یک فایل وب‌هوک** سرو
می‌کند؛ به‌صورت زنده اثبات شده است.

## چند ربات با یک فایل وب‌هوک — الگوی `secret_token`

برای هر ربات یک پوشه لازم نیست. **یک فایل وب‌هوک می‌تواند هر تعداد ربات را سرو
کند.** راز ماجرا پارامتر `secret_token` در `setWebhook` است:

1. برای هر ربات **یک secret تصادفی جداگانه** انتخاب کنید (فقط حرف، رقم، `_` و
   `-` مجاز است).
2. برای همهٔ ربات‌ها **همان یک آدرس وب‌هوک** را ثبت کنید، هر کدام با secret خودش:
   `setWebhook?url=https://example.com/hook.php&secret_token=<secret-in-rabat>`
3. تلگرام روی هر آپدیت همان secret را در هدر `X-Telegram-Bot-Api-Secret-Token`
   برمی‌گرداند.
4. فایل شما هدر را می‌خواند، secret را در یک رجیستری کوچک
   (`secret → توکن ربات`) نگاه می‌کند و آپدیت را با هویت همان ربات پردازش می‌کند.

```text
             ┌─ ربات A: setWebhook(url=hook.php, secret=SECRET_A)
 تلگرام ─────┤
             └─ ربات B: setWebhook(url=hook.php, secret=SECRET_B)
                         │   POST + هدر X-Telegram-Bot-Api-Secret-Token
                         ▼
               ┌───────────────────┐   SECRET_A → توکن A → رفتار مثل ربات A
               │     hook.php      │
               │  رجیستری:         │
               │  secret → توکن    │   SECRET_B → توکن B → رفتار مثل ربات B
               └───────────────────┘
```

چرا این بهترین راه برای «مزرعهٔ ربات» است:

- **توکن ربات هرگز در URL دیده نمی‌شود** — نمی‌تواند به لاگ‌های دسترسی،
  تاریخچهٔ مرورگر یا پروکسی‌ها درز کند؛
- خودِ secret یک **بررسی اصالت** هم هست — درخواستی که secret درست نداشته باشد
  از تلگرام نیست؛ جواب `403` بدهید و رهایش کنید؛
- **اضافه‌کردن ربات جدید = یک فراخوانی `setWebhook` و یک خط در رجیستری** —
  بدون فایل جدید، بدون استقرار جدید.

اثبات زنده (2026-10-11، دو ربات واقعی، یک فایل، ترافیک واقعی کاربر):

| رویداد | هویت مسیریابی‌شده | نتیجه |
|---|---|---|
| `/start` واقعی روی ربات B | @instaroonxbot | پاسخ داد، `ok:true` |
| تپ واقعی روی دکمهٔ ربات B (×2) | @instaroonxbot | `answerCallbackQuery` + پاسخ، `ok:true` |
| تپ واقعی روی دکمهٔ ربات A | @emojiCustom_bot | `answerCallbackQuery` + پاسخ، `ok:true` |
| POST مصنوعی با secret A / secret B | هر بار ربات درست | پاسخ داد، `ok:true` |

۷/۷ تحویل — همهٔ آپدیت‌ها به همان یک فایل رسیدند و هر پاسخ هویت درست ربات را
داشت. توکن ربات هرگز در هیچ URL یا هدری نبود.

قواعد سخت‌سازی برای پروداکشن:

- خودِ secret **کلید مسیریابی** است — باید برای هر ربات یکتا باشد؛
- تصادفی بسازیدش، ۳۲+ کاراکتر از `[A-Za-z0-9_-]` (تلگرام کاراکتر دیگر را رد
  می‌کند)؛ secret دوکاراکتری عملاً یعنی بدون secret؛
- قبل از هر کاری، secret ناشناس یا غایب را با `403` رد کنید؛
- هرگز توکن لاگ نکنید؛ حداکثر یک پیشوند کوتاه از secret؛
- چرخش secret = یک بار دیگر `setWebhook` برای همان ربات (چند ثانیه طول می‌کشد).

## ربات‌های مدیریت‌شده (Managed Bots — Bot API 9.6)

Bot API 9.6 تلگرام (2026-04-03) قابلیت **ربات‌های مدیریت‌شده (Managed Bots)** را
اضافه کرد: رباتی که `can_manage_bots` دارد (فعال‌شده در Mini App @BotFather)
می‌تواند ربات‌های دیگری را زیر خود ساخته‌شده و تحت کنترل داشته باشد. توکن فرزند
هرگز نیازی به ذخیره‌شدن ندارد — مدیر آن را با `getManagedBotToken(user_id)`
به‌صورت زنده دریافت می‌کند.

پشتیبانی فریمورک (v2.4.x):

| عنصر API (Bot API 9.6) | سطح فریمورک |
|---|---|
| `User.can_manage_bots` (getMe) | `$tg->callRaw('getMe')->result->can_manage_bots` |
| آپدیت `managed_bot` (`ManagedBotUpdated`) | به `onManagedBot($managedBot, $tg)` توزیع می‌شود — به لیست سفید پلاگین اضافه شده |
| `KeyboardButtonRequestManagedBot` | `Keyboard::reply()->requestManagedBot($text, $requestId, $suggestedName, $suggestedUsername)` |
| `getManagedBotToken` / `replaceManagedBotToken` / `get·setManagedBotAccessSettings` | `BotManager::registerManagedBot()` / `refreshManagedBot()` / `managedBotAccessSettings()` |
| فیلد پیام `ManagedBotCreated` | داخل payload در `onMessage` دیده می‌شود |

جریان سرتاسری (طرح پلاگین):

```php
use alirezax5\TelegramBase\App\Bot\BotManager;
use alirezax5\TelegramBase\App\Button\Keyboard;

// 1) offer creation (private chats only)
$tg->sendMessage($chatId, 'Create your own bot:', [
    'reply_markup' => Keyboard::reply()
        ->requestManagedBot('➕ Create my bot', 1, 'MyHelperBot', 'my_helper_bot')
        ->toArray(),
]);

// 2) the user completes the flow in Telegram → managed_bot update arrives
public function onManagedBot($managedBot, $tg): void
{
    // fetches the child token live (getManagedBotToken) and registers it
    BotManager::getInstance()->registerManagedBotFromUpdate($managedBot);
}

// 3) use the child bot — registered under its bot id
BotManager::getInstance()->get((string) $managedBot->bot->id)
    ->sendMessage($chatId, 'hello from the child bot');
```

پیش‌نیازها (اقدامات مالک، یک‌باره):

1. **مدیریت ربات‌های دیگر** را برای ربات مدیر در Mini App
   @BotFather فعال کنید — تا آن موقع `getMe` مقدار `can_manage_bots: false` را
   گزارش می‌کند و درخواست ساخت شکست می‌خورد.
2. خودِ ساخته‌شدن ربات فرزند همیشه در سمت تلگرام انجام می‌شود (رضایت
   کاربر)؛ فریمورک فقط آپدیت `managed_bot` را دریافت می‌کند و توکن را
   می‌گیرد.

بهداشت توکن: توکن‌های مدیریت‌شده فقط در حافظه (رجیستری) زندگی می‌کنند — هیچ‌چیز
در `.env` یا لاگ‌ها نوشته نمی‌شود. چرخش توکنِ یک فرزند از طریق
`BotManager::refreshManagedBot($userId, $name)` توکن قدیمی را بلافاصله باطل
می‌کند و توکن جدید را دوباره ثبت می‌کند.

### Managed Bots — آزمون زندهٔ سرتاسر (2026-10-11)

بعد از اینکه مالک **مدیریت ربات‌های دیگر** را در Mini App @BotFather فعال کرد،
`getMe` روی هر دو ربات تست به `can_manage_bots: true` تغییر کرد و جریان به‌صورت
زنده آزموده شد:

- دکمهٔ ساخت که `Keyboard::reply()->requestManagedBot(...)` می‌سازد در چت خصوصی
  فرستاده شد — تلگرام شکل کیبورد فریمورک را همان‌طور که هست پذیرفت؛
- نکتهٔ میدانی مهم: برای `KeyboardButtonRequestManagedBot` مقدار `request_id`
  باید **عدد** باشد (برخلاف `request_chat` / `request_users` که رشته‌اند —
  تلگرام رشته را با `Bad Request: can't parse KeyboardButton: Field
  "request_id" must be a valid Number` رد می‌کند)؛
- وقتی کاربر فرم ساخت را کامل کند، مدیر آپدیت `managed_bot` می‌گیرد و توکن
  فرزند را زنده با `getManagedBotToken(user_id)` می‌خواند — در دموی مستقر،
  ربات فرزند همان لحظه با توکن تازه‌گرفتهٔ خودش در چت مالک پیام می‌دهد
  (صفر توکن روی دیسک).

فعال‌کردن همان تنظیمات Mini App روی هر دو ربات تست، این‌ها را هم true کرد:
`supports_guest_queries`، `has_topics_enabled`،
`supports_join_request_queries`، `can_connect_to_business`.

- شکل واقعی آپدیت (کپچر 2026-10-11): `managed_bot.user` = شخصی که فرزند را ساخته (یک کاربر عادی)، `managed_bot.bot` = خودِ ربات فرزند (`{id, is_bot: true, username, ...}`). به `getManagedBotToken` مقدار `managed_bot.bot.id` را بدهید — id سازنده خطای `Bad Request: user is not a bot` می‌دهد. دمو توکن فرزند را زنده گرفت و فرزند یک پیام واقعی به سازندهٔ خودش تحویل داد — صفر توکن روی دیسک.

### توکن و secret کجا نگه داریم؟

| محل ذخیره | کجا مناسب است | مواظب باشید |
|---|---|---|
| **`.env`** (`BOT_TOKEN` + `BOT_EXTRA_BOTS`) | مجموعهٔ کوچک و ثابت ربات‌ها | فقط قابل‌خواندن توسط root؛ تغییرات ری‌استارت می‌خواهد |
| **جدول رجیستری در دیتابیس** (`bots`: id, name, token, secret, status) | ربات‌هایی که در زمان اجرا اضافه/حذف می‌شوند، پنل ادمین | ستون توکن را در حالت سکون رمز کنید (مثلاً sodium)؛ رجیستری را کش کنید تا هر آپدیت یعنی یک کوئری دیتابیس |
| **Managed Bots (صفر روی دیسک)** | ربات مدیری که برای کاربران فرزند می‌سازد | `can_manage_bots` لازم دارد؛ توکن زنده با `getManagedBotToken` خوانده می‌شود |
| **secret مشتق‌شده (HMAC)** — هیچ‌چیز ذخیره نمی‌شود | شما یک کلید master را کنترل می‌کنید | `secret = base64url(hmac_sha256(master_key, 'bot:' . bot_id))`؛ چرخش کلید master یعنی ثبت دوبارهٔ همهٔ وبهوک‌ها |

پیشنهاد برای مزرعهٔ رباتِ در حال رشد: **رجیستری دیتابیس (توکن رمزشده در حالت
سکون) + کش برای lookup + مسیریابی `secret_token`**، و برای فرزندهای ساخته‌شده
توسط کاربران، **Managed Bots**. `.env` را فقط برای اولین ربات مدیر نگه دارید.

### داده‌های هر ربات جدا بماند

وقتی چند ربات یک استقرار را شریک‌اند، داده‌های هر ربات باید انحصاری همان ربات
بماند. **همهٔ لایه‌های ذخیره‌سازی را با شناسهٔ ربات** (`b{bot_id}`) فضانام کنید:

| لایه | قاعده |
|---|---|
| کش | پیشوند کلید `b{bot_id}:` (مقدار `CACHE_PREFIX` را موقع بوت به‌ازای هر ربات ست کنید) |
| سشن‌ها | محدوده با **شناسهٔ ربات + شناسهٔ کاربر** — یک نفر که با دو ربات حرف می‌زند یعنی دو سشن جدا |
| آفست getUpdates | **یک فایل/کلید آفست برای هر ربات** — آفست مشترک آپدیت‌های یک ربات را به دیگری می‌خوراند |
| صف | یک نام صف برای هر ربات (`tgbase_b{bot_id}_updates`) یا برچسب `bot_id` روی payloadها |
| ذخیرهٔ فایل | `data/bots/{bot_id}/…` |
| لاگ‌ها | یک فایل لاگ برای هر ربات (`logs/bot_{id}.log`) |
| دیتابیس | جدول‌های مشترک ستون `bot_id` بگیرند؛ ایزولاسیون کامل = یک دیتابیس جدا برای هر ربات |

فرانت‌کنترلر وب‌هوک اول `secret → ربات` را حل می‌کند، بعد فریمورک را با توکن و
فضانام همان ربات بوت می‌کند — از آن به بعد هر خواندن/نوشتن خودبه‌خود داخل
سندباکس خودِ همان ربات می‌ماند.

## معناشناسی تحویل

فراخوانی‌های چندرباته روی همان کلاینت HTTP سوارند که بقیهٔ فراخوانی‌های API:

- retry/backoff و مدیریت `retry_after` به ازای هر نمونه اعمال می‌شوند؛
- توکن‌استک **وضعیت مخصوص هر نمونه** است — در ورکرهای صف هر آپدیت با یک توزیع تازه
  پردازش می‌شود، پس جفت‌های switch/restore در طول عمر همان یک آپدیت می‌مانند؛ هرگز
  به وضعیت استک در طول چند آپدیت تکیه نکنید؛
- در حالت‌های `webhook_*` همین قواعد داخل آن درخواست واحد FPM برقرارند.

## بستر تأیید زنده

`integration/TestBot/Plugin/MultiBotTestPlugin.php` (بستر تست، چت‌های خصوصی):

- `/multibot` — مجموعهٔ کامل: هویت، ایزولاسیون، ارسال متقاطع (سناریوی B)،
  عضویت از طریق ربات 2 (سناریوی A)، جابه‌جایی/بازگردانی توکن‌استک؛
- `/mbchannel <chat>` — اجرای `getChatMember` توسط ربات 2 روی یک `@channel`/شناسهٔ چت مشخص
  (ربات 2 باید ادمین/عضو آن چت باشد).

لاگ شواهد: `runtime/p331/multibot.log` — آخرین اجرای زنده
(2026-10-11 12:26:19): هویت ✔، ایزولاسیون ✔، ارسال متقاطع ✔ (239 میلی‌ثانیه)،
عضویت ✔ (13 میلی‌ثانیه)، توکن‌استک ✔ — **5/5 PASS در 393 میلی‌ثانیه**.

اجراهای اضافی بستر (2026-10-11، ران‌تایم زنده پس از همگام‌سازی):

- **مجموعهٔ مدیریت توکن TM-1** — 23/23 PASS در چهار حالت:
  `parse` (7)، `identity` (8، دو ربات واقعی)، `degrade` (5، توکن باطل‌شده)،
  `scale` (3، 32 ربات / 10k جستجو).
- **مجموعهٔ Managed Bots (TM-2)** — 8/8 PASS: `getMe` زنده
  `can_manage_bots` را نمایان می‌کند؛ `getManagedBotToken` / `getManagedBotAccessSettings`
  با قراردادهای خطای تمیز در دسترس‌اند؛ آپدیت مصنوعی `managed_bot`
  به `before → onManagedBot` توزیع می‌شود؛ `registerManagedBot()` نامعتبر
  تمیز استثنا پرتاب می‌کند و رجیستری را مسموم نمی‌کند؛ شکل کیبوردِ
  `requestManagedBot()` با `KeyboardButtonRequestManagedBot` مطابقت دارد.

</div>
