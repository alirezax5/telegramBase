<?php

declare(strict_types=1);

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

require_once __DIR__ . '/../../vendor/autoload.php';

echo "Running Telegram Base setup...\n";

$filesystem = new Filesystem();

$folders = [
    "logs",
    "AppData",
    "Migration",
    "Plugin",
    "Database",
];

try {

    foreach ($folders as $folder) {
        if (!$filesystem->exists($folder)) {
            $filesystem->mkdir($folder, 0777);
            echo "✔ Created folder: {$folder}\n";
        } else {
            echo "ℹ Folder already exists: {$folder}\n";
        }
    }

    $envExample = __DIR__ . '/../../.env.example';
    $envTarget = __DIR__ . '/../../.env';

    if ($filesystem->exists($envExample) && !$filesystem->exists($envTarget)) {
        $filesystem->copy($envExample, $envTarget);
        echo "✔ .env created from .env.example\n";
    }

    // تنظیم دسترسی
    if ($filesystem->exists("logs")) {
        $filesystem->chmod("logs", 0777, 0000, true);
        echo "✔ Permissions updated for logs/\n";
    }

    echo "\n✅ Setup completed successfully.\n";

} catch (IOExceptionInterface $exception) {
    echo "⚠ Error: " . $exception->getMessage() . "\n";
}
