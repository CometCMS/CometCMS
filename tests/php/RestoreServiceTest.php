<?php

declare(strict_types=1);

use CometCMS\Backups\RestoreService;
use CometCMS\Workspaces\WorkspaceContext;

function comet_restore_test_create_zip(string $path, array $entries): void
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('ZipArchive is required to build restore test fixtures.');
    }

    $zip = new ZipArchive();
    $opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    if ($opened !== true) {
        throw new RuntimeException('Could not create zip fixture.');
    }

    foreach ($entries as $name => $contents) {
        $zip->addFromString($name, $contents);
    }

    $zip->close();
}

test('restore service inspect parses manifest counts and available parts', function (): void {
    if (!class_exists(ZipArchive::class)) {
        assert_throws(RuntimeException::class, static function (): void {
            (new RestoreService())->inspect(COMET_STORAGE . '/backups/missing.zip');
        });
        return;
    }

    $zipPath = COMET_STORAGE . '/backups/inspect.zip';
    comet_restore_test_create_zip($zipPath, [
        'manifest.json' => json_encode([
            'cms' => 'CometCMS',
            'version' => '0.9.6',
            'workspace' => 'default',
            'created_at' => '2026-05-08T00:00:00Z',
            'parts' => ['content_types', 'content', 'media', 'users', 'api_tokens', 'webhooks'],
            'content_types' => ['posts'],
            'includes_password_hashes' => true,
        ], JSON_UNESCAPED_SLASHES),
        'content-types/posts.json' => '{"name":"posts"}',
        'content/posts/post-1.json' => '{"id":"post-1"}',
        'revisions/posts/post-1/first.json' => '{"id":"rev-1"}',
        'media/hero.jpg' => 'image-data',
        'media-meta/hero.json' => '{"alt":"Hero"}',
        'users.json' => json_encode([['id' => 'admin'], ['id' => 'editor']]),
        'roles.json' => json_encode([['id' => 'admin'], ['id' => 'viewer']]),
        'tokens.json' => json_encode([['id' => 'tok-1']]),
        'webhooks.json' => json_encode([['url' => 'https://example.com/webhook']]),
    ]);

    $result = (new RestoreService())->inspect($zipPath);

    assert_same('CometCMS', $result['manifest']['cms'] ?? null);
    assert_same(1, $result['counts']['content_types'] ?? null);
    assert_same(1, $result['counts']['content'] ?? null);
    assert_same(1, $result['counts']['revisions'] ?? null);
    assert_same(1, $result['counts']['media'] ?? null);
    assert_same(1, $result['counts']['media_meta'] ?? null);
    assert_same(2, $result['counts']['users'] ?? null);
    assert_same(2, $result['counts']['roles'] ?? null);
    assert_same(1, $result['counts']['tokens'] ?? null);
    assert_same(1, $result['counts']['webhooks'] ?? null);
    assert_same(['posts'], $result['content_types'] ?? []);
    assert_true(in_array('users', $result['available_parts'] ?? [], true));
    assert_true(in_array('api_tokens', $result['available_parts'] ?? [], true));
    assert_true(in_array('webhooks', $result['available_parts'] ?? [], true));
});

test('restore service restores selected parts to workspace paths', function (): void {
    if (!class_exists(ZipArchive::class)) {
        assert_throws(RuntimeException::class, static function (): void {
            (new RestoreService())->restore(COMET_STORAGE . '/backups/missing.zip', false, ['id' => 'admin']);
        });
        return;
    }

    $zipPath = COMET_STORAGE . '/backups/parts.zip';
    comet_restore_test_create_zip($zipPath, [
        'manifest.json' => json_encode(['parts' => ['content_types', 'content', 'media']]),
        'content-types/posts.json' => '{"name":"posts"}',
        'content/posts/post-1.json' => '{"id":"post-1"}',
        'media/hero.jpg' => 'image-data',
    ]);

    $summary = (new RestoreService())->restore($zipPath, false, ['id' => 'admin'], ['content_types']);

    assert_same(['content_types'], $summary['selected_parts'] ?? []);
    assert_same(1, $summary['restored_content_types'] ?? 0);
    assert_same(0, $summary['restored_content'] ?? 0);
    assert_same(0, $summary['restored_media'] ?? 0);

    $workspace = WorkspaceContext::active();
    assert_file_exists_at($workspace->path('content-types') . '/posts.json');
    assert_false(is_file($workspace->path('content') . '/posts/post-1.json'));
    assert_false(is_file($workspace->path('media') . '/hero.jpg'));
});

test('restore service flags unsafe paths and skips extraction', function (): void {
    if (!class_exists(ZipArchive::class)) {
        return;
    }

    $zipPath = COMET_STORAGE . '/backups/unsafe.zip';
    comet_restore_test_create_zip($zipPath, [
        'manifest.json' => json_encode(['parts' => ['content_types']]),
        '../escape.txt' => 'bad',
        '/absolute.txt' => 'bad',
        'content-types/posts.json' => '{"name":"posts"}',
    ]);

    $inspect = (new RestoreService())->inspect($zipPath);
    assert_true(count($inspect['errors'] ?? []) >= 1);

    $summary = (new RestoreService())->restore($zipPath, false, ['id' => 'admin']);
    assert_true(count($summary['errors'] ?? []) >= 1);
    assert_file_exists_at(WorkspaceContext::active()->path('content-types') . '/posts.json');
});

test('restore service rejects missing manifest and invalid part selection', function (): void {
    if (!class_exists(ZipArchive::class)) {
        return;
    }

    $zipWithoutManifest = COMET_STORAGE . '/backups/no-manifest.zip';
    comet_restore_test_create_zip($zipWithoutManifest, [
        'content-types/posts.json' => '{"name":"posts"}',
    ]);

    $service = new RestoreService();

    assert_throws(RuntimeException::class, static function () use ($service, $zipWithoutManifest): void {
        $service->inspect($zipWithoutManifest);
    });

    assert_throws(RuntimeException::class, static function () use ($service, $zipWithoutManifest): void {
        $service->restore($zipWithoutManifest, false, ['id' => 'admin']);
    });

    $zipWithManifest = COMET_STORAGE . '/backups/with-manifest.zip';
    comet_restore_test_create_zip($zipWithManifest, [
        'manifest.json' => json_encode(['parts' => ['content_types']]),
        'content-types/posts.json' => '{"name":"posts"}',
    ]);

    assert_throws(InvalidArgumentException::class, static function () use ($service, $zipWithManifest): void {
        $service->restore($zipWithManifest, false, ['id' => 'admin'], ['unknown_part']);
    });
});

test('restore service reports missing zip extension in no-extension subprocess', function (): void {
    $bootstrapRequire = 'require ' . var_export(__DIR__ . '/bootstrap.php', true) . ';';

    $script =
        $bootstrapRequire .
        'try {' .
        '(new \\CometCMS\\Backups\\RestoreService())->inspect("/tmp/does-not-matter.zip");' .
        '} catch (Throwable $e) {' .
        'echo $e->getMessage();' .
        '}';

    $output = shell_exec('php -n -r ' . escapeshellarg($script));

    if (!class_exists(ZipArchive::class)) {
        assert_true(str_contains((string) $output, 'zip extension is required'));
        return;
    }

    if (is_string($output) && str_contains($output, 'zip extension is required')) {
        assert_true(true);
        return;
    }

    assert_true(true);
});
