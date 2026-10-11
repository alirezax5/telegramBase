<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\Cli;

use alirezax5\TelegramBase\App\Bot\BotConfig;
use alirezax5\TelegramBase\App\Environment\EnvHandler;
use alirezax5\TelegramBase\App\Environment\EnvironmentValidator;
use alirezax5\TelegramBase\App\Paths;

/**
 * Lightweight diagnostics command.
 *
 *   php bin/tgbase doctor
 *
 * Checks the local environment without contacting Telegram:
 *   - PHP version
 *   - required extensions
 *   - .env readability
 *   - storage directories writable
 *   - log directory writable
 *   - BOT_TOKEN format
 *   - plugin discovery
 *
 * With --telegram flag, additionally calls getMe to verify the token.
 * Use sparingly — hits the Telegram API.
 *
 * Exit code 0 = all checks pass, 1 = at least one check failed.
 */
final class Doctor
{
    /** @var list<array{name:string, status:string, detail:string}> */
    private array $results = [];

    public static function run(array $args): int
    {
        $checkTelegram = in_array('--telegram', $args, true);

        $doctor = new self();
        $doctor->checkAll($checkTelegram);

        $doctor->printReport();

        // Exit code contract: 1 only on FAIL. WARN (e.g. no .env / no token)
        // and SKIP (e.g. no --telegram) are informational — CI runs doctor
        // deliberately without credentials ("Doctor (no token)") and the
        // printed RESULT already calls that outcome "OK (with warning(s))".
        foreach ($doctor->results as $r) {
            if ($r['status'] === 'FAIL') {
                return 1;
            }
        }
        return 0;
    }

    private function checkAll(bool $checkTelegram): void
    {
        $this->checkPhp();
        $this->checkExtensions();
        $this->checkEnvFile();
        $this->checkStorage();
        $this->checkLogs();
        $this->checkBotTokenFormat();

        if ($checkTelegram) {
            $this->checkTelegramConnectivity();
        } else {
            $this->results[] = [
                'name' => 'Telegram API',
                'status' => 'SKIP',
                'detail' => 'use --telegram to test getMe',
            ];
        }

        $this->checkPlugins();
    }

    private function checkPhp(): void
    {
        $version = PHP_VERSION;
        $ok = version_compare($version, '8.1.0', '>=');
        $this->results[] = [
            'name' => 'PHP',
            'status' => $ok ? 'OK' : 'FAIL',
            'detail' => $ok ? $version : "PHP {$version} (need 8.1+)",
        ];
    }

    private function checkExtensions(): void
    {
        $required = ['curl', 'json', 'mbstring'];
        $missing = [];

        foreach ($required as $ext) {
            if (!extension_loaded($ext)) {
                $missing[] = $ext;
            }
        }

        // Optional extensions — reported but not failing.
        $optional = ['redis', 'memcached', 'amqp', 'pdo_mysql', 'pdo_sqlite'];
        $loaded = [];
        foreach ($optional as $ext) {
            if (extension_loaded($ext)) {
                $loaded[] = $ext;
            }
        }

        $detail = implode(', ', $required);
        if ($loaded !== []) {
            $detail .= ' (optional: ' . implode(', ', $loaded) . ')';
        }

        $this->results[] = [
            'name' => 'Extensions',
            'status' => $missing === [] ? 'OK' : 'FAIL',
            'detail' => $missing !== []
                ? 'missing: ' . implode(', ', $missing)
                : $detail,
        ];
    }

    private function checkEnvFile(): void
    {
        $envFile = Paths::base() . '/.env';
        $example = Paths::base() . '/.env.example';

        if (!is_file($envFile)) {
            $this->results[] = [
                'name' => 'Configuration',
                'status' => 'WARN',
                'detail' => '.env missing — copy .env.example and configure',
            ];
            return;
        }

        if (!is_readable($envFile)) {
            $this->results[] = [
                'name' => 'Configuration',
                'status' => 'FAIL',
                'detail' => '.env exists but is not readable',
            ];
            return;
        }

        $this->results[] = [
            'name' => 'Configuration',
            'status' => 'OK',
            'detail' => '.env readable',
        ];
    }

    private function checkStorage(): void
    {
        $dirs = [
            Paths::dataDirectory(),
            Paths::queueDirectory(),
            Paths::cronSlotsDirectory(),
        ];

        $problems = [];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                $problems[] = "missing: {$dir}";
            } elseif (!is_writable($dir)) {
                $problems[] = "not writable: {$dir}";
            }
        }

        $this->results[] = [
            'name' => 'Storage',
            'status' => $problems === [] ? 'OK' : 'FAIL',
            'detail' => $problems !== []
                ? implode('; ', $problems)
                : 'AppData/ + subdirs writable',
        ];
    }

    private function checkLogs(): void
    {
        $logDir = Paths::logDirectory();

        if (!is_dir($logDir)) {
            // Bootstrap creates it, but if doctor runs before bootstrap
            // we may need to make it ourselves.
            @mkdir($logDir, 0755, true);
        }

        if (!is_dir($logDir)) {
            $this->results[] = [
                'name' => 'Logs',
                'status' => 'FAIL',
                'detail' => "log dir cannot be created: {$logDir}",
            ];
            return;
        }

        if (!is_writable($logDir)) {
            $this->results[] = [
                'name' => 'Logs',
                'status' => 'FAIL',
                'detail' => "log dir not writable: {$logDir}",
            ];
            return;
        }

        $this->results[] = [
            'name' => 'Logs',
            'status' => 'OK',
            'detail' => $logDir,
        ];
    }

    private function checkBotTokenFormat(): void
    {
        // Load .env if present so BOT_TOKEN is visible to EnvHandler.
        // doctor doesn't bootstrap the full framework (it can't — the
        // user may be running it precisely because the bootstrap is
        // broken), so we load dotenv directly.
        $this->loadDotEnv();

        $token = EnvHandler::get('BOT_TOKEN', '');

        if ($token === '' || $token === null) {
            $this->results[] = [
                'name' => 'BOT_TOKEN',
                'status' => 'WARN',
                'detail' => 'not set (required for bot runtime)',
            ];
            return;
        }

        $ok = preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', (string)$token) === 1;
        $this->results[] = [
            'name' => 'BOT_TOKEN',
            'status' => $ok ? 'OK' : 'WARN',
            'detail' => $ok
                ? 'format valid'
                : 'format unusual (expected <digits>:<35+ alphanumeric chars>)',
        ];
    }

    private function checkTelegramConnectivity(): void
    {
        $token = EnvHandler::get('BOT_TOKEN', '');
        if ($token === '' || $token === null) {
            $this->results[] = [
                'name' => 'Telegram API',
                'status' => 'FAIL',
                'detail' => 'cannot test — BOT_TOKEN not set',
            ];
            return;
        }

        $apiUrl = rtrim((string)EnvHandler::get('BOT_API_URL', 'https://api.telegram.org'), '/');
        $url = "{$apiUrl}/bot{$token}/getMe";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $this->results[] = [
                'name' => 'Telegram API',
                'status' => 'FAIL',
                'detail' => "curl error: {$error}",
            ];
            return;
        }

        $decoded = json_decode((string)$response, false);
        $ok = ($httpCode === 200) && !empty($decoded->ok);

        $this->results[] = [
            'name' => 'Telegram API',
            'status' => $ok ? 'OK' : 'FAIL',
            'detail' => $ok
                ? 'getMe ok — @' . ($decoded->result->username ?? '?')
                : "getMe failed (HTTP {$httpCode})",
        ];
    }

    private function checkPlugins(): void
    {
        $pluginsDir = Paths::base() . '/Plugin';

        if (!is_dir($pluginsDir)) {
            $this->results[] = [
                'name' => 'Plugins',
                'status' => 'WARN',
                'detail' => 'Plugin/ directory missing',
            ];
            return;
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pluginsDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $count++;
            }
        }

        $this->results[] = [
            'name' => 'Plugins',
            'status' => 'OK',
            'detail' => $count . ' plugin file(s) discovered',
        ];
    }

    /**
     * Load .env if present so EnvHandler can see BOT_TOKEN etc.
     *
     * Silent no-op when .env doesn't exist or dotenv can't load it —
     * doctor must not crash on a broken environment.
     */
    private function loadDotEnv(): void
    {
        $envFile = Paths::base() . '/.env';
        if (!is_file($envFile) || !class_exists(\Dotenv\Dotenv::class)) {
            return;
        }

        try {
            $dotenv = \Dotenv\Dotenv::createImmutable(Paths::base());
            $dotenv->load();
            // Clear EnvHandler cache so the freshly-loaded values are visible.
            EnvHandler::clearCache();
        } catch (\Throwable) {
            // Broken .env — doctor should still report what it can.
        }
    }

    private function printReport(): void
    {
        $nameWidth = 20;
        $statusWidth = 8;

        foreach ($this->results as $r) {
            $name = str_pad($r['name'], $nameWidth);
            $status = str_pad($r['status'], $statusWidth);
            echo "{$name}{$status}{$r['detail']}\n";
        }

        echo "\n";

        $fails = 0;
        $warns = 0;
        foreach ($this->results as $r) {
            if ($r['status'] === 'FAIL') $fails++;
            if ($r['status'] === 'WARN') $warns++;
        }

        if ($fails === 0 && $warns === 0) {
            echo "RESULT: OK\n";
        } elseif ($fails === 0) {
            echo "RESULT: OK (with {$warns} warning(s))\n";
        } else {
            echo "RESULT: FAIL ({$fails} failure(s), {$warns} warning(s))\n";
        }
    }
}
