<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\Cli;

use alirezax5\TelegramBase\App\Bootstrap\Bootstrap;
use alirezax5\TelegramBase\App\Bootstrap\PluginsBootstrap;
use alirezax5\TelegramBase\App\Environment\EnvHandler;

/**
 * List discovered plugins and their handlers.
 *
 *   php bin/tgbase plugins
 *
 * Boots the framework (with TGBASE_SKIP_BOT_VALIDATION so it works
 * without a real token), then prints every discovered plugin class,
 * its priority, its chat-type filter, and the handler methods it
 * registered.
 */
final class PluginsList
{
    public static function run(): int
    {
        // Migrations and plugin-listing don't need a live bot token.
        EnvHandler::clearCache();
        putenv('TGBASE_SKIP_BOT_VALIDATION=1');
        $_ENV['TGBASE_SKIP_BOT_VALIDATION'] = '1';

        Bootstrap::boot();
        PluginsBootstrap::boot();

        $handler = PluginsBootstrap::getPlugins();

        if ($handler === null) {
            echo "No plugin handler initialized.\n";
            return 0;
        }

        $stats = $handler->getStats();

        echo "Plugins discovered: {$stats['plugins']}\n";
        echo "Handlers registered: {$stats['handlers']}\n";
        echo "\n";

        if ($stats['plugins'] === 0) {
            echo "No plugins found in Plugin/ directory.\n";
            echo "Create one with: php bin/tgbase make:plugin Example\n";
            return 0;
        }

        // Access the private $plugins array via reflection to print details.
        $ref = new \ReflectionClass($handler);
        $pluginsProp = $ref->getProperty('plugins');
        $pluginsProp->setAccessible(true);
        $plugins = $pluginsProp->getValue($handler);

        $instancesProp = $ref->getProperty('instances');
        $instancesProp->setAccessible(true);
        $instances = $instancesProp->getValue($handler);

        // Group by class.
        $byClass = [];
        foreach ($plugins as $method => $entries) {
            foreach ($entries as $entry) {
                $class = $entry['class'];
                if (!isset($byClass[$class])) {
                    $byClass[$class] = [
                        'priority' => $entry['priority'],
                        'chatTypes' => $entry['chatTypes'],
                        'methods' => [],
                    ];
                }
                $byClass[$class]['methods'][] = $method;
            }
        }

        uasort($byClass, fn($a, $b) => $a['priority'] <=> $b['priority']);

        $classWidth = 50;
        echo str_pad('Plugin', $classWidth) . "Priority  ChatTypes  Handlers\n";
        echo str_repeat('-', $classWidth + 30) . "\n";

        foreach ($byClass as $class => $info) {
            $chatTypes = $info['chatTypes'] === [] ? 'all' : implode(',', $info['chatTypes']);
            $handlers = implode(',', $info['methods']);
            echo str_pad($class, $classWidth)
                . str_pad((string)$info['priority'], 9)
                . str_pad($chatTypes, 11)
                . $handlers . "\n";
        }

        return 0;
    }
}
