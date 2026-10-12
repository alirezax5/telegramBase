<?php

declare(strict_types=1);

use alirezax5\TelegramBase\App\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * مایگریشن هستهٔ Conversation — کاملاً idempotent (اجرای دوباره بی‌اثر است).
 *
 * جدول conversations: state ماندگار گفتگوهای چندمرحله‌ای به ازای هر چت.
 *   status = active    → گفتگو در جریان است (فقط همین ردیف رهگیری می‌شود)
 *   status = done      → onFinish اجرا شده؛ ردیف برای بازبینی/گزارش می‌ماند
 *   status = cancelled → با /cancel یا /start یا تریگر دوباره یا timeout بسته شده
 *
 * ستون bot_id برای ایزوله‌سازی مولتی‌بات است (فارم: مقدار از TGBASE_BOT_ID؛
 * تک‌بات: پیش‌فرض default و بی‌اثر).
 *
 * کلاس‌های هسته: App\Conversation\{Conversation, Step, ConversationManager,
 * ConversationStore} — فعال‌سازی با CORE_PLUGINS در .env (docs/conversations.md).
 */
return new class extends Migration
{
    public function up(Builder $schema): void
    {
        if (!$schema->hasTable('conversations')) {
            $schema->create('conversations', function (Blueprint $table) {
                $table->id();
                // فارم مولتی‌بات: شناسهٔ بات برای ایزوله‌سازی (تک‌بات: default)
                $table->string('bot_id', 64)->default('default');
                $table->bigInteger('chatid')->index();
                $table->string('flow', 64);            // flowKey مثلا register
                $table->string('flow_class', 191);     // FQCN پلاگین فلو
                $table->string('step', 64);            // مرحلهٔ جاری
                $table->text('answers')->nullable();   // JSON پاسخ‌ها
                $table->string('status', 16)->default('active'); // active|done|cancelled
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();     // مبنای timeout
            });
        }
    }

    public function down(Builder $schema): void
    {
        if ($schema->hasTable('conversations')) {
            $schema->drop('conversations');
        }
    }
};
