<?php

declare(strict_types=1);

echo "Running Telegram Base setup...\n";

$folders = [
    "logs",
    "AppData",
    "Migration",
    "Plugin",
    "Database",
];

foreach ($folders as $folder) {
    if (!is_dir($folder)) {
        mkdir($folder, 0777, true);
        echo "✔ Created folder: {$folder}\n";
    } else {
        echo "ℹ Folder already exists: {$folder}\n";
    }
}

// ایجاد .env از .env.example
$envExample = __DIR__ . '/../../.env.example';
$envTarget = __DIR__ . '/../../.env';

if (file_exists($envExample) && !file_exists($envTarget)) {
    copy($envExample, $envTarget);
    echo "✔ .env created from .env.example\n";
}

// Permission logs
$logFolder = __DIR__ . '/../../logs';
if (is_dir($logFolder)) {
    chmod($logFolder, 0777);
    echo "✔ Permissions updated for logs/\n";
}

echo "\n✅ Setup completed successfully.\n";
