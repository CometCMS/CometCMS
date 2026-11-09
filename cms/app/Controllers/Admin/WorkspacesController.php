<?php

declare(strict_types=1);

namespace CometCMS\Controllers\Admin;

use CometCMS\Core\Http;
use CometCMS\Core\MimeDetector;
use CometCMS\Core\Security;
use CometCMS\Core\ValidationException;
use CometCMS\Setup\WebsiteStarter;
use CometCMS\Workspaces\WorkspaceContext;
use CometCMS\Workspaces\WorkspaceRepository;

final class WorkspacesController extends BaseController
{
    private WorkspaceRepository $workspaces;

    public function __construct(Http $http)
    {
        parent::__construct($http);
        $this->workspaces = new WorkspaceRepository();
    }

    public function index(): never
    {
        $user = $this->requireUser();
        $all  = $this->workspaces->all();

        $data = array_values(array_filter($all, function (array $ws) use ($user): bool {
            return $this->permissions->allows($user, 'workspaces.read', [
                'principal' => $user,
                'type'      => 'workspace',
                'slug'      => $ws['slug'],
            ]);
        }));

        $this->json(['data' => $data]);
    }

    public function store(): never
    {
        $user = $this->requirePermission('workspaces.manage', ['resource' => 'workspaces:*']);
        $this->verifyCsrf();
        $body = $this->requestJson();

        // Existing API clients create an empty workspace unless they choose a starter.
        $starterTemplate = $body['starter_template'] ?? 'empty';
        if (!in_array($starterTemplate, ['empty', 'website'], true)) {
            $this->json(['error' => ['code' => 'validation_failed', 'message' => 'Choose a valid starting point.']], 422);
        }

        $slug = Security::slug((string) ($body['slug'] ?? $body['label'] ?? ''));
        $existing = $this->workspaces->find($slug, true);
        if ($slug === '' || $existing !== null) {
            $message = $slug === '' ? 'Choose a workspace slug.' : (
                !empty($existing['archived'])
                    ? 'An archived workspace already uses this slug. Choose a different slug.'
                    : 'Workspace already exists.'
            );
            $code = !empty($existing['archived']) ? 'workspace_archived' : 'validation_failed';
            $this->json(['error' => ['code' => $code, 'message' => $message]], 422);
        }

        try {
            $workspace = $this->workspaces->save([
                'slug' => $slug,
                'label' => (string) ($body['label'] ?? ''),
                ...isset($body['languages']) ? ['languages' => $body['languages']] : [],
            ]);
            if ($starterTemplate === 'website') {
                $activeWorkspace = WorkspaceContext::active()->slug();
                try {
                    (new WebsiteStarter(new WorkspaceContext($slug)))->create($user, includeEvents: false);
                } finally {
                    WorkspaceContext::setActive($activeWorkspace);
                }
            }
        } catch (ValidationException $e) {
            $this->json(['error' => ['code' => 'validation_failed', 'message' => $e->getMessage(), 'fields' => $e->fields()]], 422);
        } catch (\Throwable $e) {
            $this->json(['error' => ['code' => 'validation_failed', 'message' => $e->getMessage()]], 422);
        }

        $this->json(['data' => $workspace], 201);
    }

    public function update(string $slug): never
    {
        $this->requirePermission('workspaces.manage', ['type' => 'workspace', 'slug' => $slug]);
        $this->verifyCsrf();
        $body = $this->requestJson();

        try {
            $workspace = $this->workspaces->save([
                'label' => (string) ($body['label'] ?? ''),
                'archived' => (bool) ($body['archived'] ?? false),
                ...isset($body['languages']) ? ['languages' => $body['languages']] : [],
            ], $slug);
        } catch (ValidationException $e) {
            $this->json(['error' => ['code' => 'validation_failed', 'message' => $e->getMessage(), 'fields' => $e->fields()]], 422);
        } catch (\Throwable $e) {
            $this->json(['error' => ['code' => 'validation_failed', 'message' => $e->getMessage()]], 422);
        }

        $this->json(['data' => $workspace]);
    }

    public function archive(string $slug): never
    {
        $this->requirePermission('workspaces.manage', ['type' => 'workspace', 'slug' => $slug]);
        $this->verifyCsrf();

        try {
            $workspace = $this->workspaces->archive($slug);
        } catch (\Throwable $e) {
            $this->json(['error' => ['code' => 'validation_failed', 'message' => $e->getMessage()]], 422);
        }

        $this->json(['data' => $workspace]);
    }

    public function setDefault(string $slug): never
    {
        $this->requirePermission('workspaces.manage', ['type' => 'workspace', 'slug' => $slug]);
        $this->verifyCsrf();

        try {
            $this->workspaces->setDefault($slug);
        } catch (\Throwable $e) {
            $this->json(['error' => ['code' => 'validation_failed', 'message' => $e->getMessage()]], 422);
        }

        $this->json(['data' => $this->workspaces->find($slug)]);
    }

    public function iconServe(string $slug): never
    {
        $this->requireUser();
        $path = $this->workspaces->iconPath($slug);

        if ($path === null) {
            http_response_code(404);
            exit;
        }

        $this->http->streamFile($path, 'image/png', [
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function iconUpload(string $slug): never
    {
        $this->requirePermission('workspaces.manage', ['type' => 'workspace', 'slug' => $slug]);
        $this->verifyCsrf();

        if (!$this->workspaces->exists($slug)) {
            $this->json(['error' => ['code' => 'not_found', 'message' => 'Workspace not found.']], 404);
        }

        $file = $_FILES['file'] ?? null;
        $uploadError = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;

        if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
            $this->json(['error' => ['code' => 'file_too_large', 'message' => 'File exceeds the server upload limit.']], 422);
        }

        if ($uploadError !== UPLOAD_ERR_OK || !is_array($file)) {
            $this->json(['error' => ['code' => 'no_file', 'message' => 'No file uploaded.']], 422);
        }

        $size = (int) ($file['size'] ?? 0);

        if ($size <= 0 || $size > 10485760) {
            $this->json(['error' => ['code' => 'file_too_large', 'message' => 'File is too large (max 10 MB).']], 422);
        }

        $mime = MimeDetector::detect((string) $file['tmp_name'], (string) ($file['name'] ?? ''));
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

        if (!in_array($mime, $allowedMimes, true)) {
            $this->json(['error' => ['code' => 'file_type_not_allowed', 'message' => 'Only JPEG, PNG, WebP, or GIF images are allowed.']], 422);
        }

        if (!function_exists('imagecreatefromstring')) {
            $this->json(['error' => ['code' => 'image_support_missing', 'message' => 'Workspace icons need the PHP GD extension, which is not enabled on this server.']], 500);
        }

        $slug = Security::slug($slug);
        $contents = file_get_contents((string) $file['tmp_name']);
        $image = is_string($contents) ? @imagecreatefromstring($contents) : false;

        if (!$image instanceof \GdImage) {
            $this->json(['error' => ['code' => 'invalid_image', 'message' => 'Could not decode the uploaded image.']], 422);
        }

        $target = COMET_STORAGE . '/workspaces/' . $slug . '/icon.png';
        $tmp = $target . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $saved = imagepng($image, $tmp);
        unset($image);

        if (!$saved || !rename($tmp, $target)) {
            if (is_file($tmp)) {
                unlink($tmp);
            }
            $this->json(['error' => ['code' => 'upload_failed', 'message' => 'Could not store the uploaded file.']], 500);
        }

        $this->json(['data' => ['ok' => true]]);
    }

    public function iconDelete(string $slug): never
    {
        $this->requirePermission('workspaces.manage', ['type' => 'workspace', 'slug' => $slug]);
        $this->verifyCsrf();

        $slug = Security::slug($slug);
        $path = $this->workspaces->iconPath($slug);
        if ($path !== null) {
            unlink($path);
        }

        $this->json(['data' => ['ok' => true]]);
    }

}
