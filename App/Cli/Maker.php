<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\Cli;

/**
 * Generates scaffolding files (plugins, middleware, classes, languages, buttons)
 * from the stub templates in this directory.
 */
final class Maker
{
    private static function parseType(array $args): array
    {
        $name = null;
        $type = 'private';

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--type=')) {
                $type = substr($arg, 7);
            } elseif (str_starts_with($arg, '--')) {
                // ignore other flags
            } elseif ($name === null) {
                $name = $arg;
            }
        }

        if ($name === null) {
            throw new \InvalidArgumentException('Missing name/path argument');
        }

        return [$name, $type];
    }

    /**
     * Create a plugin from a path like "users/register".
     */
    public static function makePlugin(string $basePath, ...$args): void
    {
        [$path, $type] = self::parseType($args);
        $path = trim($path, '/');

        $parts = array_map('ucfirst', explode('/', $path));
        $className = array_pop($parts);
        $namespace = 'alirezax5\\TelegramBase\\Plugin'
            . ($parts ? '\\' . implode('\\', $parts) : '');

        $dir = $basePath . '/Plugin/' . implode('/', $parts);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $content = strtr(
            file_get_contents(__DIR__ . '/stubs/plugin.stub'),
            [
                '{{NAMESPACE}}' => $namespace,
                '{{CLASS}}' => $className,
                '{{CHAT_TYPE}}' => $type,
            ]
        );

        $file = $dir . '/' . $className . '.php';
        if (file_exists($file)) {
            throw new \RuntimeException("File already exists: {$file}");
        }

        file_put_contents($file, $content);
        echo "✅ Created: {$file}\n";
    }

    /**
     * Create a middleware class.
     */
    public static function makeMiddleware(string $basePath, ...$args): void
    {
        [$name, ] = self::parseType($args);
        $name = ucfirst($name);

        $dir = $basePath . '/App/Middleware/Middlewares';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file = $dir . '/' . $name . '.php';
        if (file_exists($file)) {
            throw new \RuntimeException("File already exists: {$file}");
        }

        $content = strtr(
            file_get_contents(__DIR__ . '/stubs/middleware.stub'),
            ['{{CLASS}}' => $name]
        );

        file_put_contents($file, $content);
        echo "✅ Created: {$file}\n";
    }

    /**
     * Generic class scaffold (session, helper, etc).
     */
    public static function makeClassFile(string $basePath, string $kind, ...$args): void
    {
        [$name, ] = self::parseType($args);
        $name = ucfirst($name);

        $dir = $basePath . '/App/' . $kind;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file = $dir . '/' . $name . '.php';
        if (file_exists($file)) {
            throw new \RuntimeException("File already exists: {$file}");
        }

        $content = strtr(
            file_get_contents(__DIR__ . '/stubs/class.stub'),
            [
                '{{NAMESPACE}}' => "alirezax5\\TelegramBase\\App\\{$kind}",
                '{{CLASS}}' => $name,
            ]
        );

        file_put_contents($file, $content);
        echo "✅ Created: {$file}\n";
    }

    /**
     * Create a migration file with a timestamped name.
     */
    public static function makeMigration(string $basePath, ...$args): void
    {
        [$name, ] = self::parseType($args);
        $name = trim(strtolower((string)$name), '_');
        $name = preg_replace('/[^a-z0-9_]+/', '_', $name);

        if ($name === '') {
            throw new \InvalidArgumentException('Missing migration name');
        }

        $table = str_replace(['create_', '_table', 'drop_'], '', $name) ?: $name;
        $table = trim($table, '_');

        $dir = $basePath . '/Database/Migrations';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = date('Y_m_d_His') . '_' . $name . '.php';
        $file = $dir . '/' . $filename;
        if (file_exists($file)) {
            throw new \RuntimeException("File already exists: {$file}");
        }

        $content = strtr(
            file_get_contents(__DIR__ . '/stubs/migration.stub'),
            ['{{TABLE}}' => $table]
        );

        file_put_contents($file, $content);
        echo "✅ Created: {$file}\n";
    }

    /**
     * Create a language file under Language/.
     */
    public static function makeLanguage(string $basePath, ...$args): void
    {
        [$name, ] = self::parseType($args);

        $dir = $basePath . '/Language';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file = $dir . '/' . $name . '.php';
        if (file_exists($file)) {
            throw new \RuntimeException("File already exists: {$file}");
        }

        $content = <<<PHP
        <?php

        return [
            'welcome' => 'خوش آمدید',
        ];

        PHP;

        file_put_contents($file, $content);
        echo "✅ Created: {$file}\n";
    }

    /**
     * Create a button definition file.
     */
    public static function makeButton(string $basePath, ...$args): void
    {
        [$path, ] = self::parseType($args);
        $path = trim($path, '/');

        $dir = $basePath . '/Button/' . dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file = $basePath . '/Button/' . $path . '.php';
        if (file_exists($file)) {
            throw new \RuntimeException("File already exists: {$file}");
        }

        $content = <<<PHP
        <?php
        /** @var \\alirezax5\\TelegramBase\\App\\Button\\ButtonManager \$this */
        return [];

        PHP;

        file_put_contents($file, $content);
        echo "✅ Created: {$file}\n";
    }
}