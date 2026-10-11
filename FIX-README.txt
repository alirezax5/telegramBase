بستهٔ اصلاح CI — مخزن telegram-base (فریمورک)
================================================

محتوا (۵ فایل — همان مسیرها را در ریشهٔ مخزن override کن):

1) tests/smoke.php
   رفع: BOT_API_URL به هر ۶ کیس اضافه شد (هم‌راستا با defaults خود tests/run.php)

2) App/Logger/LoggerConfig.php
   رفع امنیتی: گارد assertSafeLogDir() استخراج شد (همان دو چک، همان پیام‌ها)

3) App/Paths.php
   رفع امنیتی: ensureDirectories() قبل از هر mkdir این گارد را صدا می‌زند —
   مسیرهای LOG_DIR خطرناک دیگر هرگز ساخته نمی‌شوند

4) App/Cli/Doctor.php
   رفع: کد خروج ۱ فقط برای FAIL؛ WARN/SKIP اطلاعاتی‌اند (CI بدون token اجرا می‌کند)

5) .github/workflows/tests.yml
   رفع: audit بعد از install؛ حذف PHP 8.1 (EOL) از ماتریس؛ تریگرهای درست

روش اعمال:
  محتوا را روی ریشهٔ مخزن محلیت استخراج کن (پوشه‌ها merge شوند)، بعد:
  git add tests/smoke.php App/Logger/LoggerConfig.php App/Paths.php App/Cli/Doctor.php .github/workflows/tests.yml
  git commit -m "Fix CI: smoke BOT_API_URL, log-dir guard before mkdir, doctor exit code, workflow order"
  git push

راه تأیید سریع قبل از push:
  در tests/smoke.php عبارت BOT_API_URL باید دقیقاً ۶ بار باشد.
  بعد از push، در GitHub فایل tests/smoke.php را باز کن — همان ۶ مورد باید دیده شود.
