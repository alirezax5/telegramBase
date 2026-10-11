<div dir="rtl">

# الميزات — صندوق الأدوات

كل ما في هذه الوثيقة مشحون مع الإطار ويُضبط عبر `.env` (انظر [configuration.md](configuration.md)). التفاصيل المعمّقة: [caching.md](caching.md) · [sessions.md](sessions.md) · [i18n.md](i18n.md) · [buttons.md](buttons.md) · [m[multi-bot.md](multi-bot.md).

## الجلسات

حالة لكل محادثة تنجو بين الرسائل، مدعومة بمُشغِّل الكاش لديك — الدليل الكامل في [sessions.md](sessions.md):

```php
use alirezax5\TelegramBase\App\Session\SessionManager;

$session = SessionManager::start($chatId);

$session->set('step', 'waiting_email');
$step = $session->get('step');            // null when unset
$step = $session->get('step', 'default'); // with fallback

// TTL via SESSION_TTL (default 3600s); state auto-expires after silence.
```

الاستخدام النموذجي: التدفقات متعددة الخطوات (معالجات نماذج، «اضغط 1 من أجل...»). الجلسة مُفتّحة بمعرف المحادثة، فيملك كل مستخدم آلة حالات مستقلة.

## باني لوحات المفاتيح

بناء سلس للوحات المفاتيح المضمنة ولوحات الرد — الدليل الكامل في [buttons.md](buttons.md) (بما فيها القوائم المعرفة في ملفات عبر `ButtonManager`/`btn()`):

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

يمكن أن تأتي تسميات الأزرار من ملفات الترجمة عبر تعريفات `Button/` (‏`BUTTONS_DIR`، `BUTTONS_FILE`).

## الوسطاء البرمجيون

خط أنابيب يعمل **قبل توزيع الإضافات** لكل تحديث — في الاستطلاع والويب هوك وقائمة الانتظار على السواء. سجّله على نسخة `Core` قبل `run()`:

```php
$core = new Core();
$core->middleware()->add(new RateLimitMiddleware(10, 60)); // 10 msgs / 60 s per chat
$core->middleware()->add(new AdminGuardMiddleware());      // only ADMINS_WHITELIST ids pass
$core->run();
```

تُنفَّذ الوسائط البرمجية بترتيب التسجيل (نموذج البصلة). يمكن لكل وسيط أن **يمرّر** (باستدعاء `$next()`) أو **يقطع مبكرًا** (يعود دون استدعاء `$next()` — فيُرفض التحديث). التسجيل بنص الصنف مدعوم ويُنشأ نسخة منه بشكل مؤجل.

| مدمج | الغرض |
|----------|---------|
| `RateLimitMiddleware(max, window)` | حد معدل بنافذة منزلقة لكل معرف محادثة |
| `AdminGuardMiddleware` | يقيّد التحديثات بـ `ADMINS_WHITELIST` |
| `AuditLogMiddleware` | تسجيل كل تحديث مع زمن الاستجابة |

وسيط برمجي مخصص:

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

## التخزين المؤقت (كاش)

يكشف `CacheManager` واجهة API واحدة فوق أربعة مُشغِّلات (`CACHE_DRIVER`): ‏`array` (لكل عملية)، و`file`، و`redis`، و`memcached`. الجلسات وبيانات اكتشاف الإضافات الوصفية والترجمات وتسميات الأزرار كلها تركب عليه — تغيير المُشغِّل يغيّر قصة التخزين الدائم للإطار كله دون تعديل الكود. الدليل الكامل بما فيه مصفوفة العبور بين العمليات المُتحقق منها ونطاق `flush()` لكل مُشغِّل: [caching.md](caching.md).

## تعدد اللغات (i18n)

ملفات الترجمة تسكن في `Language/` (الافتراضي `fa`). استخدم الدالة المساعدة `__()` في الإضافات — الدليل الكامل في [i18n.md](i18n.md):

```php
$Telegram->sendMessage($chatId, __('welcome_message'));
```

ملفات اللغة مصفوفات PHP (ولّد واحدة بـ `php bin/tgbase make:language en`). تُخزَّن في الكاش بـ `LANG_CACHE_TTL`. ⚠️ يجب أن يطابق `LANG_DRIVER` تنسيق ملفاتك (`php` للملفات المشحونة — انظر [i18n.md](i18n.md)).

## تعدد البوتات

شغّل عدة بوتات من عملية واحدة: سجل `BotManager` + متغير البيئة `BOT_EXTRA_BOTS` (مدخلات بصيغة `name:token`) + مجموعة الرموز في المكتبة. كلا السيناريوهين الواقعيين (فحص العضوية منفَّذًا بواسطة البوت رقم 2، والإرسال المفوَّض بوصفه البوت رقم 2) موثّقان بأدلة مباشرة في [m[multi-bot.md](multi-bot.md).

## المجدول (مهام cron)

سجّل المهام في `bin/tasks.php`، وشغّلها كل دقيقة من crontab:

```php
// bin/tasks.php
$scheduler->call('prune_old_sessions', '0 3 * * *');   // daily 3am
$scheduler->call('send_summary',      '*/5 * * * *');  // every 5 min
```

```bash
* * * * * cd /path/to/project && php bin/scheduler.php
```

المهام محمية بأقفال (`SCHEDULER_LOCK_*`) فلا تُشغَّل النبضات المتداخلة مرتين. نقطة الدخول نفسها تقود أوضاع البوت `cronjob_*`: تنفّذ `cronjob_update` جلب استطلاع واحدًا لكل نبضة؛ وتشغّل `cronjob_queue` عامل قائمة الانتظار حتى `CRON_MAX_TIME` ثانية عبر فتحات `CRON_WORKER` المقفلة بملفات.

## قاعدة البيانات والترحيلات

تكامل Eloquent اختياري (`DATABASE_ENABLE=true` + بيانات الاعتماد في `Database/Connections.php`):

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

لا تتطلب أوامر الترحيل `BOT_TOKEN` أبدًا — يتخطى المُتحقق الفحوصات المتعلقة بالبوت لأجلها (`TGBASE_SKIP_BOT_VALIDATION`).

## أوامر CLI

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

مثال على مخرجات `doctor`:

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

رمز الخروج `0` = كل الفحوصات ناجحة، و`1` = فشل فحص واحد على الأقل.

</div>
