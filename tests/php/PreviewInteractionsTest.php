<?php

declare(strict_types=1);

use CometCMS\Site\GalleryLightbox;
use CometCMS\Site\SiteSearch;
use CometCMS\Site\Tabs;
use CometCMS\Site\ThemeToggle;

test('preview admin bar uses the saved user theme independently of the website', function (): void {
    $context = \CometCMS\Site\SiteContext::make(new \CometCMS\Site\SiteRepository());
    $bar = new \CometCMS\Site\PreviewAdminBar($context);
    $websiteTheme = $context->theme();
    foreach (['blue', 'green', 'purple', 'orange', 'red', 'cyan', 'dark'] as $theme) {
        $context->adminUser = ['username' => 'admin', 'theme' => $theme];
        $html = $bar->render([]);
        assert_true(str_contains($html, 'data-admin-theme="' . $theme . '"'));
        preg_match('#src="data:image/png;base64,([^"]+)"#', $html, $logo);
        assert_true(str_starts_with(base64_decode($logo[1]), "\x89PNG"));
        assert_same($websiteTheme, $context->theme());
    }
    $context->adminUser = ['theme' => '" onmouseover="alert(1)'];
    assert_true(str_contains($bar->render([]), 'data-admin-theme="blue"'));
});

test('full page previews allow bundled interactions and search only readable drafts and snapshots', function (): void {
    $root = comet_site_preview_test_install();
    $prefix = 'interactive-' . bin2hex(random_bytes(8));
    comet_test_run_php(['-r', 'require ' . var_export($root . '/app/bootstrap.php', true) . ';' . <<<'SEED'
$users = new \CometCMS\Auth\UserRepository();
$users->create('preview-admin', 'secret-password', 'admin');
$users->update('preview-admin', ['theme' => 'orange']);
(new \CometCMS\Auth\RoleRepository())->create(['label' => 'Preview reader', 'permissions' => [
    ['effect' => 'allow', 'actions' => ['content.read'], 'resources' => ['content:posts:*']],
    ['effect' => 'deny', 'actions' => ['content.read'], 'resources' => ['content:posts:locked']],
]]);
$users->create('preview-reader', 'secret-password', 'preview-reader');
(new \CometCMS\Workspaces\WorkspaceRepository())->all();
(new \CometCMS\Content\ContentTypeRepository())->save(['name' => 'posts']);
$content = \CometCMS\Content\ContentRepository::make();
$content->save('posts', ['title' => 'Visible draft', 'slug' => 'open'], ['id' => 'preview-admin']);
$content->save('posts', ['title' => 'Confidential draft', 'slug' => 'locked'], ['id' => 'preview-admin']);
$site = new \CometCMS\Site\SiteRepository();
$site->saveSettings(['routes' => ['posts' => ['enabled' => true, 'pattern' => '/posts/{slug}', 'index_path' => '/posts']]]);
file_put_contents($site->workspace()->path('media') . '/photo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="40"></svg>');
$node = \CometCMS\Site\LayoutSchema::node(...);
$site->saveLayout('posts', 'detail', [$node('section', [], [], [$node('row', [], [], [$node('column', [], [], [
    $node('gallery', ['media' => ['photo.svg'], 'lightbox' => true]),
    $node('tabs', [], [], [$node('tab', ['title' => 'First']), $node('tab', ['title' => 'Second'])]),
    $node('search'), $node('theme_toggle'),
])])])]);
SEED . 'foreach (["admin", "reader"] as $role) { session_id(' . var_export($prefix, true) . ' . "-" . $role); session_start(); $_SESSION["user_id"] = "preview-" . $role; $_SESSION["cometcms_csrf"] = "preview-token"; session_write_close(); }']);
    $port = 22080 + random_int(0, 2000);
    $server = comet_test_start_php_server('127.0.0.1', $port, $root . '/router.php');
    try {
        usleep(300000);
        foreach (['', '/cms'] as $base) {
            $cookie = 'cometcms_admin=' . $prefix . '-admin';
            $source = $base . '/admin/media?folder=photos&workspace=default#selection';
            $returnQuery = '&returnTo=' . rawurlencode($source);
            [$status, $html, $headers] = comet_site_preview_test_request($port, $base . '/admin/site-preview/posts/open?workspace=default' . $returnQuery, $cookie);
            assert_true(str_contains($html, 'class="c-admin-brand" href="' . htmlspecialchars($source, ENT_QUOTES) . '"'));
            assert_true(str_contains($html, 'returnTo=' . urlencode($source)));
            assert_same(200, $status);
            assert_true(str_contains($html, 'data-admin-theme="orange"'));
            foreach ([GalleryLightbox::class, Tabs::class, SiteSearch::class, ThemeToggle::class] as $module) {
                assert_true(str_contains($headers['content-security-policy'], $module::cspSource()));
                assert_true(str_contains($html, $module::script()));
            }
            assert_false(str_contains($headers['content-security-policy'], "script-src 'none'"));
            assert_false(str_contains($headers['content-security-policy'], "script-src 'self'"));
            assert_true(str_contains($headers['content-security-policy'], "form-action 'none'"));
            assert_true(str_contains($html, 'data-search="' . $base . '/admin/api/site/preview-search?workspace=default'));
            $readerCookie = 'cometcms_admin=' . $prefix . '-reader';
            [$status, $json, $headers] = comet_site_preview_test_request($port, $base . '/admin/api/site/preview-search?workspace=default&q=draft' . $returnQuery, $readerCookie);
            assert_same(200, $status);
            assert_same('private, no-store, no-transform', $headers['cache-control']);
            $hits = json_decode($json, true)['data'];
            assert_same(['Visible draft'], array_column($hits, 'title'));
            assert_true(str_starts_with($hits[0]['url'], $base . '/admin/site-preview?workspace=default&path='));
            parse_str(parse_url($hits[0]['url'], PHP_URL_QUERY), $navigation);
            assert_same($source, $navigation['returnTo']);
            assert_same(401, comet_site_preview_test_request($port, $base . '/admin/api/site/preview-search?q=draft')[0]);
            [$status, $json] = comet_site_preview_test_request($port, $base . '/admin/api/site/preview-snapshots?workspace=default', $cookie, 'POST', [
                'target' => 'entry', 'type' => 'posts', 'entry' => 'open',
                'entries' => [['type' => 'posts', 'id' => 'open', 'values' => ['title' => 'Unsaved snapshot title']]],
            ], 'preview-token');
            assert_same(200, $status);
            parse_str((string) parse_url(json_decode($json, true)['data']['url'], PHP_URL_QUERY), $query);
            $searchPath = $base . '/admin/api/site/preview-search?workspace=default&q=Unsaved&snapshot=' . $query['snapshot'];
            [$status, $json] = comet_site_preview_test_request($port, $searchPath, $cookie);
            assert_same(200, $status);
            $hits = json_decode($json, true)['data'];
            assert_same(['Unsaved snapshot title'], array_column($hits, 'title'));
            assert_true(str_contains($hits[0]['url'], 'snapshot=' . $query['snapshot']));
            assert_same(410, comet_site_preview_test_request($port, $searchPath, $readerCookie)[0]);
        }
    } finally {
        comet_test_stop_process($server);
    }
});
