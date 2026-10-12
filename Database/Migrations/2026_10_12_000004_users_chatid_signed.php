<?php

declare(strict_types=1);

use alirezax5\TelegramBase\App\Database\Migrations\Migration;
use Illuminate\Database\Schema\Builder;

/**
 * Make users.chatid SIGNED so group chat ids (negative) can be stored.
 *
 * The historical column was unsignedBigInteger — MySQL clamps any
 * negative value (supergroup/group chat id) to 0, so per-chat state
 * for group chats (admin panel state machine: adm_scope, bulk,
 * adm_search, sendto, …) silently failed: setState() hit 0 rows and
 * stateOf() never saw the value. This is TM-22 (payam admin panel in
 * the admin/test group).
 *
 * Idempotent: converts only when the column is still unsigned; mysql
 * only (sqlite has no unsigned bigint concept — no-op there).
 */
return new class extends Migration
{
    public function up(Builder $schema): void
    {
        if (!$schema->hasTable('users') || !$schema->hasColumn('users', 'chatid')) {
            return;
        }

        $conn = $schema->getConnection();
        if ($conn->getDriverName() !== 'mysql') {
            return; // sqlite/postgres bigint is already signed
        }

        $col = $conn->selectOne(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS '
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'chatid'"
        );

        if ($col !== null && stripos((string) $col->COLUMN_TYPE, 'unsigned') !== false) {
            $conn->statement('ALTER TABLE users MODIFY chatid BIGINT NOT NULL');
        }
    }

    public function down(Builder $schema): void
    {
        // deliberately not reversible: converting back to unsigned would
        // clamp existing negative (group) ids to 0 and corrupt state.
    }
};
