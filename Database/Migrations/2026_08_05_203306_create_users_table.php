<?php

use alirezax5\TelegramBase\App\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Create the users table.
 *
 * The historical version created only id + timestamps, while
 * Database\Users reads/writes chatid, lang, role, active, status,
 * command, data, type and registered_at — every install therefore had
 * to hand-extend the table before the model worked. Fresh installs now
 * get the full model-compatible schema out of the box.
 *
 * Installs that already ran the OLD id-only version are covered by
 * 2026_10_12_000001_extend_users_table.php (idempotent column adds),
 * which is a no-op on top of this full schema.
 */
return new class extends Migration
{
    public function up(Builder $schema): void
    {
        $schema->create('users', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('chatid')->unique(); // SIGNED (TM-22): group chat ids are negative
            $table->string('username', 64)->nullable();
            $table->string('first_name', 128)->nullable();
            $table->string('last_name', 128)->nullable();
            $table->string('lang', 5)->nullable();          // NULL = not chosen yet
            $table->string('role', 16)->default('user');    // user|moderator|admin (superadmin = env)
            $table->boolean('active')->default(true);
            $table->boolean('status')->default(true);       // false = excluded from broadcast
            $table->string('command')->nullable();          // per-user command state
            $table->text('data')->nullable();               // per-user data payload
            $table->string('type', 16)->nullable();         // segment label (vip/gold/...)
            $table->timestamp('registered_at')->nullable();
            $table->timestamps();                           // created_at used by getRecentUsers()
        });
    }

    public function down(Builder $schema): void
    {
        $schema->dropIfExists('users');
    }
};
