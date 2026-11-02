<?php

declare(strict_types=1);

test('website links flag hosts that serve another workspace and clear after domain assignment', function (): void {
    $seed = <<<'PHP'
$_SERVER['HTTP_HOST'] = 'localhost:8000';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_GET['workspace'] = 'portfolio';
$registry = new \CometCMS\Workspaces\WorkspaceRepository();
$registry->save(['slug' => 'default', 'label' => 'Default']);
$registry->save(['slug' => 'portfolio', 'label' => 'Portfolio']);
$registry->setDefault('default');
\CometCMS\Workspaces\WorkspaceContext::setActive('portfolio');
(new \CometCMS\Content\ContentTypeRepository())->save(['name' => 'posts']);
\CometCMS\Content\ContentRepository::make()->save('posts', ['title' => 'Live post', 'slug' => 'live', 'status' => 'published'], ['id' => 'admin']);
$site = new \CometCMS\Site\SiteRepository();
$settings = ['routes' => ['posts' => ['enabled' => true, 'pattern' => '/posts/{slug}']]];
PHP;
    $cases = [
        // The default workspace owns an unassigned host, even if it has no matching page.
        ['$registry->setDefault("portfolio");', false],
        ['$otherContext = new \CometCMS\Workspaces\WorkspaceContext("default"); (new \CometCMS\Content\ContentTypeRepository($otherContext))->save(["name" => "posts"]); $other = new \CometCMS\Site\SiteRepository($otherContext); $other->saveSettings(["homepage" => ["type" => "posts"]]);', true],
        // No website answers the host either: the generated URL still cannot reach this workspace.
        ['', true],
        ['$settings["domains"] = ["portfolio.test"];', false],
    ];
    foreach ($cases as [$configure, $conflict]) {
        [$status, $json] = comet_admin_request('admin', $seed . "\n" . $configure . "\n" . '$site->saveSettings($settings); \CometCMS\Workspaces\WorkspaceContext::setActive("portfolio");', 'GET', '/admin/api/site/links/posts/live',
            '(new \CometCMS\Controllers\Admin\SiteController(new \CometCMS\Core\Http()))->links("posts", "live");');
        assert_same(200, $status, json_encode($json));
        assert_true(is_string($json['data']['url'] ?? null));
        assert_same($conflict, $json['data']['url_conflict'] ?? null, $configure . json_encode($json));
        assert_true($json['data']['previewable']);
    }
});

test('entry previews and website links enforce entry read restrictions for slugs and stable IDs', function (): void {
    $seed = <<<'PHP'
$roles->create(['label' => 'Restricted', 'permissions' => [
    ['effect' => 'allow', 'actions' => ['content.read'], 'resources' => ['content:posts:*']],
    ['effect' => 'deny', 'actions' => ['content.read'], 'resources' => ['content:posts:locked']],
]]);
$users->create('limited', 'secret-password', 'restricted');
(new \CometCMS\Content\ContentTypeRepository())->save(['name' => 'posts']);
$content = \CometCMS\Content\ContentRepository::make();
$locked = $content->save('posts', ['title' => 'Confidential draft', 'slug' => 'locked'], ['id' => 'admin']);
$content->save('posts', ['title' => 'Allowed draft', 'slug' => 'open'], ['id' => 'admin']);
PHP;
    $controller = '(new \CometCMS\Controllers\Admin\SiteController(new \CometCMS\Core\Http()))';
    foreach (['previewEntry', 'links'] as $method) {
        foreach (['"locked"', '$locked["uid"]'] as $identifier) {
            [$status] = comet_admin_request('limited', $seed, 'GET', '/admin/site-preview/posts/locked', $controller . '->' . $method . '("posts", ' . $identifier . ');');
            assert_same(403, $status, 'An explicit entry deny applies to ' . $method . ' for ' . $identifier);
        }
        [$status] = comet_admin_request('limited', $seed, 'GET', '/admin/site-preview/posts/open', $controller . '->' . $method . '("posts", "open");');
        assert_same(200, $status, 'Allowed drafts remain available through ' . $method);
    }
});

test('entry previews support permissions limited to the author of the entry', function (): void {
    $seed = <<<'PHP'
$roles->create(['label' => 'Author', 'permissions' => [
    ['effect' => 'allow', 'actions' => ['content.read'], 'resources' => ['content:posts:*'], 'conditions' => ['own' => true]],
]]);
$users->create('writer', 'secret-password', 'author');
(new \CometCMS\Content\ContentTypeRepository())->save(['name' => 'posts']);
$content = \CometCMS\Content\ContentRepository::make();
$content->save('posts', ['title' => 'Own draft', 'slug' => 'own'], ['id' => 'writer']);
$content->save('posts', ['title' => 'Another draft', 'slug' => 'other'], ['id' => 'admin']);
PHP;
    $controller = '(new \CometCMS\Controllers\Admin\SiteController(new \CometCMS\Core\Http()))';
    foreach (['own' => 200, 'other' => 403] as $slug => $expected) {
        [$status] = comet_admin_request('writer', $seed, 'GET', '/admin/site-preview/posts/' . $slug, $controller . '->previewEntry("posts", "' . $slug . '");');
        assert_same($expected, $status);
    }
});

test('list page and homepage previews show saved layouts to editors who may see them', function (): void {
    $seed = <<<'PHP'
$roles->create(['label' => 'Pages only', 'permissions' => [
    ['effect' => 'allow', 'actions' => ['content.read'], 'resources' => ['content:pages:*']],
]]);
$users->create('limited', 'secret-password', 'pages-only');
(new \CometCMS\Content\ContentTypeRepository())->save(['name' => 'posts']);
(new \CometCMS\Content\ContentTypeRepository())->save(['name' => 'pages']);
(new \CometCMS\Content\ContentTypeRepository())->save(['name' => 'notes', 'visibility' => 'private']);
PHP;
    $controller = '(new \CometCMS\Controllers\Admin\SiteController(new \CometCMS\Core\Http()))';
    foreach (['posts' => 403, 'pages' => 200] as $type => $expected) {
        [$status] = comet_admin_request('limited', $seed, 'GET', '/admin/site-preview/' . $type, $controller . '->previewIndex("' . $type . '");');
        assert_same($expected, $status, 'List page preview of ' . $type);
    }
    assert_same(404, comet_admin_request('admin', $seed, 'GET', '/admin/site-preview/notes', $controller . '->previewIndex("notes");')[0], 'Private types have no page.');
    assert_same(403, comet_admin_request('limited', $seed, 'GET', '/admin/site-preview', $controller . '->previewHome();')[0], 'The homepage preview needs website access.');
    assert_same(200, comet_admin_request('admin', $seed, 'GET', '/admin/site-preview', $controller . '->previewHome();')[0]);
});

test('frontend preview browsing filters restricted entries and hides unauthorized actions', function (): void {
    $seed = <<<'PHP'
$roles->create(['label' => 'Read only', 'permissions' => [
    ['effect' => 'allow', 'actions' => ['content.read'], 'resources' => ['content:posts:*']],
    ['effect' => 'deny', 'actions' => ['content.read'], 'resources' => ['content:posts:locked']],
]]);
$users->create('limited', 'secret-password', 'read-only');
(new \CometCMS\Content\ContentTypeRepository())->save(['name' => 'posts']);
$content = \CometCMS\Content\ContentRepository::make();
$content->save('posts', ['title' => 'Confidential draft', 'slug' => 'locked'], ['id' => 'admin']);
$content->save('posts', ['title' => 'Allowed draft', 'slug' => 'open'], ['id' => 'admin']);
(new \CometCMS\Site\SiteRepository())->saveSettings([
    'homepage' => ['type' => 'posts'],
    'routes' => ['posts' => ['enabled' => true, 'pattern' => '/posts/{slug}', 'index_path' => '/posts']],
]);
PHP;
    $controller = 'ob_start(); register_shutdown_function(static function (): void { echo json_encode(["html" => ob_get_clean()]); }); (new \CometCMS\Controllers\Admin\SiteController(new \CometCMS\Core\Http()))->previewSite();';
    foreach (['/' => 200, '/posts/open' => 200, '/posts/locked' => 404] as $path => $expected) {
        [$status, $response] = comet_admin_request('limited', $seed, 'GET', '/admin/site-preview?path=' . rawurlencode($path), $controller);
        $html = $response['html'];
        assert_same($expected, $status);
        assert_false(str_contains($html, 'Confidential draft'));
        assert_false(str_contains($html, 'Create new'));
        assert_false(str_contains($html, 'Edit page'));
        assert_false(str_contains($html, 'Edit layout'));
        assert_true(str_contains($html, 'Back to admin'));
        if ($path !== '/posts/locked') {
            assert_true(str_contains($html, 'Allowed draft'));
        }
    }
});

/** Exercise the real entry point and session bootstrap using an isolated install. */
function comet_site_preview_test_install(): string
{
    $root = COMET_STORAGE . '/preview-install';
    mkdir($root, 0775, true);

    foreach (['app', 'config'] as $directory) {
        $source = COMET_ROOT . '/' . $directory;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        mkdir($root . '/' . $directory, 0775, true);
        foreach ($files as $file) {
            $target = $root . '/' . $directory . substr($file->getPathname(), strlen($source));
            if ($file->isDir()) {
                mkdir($target, 0775, true);
            } else {
                copy($file->getPathname(), $target);
            }
        }
    }

    copy(COMET_ROOT . '/index.php', $root . '/index.php');
    // Simulate the SCRIPT_NAME supplied by Apache in root and subdirectory installs.
    file_put_contents($root . '/router.php', <<<'PHP'
<?php
$_SERVER['SCRIPT_NAME'] = str_starts_with($_SERVER['REQUEST_URI'], '/cms/') ? '/cms/index.php' : '/index.php';
require __DIR__ . '/index.php';
PHP);

    return $root;
}

function comet_site_preview_test_request(int $port, string $path, string $cookie = '', string $method = 'GET', array $body = [], string $csrf = ''): array
{
    $body = file_get_contents('http://127.0.0.1:' . $port . $path, false, stream_context_create([
        'http' => [
            'ignore_errors' => true,
            'follow_location' => 0,
            'method' => $method,
            'header' => ($cookie !== '' ? 'Cookie: ' . $cookie . "\r\n" : '') . "Content-Type: application/json\r\nX-CSRF-Token: " . $csrf . "\r\n",
            'content' => $method === 'GET' ? '' : json_encode($body),
        ],
    ]));
    $responseHeaders = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?? [])
        : (${'http_response_header'} ?? []);
    $headers = [];
    foreach ($responseHeaders as $header) {
        if (str_contains($header, ':')) {
            [$name, $value] = explode(':', $header, 2);
            $headers[strtolower($name)] = trim($value);
        }
    }
    preg_match('#^HTTP/\S+\s+(\d{3})#', $responseHeaders[0] ?? '', $match);

    return [(int) ($match[1] ?? 0), (string) $body, $headers];
}

test('headless public roots show the placeholder in root and subdirectory installs', function (): void {
    $root = comet_site_preview_test_install();
    $port = 22080 + random_int(0, 2000);
    $server = comet_test_start_php_server('127.0.0.1', $port, $root . '/router.php');

    try {
        usleep(300000);
        foreach (['/', '/cms/'] as $path) {
            [$status, $body, $headers] = comet_site_preview_test_request($port, $path);
            assert_same(200, $status);
            assert_true(str_contains($body, 'alt="CometCMS"'));
            assert_true(str_contains($body, 'src="data:image/png;base64,'), 'The logo needs no admin build or asset request.');
            assert_false(isset($headers['location']), 'The public root does not redirect into the admin.');
            assert_true(str_contains($headers['content-type'], 'text/html'));
            assert_true(str_contains($headers['content-security-policy'], "script-src 'none'"));
        }
        assert_same(404, comet_site_preview_test_request($port, '/unknown-page')[0]);
    } finally {
        comet_test_stop_process($server);
    }
});

test('custom theme stylesheets are served separately in root and subdirectory installs', function (): void {
    $root = comet_site_preview_test_install();
    comet_test_run_php(['-r', 'require ' . var_export($root . '/app/bootstrap.php', true) . ';' . <<<'PHP'
(new \CometCMS\Workspaces\WorkspaceRepository())->all();
(new \CometCMS\Site\SiteRepository())->saveTheme(['custom_css' => '.custom-file { color: red; }']);
PHP]);
    $version = substr(sha1('.custom-file { color: red; }'), 0, 12);
    $port = 22080 + random_int(0, 2000);
    $server = comet_test_start_php_server('127.0.0.1', $port, $root . '/router.php');
    try {
        usleep(300000);
        foreach (['', '/cms'] as $base) {
            $path = $base . '/_site/default/theme/style.css?v=' . $version;
            [$status, $css, $headers] = comet_site_preview_test_request($port, $path);
            assert_same(200, $status);
            assert_same('.custom-file { color: red; }', $css);
            assert_same('text/css; charset=utf-8', $headers['content-type']);
            assert_same('nosniff', $headers['x-content-type-options']);
            assert_same('"' . $version . '"', $headers['etag']);
            assert_true(str_contains($headers['cache-control'], 'immutable'));
            [$status, $baseCss] = comet_site_preview_test_request($port, $base . '/_site/default/style.css');
            assert_same(200, $status);
            assert_true(str_contains($baseCss, '--c-primary:'));
            assert_false(str_contains($baseCss, '.custom-file'));
            assert_same(200, comet_site_preview_test_request($port, $path, '', 'HEAD')[0]);
        }
        assert_same(404, comet_site_preview_test_request($port, '/_site/missing/theme/style.css')[0]);
    } finally {
        comet_test_stop_process($server);
    }
});

test('website history routes protect revisions and restore saved layouts regions and themes', function (): void {
    $root = comet_site_preview_test_install();
    $sessionPrefix = 'history-' . bin2hex(random_bytes(6));
    comet_test_run_php(['-r', 'require ' . var_export($root . '/app/bootstrap.php', true) . ';' . <<<'PHP'
$users = new \CometCMS\Auth\UserRepository();
$users->create('history-admin', 'secret-password', 'admin');
$users->create('history-viewer', 'secret-password', 'viewer');
(new \CometCMS\Auth\RoleRepository())->create(['label' => 'Designer', 'permissions' => [
    ['effect' => 'allow', 'actions' => ['site.manage'], 'resources' => ['site:*']],
]]);
$users->create('history-designer', 'secret-password', 'designer');
(new \CometCMS\Content\ContentTypeRepository())->save(['name' => 'about']);
PHP .
        'foreach (["admin", "viewer", "designer"] as $role) { session_id(' . var_export($sessionPrefix, true) . ' . "-" . $role); session_start(); $_SESSION["user_id"] = "history-" . $role; $_SESSION["cometcms_csrf"] = "history-token"; session_write_close(); }'
    ]);
    $port = 22080 + random_int(0, 2000);
    $server = comet_test_start_php_server('127.0.0.1', $port, $root . '/router.php');
    $request = static function (string $path, string $method = 'GET', array $body = [], string $role = 'admin', string $csrf = 'history-token') use ($port, $sessionPrefix): array {
        [$status, $json] = comet_site_preview_test_request($port, '/cms/admin/api/site' . $path, $role !== '' ? 'cometcms_admin=' . $sessionPrefix . '-' . $role : '', $method, $body, $csrf);
        return [$status, json_decode($json, true)];
    };
    try {
        usleep(300000);
        assert_same(401, $request('/theme/revisions', 'GET', [], '')[0]);
        assert_same(403, $request('/theme/revisions', 'GET', [], 'viewer')[0]);
        $nodes = [\CometCMS\Site\LayoutSchema::node('section', [], ['background' => 'accent'])];
        assert_same(200, $request('/layouts/about/detail', 'PUT', ['nodes' => $nodes])[0]);
        assert_same(200, $request('/layouts/about/detail', 'PUT', ['nodes' => []])[0]);
        [$status, $history] = $request('/layouts/about/detail/revisions');
        assert_same(200, $status);
        assert_same(2, count($history['data']));
        assert_same('history-admin', $history['data'][0]['created_by']);
        $id = $history['data'][0]['id'];
        $readPath = '/layouts/about/detail/revisions/' . $id;
        assert_same(401, $request($readPath, 'GET', [], '')[0]);
        assert_same(403, $request($readPath, 'GET', [], 'viewer')[0]);
        [$status, $loaded] = $request($readPath);
        assert_same(200, $status);
        assert_same('accent', $loaded['data']['nodes'][0]['style']['background']);
        assert_same([], $request('/layouts/about/detail')[1]['data']['nodes']);
        assert_same($history, $request('/layouts/about/detail/revisions')[1], 'Reading must not create revisions.');
        assert_same(404, $request('/layouts/about/detail/revisions/missing')[0]);
        $restorePath = '/layouts/about/detail/revisions/' . $id . '/restore';
        assert_same(419, $request($restorePath, 'POST', [], 'admin', 'wrong')[0]);
        assert_same(403, $request($restorePath, 'POST', [], 'viewer')[0]);
        [$status, $restored] = $request($restorePath, 'POST');
        assert_same(200, $status);
        assert_same('accent', $restored['data']['nodes'][0]['style']['background']);
        assert_true($restored['data']['customized']);
        assert_same(404, $request('/layouts/about/detail/revisions/missing/restore', 'POST')[0]);

        $request('/regions/header', 'PUT', ['nodes' => $nodes]);
        $request('/regions/header', 'DELETE');
        [, $history] = $request('/regions/header/revisions');
        $id = $history['data'][0]['id'];
        [$status, $loaded] = $request('/regions/header/revisions/' . $id);
        assert_same(200, $status);
        assert_same('accent', $loaded['data']['nodes'][0]['style']['background']);
        assert_same($history, $request('/regions/header/revisions')[1]);
        assert_same(404, $request('/regions/footer/revisions/' . $id)[0]);
        [$status, $restored] = $request('/regions/header/revisions/' . $id . '/restore', 'POST');
        assert_same(200, $status);
        assert_true($restored['data']['customized']);
        assert_same(404, $request('/regions/footer/revisions/' . $id . '/restore', 'POST')[0]);

        [, $created] = $request('/components', 'POST', ['name' => 'History component', 'nodes' => $nodes]);
        $componentPath = '/components/' . $created['data']['id'];
        $request($componentPath, 'PUT', ['nodes' => [\CometCMS\Site\LayoutSchema::node('section')]]);
        [, $componentHistory] = $request($componentPath . '/revisions');
        $componentRevisionPath = $componentPath . '/revisions/' . $componentHistory['data'][0]['id'];
        [$status, $loaded] = $request($componentRevisionPath);
        assert_same(200, $status);
        assert_same('accent', $loaded['data']['nodes'][0]['style']['background']);
        assert_same($componentHistory, $request($componentPath . '/revisions')[1]);
        assert_false(($request($componentPath)[1]['data']['nodes'][0]['style']['background'] ?? '') === 'accent');
        assert_same(403, $request($componentRevisionPath, 'GET', [], 'viewer')[0]);
        assert_same(404, $request($componentPath . '/revisions/missing')[0]);

        $request('/theme', 'PUT', ['base_size' => 14, 'custom_css' => '.old {color:red}']);
        $request('/theme', 'PUT', ['base_size' => 20, 'custom_css' => '.new {color:blue}']);
        [, $history] = $request('/theme/revisions');
        $id = $history['data'][0]['id'];
        [$status, $loaded] = $request('/theme/revisions/' . $id, 'GET', [], 'designer');
        assert_same(200, $status);
        assert_same(14, $loaded['data']['base_size']);
        assert_same('.new {color:blue}', $loaded['data']['custom_css']);
        assert_same('.old {color:red}', $request('/theme/revisions/' . $id)[1]['data']['custom_css']);
        assert_same(20, $request('')[1]['data']['theme']['base_size']);
        assert_same($history, $request('/theme/revisions')[1]);
        [$status, $restored] = $request('/theme/revisions/' . $id . '/restore', 'POST', [], 'designer');
        assert_same(200, $status);
        assert_same(14, $restored['data']['base_size']);
        assert_same('.new {color:blue}', $restored['data']['custom_css'], 'Designer restores cannot change administrator-only CSS.');
        [$status, $restored] = $request('/theme/revisions/' . $id . '/restore', 'POST');
        assert_same(200, $status);
        assert_same('.old {color:red}', $restored['data']['custom_css']);
    } finally {
        comet_test_stop_process($server);
    }
});

test('entry preview redirects preserve workspace locale and authenticated draft rendering', function (): void {
    $root = comet_site_preview_test_install();
    $sessionId = 'preview-' . bin2hex(random_bytes(8));
    comet_test_run_php(['-r', 'require ' . var_export($root . '/app/bootstrap.php', true) . ';' . <<<'PHP'
(new \CometCMS\Auth\UserRepository())->create('preview-editor', 'secret-password', 'editor');
$registry = new \CometCMS\Workspaces\WorkspaceRepository();
$registry->save(['slug' => 'preview-site', 'label' => 'Preview site']);
foreach (['default', 'preview-site'] as $slug) {
    $workspace = new \CometCMS\Workspaces\WorkspaceContext($slug);
    (new \CometCMS\Localization\LanguageSettings($workspace))->save(['locales' => ['en', 'de'], 'default_locale' => 'en']);
    (new \CometCMS\Content\ContentTypeRepository($workspace))->save([
        'name' => 'startseite', 'locales' => ['en', 'de'], 'default_locale' => 'en',
    ]);
    (new \CometCMS\Content\ContentTypeRepository($workspace))->save(['name' => 'posts']);
    $content = \CometCMS\Content\ContentRepository::make($workspace);
    $content->save('posts', ['title' => 'Collection draft', 'slug' => 'draft-post'], ['id' => 'preview-editor']);
    $entry = $content->save('startseite', ['title' => $slug . ' English draft', 'slug' => 'startseite', 'locale' => 'en'], ['id' => 'preview-editor']);
    $content->save('startseite', ['title' => $slug . ' Deutscher Entwurf', 'slug' => 'startseite-de', 'locale' => 'de'], ['id' => 'preview-editor'], $entry['id']);
    (new \CometCMS\Site\SiteRepository($workspace))->saveSettings([
        'enabled' => true, 'locales' => ['en', 'de'], 'default_locale' => 'en',
        'homepage' => ['type' => 'startseite', 'entry' => 'startseite'],
        'routes' => ['startseite' => ['enabled' => true], 'posts' => ['enabled' => true, 'pattern' => '/posts/{slug}', 'index_path' => '/posts']],
    ]);
}
PHP .
        'session_id(' . var_export($sessionId, true) . '); session_start(); $_SESSION["user_id"] = "preview-editor"; session_write_close();'
    ]);
    $cookie = 'cometcms_admin=' . $sessionId;
    $port = 22080 + random_int(0, 2000);
    $server = comet_test_start_php_server('127.0.0.1', $port, $root . '/router.php');

    try {
        usleep(300000);
        foreach (['', '/cms'] as $base) {
            $query = '?workspace=preview-site&locale=de';
            [$status, , $headers] = comet_site_preview_test_request($port, $base . '/site-preview/startseite/startseite-de' . $query, $cookie);
            assert_same(302, $status);
            assert_same($base . '/admin/site-preview/startseite/startseite-de' . $query, $headers['location'] ?? null);

            [$status, $html, $headers] = comet_site_preview_test_request($port, $headers['location'], $cookie);
            assert_same(200, $status);
            assert_true(str_contains($html, 'preview-site Deutscher Entwurf'));
            assert_false(str_contains($html, 'default Deutscher Entwurf'));
            assert_true(str_contains($html, '<html lang="de">'));
            assert_same('private, no-store, no-transform', $headers['cache-control'] ?? null);
            assert_same('noindex, nofollow', $headers['x-robots-tag'] ?? null);

            assert_true(str_contains($html, 'Back to admin'));
            assert_true(str_contains($html, 'Edit entry'));
            assert_true(str_contains($html, 'Create new'));
            assert_matches('#href="' . preg_quote($base, '#') . '/admin/content/startseite/[A-Za-z0-9_-]+\?workspace=preview-site&amp;locale=de"#', $html);
            assert_same(1, preg_match('/href="([^"]+)" lang="en"/', $html, $languageLink));
            [$status, $english] = comet_site_preview_test_request($port, html_entity_decode($languageLink[1]), $cookie);
            assert_same(200, $status, 'Language links resolve stable IDs across different localized slugs.');
            assert_true(str_contains($english, 'preview-site English draft'));

            [$status, $home] = comet_site_preview_test_request($port, $base . '/admin/site-preview?workspace=preview-site', $cookie);
            assert_same(200, $status);
            assert_true(str_contains($home, 'preview-site English draft'), 'The logo destination previews the draft homepage.');
            assert_true(str_contains($home, 'Back to admin'));
            [$status, $translatedHome] = comet_site_preview_test_request($port, $base . '/admin/site-preview?workspace=preview-site&locale=de', $cookie);
            assert_same(200, $status);
            assert_true(str_contains($translatedHome, 'preview-site Deutscher Entwurf'));
            [$status, $legacyIndex] = comet_site_preview_test_request($port, $base . '/admin/site-preview/posts?workspace=preview-site', $cookie);
            assert_same(200, $status);
            assert_true(str_contains($legacyIndex, 'Back to admin'));
            assert_true(str_contains($legacyIndex, 'Create new'));

            [$status, $index] = comet_site_preview_test_request($port, $base . '/admin/site-preview?workspace=preview-site&path=/posts', $cookie);
            assert_same(200, $status);
            assert_true(str_contains($index, 'Create new'));
            assert_true(str_contains($index, $base . '/admin/content/posts/new?workspace=preview-site'));
            assert_true(str_contains($index, 'path=%2Fposts%2Fdraft-post'), 'Collection links remain in preview.');
            [$status, $draft] = comet_site_preview_test_request($port, $base . '/admin/site-preview?workspace=preview-site&path=/posts/draft-post', $cookie);
            assert_same(200, $status);
            assert_true(str_contains($draft, 'Collection draft'));
            assert_true(str_contains($draft, 'Create new'));
            assert_true(str_contains($draft, 'Draft'));

            [$status, $json] = comet_site_preview_test_request($port, $base . '/admin/api/site/status?workspace=preview-site', $cookie);
            assert_same(200, $status);
            assert_same($base . '/admin/site-preview?workspace=preview-site', json_decode($json, true)['data']['homepage_preview']);
            assert_same(401, comet_site_preview_test_request($port, $base . '/admin/site-preview?workspace=preview-site')[0]);

            [$status] = comet_site_preview_test_request($port, $base . '/admin/site-preview/startseite/startseite-de' . $query);
            assert_same(401, $status, 'An editor session is required to preview a draft.');
        }

        [$status] = comet_site_preview_test_request($port, '/startseite/startseite');
        assert_same(404, $status, 'The draft stays hidden on the public website.');
    } finally {
        comet_test_stop_process($server);
    }
});

test('unsaved previews preserve draft content design navigation and languages without publishing', function (): void {
    $root = comet_site_preview_test_install();
    $sessionPrefix = 'snapshot-' . bin2hex(random_bytes(8));
    comet_test_run_php(['-r', 'require ' . var_export($root . '/app/bootstrap.php', true) . ';' . <<<'PHP'
$users = new \CometCMS\Auth\UserRepository();
$users->create('snapshot-admin', 'secret-password', 'admin');
$users->create('snapshot-editor', 'secret-password', 'editor');
$users->create('snapshot-viewer', 'secret-password', 'viewer');
$registry = new \CometCMS\Workspaces\WorkspaceRepository();
$registry->all();
(new \CometCMS\Workspaces\WorkspaceContext('default'))->ensure();
$registry->save(['slug' => 'other-site', 'label' => 'Other site']);
(new \CometCMS\Localization\LanguageSettings())->save(['locales' => ['en', 'de'], 'default_locale' => 'en']);
(new \CometCMS\Content\ContentTypeRepository())->save(['name' => 'posts', 'locales' => ['en', 'de'], 'default_locale' => 'en', 'fields' => ['design' => ['type' => 'page']]]);
$content = \CometCMS\Content\ContentRepository::make();
$entry = $content->save('posts', ['title' => 'Saved English', 'slug' => 'first', 'status' => 'published', 'locale' => 'en'], ['id' => 'snapshot-admin']);
$content->save('posts', ['title' => 'Gespeichertes Deutsch', 'locale' => 'de'], ['id' => 'snapshot-admin'], $entry['id']);
$content->save('posts', ['title' => 'Unchanged page', 'slug' => 'second', 'status' => 'published', 'locale' => 'en'], ['id' => 'snapshot-admin']);
(new \CometCMS\Site\SiteRepository())->saveSettings([
    'locales' => ['en', 'de'], 'default_locale' => 'en',
    'homepage' => ['type' => 'posts', 'entry' => 'first'],
    'routes' => ['posts' => ['enabled' => true, 'pattern' => '/posts/{slug}', 'index_path' => '/posts']],
]);
PHP .
        'foreach (["admin", "editor", "viewer"] as $role) { session_id(' . var_export($sessionPrefix, true) . ' . "-" . $role); session_start(); $_SESSION["user_id"] = "snapshot-" . $role; $_SESSION["cometcms_csrf"] = "snapshot-token"; session_write_close(); }'
    ]);
    $port = 22080 + random_int(0, 2000);
    $server = comet_test_start_php_server('127.0.0.1', $port, $root . '/router.php');
    $request = static fn(string $path, string $method = 'GET', array $body = [], string $role = 'admin', string $csrf = 'snapshot-token'): array =>
        comet_site_preview_test_request($port, '/cms' . $path, $role !== '' ? 'cometcms_admin=' . $sessionPrefix . '-' . $role : '', $method, $body, $csrf);
    $body = [
        'target' => 'layout', 'type' => 'posts', 'variant' => 'detail', 'entry' => 'first', 'locale' => 'en',
        'theme' => ['custom_css' => '.unsaved-design { color: red; }'],
        'nodes' => [['id' => 'sec', 'kind' => 'section', 'children' => [
            ['id' => 'row', 'kind' => 'row', 'children' => [
                ['id' => 'col', 'kind' => 'column', 'children' => [
                    ['id' => 'heading', 'kind' => 'heading', 'props' => ['text' => 'Unsaved layout']],
                    ['id' => 'title', 'kind' => 'field', 'props' => ['source' => \CometCMS\Site\SmartValue::reference('title')]],
                ]],
            ]],
        ]]],
        'entries' => [
            ['type' => 'posts', 'id' => 'first', 'values' => ['title' => 'Unsaved English', 'locale' => 'en']],
            ['type' => 'posts', 'id' => 'first', 'values' => ['title' => 'Ungespeichertes Deutsch', 'locale' => 'de']],
        ],
    ];
    try {
        usleep(300000);
        assert_same(401, $request('/admin/api/site/preview-snapshots', 'POST', $body, '')[0]);
        assert_same(419, $request('/admin/api/site/preview-snapshots', 'POST', $body, 'admin', '')[0]);
        assert_same(403, $request('/admin/api/site/preview-snapshots', 'POST', $body, 'editor')[0], 'Content editors cannot override the website design.');
        [$status, $json] = $request('/admin/api/site/preview-snapshots', 'POST', $body);
        assert_same(200, $status);
        $url = json_decode($json, true)['data']['url'];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $token = $query['snapshot'];
        $previewPath = '/admin/site-preview?workspace=default&snapshot=' . $token;
        [$status, $html, $headers] = $request($previewPath);
        assert_same(200, $status);
        assert_true(str_contains($html, 'Unsaved English'));
        assert_true(str_contains($html, 'Unsaved layout'));
        assert_true(str_contains($html, '.unsaved-design'));
        assert_true(str_contains($html, 'class="c-admin-status c-admin-status-preview"'));
        assert_false(str_contains($html, 'class="c-admin-status c-admin-status-published"'));
        assert_false(str_contains($html, 'c-admin-mode'));
        assert_true(str_contains($html, 'snapshot=' . $token . '&amp;locale=de') || str_contains($html, 'locale=de&amp;snapshot=' . $token));
        assert_same('private, no-store, no-transform', $headers['cache-control']);
        assert_same('noindex, nofollow', $headers['x-robots-tag']);
        assert_false(str_contains($html, 'data-node='), 'Full-page previews have no builder overlays.');
        [$status, $translated] = $request('/admin/site-preview/posts/first?workspace=default&snapshot=' . $token . '&locale=de');
        assert_same(200, $status);
        assert_true(str_contains($translated, 'Ungespeichertes Deutsch'));
        assert_true(str_contains($translated, 'Unsaved layout'));
        [$status, $index] = $request($previewPath . '&path=/posts');
        assert_same(200, $status);
        assert_true(str_contains($index, 'Unsaved English'), 'Lists use the same unsaved entry snapshot.');
        assert_true(str_contains($index, 'snapshot=' . $token), 'Page links retain the snapshot.');
        [$status, $canvasJson] = $request('/admin/api/site/preview', 'POST', $body);
        assert_same(200, $status);
        assert_true(str_contains(json_decode($canvasJson, true)['data']['html'], 'Unsaved English'), 'The builder canvas renders the same entry edits.');
        [$status, $saved] = $request('/admin/api/content/posts/first');
        assert_same(200, $status);
        $saved = json_decode($saved, true)['data'];
        assert_same('Saved English', $saved['title']);
        assert_same('Gespeichertes Deutsch', $saved['values']['title']['values']['de']);
        [$status, $live] = $request('/', 'GET', [], '');
        assert_same(200, $status);
        assert_true(str_contains($live, 'Saved English'));
        assert_false(str_contains($live, 'Unsaved layout'));
        assert_false(str_contains($live, '.unsaved-design'));
        assert_same(401, $request($previewPath, 'GET', [], '')[0]);
        assert_same(410, $request($previewPath, 'GET', [], 'editor')[0], 'Preview links remain private to their creator.');
        assert_same(410, $request('/admin/site-preview?workspace=other-site&snapshot=' . $token)[0]);
        $pageBody = [
            'target' => 'entry_page', 'type' => 'posts', 'entry' => 'first', 'field' => 'design',
            'nodes' => [comet_page_test_section('preview', [comet_page_test_heading('preview-heading', 'Unsaved page content')])],
        ];
        assert_same(403, $request('/admin/api/site/preview-snapshots', 'POST', $pageBody, 'editor')[0]);
        [$status, $json] = $request('/admin/api/site/preview-snapshots', 'POST', $pageBody);
        assert_same(200, $status);
        $pagePath = substr(json_decode($json, true)['data']['url'], strlen('/cms'));
        [$status, $pageHtml] = $request($pagePath);
        assert_same(200, $status);
        assert_true(str_contains($pageHtml, 'Unsaved page content'));
        assert_false(str_contains($pageHtml, 'data-node='));
        assert_false(str_contains($pageHtml, 'data-drop-root'));
        [$status, $otherPage] = $request($pagePath . '&path=/posts/second');
        assert_same(200, $status);
        assert_false(str_contains($otherPage, 'Unsaved page content'));
        [$status, $savedPage] = $request('/admin/api/content/posts/first');
        assert_same(200, $status);
        assert_false(str_contains($savedPage, 'Unsaved page content'));

        $entryBody = ['target' => 'entry', 'type' => 'posts', 'entry' => 'first', 'locale' => 'en', 'entries' => [$body['entries'][0]]];
        assert_same(403, $request('/admin/api/site/preview-snapshots', 'POST', $entryBody, 'viewer')[0]);
        [$status, $json] = $request('/admin/api/site/preview-snapshots', 'POST', $entryBody, 'editor');
        assert_same(200, $status, 'Content editors can preview their entry edits.');
        $editorPreviewPath = substr(json_decode($json, true)['data']['url'], strlen('/cms'));
        [$status, $editorHtml] = $request($editorPreviewPath, 'GET', [], 'editor');
        assert_same(200, $status);
        assert_true(str_contains($editorHtml, 'Unsaved English'));
        assert_true(str_contains($editorHtml, 'class="c-admin-status c-admin-status-preview"'));
        parse_str((string) parse_url($editorPreviewPath, PHP_URL_QUERY), $editorQuery);
        $navigation = '/admin/site-preview?workspace=default&snapshot=' . $editorQuery['snapshot'];
        [$status, $otherPage] = $request($navigation . '&path=/posts/second', 'GET', [], 'editor');
        assert_same(200, $status);
        assert_true(str_contains($otherPage, 'class="c-admin-status c-admin-status-published"'), 'An unaffected page shows its saved state within the same snapshot.');
        [$status, $back] = $request($navigation . '&path=/', 'GET', [], 'editor');
        assert_same(200, $status);
        assert_true(str_contains($back, 'class="c-admin-status c-admin-status-preview"'));
        assert_same(200, $request('/admin/api/content/posts/first', 'PUT', ['title' => 'Unsaved English', 'locale' => 'en'])[0]);
        [$status, $upToDate] = $request($editorPreviewPath, 'GET', [], 'editor');
        assert_same(200, $status);
        assert_true(str_contains($upToDate, 'class="c-admin-status c-admin-status-published"'), 'A preview matching newly saved content shows the saved state.');

        $entryBody['entry'] = '';
        $entryBody['entries'] = [['type' => 'posts', 'id' => '', 'values' => ['title' => 'Brand new unsaved entry', 'locale' => 'en']]];
        [$status, $json] = $request('/admin/api/site/preview-snapshots', 'POST', $entryBody, 'editor');
        assert_same(200, $status);
        [$status, $newHtml] = $request(substr(json_decode($json, true)['data']['url'], strlen('/cms')), 'GET', [], 'editor');
        assert_same(200, $status);
        assert_true(str_contains($newHtml, 'Brand new unsaved entry'));
        assert_same(404, $request('/admin/api/content/posts/brand-new-unsaved-entry')[0]);
        assert_same(200, $request('/admin/api/users/snapshot-editor', 'PUT', ['role' => 'viewer'])[0]);
        assert_same(403, $request($editorPreviewPath, 'GET', [], 'editor')[0], 'Existing snapshots recheck current editing permissions.');
        $snapshotFile = $root . '/storage/workspaces/default/cache/site-previews/' . $token . '.json';
        $stored = json_decode((string) file_get_contents($snapshotFile), true);
        $stored['expires'] = time() - 1;
        file_put_contents($snapshotFile, json_encode($stored));
        [$status, $expired] = $request($previewPath);
        assert_same(410, $status);
        assert_true(str_contains($expired, 'Open a new preview from the editor'));
    } finally {
        comet_test_stop_process($server);
    }
});

test('preview admin bar returns to admin and keeps current page editing separate', function (): void {
    (new \CometCMS\Localization\LanguageSettings())->save(['locales' => ['en', 'de'], 'default_locale' => 'en']);
    $types = new \CometCMS\Content\ContentTypeRepository();
    $types->save(['name' => 'posts']);
    $type = $types->find('posts');
    (new \CometCMS\Site\SiteRepository())->saveSettings(['routes' => ['posts' => ['index_enabled' => true]]]);
    $context = \CometCMS\Site\SiteContext::make(new \CometCMS\Site\SiteRepository(), '/cms');
    $entry = $context->content->save('posts', ['title' => 'Original', 'slug' => 'original'], ['id' => 'admin']);
    $context->content->save('posts', ['slug' => 'renamed', 'locale' => 'en'], ['id' => 'admin'], $entry['id']);
    $entry = $context->content->save('posts', ['title' => 'Deutsch', 'slug' => 'deutsch', 'locale' => 'de'], ['id' => 'admin'], $entry['id']);
    $context->locale = 'de';
    $context->adminCan = static fn(): bool => true;
    $bar = new \CometCMS\Site\PreviewAdminBar($context);
    $links = static function (string $html): \DOMXPath {
        $document = new \DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        return new \DOMXPath($document);
    };
    $builderUrl = '/cms/admin/page-builder/builder/layout/posts/detail?workspace=default&locale=de&entry=' . $entry['uid'];
    $entryUrl = '/cms/admin/content/posts/' . $entry['uid'] . '?workspace=default&locale=de';
    foreach (['en' => ['Edit page', 'Edit entry', 'Back to admin'], 'de' => ['Seite bearbeiten', 'Eintrag bearbeiten', 'Zurück zur Verwaltung']] as $language => [$editPage, $editEntry, $back]) {
        $context->adminUser = ['username' => 'admin', 'language' => $language];
        $xpath = $links($bar->render(['type' => $type, 'entry' => $entry]));
        $brand = $xpath->query('//a[@class="c-admin-brand"]')->item(0);
        $primary = $xpath->query('//a[contains(@class,"c-admin-action-primary")]')->item(0);
        assert_same('/cms/admin/dashboard?workspace=default&locale=de', $brand->getAttribute('href'));
        assert_same($back, $brand->getAttribute('title'));
        assert_same($builderUrl, $primary->getAttribute('href'));
        assert_same($editPage, $primary->textContent);
        assert_same($entryUrl, $xpath->query('//a[@title="' . $editEntry . '"]')->item(0)->getAttribute('href'));
        assert_same('/cms/admin/dashboard?workspace=default&locale=de', $xpath->query('//details[contains(@class,"c-admin-account")]//a[last()]')->item(0)->getAttribute('href'));
    }

    $xpath = $links($bar->render(['type' => $type, 'entry' => null]));
    $indexBuilder = '/cms/admin/page-builder/builder/layout/posts/index?workspace=default&locale=de';
    assert_same('/cms/admin/dashboard?workspace=default&locale=de', $xpath->query('//a[@class="c-admin-brand"]')->item(0)->getAttribute('href'));
    assert_same($indexBuilder, $xpath->query('//a[contains(@class,"c-admin-action-primary")]')->item(0)->getAttribute('href'));

    $context->adminCan = static fn(string $action): bool => $action === 'content.update';
    $html = $bar->render(['type' => $type, 'entry' => $entry]);
    $xpath = $links($html);
    assert_same('/cms/admin/dashboard?workspace=default&locale=de', $xpath->query('//a[@class="c-admin-brand"]')->item(0)->getAttribute('href'));
    assert_same($entryUrl, $xpath->query('//a[contains(@class,"c-admin-action-primary")]')->item(0)->getAttribute('href'));
    assert_false(str_contains($html, '/page-builder/'), 'Content editors keep their entry editor without a forbidden builder link.');

    $context->adminCan = static fn(string $action): bool => $action === 'site.manage';
    $html = $bar->render(['type' => $type, 'entry' => $entry]);
    assert_true(str_contains($html, 'c-admin-action-primary'));
    assert_false(str_contains($html, 'Eintrag bearbeiten'), 'Builder access does not grant entry editing.');
    $unsaved = $context->content->preview('posts', ['title' => 'New unsaved page'], ['id' => 'admin'], null);
    $html = $bar->render(['type' => $type, 'entry' => $context->localize('posts', $unsaved)]);
    assert_false(str_contains($html, '/page-builder/'), 'An unsaved entry cannot be selected in the builder yet.');
    $html = $bar->render(['type' => null, 'entry' => null]);
    assert_false(str_contains($html, '/page-builder/'), 'Pages without a content type keep the backend fallback.');
});

test('the admin bar shows one localized state for the displayed content', function (): void {
    $types = new \CometCMS\Content\ContentTypeRepository();
    $types->save(['name' => 'posts']);
    $type = $types->find('posts');
    $site = new \CometCMS\Site\SiteRepository();
    $context = \CometCMS\Site\SiteContext::make($site);
    $context->adminCan = static fn(): bool => true;
    $bar = new \CometCMS\Site\PreviewAdminBar($context);
    $cases = [
        ['published', null, 'published', 'Published', 'Veröffentlicht'],
        ['protected', null, 'protected', 'Protected', 'Geschützt'],
        ['draft', null, 'draft', 'Draft', 'Entwurf'],
        ['published', '2099-01-01T00:00:00Z', 'scheduled', 'Scheduled', 'Geplant'],
        ['archived', null, 'archived', 'Archived', 'Archiviert'],
    ];
    foreach ($cases as [$status, $date, $display, $en, $de]) {
        $entry = $context->content->save('posts', [
            'title' => 'Example entry', 'slug' => $display, 'status' => $status, 'published_at' => $date,
        ], ['id' => 'admin']);
        foreach (['en' => $en, 'de' => $de] as $language => $label) {
            $context->adminUser = ['username' => 'admin', 'language' => $language];
            $context->previewToken = '';
            $html = $bar->render(['type' => $type, 'entry' => $entry]);
            assert_same(1, substr_count($html, 'class="c-admin-status c-admin-status-'));
            assert_true(str_contains($html, 'c-admin-status-' . $display . '"'));
            assert_true(str_contains($html, '</span>' . $label . '</span>'));
            assert_false(str_contains($html, 'c-admin-mode'));
            $context->previewToken = 'snapshot';
            $unchanged = $bar->render(['type' => $type, 'entry' => $entry]);
            assert_true(str_contains($unchanged, 'c-admin-status-' . $display . '"'), 'A snapshot token alone does not change the displayed state.');
            $html = $bar->render(['type' => $type, 'entry' => $entry], true);
            assert_same(1, substr_count($html, 'class="c-admin-status c-admin-status-'));
            assert_true(str_contains($html, 'c-admin-status-preview"'));
            assert_false(str_contains($html, 'c-admin-status-' . $display . '"'));
            assert_true(str_contains($html, '</span>' . ($language === 'de' ? 'Vorschau' : 'Preview') . '</span>'));
        }
    }
    $context->previewToken = '';
    assert_true(str_contains($bar->render(['type' => $type, 'entry' => null]), 'c-admin-status-preview"'), 'Collection and layout previews also have one display state.');
});

test('preview status follows rendered changes in the current page locale and shared design', function (): void {
    (new \CometCMS\Localization\LanguageSettings())->save(['locales' => ['en', 'de'], 'default_locale' => 'en']);
    $types = new \CometCMS\Content\ContentTypeRepository();
    $types->save(['name' => 'posts', 'locales' => ['en', 'de'], 'default_locale' => 'en']);
    $types->save(['name' => 'about']);
    $site = new \CometCMS\Site\SiteRepository();
    $site->saveSettings(['locales' => ['en', 'de'], 'default_locale' => 'en', 'routes' => [
        'posts' => ['enabled' => true, 'pattern' => '/posts/{slug}', 'index_path' => '/posts'],
        'about' => ['enabled' => true, 'pattern' => '/about/{slug}'],
    ]]);
    $nodes = static fn(array $children): array => \CometCMS\Site\LayoutSchema::normalize([
        ['id' => 'section', 'kind' => 'section', 'children' => [
            ['id' => 'row', 'kind' => 'row', 'children' => [
                ['id' => 'column', 'kind' => 'column', 'children' => $children],
            ]],
        ]],
    ]);
    $detail = $nodes([['id' => 'title', 'kind' => 'field', 'props' => ['source' => \CometCMS\Site\SmartValue::reference('title')]]]);
    $site->saveLayout('posts', 'detail', $detail);
    $site->saveLayout('about', 'detail', $detail);
    $site->saveRegion('header', []);
    $site->saveRegion('footer', []);
    $content = \CometCMS\Content\ContentRepository::make();
    $user = ['id' => 'admin'];
    $content->save('posts', ['title' => 'First', 'slug' => 'first', 'locale' => 'en', 'status' => 'published'], $user);
    $first = $content->save('posts', ['title' => 'Erste', 'slug' => 'erste', 'locale' => 'de', 'status' => 'published'], $user, 'first');
    $content->save('posts', ['title' => 'Second', 'slug' => 'second', 'locale' => 'en', 'status' => 'published'], $user);
    $content->save('about', ['title' => 'About', 'slug' => 'about', 'status' => 'published'], $user);
    $context = static function () use ($site): \CometCMS\Site\SiteContext {
        $ctx = \CometCMS\Site\SiteContext::make($site);
        $ctx->preview = $ctx->previewBanner = $ctx->browsePreview = true;
        $ctx->previewToken = 'same-snapshot';
        $ctx->locale = 'en';
        $ctx->adminUser = ['username' => 'admin', 'language' => 'en'];
        $ctx->adminCan = static fn(): bool => true;
        return $ctx;
    };
    $assertState = static function (\CometCMS\Site\SiteContext $ctx, string $type, string $id, string $expected) use ($types): void {
        $entry = $ctx->findEntry($type, $id, true);
        $html = (new \CometCMS\Site\PageRenderer($ctx))->entryPage($types->find($type), $entry);
        preg_match('/class="c-admin-status c-admin-status-([a-z]+)"/', $html, $match);
        assert_same($expected, $match[1] ?? '', $type . '/' . $id . ' in ' . $ctx->locale);
    };
    $changed = $content->preview('posts', ['title' => 'Unsaved first', 'locale' => 'en'], $user, $first);
    $ctx = $context();
    $ctx->overrideEntry('posts', $changed, 'first');
    $assertState($ctx, 'posts', 'first', 'preview');
    $assertState($ctx, 'posts', 'second', 'published');
    $assertState($ctx, 'about', 'about', 'published');
    $ctx->locale = 'de';
    $assertState($ctx, 'posts', 'first', 'published');
    $ctx->locale = 'en';
    $assertState($ctx, 'posts', 'first', 'preview');

    $ctx = $context();
    $ctx->overrideEntry('posts', $content->preview('posts', ['title' => 'First', 'locale' => 'en'], $user, $first), 'first');
    $ctx->override('theme', $site->theme());
    $ctx->override('layout:posts:detail:default', $detail);
    $assertState($ctx, 'posts', 'first', 'published');

    $ctx = $context();
    $ctx->override('layout:posts:detail:default', $nodes([['id' => 'heading', 'kind' => 'heading', 'props' => ['text' => 'Shared draft layout']]]));
    $assertState($ctx, 'posts', 'first', 'preview');
    $assertState($ctx, 'posts', 'second', 'preview');
    $assertState($ctx, 'about', 'about', 'published');
    $ctx->override('theme', array_replace($site->theme(), ['custom_css' => '.unsaved { color: red; }']));
    $assertState($ctx, 'about', 'about', 'preview');

    $ctx = $context();
    $ctx->override('region:header', $nodes([['id' => 'header', 'kind' => 'heading', 'props' => ['text' => 'Draft header']]]));
    $assertState($ctx, 'about', 'about', 'preview');
    $assertState($ctx, 'posts', 'second', 'preview');

    $component = $site->createComponent('Shared section', $nodes([['id' => 'component-title', 'kind' => 'heading', 'props' => ['text' => 'Saved component']]]));
    $ctx = $context();
    $ctx->override('component:' . $component['id'], array_replace($component, ['nodes' => $nodes([
        ['id' => 'component-title', 'kind' => 'heading', 'props' => ['text' => 'Draft component']],
    ])]));
    $assertState($ctx, 'about', 'about', 'published');
    $site->saveRegion('footer', [['id' => 'instance', 'kind' => 'component', 'props' => ['component' => $component['id'], 'root' => 'section']]]);
    $assertState($ctx, 'about', 'about', 'preview');
    $site->saveRegion('footer', []);

    $ctx = $context();
    $ctx->overrideEntry('posts', $changed, 'first');
    $assertState($ctx, 'about', 'about', 'published');
    assert_false($ctx->readsOverrides(), 'A page that reads no unsaved data skips the saved comparison render.');
    $assertState($ctx, 'posts', 'first', 'preview');
    assert_true($ctx->readsOverrides());

    $collection =['id' => 'collection', 'kind' => 'collection', 'props' => ['type' => 'posts', 'display' => 'list']];
    $site->saveLayout('about', 'detail', $nodes([$collection]));
    $ctx = $context();
    $ctx->overrideEntry('posts', $changed, 'first');
    $assertState($ctx, 'about', 'about', 'preview');
    $collection['props'] += ['filter_field' => 'slug', 'filter_value' => 'second'];
    $site->saveLayout('about', 'detail', $nodes([$collection]));
    $assertState($ctx, 'about', 'about', 'published');
});


test('preview return navigation preserves its source through browsing languages and search', function (): void {
    (new \CometCMS\Localization\LanguageSettings())->save(['locales' => ['en', 'de'], 'default_locale' => 'en']);
    $types = new \CometCMS\Content\ContentTypeRepository();
    $types->save(['name' => 'posts', 'locales' => ['en', 'de'], 'default_locale' => 'en']);
    $context = \CometCMS\Site\SiteContext::make(new \CometCMS\Site\SiteRepository(), '/cms');
    $context->browsePreview = true;
    $context->locale = 'de';
    $context->previewToken = 'private-snapshot';
    $context->adminCan = static fn(): bool => true;
    $entry = $context->content->save('posts', ['title' => 'Example', 'slug' => 'example'], ['id' => 'admin']);
    $bar = new \CometCMS\Site\PreviewAdminBar($context);
    foreach ([
        '/cms/admin/content/posts/' . $entry['uid'] . '?locale=en&workspace=default#fields',
        '/cms/admin/page-builder/builder/layout/posts/detail?entry=example&workspace=default',
        '/cms/admin/media?folder=photos&page=2&workspace=default',
    ] as $source) {
        $context->setPreviewReturnUrl($source);
        assert_same($source, $context->previewReturnUrl());
        assert_same($source, $context->withoutOverrides()->previewReturnUrl());
        $html = $bar->render(['type' => $types->find('posts'), 'entry' => $entry]);
        $document = new \DOMDocument();
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        assert_same($source, $xpath->query('//a[@class="c-admin-brand"]')->item(0)->getAttribute('href'));
        $languageUrl = $xpath->query('//details[contains(@class,"c-admin-languages")]//a')->item(0)->getAttribute('href');
        foreach ([$context->path('/posts?sort=title#results'), $context->searchUrl(), $languageUrl] as $url) {
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            assert_same($source, $query['returnTo']);
            assert_same('private-snapshot', $query['snapshot']);
            $next = \CometCMS\Site\SiteContext::make($context->site, '/cms');
            $next->setPreviewReturnUrl($query['returnTo']);
            assert_same($source, $next->previewReturnUrl());
        }
    }
    $context->setPreviewReturnUrl('/cms/admin/media?workspace=other');
    assert_same('/cms/admin/media?workspace=default', $context->previewReturnUrl());
});

test('preview return navigation rejects external paths traversal and preview loops', function (): void {
    foreach (['', '/cms'] as $base) {
        $context = \CometCMS\Site\SiteContext::make(new \CometCMS\Site\SiteRepository(), $base);
        foreach ([null, [], '', 'https://evil.example/admin/media', '//evil.example/admin/media',
            '/elsewhere/admin/media', $base . '/admin/site-preview?path=/posts', $base . '/admin/api/site/preview-search',
            $base . '/admin/../media', $base . '/admin/%2e%2e/media', $base . '/admin/%252e%252e/media',
            $base . '/admin/%2f%2fevil.example', $base . '/admin/media%5c..%5c..',
            $base . "/admin/media\n", $base . '/admin/login', $base . '/admin/logout',
        ] as $unsafe) {
            $context->setPreviewReturnUrl($base . '/admin/media');
            $context->setPreviewReturnUrl($unsafe);
            assert_same($base . '/admin/dashboard?workspace=default&locale=', $context->previewReturnUrl());
            assert_same([], $context->previewNavigationQuery());
        }
    }
});


test('preview edit page opens the entry page tab by stable ID with locale and field permissions', function (): void {
    (new \CometCMS\Localization\LanguageSettings())->save(['locales' => ['en', 'de'], 'default_locale' => 'en']);
    $types = new \CometCMS\Content\ContentTypeRepository();
    $types->save(['name' => 'pages', 'locales' => ['en', 'de'], 'default_locale' => 'en', 'fields' => [
        'design' => ['type' => 'page'], 'aside' => ['type' => 'page'],
    ]]);
    $type = $types->find('pages');
    $unlisted = \CometCMS\Site\SiteContext::make(new \CometCMS\Site\SiteRepository(), '/cms');
    $unlisted->adminCan = static fn(): bool => true;
    assert_false(str_contains((new \CometCMS\Site\PreviewAdminBar($unlisted))->render(['type' => $type, 'entry' => null]), '/layout/pages/index'), 'Types without a list page offer no list page builder.');
    (new \CometCMS\Site\SiteRepository())->saveSettings(['routes' => ['pages' => ['index_enabled' => true]]]);
    $context = \CometCMS\Site\SiteContext::make(new \CometCMS\Site\SiteRepository(), '/cms');
    $entry = $context->content->save('pages', ['title' => 'English', 'slug' => 'original', 'locale' => 'en'], ['id' => 'admin']);
    $context->content->save('pages', ['slug' => 'renamed', 'locale' => 'en'], ['id' => 'admin'], $entry['id']);
    $entry = $context->content->save('pages', ['title' => 'Deutsch', 'slug' => 'deutsch', 'locale' => 'de'], ['id' => 'admin'], $entry['id']);
    $context->locale = 'de';
    $context->setPreviewReturnUrl('/cms/admin/media?workspace=default');
    $bar = new \CometCMS\Site\PreviewAdminBar($context);
    $primaryUrl = static function (string $html): string {
        $document = new \DOMDocument();
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        return (new \DOMXPath($document))->query('//a[contains(@class,"c-admin-action-primary")]')->item(0)->getAttribute('href');
    };
    $pageUrl = '/cms/admin/page-builder/builder/page/pages/' . $entry['uid'] . '?workspace=default&locale=de&field=';
    $layoutUrl = '/cms/admin/page-builder/builder/layout/pages/detail?workspace=default&locale=de&entry=' . $entry['uid'];
    $state = ['type' => $type, 'entry' => $entry];
    $context->adminCan = static fn(): bool => true;
    foreach (['en', 'de'] as $language) {
        $context->adminUser = ['language' => $language];
        assert_same($pageUrl . 'design', $primaryUrl($bar->render($state)), 'Page content need not be customized to open its entry tab.');
    }
    $context->adminCan = static fn(string $action, array $scope): bool => $action === 'site.manage'
        || ($action === 'content.update' && ($scope['fields'] ?? []) === ['aside']);
    assert_same($pageUrl . 'aside', $primaryUrl($bar->render($state)), 'Select a page field the user can edit.');
    $context->adminCan = static fn(string $action): bool => $action === 'site.manage';
    assert_same($layoutUrl, $primaryUrl($bar->render($state)), 'Layout-only access must not open a forbidden entry page.');
    $context->adminCan = static fn(): bool => true;
    assert_same('/cms/admin/page-builder/builder/layout/pages/index?workspace=default&locale=de', $primaryUrl($bar->render(['type' => $type, 'entry' => null])));
    $context->adminCan = static fn(string $action): bool => $action === 'content.update';
    assert_same('/cms/admin/content/pages/' . $entry['uid'] . '?workspace=default&locale=de', $primaryUrl($bar->render($state)));
    assert_same('/cms/admin/media?workspace=default', $context->previewReturnUrl(), 'Editing the viewed entry does not change the return destination.');
});
