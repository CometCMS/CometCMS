<?php

declare(strict_types=1);

use CometCMS\Updates\UpdateService;

test('update replacement invalidates cached PHP files', function (): void {
    $script = COMET_STORAGE . '/update-opcache.php';
    file_put_contents($script, <<<'PHP'
<?php
require $argv[1] . '/tests/php/bootstrap.php';
$source = COMET_STORAGE . '/source';
$target = COMET_STORAGE . '/target';
mkdir($source . '/app', 0775, true);
mkdir($target . '/app', 0775, true);
foreach (['index.php', 'app/version.php'] as $path) {
    file_put_contents($source . '/' . $path, "<?php return '2.1.0';");
    file_put_contents($target . '/' . $path, "<?php return '2.0.0';");
    if (!opcache_compile_file($target . '/' . $path)) {
        throw new RuntimeException('Could not cache the old PHP file.');
    }
}
$replace = new ReflectionMethod(CometCMS\Updates\UpdateService::class, 'replacePackage');
$replace->invoke(new CometCMS\Updates\UpdateService(), $source, $target, []);
foreach (['index.php', 'app/version.php'] as $path) {
    echo require $target . '/' . $path;
    echo "\n";
}
comet_test_remove_directory(COMET_STORAGE);
PHP);

    $output = comet_test_run_php([
        '-d', 'opcache.enable_cli=1',
        '-d', 'opcache.validate_timestamps=0',
        '-d', 'opcache.file_update_protection=0',
        $script,
        COMET_TEST_ROOT,
    ]);
    assert_same("2.1.0\n2.1.0\n", $output);
});

test('update downloads distinguish HTTP errors from connection failures', function (): void {
    $router = COMET_STORAGE . '/update-download-router.php';
    file_put_contents($router, <<<'PHP'
<?php
if ($_SERVER['REQUEST_URI'] === '/missing') {
    http_response_code(404);
}
echo 'release fixture';
PHP);
    $port = 28080 + random_int(0, 2000);
    $server = comet_test_start_php_server('127.0.0.1', $port, $router);
    $method = new ReflectionMethod(UpdateService::class, 'httpGet');
    $service = new UpdateService();
    $fetch = static fn(string $path): string => $method->invoke($service, 'http://127.0.0.1:' . $port . $path);

    try {
        usleep(300000);
        assert_same('release fixture', $fetch('/'));
        $failure = null;
        try {
            $fetch('/missing');
        } catch (RuntimeException $error) {
            $failure = $error;
        }
        assert_same('No public GitHub release was found for the configured repository.', $failure?->getMessage());
    } finally {
        comet_test_stop_process($server);
    }

    // The modern header API retains the last response after a connection failure.
    $failure = null;
    try {
        $fetch('/');
    } catch (RuntimeException $error) {
        $failure = $error;
    }
    assert_same('Could not download release information or assets.', $failure?->getMessage());
});

test('update service builds configured fallback release sources', function (): void {
    global $cometConfig;

    $originalUpdates = $cometConfig['updates'];

    try {
        $cometConfig['updates']['repository_url'] = 'https://github.com/GetCometCMS/CometCMS';
        $cometConfig['updates']['releases_api_url'] = '';
        $cometConfig['updates']['fallback_repository_urls'] = [
            'https://github.com/example/cometcms-mirror',
        ];

        $method = new ReflectionMethod(UpdateService::class, 'config');
        $config = $method->invoke(new UpdateService());

        assert_same(
            [
                'https://api.github.com/repos/GetCometCMS/CometCMS/releases/latest',
                'https://api.github.com/repos/example/cometcms-mirror/releases/latest',
            ],
            array_column($config['release_sources'], 'api_url')
        );
    } finally {
        $cometConfig['updates'] = $originalUpdates;
    }
});

test('update service has no implicit fallback when config key is missing', function (): void {
    global $cometConfig;

    $originalUpdates = $cometConfig['updates'];

    try {
        unset($cometConfig['updates']['fallback_repository_urls']);
        $cometConfig['updates']['repository_url'] = 'https://github.com/GetCometCMS/CometCMS';
        $cometConfig['updates']['releases_api_url'] = '';

        $method = new ReflectionMethod(UpdateService::class, 'config');
        $config = $method->invoke(new UpdateService());

        assert_same(
            ['https://api.github.com/repos/GetCometCMS/CometCMS/releases/latest'],
            array_column($config['release_sources'], 'api_url')
        );
    } finally {
        $cometConfig['updates'] = $originalUpdates;
    }
});
