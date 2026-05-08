<?php

declare(strict_types=1);

namespace CometCMS\Controllers\Admin;

use CometCMS\Core\Http;
use CometCMS\Core\MimeDetector;
use CometCMS\Core\Security;
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
        $this->requirePermission('workspaces.manage', ['resource' => 'workspaces:*']);
        $this->verifyCsrf();
        $body = $this->requestJson();

        try {
            $workspace = $this->workspaces->save([
                'slug' => Security::slug((string) ($body['slug'] ?? $body['label'] ?? '')),
                'label' => (string) ($body['label'] ?? ''),
            ]);
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
            ], $slug);
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
        $path = $this->iconPath($slug);

        if ($path === null) {
            http_response_code(404);
            exit;
        }

        $mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = $mimeMap[$ext] ?? 'image/jpeg';

        header('Content-Type: ' . $mime);
        header('Cache-Control: private, max-age=86400');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
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
        $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

        if (!array_key_exists($mime, $extMap)) {
            $this->json(['error' => ['code' => 'file_type_not_allowed', 'message' => 'Only JPEG, PNG, WebP, or GIF images are allowed.']], 422);
        }

        $dir = COMET_STORAGE . '/workspaces/icons/';

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $slug = Security::slug($slug);

        foreach (array_values($extMap) as $oldExt) {
            $old = $dir . $slug . '.' . $oldExt;
            if (is_file($old)) {
                unlink($old);
            }
        }

        $target = $dir . $slug . '.' . $extMap[$mime];

        if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
            $this->json(['error' => ['code' => 'upload_failed', 'message' => 'Could not store the uploaded file.']], 500);
        }

        $this->json(['data' => ['ok' => true]]);
    }

    public function iconDelete(string $slug): never
    {
        $this->requirePermission('workspaces.manage', ['type' => 'workspace', 'slug' => $slug]);
        $this->verifyCsrf();

        $dir = COMET_STORAGE . '/workspaces/icons/';
        $slug = Security::slug($slug);

        foreach (['jpg', 'png', 'webp', 'gif'] as $ext) {
            $path = $dir . $slug . '.' . $ext;
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->json(['data' => ['ok' => true]]);
    }

    private function iconPath(string $slug): ?string
    {
        $slug = Security::slug($slug);
        $dir = COMET_STORAGE . '/workspaces/icons/';

        foreach (['jpg', 'png', 'webp', 'gif'] as $ext) {
            $path = $dir . $slug . '.' . $ext;
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
