<?php

declare(strict_types=1);

function comet_api_controller_test_bootstrap_require_snippet(): string
{
    return 'require ' . var_export(__DIR__ . '/bootstrap.php', true) . ';';
}

test('private content collection rejects unauthenticated reads', function (): void {
    $output = comet_test_run_php(['-r',
        comet_api_controller_test_bootstrap_require_snippet() .
        'comet_test_reset_storage();' .
        '(new \\CometCMS\\Storage\\JsonStore(comet_test_workspace_path() . "/content-types"))->write(["name" => "members", "visibility" => "private", "api_enabled" => true], "members");' .
        '\\CometCMS\\Content\\ContentRepository::make()->save("members", ["title" => "Member", "status" => "published"], ["id" => "admin"]);' .
        '$_SERVER["REQUEST_METHOD"] = "GET";' .
        '$_SERVER["REQUEST_URI"] = "/api/v1/workspaces/default/content/members";' .
        '$_SERVER["SCRIPT_NAME"] = "/index.php";' .
        '(new \\CometCMS\\Controllers\\ApiController(new \\CometCMS\\Core\\Http()))->useWorkspace("default")->contentIndex("members");'
    ]);

    assert_true(str_contains($output, '"code": "unauthorized"'));
    assert_true(str_contains($output, 'Missing bearer token'));
});

test('private content collection allows a token with collection read permission', function (): void {
    $output = comet_test_run_php(['-r',
        comet_api_controller_test_bootstrap_require_snippet() .
        'comet_test_reset_storage();' .
        '(new \\CometCMS\\Storage\\JsonStore(comet_test_workspace_path() . "/content-types"))->write(["name" => "members", "visibility" => "private", "api_enabled" => true], "members");' .
        '\\CometCMS\\Content\\ContentRepository::make()->save("members", ["title" => "Member", "status" => "published"], ["id" => "admin"]);' .
        '$plain = (new \\CometCMS\\Auth\\ApiTokenRepository())->create("reader", "", [["effect" => "allow", "actions" => ["content.read"], "resources" => ["content:members:*"]]]);' .
        '$_SERVER["HTTP_AUTHORIZATION"] = "Bearer " . $plain;' .
        '$_SERVER["REQUEST_METHOD"] = "GET";' .
        '$_SERVER["REQUEST_URI"] = "/api/v1/workspaces/default/content/members";' .
        '$_SERVER["SCRIPT_NAME"] = "/index.php";' .
        '(new \\CometCMS\\Controllers\\ApiController(new \\CometCMS\\Core\\Http()))->useWorkspace("default")->contentIndex("members");'
    ]);

    assert_true(str_contains($output, '"title": "Member"'));
});

test('unauthenticated content type listing omits private schemas', function (): void {
    $output = comet_test_run_php(['-r',
        comet_api_controller_test_bootstrap_require_snippet() .
        'comet_test_reset_storage();' .
        '$types = new \\CometCMS\\Content\\ContentTypeRepository();' .
        '$types->save(["name" => "pages", "api_enabled" => true]);' .
        '$types->save(["name" => "members", "visibility" => "private"]);' .
        '$_SERVER["REQUEST_METHOD"] = "GET";' .
        '$_SERVER["REQUEST_URI"] = "/api/v1/workspaces/default/content-types";' .
        '$_SERVER["SCRIPT_NAME"] = "/index.php";' .
        '(new \\CometCMS\\Controllers\\ApiController(new \\CometCMS\\Core\\Http()))->useWorkspace("default")->contentTypes();'
    ]);

    assert_true(str_contains($output, '"name": "pages"'));
    assert_false(str_contains($output, '"name": "members"'));
});

test('media endpoint serves configured image variants', function (): void {
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
        return;
    }

    $output = comet_test_run_php(['-r',
        comet_api_controller_test_bootstrap_require_snippet() .
        'comet_test_reset_storage();' .
        '$image = imagecreatetruecolor(1200, 600);' .
        'imagejpeg($image, comet_test_workspace_path() . "/media/hero.jpg", 90);' .
        'unset($image);' .
        '$_GET = ["w" => "400", "format" => "jpeg"];' .
        '$_SERVER["REQUEST_METHOD"] = "GET";' .
        '$_SERVER["REQUEST_URI"] = "/media/default/hero.jpg?w=400&format=jpeg";' .
        '$_SERVER["SCRIPT_NAME"] = "/index.php";' .
        '(new \\CometCMS\\Controllers\\ApiController(new \\CometCMS\\Core\\Http()))->useWorkspace("default")->mediaShow("hero.jpg");'
    ]);

    $dimensions = getimagesizefromstring($output);
    assert_same(400, $dimensions[0] ?? null);
    assert_same(200, $dimensions[1] ?? null);
});

test('media endpoint serves named image sizes', function (): void {
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
        return;
    }

    $output = comet_test_run_php(['-r',
        comet_api_controller_test_bootstrap_require_snippet() .
        'comet_test_reset_storage();' .
        '$image = imagecreatetruecolor(1200, 600);' .
        'imagejpeg($image, comet_test_workspace_path() . "/media/hero.jpg", 90);' .
        'unset($image);' .
        '$_GET = ["size" => "medium", "format" => "jpeg"];' .
        '$_SERVER["REQUEST_METHOD"] = "GET";' .
        '$_SERVER["REQUEST_URI"] = "/media/default/hero.jpg?size=medium&format=jpeg";' .
        '$_SERVER["SCRIPT_NAME"] = "/index.php";' .
        '(new \\CometCMS\\Controllers\\ApiController(new \\CometCMS\\Core\\Http()))->useWorkspace("default")->mediaShow("hero.jpg");'
    ]);

    $dimensions = getimagesizefromstring($output);
    assert_same(960, $dimensions[0] ?? null);
    assert_same(480, $dimensions[1] ?? null);
});

test('media listing advertises responsive variant urls', function (): void {
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
        return;
    }

    $output = comet_test_run_php(['-r',
        comet_api_controller_test_bootstrap_require_snippet() .
        'comet_test_reset_storage();' .
        '$image = imagecreatetruecolor(1200, 600);' .
        'imagejpeg($image, comet_test_workspace_path() . "/media/hero.jpg", 90);' .
        'unset($image);' .
        '$_SERVER["REQUEST_METHOD"] = "GET";' .
        '$_SERVER["REQUEST_URI"] = "/api/v1/workspaces/default/media";' .
        '$_SERVER["SCRIPT_NAME"] = "/index.php";' .
        '(new \\CometCMS\\Controllers\\ApiController(new \\CometCMS\\Core\\Http()))->useWorkspace("default", false, true)->mediaIndex();'
    ]);

    assert_true(str_contains($output, '"variants": {'));
    assert_true(str_contains($output, 'hero.jpg?w=400&format='));
    assert_false(str_contains($output, 'hero.jpg?w=1920&format='));
    assert_true(str_contains($output, 'hero.jpg?size=medium'));
    assert_false(str_contains($output, 'hero.jpg?size=large'), 'Sizes the original does not exceed are left out.');
});

/** Read the same public collection with and without explicit API exposure. */
function comet_api_exposure_test_request(bool $enabled, string $method = 'contentIndex', string $id = ''): array
{
    $output = comet_test_run_php(['-r',
        comet_api_controller_test_bootstrap_require_snippet() .
        'comet_test_reset_storage();' .
        '$types = new \\CometCMS\\Content\\ContentTypeRepository();' .
        '$types->save(["name" => "pages", "api_enabled" => ' . var_export($enabled, true) . ', "fields" => ["related" => ["type" => "relation", "target" => "internal"]]]);' .
        '$types->save(["name" => "internal"]);' .
        '$content = \\CometCMS\\Content\\ContentRepository::make();' .
        '$content->save("internal", ["title" => "Secret internal content", "status" => "published"], ["id" => "admin"]);' .
        '$content->save("pages", ["title" => "Visible", "slug" => "visible", "related" => "secret-internal-content", "status" => "published"], ["id" => "admin"]);' .
        '$content->save("pages", ["title" => "Hidden draft", "slug" => "draft", "status" => "draft"], ["id" => "admin"]);' .
        '$content->save("pages", ["title" => "Protected content", "slug" => "protected", "status" => "protected"], ["id" => "admin"]);' .
        '$_GET = ["include" => "related"];' .
        '$_SERVER["REQUEST_METHOD"] = "GET";' .
        '$_SERVER["REQUEST_URI"] = "/api/v1/workspaces/default/content/pages";' .
        '$_SERVER["SCRIPT_NAME"] = "/index.php";' .
        '(new \\CometCMS\\Controllers\\ApiController(new \\CometCMS\\Core\\Http()))->useWorkspace("default")->' . $method . '(' . ($method === 'contentTypes' ? '' : '"pages"' . ($method === 'contentShow' ? ', ' . var_export($id, true) : '')) . ');'
    ]);
    return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
}

test('API exposure controls schema discovery collection reads and individual entries', function (): void {
    assert_same([], comet_api_exposure_test_request(false, 'contentTypes')['data']);
    foreach (['contentTypeShow', 'contentIndex', 'contentShow'] as $method) {
        assert_same('not_found', comet_api_exposure_test_request(false, $method, 'visible')['error']['code']);
    }
    assert_same(['pages'], array_column(comet_api_exposure_test_request(true, 'contentTypes')['data'], 'name'));
    assert_same('pages', comet_api_exposure_test_request(true, 'contentTypeShow')['data']['name']);
    $data = comet_api_exposure_test_request(true)['data'];
    assert_same(['Visible'], array_column($data, 'title'), 'API exposure does not publish drafts or protected entries.');
    assert_null($data[0]['data']['related'], 'Relations cannot expose types whose API is disabled.');
    assert_same('Visible', comet_api_exposure_test_request(true, 'contentShow', 'visible')['data']['title']);
    assert_same('not_found', comet_api_exposure_test_request(true, 'contentShow', 'draft')['error']['code']);
    assert_same('not_found', comet_api_exposure_test_request(true, 'contentShow', 'protected')['error']['code']);
});

/** Seed a legacy public submission schema without going through save-time defaults. */
function comet_api_submission_privacy_request(string $method, bool $enabled = true, string $visibility = 'public', string $status = 'published', ?bool $authorized = null, bool $related = false): array
{
    $schema = [
        'name' => 'inquiries', 'api_enabled' => $enabled, 'visibility' => $visibility, 'allow_submissions' => true,
        'submission_settings' => ['status' => $status],
        'fields' => ['email' => ['type' => 'text'], 'message' => ['type' => 'textarea']],
    ];
    $collection = $related ? 'pages' : 'inquiries';
    $arguments = $method === 'contentTypes' ? '' : var_export($collection, true) . ($method === 'contentShow' ? ', "visible"' : '');
    $output = comet_test_run_php(['-r',
        comet_api_controller_test_bootstrap_require_snippet() .
        'comet_test_reset_storage();' .
        '(new \\CometCMS\\Storage\\JsonStore(comet_test_workspace_path() . "/content-types"))->write(' . var_export($schema, true) . ', "inquiries");' .
        '$content = \\CometCMS\\Content\\ContentRepository::make();' .
        '$entry = $content->save("inquiries", ["title" => "Private submission", "slug" => "visible", "email" => "private@example.com", "message" => "Confidential message", "status" => ' . var_export($status, true) . '], ["id" => "admin"]);' .
        '(new \\CometCMS\\Content\\ContentTypeRepository())->save(["name" => "pages", "api_enabled" => true, "fields" => ["inquiry" => ["type" => "relation", "target" => "inquiries"], "inquiries" => ["type" => "relation", "target" => "inquiries", "multiple" => true]]]);' .
        '$content->save("pages", ["title" => "Visible", "slug" => "visible", "status" => "published", "inquiry" => $entry["id"], "inquiries" => [$entry["id"]]], ["id" => "admin"]);' .
        ($authorized === null ? '' :
            '$plain = (new \\CometCMS\\Auth\\ApiTokenRepository())->create("reader", "", [["effect" => "allow", "actions" => ["content.read", "schema.read"], "resources" => [' . var_export($authorized ? '*' : 'content:other:*', true) . ']]]);' .
            '$_SERVER["HTTP_AUTHORIZATION"] = "Bearer " . $plain;') .
        '$_GET = ["include" => "inquiry,inquiries"];' .
        '$_SERVER["REQUEST_METHOD"] = "GET";' .
        '$_SERVER["REQUEST_URI"] = ' . var_export('/api/v1/workspaces/default/content/' . $collection, true) . ';' .
        '$_SERVER["SCRIPT_NAME"] = "/index.php";' .
        // Simulate a response from before the fix containing an expanded submission.
        '$_SERVER["QUERY_STRING"] = "include=inquiry,inquiries";' .
        '(\\CometCMS\\Cache\\ApiCache::fromConfig())->put(hash("sha256", $_SERVER["REQUEST_URI"] . "?" . $_SERVER["QUERY_STRING"] . "|public-v1"), ["data" => [["email" => "private@example.com"]]]);' .
        '(new \\CometCMS\\Controllers\\ApiController(new \\CometCMS\\Core\\Http()))->useWorkspace("default")->' . $method . '(' . $arguments . ');'
    ]);
    return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
}

test('anonymous API never exposes submission schemas or entries regardless of exposure visibility or status', function (): void {
    foreach ([false, true] as $enabled) {
        foreach (['private', 'public'] as $visibility) {
            foreach (['draft', 'published'] as $status) {
                $listing = comet_api_submission_privacy_request('contentTypes', $enabled, $visibility, $status);
                assert_same(['pages'], array_column($listing['data'], 'name'));
                foreach (['contentTypeShow', 'contentIndex', 'contentShow'] as $method) {
                    $payload = comet_api_submission_privacy_request($method, $enabled, $visibility, $status);
                    assert_same($enabled ? 'unauthorized' : 'not_found', $payload['error']['code']);
                    assert_false(isset($payload['data']));
                }
            }
        }
    }
});

test('anonymous API relation expansion cannot expose published submissions', function (): void {
    foreach (['contentIndex', 'contentShow'] as $method) {
        $payload = comet_api_submission_privacy_request($method, related: true);
        $entry = $method === 'contentIndex' ? $payload['data'][0] : $payload['data'];
        assert_same('Visible', $entry['title']);
        assert_null($entry['data']['inquiry']);
        assert_same([], $entry['data']['inquiries']);
        assert_false(str_contains(json_encode($payload), 'private@example.com'));
    }
});

test('API submission reads still require a token with the appropriate permission', function (): void {
    foreach (['contentTypeShow', 'contentIndex', 'contentShow'] as $method) {
        $allowed = comet_api_submission_privacy_request($method, authorized: true);
        assert_true(isset($allowed['data']));
        if ($method !== 'contentTypeShow') {
            $entry = $method === 'contentIndex' ? $allowed['data'][0] : $allowed['data'];
            assert_same('private@example.com', $entry['data']['email']);
        }
        $denied = comet_api_submission_privacy_request($method, authorized: false);
        assert_same('forbidden', $denied['error']['code']);
    }
});
