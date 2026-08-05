<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Database\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Base class for user migrations.
 *
 * Extend this and implement up()/down(). The $schema argument is
 * the Illuminate Builder, usable with Schema::create/drop/table.
 *
 * Example:
 *   return new class extends Migration {
 *       public function up(Builder $schema): void { ... }
 *       public function down(Builder $schema): void { ... }
 *   };
 */
abstract class Migration
{
    /**
     * Apply the migration.
     */
    abstract public function up(Builder $schema): void;

    /**
     * Reverse the migration.
     */
    abstract public function down(Builder $schema): void;
}