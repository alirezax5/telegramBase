<?php

declare(strict_types=1);

use alirezax5\TelegramBase\App\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Extend the users table to the schema the Users model actually uses.
 *
 * The framework shipped `create_users_table` with only id + timestamps,
 * while Database\Users reads/writes chatid, lang, role, active, status,
 * command, data, type and registered_at. Fresh installs get the full
 * schema from the (fixed) create migration; every install that already
 * ran the old id-only migration gets the missing columns added here.
 *
 * Idempotent by design: each column is added only when missing, so the
 * migration is safe to run against tables that were extended manually
 * (e.g. per-bot custom schemas). Re-running is a no-op.
 */
return new class extends Migration
{
    public function up(Builder $schema): void
    {
        if (!$schema->hasTable('users')) {
            return; // fresh install — create_users_table provides everything
        }

        $schema->table('users', function (Blueprint $table) use ($schema) {
            if (!$schema->hasColumn('users', 'chatid')) {
                // nullable: a NOT NULL add on a legacy table that already
                // contains rows fails on sqlite (and MySQL strict mode).
                // The model always writes chatid, so values stay non-null
                // in practice; the unique index still holds.
                $table->bigInteger('chatid')->nullable()->unique();
            }
            if (!$schema->hasColumn('users', 'username')) {
                $table->string('username', 64)->nullable();
            }
            if (!$schema->hasColumn('users', 'first_name')) {
                $table->string('first_name', 128)->nullable();
            }
            if (!$schema->hasColumn('users', 'last_name')) {
                $table->string('last_name', 128)->nullable();
            }
            if (!$schema->hasColumn('users', 'lang')) {
                // NULL = "language not chosen yet" -> show the language picker
                $table->string('lang', 5)->nullable();
            }
            if (!$schema->hasColumn('users', 'role')) {
                // user | moderator | admin (superadmin comes from env, not DB)
                $table->string('role', 16)->default('user');
            }
            if (!$schema->hasColumn('users', 'active')) {
                $table->boolean('active')->default(true);
            }
            if (!$schema->hasColumn('users', 'status')) {
                // status=false removes the user from broadcasts (ban/unsubscribe)
                $table->boolean('status')->default(true);
            }
            if (!$schema->hasColumn('users', 'command')) {
                // per-user command state (Users::updateCommand)
                $table->string('command')->nullable();
            }
            if (!$schema->hasColumn('users', 'data')) {
                // per-user data payload (Users::updateData)
                $table->text('data')->nullable();
            }
            if (!$schema->hasColumn('users', 'type')) {
                // free-form segment label (Users::getCountVip / getCountGold)
                $table->string('type', 16)->nullable();
            }
            if (!$schema->hasColumn('users', 'registered_at')) {
                $table->timestamp('registered_at')->nullable();
            }
        });
    }

    public function down(Builder $schema): void
    {
        if (!$schema->hasTable('users')) {
            return;
        }

        $schema->table('users', function (Blueprint $table) use ($schema) {
            foreach ([
                'username', 'first_name', 'last_name', 'lang', 'role',
                'active', 'status', 'command', 'data', 'type', 'registered_at',
            ] as $col) {
                if ($schema->hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
