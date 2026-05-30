<?php

declare(strict_types=1);

define('COMET_ROOT', dirname(__DIR__));
define('COMET_APP', COMET_ROOT . '/app');
define('COMET_STORAGE', COMET_ROOT . '/storage');

function comet_bootstrap_failure(string $title, string $message, array $details = []): never
{
    if (function_exists('comet_entry_failure')) {
        comet_entry_failure($title, $message, $details);
    }

    error_log('CometCMS bootstrap error: ' . $title . ' - ' . $message . ' ' . json_encode($details, JSON_UNESCAPED_SLASHES));
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $title . "\n\n" . $message;
    exit;
}

$cometConfig = require COMET_ROOT . '/config/config.php';

if (!is_array($cometConfig)) {
    comet_bootstrap_failure('Invalid configuration', 'config/config.php must return a PHP array.', [
        'file' => COMET_ROOT . '/config/config.php',
    ]);
}

// Merge runtime settings from storage/settings.json (takes precedence over config.php)
$_settingsFile = COMET_STORAGE . '/settings.json';
if (is_file($_settingsFile)) {
    $_runtimeSettings = json_decode((string) file_get_contents($_settingsFile), true);
    if (is_array($_runtimeSettings)) {
        $cometConfig = array_replace($cometConfig, $_runtimeSettings);
    }
    unset($_runtimeSettings);
}
unset($_settingsFile);

spl_autoload_register(static function (string $class): void {
    $prefix = 'CometCMS\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $path = COMET_APP . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($path)) {
        require $path;
    }
});

function comet_config(string $key, mixed $default = null): mixed
{
    global $cometConfig;

    $value = $cometConfig;

    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }

        $value = $value[$segment];
    }

    return $value;
}

function comet_version(): string
{
    $versionFile = COMET_APP . '/version.php';

    if (is_file($versionFile)) {
        $version = require $versionFile;

        if (is_string($version) && $version !== '') {
            return $version;
        }
    }

    return (string) comet_config('app.version', '1.0.0');
}

date_default_timezone_set((string) comet_config('app.timezone', 'UTC'));

foreach (
    [
        COMET_STORAGE,
        COMET_STORAGE . '/users',
        COMET_STORAGE . '/api-tokens',
        COMET_STORAGE . '/roles',
        COMET_STORAGE . '/sessions',
        COMET_STORAGE . '/cache',
        COMET_STORAGE . '/cache/login-throttle',
        COMET_STORAGE . '/logs',
        COMET_STORAGE . '/backups',
        COMET_STORAGE . '/updates',
        COMET_STORAGE . '/workspaces',
    ] as $directory
) {
    if (!is_dir($directory)) {
        $lastError = null;
        set_error_handler(static function (int $severity, string $message) use (&$lastError): bool {
            $lastError = $message;
            return true;
        });
        $created = mkdir($directory, 0775, true);
        restore_error_handler();

        if (!$created && !is_dir($directory)) {
            comet_bootstrap_failure('Storage is not writable', 'CometCMS could not create a required storage directory.', [
                'directory' => $directory,
                'error' => $lastError,
                'hint' => 'Make storage/ writable by the web server user.',
            ]);
        }
    }

    if (!is_writable($directory)) {
        comet_bootstrap_failure('Storage is not writable', 'CometCMS cannot write to a required storage directory.', [
            'directory' => $directory,
            'hint' => 'Make storage/ writable by the web server user.',
        ]);
    }
}

(new \CometCMS\Auth\RoleRepository())->seed();

$scriptBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$cookiePath = $scriptBase === '' ? '/' : $scriptBase;
$requestPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($scriptBase !== '' && $scriptBase !== '/' && str_starts_with($requestPath, $scriptBase)) {
    $requestPath = substr($requestPath, strlen($scriptBase));
}

$requestPath = '/' . ltrim($requestPath, '/');
$needsSession = !str_starts_with($requestPath, '/api/')
    && $requestPath !== '/api'
    && !str_starts_with($requestPath, '/mcp/')
    && $requestPath !== '/mcp'
    && !str_starts_with($requestPath, '/media/')
    && !str_starts_with($requestPath, '/media-thumbs/');

session_name((string) comet_config('security.session_name', 'cometcms_admin'));

if (session_status() !== PHP_SESSION_ACTIVE && session_module_name() !== 'files') {
    session_module_name('files');
}

session_save_path(COMET_STORAGE . '/sessions');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => $cookiePath,
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);

if ($needsSession && session_status() !== PHP_SESSION_ACTIVE) {
    $lastError = null;
    set_error_handler(static function (int $severity, string $message) use (&$lastError): bool {
        $lastError = $message;
        return true;
    });
    $started = session_start();
    restore_error_handler();

    if (!$started) {
        comet_bootstrap_failure('Session storage is not writable', 'CometCMS could not start an admin session.', [
            'session_path' => COMET_STORAGE . '/sessions',
            'error' => $lastError,
            'hint' => 'Make storage/sessions writable by the web server user.',
        ]);
    }
}
