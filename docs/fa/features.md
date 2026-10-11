<div dir="rtl">

# امکانات — جعبه‌ابزار

همه‌چیز در این سند همراه فریمورک ارائه می‌شود و از طریق `.env` پیکربندی می‌شود (به [configuration.md](configuration.md) نگاهی بیندازید). راهنماهای عمیق‌تر: [caching.md](caching.md) · [sessions.md](sessions.md) · [i18n.md](i18n.md) · [buttons.md](buttons.md) · [m[multi-bot.md](multi-bot.md).

## سشن‌ها

وضعیت هر چت که بین پیام‌ها باقی می‌ماند و روی درایور کش شما سوار است — راهنمای کامل در [sessions.md](sessions.md):

```php
use alirezax5\TelegramBase\App\Session\SessionManager;

$session = SessionManager::start($chatId);

$session->set('step', 'waiting_email');
$step = $session->get('step');            // null when unset
$step = $session->get('step', 'default'); // with fallback

// TTL via SESSION_TTL (default 3600s); state auto-expires after silence.
```

کاربرد معمول: جریان‌های چندمرحله‌ای (ویزارد فرم‌ها، «برای ... عدد 1 را بزنید»). سشن با شناسهٔ چت کلیدگذاری می‌شود، پس هر کاربر یک ماشین وضعیت مستقل دارد.

## سازندهٔ کیبورد

ساخت fluent کیبوردهای inline و reply — راهنمای کامل در [buttons.md](buttons.md) (از جمله منوهای تعریف‌شده در فایل از طریق `ButtonManager`/`btn()`):

```php
use alirezax5\TelegramBase\App\Button\Keyboard;

// Inline keyboard — two rows
$kb = Keyboard::inline()
    ->button('Help', 'btn_help')
    ->button('Settings', 'btn_settings')
    ->row()                       // next buttons go on a new row
    ->button('Close', 'btn_close')
    ->toArray();

$Telegram->sendMessage($chatId, 'Choose:', ['reply_markup' => $kb]);

// Reply keyboard — replaces the user's virtual keyboard
$reply = Keyboard::reply()
    ->button('Option A')
    ->button('Option B')
    ->resize()      // fit text
    ->oneTime()     // hide after first tap
    ->toArray();
```

برچسب دکمه‌ها می‌توانند از طریق تعاریف `Button/` (`BUTTONS_DIR`، `BUTTONS_FILE`) از فایل‌های ترجمه بیایند.

## میدلور

پایپ‌لاینی که برای هر آپدیت **قبل از توزیع پلاگین‌ها** اجرا می‌شود — چه polling، چه وبهوک و چه صف. قبل از `run()` روی نمونهٔ `Core` ثبتش کنید:

```php
$core = new Core();
$core->middleware()->add(new RateLimitMiddleware(10, 60)); // 10 msgs / 60 s per chat
$core->middleware()->add(new AdminGuardMiddleware());      // only ADMINS_WHITELIST ids pass
$core->run();
```

میدلورها به ترتیب ثبت اجرا می‌شوند (مدل پیازی/onion). هر یک می‌تواند **عبور کند** (یعنی `$next()` را صدا بزند) یا **زنجیره را قطع کند** (بدون صدا زدن `$next()` برگردد — آپدیت رد می‌شود). ثبت با رشتهٔ نام کلاس هم پشتیبانی می‌شود و به‌صورت lazy نمونه‌سازی می‌شود.

| داخلی | هدف |
|----------|---------|
| `RateLimitMiddleware(max, window)` | محدودیت نرخ با پنجرهٔ لغزان به ازای هر شناسهٔ چت |
| `AdminGuardMiddleware` | محدودکردن آپدیت‌ها به شناسه‌های `ADMINS_WHITELIST` |
| `AuditLogMiddleware` | لاگ‌کردن هر آپدیت همراه با زمان پاسخ |

میدلور سفارشی:

```php
class MyMiddleware implements MiddlewareInterface {
    public function handle(object $update, Telegram $tg, callable $next): mixed {
        // pre-process
        $result = $next();
        // post-process
        return $result;
    }
}
```

## کش

`CacheManager` یک API یکسان روی چهار درایور (`CACHE_DRIVER`) ارائه می‌دهد: `array` (مخصوص هر پروسه)، `file`، `redis`، `memcached`. سشن‌ها، فرادادهٔ کشف پلاگین‌ها، ترجمه‌ها و برچسب‌های دکمه‌ها همگی روی آن سوارند — تغییر درایور، داستان ماندگاری کل فریمورک را بدون هیچ تغییری در کد عوض می‌کند. راهنمای کامل شامل ماتریس تأییدشدهٔ بین‌پروسه‌ای و دامنهٔ `flush()` هر درایور: [caching.md](caching.md).

## i18n

فایل‌های ترجمه در `Language/` قرار دارند (پیش‌فرض `fa`). در پلاگین‌ها از هلپر `__()` استفاده کنید — راهنمای کامل در [i18n.md](i18n.md):

```php
$Telegram->sendMessage($chatId, __('welcome_message'));
```

فایل‌های زبان آرایه‌های PHP هستند (با `php bin/tgbase make:language en` یکی بسازید). با `LANG_CACHE_TTL` کش می‌شوند. ⚠️ مقدار `LANG_DRIVER` باید با فرمت فایل‌های شما بخواند (`php` برای فایل‌های همراه — به [i18n.md](i18n.md) نگاهی بیندازید).

## چندرباته

اجرای چند ربات از یک پروسه: رجیستری `BotManager` + متغیر محیطی `BOT_EXTRA_BOTS` (ورودی‌های `name:token`) + توکن‌استک کتابخانه. هر دو سناریوی واقعی (بررسی عضویت توسط ربات شمارهٔ 2، ارسال تفویض‌شده در قالب ربات شمارهٔ 2) با شواهد زنده در [m[multi-bot.md](multi-bot.md) مستند شده‌اند.

## زمان‌بند (تسک‌های کرون)

تسک‌ها را در `bin/tasks.php` ثبت کنید و هر دقیقه از crontab اجرا کنید:

```php
// bin/tasks.php
$scheduler->call('prune_old_sessions', '0 3 * * *');   // daily 3am
$scheduler->call('send_summary',      '*/5 * * * *');  // every 5 min
```

```bash
* * * * * cd /path/to/project && php bin/scheduler.php
```

تسک‌ها با قفل محافظت می‌شوند (`SCHEDULER_LOCK_*`) تا تیک‌های همپوشان دوبار اجرا نشوند. همین نقطهٔ ورود، حالت‌های `cronjob_*` ربات را هم می‌راند: `cronjob_update` در هر تیک یک fetch از polling انجام می‌دهد؛ `cronjob_queue` ورکر صف را حداکثر به مدت `CRON_MAX_TIME` ثانیه و روی اسلات‌های قفل‌شدهٔ فایلیِ `CRON_WORKER` اجرا می‌کند.

## دیتابیس و مایگریشن‌ها

یکپارچگی اختیاری با Eloquent (`DATABASE_ENABLE=true` + اطلاعات اتصال در `Database/Connections.php`):

```bash
php bin/tgbase make:migration create_users_table
# → Database/Migrations/2026_08_05_203306_create_users_table.php

php bin/tgbase migrate            # run pending
php bin/tgbase migrate:status     # what's applied
php bin/tgbase migrate:rollback   # undo last batch
```

```php
use alirezax5\TelegramBase\App\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration {
    public function up(\Illuminate\Database\Schema\Builder $schema): void {
        $schema->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }
    public function down(\Illuminate\Database\Schema\Builder $schema): void {
        $schema->dropIfExists('users');
    }
};
```

دستورهای migrate هرگز به `BOT_TOKEN` نیاز ندارند — اعتبارسنج برای آن‌ها بررسی‌های مربوط به ربات را رد می‌کند (`TGBASE_SKIP_BOT_VALIDATION`).

## دستورهای CLI

```bash
php bin/tgbase list
# Scaffolding
make:plugin <path>          # --type=private|group|both
make:middleware <name>
make:session <name>
make:language <code>
make:button <code>
make:migration <name>
# Database
migrate / migrate:rollback / migrate:status
# Diagnostics
doctor [--telegram]         # local checks; --telegram adds one getMe call
plugins                     # discovered plugins + handlers
retry:drain                 # drain due retry-queue items on demand
```

نمونهٔ خروجی `doctor`:

```
PHP                 OK      8.4.24
Extensions          OK      curl, json, mbstring (optional: pdo_sqlite)
Configuration       OK      .env readable
Storage             OK      AppData/ + subdirs writable
Logs                OK      /path/to/logs
BOT_TOKEN           OK      format valid
Telegram API        SKIP    use --telegram to test getMe
Plugins             OK      2 plugin file(s) discovered

RESULT: OK
```

کد خروج `0` یعنی همهٔ بررسی‌ها موفق، `1` یعنی دست‌کم یک شکست.

</div>
