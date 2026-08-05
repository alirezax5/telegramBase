<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Database;

use alirezax5\TelegramBase\App\Config\Config;
use alirezax5\TelegramBase\App\Database\Migrations\Migration;
use alirezax5\TelegramBase\App\Paths;
use Illuminate\Database\Schema\Blueprint;

/**
 * Lightweight migration runner on top of Illuminate's Schema Builder.
 *
 * Tracks applied migrations in a `migrations` table (filename, batch).
 * Commands: up (migrate), down (rollback), status.
 */
final class MigrationRunner
{
    private const TABLE = 'migrations';

    private string $dir;

    /**
     * @var \Illuminate\Database\Connection|null
     */
    private $connection;

    public function __construct(?string $dir = null, ?\Illuminate\Database\Connection $connection = null)
    {
        $this->dir = $dir ?? Paths::base() . '/Database/Migrations';
        $this->connection = $connection;

        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0755, true);
        }
    }

    /**
     * Resolve the active connection (injected or from DatabaseManager).
     */
    private function connection(): \Illuminate\Database\Connection
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        return DatabaseManager::connection();
    }

    /**
     * Path to the migrations directory.
     */
    public function dir(): string
    {
        return $this->dir;
    }

    /**
     * Ensure the migrations tracking table exists.
     */
    private function ensureTable(): void
    {
        $schema = $this->connection()->getSchemaBuilder();

        if ($schema->hasTable(self::TABLE)) {
            return;
        }

        $schema->create(self::TABLE, static function (Blueprint $table): void {
            $table->string('migration')->primary();
            $table->integer('batch');
        });
    }

    /**
     * List migration files (sorted), as [filename => absolute path].
     *
     * @return array<string, string>
     */
    public function files(): array
    {
        $files = glob($this->dir . '/*.php') ?: [];
        sort($files);

        $result = [];
        foreach ($files as $file) {
            $result[basename($file)] = $file;
        }

        return $result;
    }

    /**
     * Applied migrations from the DB: [filename => batch].
     *
     * @return array<string, int>
     */
    private function applied(): array
    {
        $this->ensureTable();

        $rows = $this->connection()
            ->table(self::TABLE)
            ->get(['migration', 'batch']);

        $result = [];
        foreach ($rows as $row) {
            $result[$row->migration] = (int)$row->batch;
        }

        return $result;
    }

    /**
     * Run pending migrations. Returns number executed.
     */
    public function up(): int
    {
        $this->ensureTable();

        $applied = $this->applied();
        $batch = $this->nextBatch();
        $ran = 0;

        foreach ($this->files() as $filename => $path) {
            if (isset($applied[$filename])) {
                continue;
            }

            $this->runFile($path, 'up');

            $this->connection()
                ->table(self::TABLE)
                ->insert(['migration' => $filename, 'batch' => $batch]);

            echo "✅ migrated: {$filename}\n";
            $ran++;
        }

        if ($ran === 0) {
            echo "Nothing to migrate.\n";
        }

        return $ran;
    }

    /**
     * Rollback the last batch. Returns number rolled back.
     */
    public function down(): int
    {
        $this->ensureTable();

        $applied = $this->applied();
        if ($applied === []) {
            echo "Nothing to rollback.\n";
            return 0;
        }

        $maxBatch = max($applied);
        $rolled = 0;

        // Rollback in reverse filename order within the last batch.
        $toRollback = [];
        foreach ($this->files() as $filename => $path) {
            if (isset($applied[$filename]) && $applied[$filename] === $maxBatch) {
                $toRollback[$filename] = $path;
            }
        }
        $toRollback = array_reverse($toRollback, true);

        foreach ($toRollback as $filename => $path) {
            $this->runFile($path, 'down');

            $this->connection()
                ->table(self::TABLE)
                ->where('migration', $filename)
                ->delete();

            echo "↩️  rolled back: {$filename}\n";
            $rolled++;
        }

        return $rolled;
    }

    /**
     * Show migration status (pending/applied).
     *
     * @return array<int, array{file: string, applied: bool}>
     */
    public function status(): array
    {
        $applied = $this->applied();

        $rows = [];
        foreach ($this->files() as $filename => $path) {
            $rows[] = [
                'file' => $filename,
                'applied' => isset($applied[$filename]),
            ];
        }

        return $rows;
    }

    private function nextBatch(): int
    {
        $max = $this->connection()
            ->table(self::TABLE)
            ->max('batch');

        return ((int)$max) + 1;
    }

    private function runFile(string $path, string $method): void
    {
        $migration = require $path;

        if (!$migration instanceof Migration) {
            throw new \RuntimeException(
                "Migration file must return an instance of " . Migration::class . ": {$path}"
            );
        }

        $schema = $this->connection()->getSchemaBuilder();

        try {
            $migration->{$method}($schema);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "Migration {$method} failed in {$path}: {$e->getMessage()}",
                0,
                $e
            );
        }
    }
}
