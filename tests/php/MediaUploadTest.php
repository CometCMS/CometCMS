<?php

declare(strict_types=1);

use CometCMS\Media\MediaRepository;
use CometCMS\Media\MediaUpload;
use CometCMS\Media\UploadException;

test('shared media uploads normalize multipart files and preserve validation errors', function (): void {
    assert_same([], MediaUpload::files(null));
    assert_same([], MediaUpload::files([]));
    $files = MediaUpload::files(['name' => ['good.txt', 'bad.txt'], 'tmp_name' => ['one', 'two'],
        'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_INI_SIZE], 'size' => [4, 0]]);
    assert_same(2, count($files));
    assert_same('two', $files[1]['tmp_name']);
    assert_same([$files[0]], MediaUpload::files($files[0]));
    $upload = new MediaUpload(new MediaRepository());
    foreach ([
        [[], 'upload_failed'],
        [[$files[1]], 'upload_failed'],
        [[['error' => UPLOAD_ERR_OK, 'size' => 0]], 'file_too_large'],
    ] as [$input, $code]) {
        try {
            $upload->upload($input, '', 'admin');
            throw new RuntimeException('Invalid upload was accepted.');
        } catch (UploadException $error) {
            assert_same($code, $error->errorCode);
            assert_same(422, $error->status);
        }
    }
});

test('admin and REST uploads store real multipart files with collision names and canonical thumbnail URLs', function (): void {
    if (!function_exists('imagepng')) return;
    $root = comet_site_preview_test_install();
    $router = $root . '/media-upload-router.php';
    $bootstrap = var_export($root . '/app/bootstrap.php', true);
    file_put_contents($router, "<?php\n\$_SERVER['SCRIPT_NAME'] = '/index.php';\nrequire " . $bootstrap . ";\n" . <<<'PHP'
$_SERVER['SCRIPT_NAME'] = '/index.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
(new \CometCMS\Auth\RoleRepository())->seed();
$users = new \CometCMS\Auth\UserRepository();
if ($users->find('uploader') === null) $users->create('uploader', 'secret-password', 'admin');
$_SESSION['user_id'] = 'uploader';
$_SESSION['cometcms_csrf'] = 'test-token';
$http = new \CometCMS\Core\Http();
if (str_starts_with($_SERVER['REQUEST_URI'], '/admin/')) {
    (new \CometCMS\Controllers\Admin\MediaController($http))->store();
}
$token = (new \CometCMS\Auth\ApiTokenRepository())->create('Uploader', '', [
    ['effect' => 'allow', 'actions' => ['media.upload'], 'resources' => ['media:*']],
]);
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
(new \CometCMS\Controllers\ApiController($http))->useWorkspace('default')->mediaStore();
PHP);
    $image = imagecreatetruecolor(800, 400);
    ob_start();
    imagepng($image);
    $png = ob_get_clean();
    $port = 20080 + random_int(0, 4000);
    $server = comet_test_start_php_server('127.0.0.1', $port, $router);
    $request = static function (string $path, string $filename, string $csrf = 'test-token') use ($port, $png): array {
        $boundary = 'comet-upload-boundary';
        $body = '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"category\"\r\n\r\nImages / Uploads\r\n"
            . '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"media[]\"; filename=\"" . $filename . "\"\r\nContent-Type: image/png\r\n\r\n" . $png
            . "\r\n--" . $boundary . "--\r\n";
        $response = file_get_contents('http://127.0.0.1:' . $port . $path, false, stream_context_create(['http' => [
            'method' => 'POST', 'ignore_errors' => true,
            'header' => 'Content-Type: multipart/form-data; boundary=' . $boundary . "\r\nX-CSRF-Token: " . $csrf . "\r\n",
            'content' => $body,
        ]]));
        $headers = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : (${'http_response_header'} ?? []);
        preg_match('#^HTTP/\S+\s+(\d{3})#', $headers[0] ?? '', $match);
        return [(int) ($match[1] ?? 0), json_decode((string) $response, true) ?? ['unexpected_response' => $response]];
    };
    try {
        usleep(300000);
        [$status, $admin] = $request('/admin/api/media', 'Shared image.png');
        assert_same(201, $status, json_encode($admin));
        assert_same('shared-image.png', $admin['data'][0]['filename']);
        assert_same('Images / Uploads', $admin['data'][0]['category']);
        assert_same('uploader', $admin['data'][0]['uploaded_by']);
        assert_true(str_contains($admin['data'][0]['thumb_url'], '?size=thumbnail'));
        [$status, $rest] = $request('/api/v1/workspaces/default/media', 'Shared image.png');
        assert_same(201, $status, json_encode($rest));
        assert_same('shared-image-2.png', $rest['data'][0]['filename']);
        assert_same('Images / Uploads', $rest['data'][0]['category']);
        assert_true(str_contains($rest['data'][0]['thumb_url'], '?size=thumbnail'));
        assert_same(419, $request('/admin/api/media', 'blocked.png', 'wrong-token')[0]);
        [$status, $invalid] = $request('/api/v1/workspaces/default/media', 'disguised.php');
        assert_same(422, $status);
        assert_same('file_type_not_allowed', $invalid['error']['code']);
    } finally {
        comet_test_stop_process($server);
    }
});
