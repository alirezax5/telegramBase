<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\Cli;

use alirezax5\TelegramBase\App\Paths;

/**
 * Minimal CLI command dispatcher for the scaffolding commands.
 *
 *   php bin/tgbase make:plugin users/register --type=private
 *   php bin/tgbase make:middleware RateLimit
 *   php bin/tgbase make:session UserProfile
 *   php bin/tgbase make:language fa
 *   php bin/tgbase make:button menu
 *   php bin/tgbase list
 */
final class Console
{
    private string $basePath;

    /** @var array<int, string> */
    private array $argv;

    /** @var array<string, callable> */
    private array $commands = [];

    public function __construct(array $argv)
    {
        $this->basePath = Paths::base();
        $this->argv = $argv;
        $this->registerCommands();
    }

    private function registerCommands(): void
    {
        $this->commands = [
            'make:plugin' => fn (array $args) => Maker::makePlugin($this->basePath, ...$args),
            'make:middleware' => fn (array $args) => Maker::makeMiddleware($this->basePath, ...$args),
            'make:session' => fn (array $args) => Maker::makeClassFile($this->basePath, 'Session', ...$args),
            'make:language' => fn (array $args) => Maker::makeLanguage($this->basePath, ...$args),
            'make:button' => fn (array $args) => Maker::makeButton($this->basePath, ...$args),
            'make:migration' => fn (array $args) => Maker::makeMigration($this->basePath, ...$args),
            'migrate' => fn (array $args) => Migrate::run(),
            'migrate:rollback' => fn (array $args) => Migrate::rollback(),
            'migrate:status' => fn (array $args) => Migrate::status(),
            'list' => fn (array $args) => $this->list(),
            'help' => fn (array $args) => $this->help(),
        ];
    }

    public function run(): int
    {
        $command = $this->argv[1] ?? 'help';

        if (!isset($this->commands[$command])) {
            fwrite(STDERR, "Unknown command: {$command}\n");
            $this->help();
            return 1;
        }

        $args = array_slice($this->argv, 2);

        try {
            ($this->commands[$command])($args);
            return 0;
        } catch (\Throwable $e) {
            fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
            return 1;
        }
    }

    private function list(): void
    {
        echo "Available commands:\n";
        foreach (array_keys($this->commands) as $c) {
            echo "  {$c}\n";
        }
    }

    private function help(): void
    {
        $this->list();
        echo "\nExamples:\n";
        echo "  php bin/tgbase make:plugin users/register --type=private\n";
        echo "  php bin/tgbase make:middleware RateLimit\n";
        echo "  php bin/tgbase make:session UserProfile\n";
        echo "  php bin/tgbase make:migration create_users_table\n";
        echo "  php bin/tgbase migrate\n";
        echo "  php bin/tgbase migrate:status\n";
        echo "  php bin/tgbase migrate:rollback\n";
        echo "  php bin/tgbase make:language fa\n";
        echo "  php bin/tgbase make:button menu\n";
    }
}