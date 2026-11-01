<?php

declare(strict_types=1);

use CometCMS\Media\MediaRepository;
use CometCMS\Site\GalleryLightbox;
use CometCMS\Site\LayoutSchema;
use CometCMS\Site\NodeRenderer;
use CometCMS\Site\PageRenderer;

test('gallery modals are opt in and only public images become viewer triggers', function (): void {
    comet_site_test_setup();
    foreach (['first.svg', 'second.svg', 'private.svg'] as $file) {
        file_put_contents(comet_test_workspace_path() . '/media/' . $file, '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="40"></svg>');
    }
    file_put_contents(comet_test_workspace_path() . '/media/readme.txt', 'Download');
    $media = new MediaRepository();
    $media->updateVisibility('private.svg', 'private');
    $media->updateMeta('first.svg', 'First image', 'Caption <script>unsafe</script>');
    $gallery = LayoutSchema::node('gallery', ['media' => ['first.svg', 'readme.txt', 'private.svg', 'second.svg'], 'lightbox' => true, 'lightbox_navigation' => false, 'masonry' => true, 'captions' => true, 'link' => ['type' => 'current']]);
    $saved = LayoutSchema::normalizeComponent([LayoutSchema::node('column', [], [], [$gallery])], 'column')[0]['children'][0];
    assert_same(true, $saved['props']['lightbox']);
    assert_same(false, $saved['props']['lightbox_navigation']);
    assert_same(false, LayoutSchema::node('gallery')['props']['lightbox']);
    $renderer = new NodeRenderer(comet_site_test_context());
    $html = $renderer->render([$saved]);
    assert_true(str_contains($html, 'c-gallery-masonry'));
    assert_true(str_contains($html, 'data-gallery-navigation="false"'));
    assert_same(2, substr_count($html, 'data-gallery-image'));
    assert_true(str_contains($html, 'data-caption="Caption &lt;script&gt;unsafe&lt;/script&gt;"'));
    assert_false(str_contains($html, 'private.svg'));
    assert_false(str_contains($html, 'class="c-image-link"'));
    assert_true(str_contains($html, 'readme.txt'));
    $saved['props']['lightbox'] = false;
    $html = $renderer->render([$saved]);
    assert_false(str_contains($html, 'data-gallery-image'));
    assert_false(str_contains($html, 'data-gallery-lightbox'));
});

test('pages include one hashed viewer module only when enabled while previews remain script free', function (): void {
    $site = comet_site_test_setup();
    file_put_contents(comet_test_workspace_path() . '/media/photo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="40"></svg>');
    $gallery = LayoutSchema::node('gallery', ['media' => ['photo.svg'], 'lightbox' => true]);
    $site->saveLayout('blog', 'detail', [LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [$gallery, $gallery])])])]);
    $ctx = comet_site_test_context($site);
    $renderer = new PageRenderer($ctx);
    $type = $ctx->type('blog');
    $entry = $ctx->findEntry('blog', 'hello');
    $html = $renderer->entryPage($type, $entry);
    assert_same(1, substr_count($html, '<script type="module">'));
    preg_match('#<script type="module">(.*?)</script>#s', $html, $match);
    assert_same(GalleryLightbox::script(), $match[1]);
    assert_same("'sha256-" . base64_encode(hash('sha256', $match[1], true)) . "'", GalleryLightbox::cspSource());
    $ctx->preview = true;
    $preview = $renderer->layoutPreview($type, 'detail', $entry);
    assert_true(str_contains($preview, "script-src 'none'"));
    assert_true(str_contains($preview, 'data-gallery-lightbox'));
    assert_false(str_contains($preview, '<script'));
});

test('public gallery pages permit only the bundled viewer hash in root and subdirectory installs', function (): void {
    $root = comet_site_preview_test_install();
    comet_test_run_php(['-r', 'require ' . var_export($root . '/app/bootstrap.php', true) . ';' . <<<'PHP'
(new \CometCMS\Workspaces\WorkspaceRepository())->all();
(new \CometCMS\Content\ContentTypeRepository())->save(['name' => 'about']);
\CometCMS\Content\ContentRepository::make()->save('about', ['title' => 'About', 'status' => 'published'], ['id' => 'admin']);
$site = new \CometCMS\Site\SiteRepository();
$site->saveSettings(['routes' => ['about' => ['enabled' => true, 'pattern' => '/{slug}', 'index_path' => '']]]);
file_put_contents($site->workspace()->path('media') . '/photo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="40"></svg>');
$gallery = \CometCMS\Site\LayoutSchema::node('gallery', ['media' => ['photo.svg'], 'lightbox' => true]);
$site->saveLayout('about', 'detail', [\CometCMS\Site\LayoutSchema::node('section', [], [], [\CometCMS\Site\LayoutSchema::node('row', [], [], [\CometCMS\Site\LayoutSchema::node('column', [], [], [$gallery])])])]);
PHP]);
    $port = 22080 + random_int(0, 2000);
    $server = comet_test_start_php_server('127.0.0.1', $port, $root . '/router.php');
    try {
        usleep(300000);
        foreach (['', '/cms'] as $base) {
            [$status, $html, $headers] = comet_site_preview_test_request($port, $base . '/about');
            assert_same(200, $status);
            assert_true(str_contains($headers['content-security-policy'], 'script-src ' . GalleryLightbox::cspSource()));
            assert_false(str_contains($headers['content-security-policy'], "script-src 'self'"));
            assert_false(str_contains($headers['content-security-policy'], "script-src 'unsafe-inline'"));
            assert_true(str_contains($html, 'href="' . $base . '/media/default/photo.svg"'));
        }
    } finally {
        comet_test_stop_process($server);
    }
});
