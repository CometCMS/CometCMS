<?php

declare(strict_types=1);

namespace CometCMS\Controllers\Admin;

use CometCMS\Core\MimeDetector;
use CometCMS\Core\Security;

final class UsersController extends BaseController
{
    public function index(): never
    {
        $this->requirePermission('users.read', ['type' => 'user']);
        $this->json(['data' => array_map([$this, 'safeUser'], $this->users->all())]);
    }

    public function store(): never
    {
        $actor = $this->requirePermission('users.create', ['type' => 'user']);
        $this->verifyCsrf();
        $body = $this->requestJson();
        $password = (string) ($body['password'] ?? '');

        if (strlen($password) < 8) {
            $this->json(['error' => ['code' => 'validation_failed', 'message' => 'Password must be at least 8 characters.']], 422);
        }

        try {
            $user = $this->users->create(
                (string) ($body['username'] ?? ''),
                $password,
                (string) ($body['role'] ?? 'viewer')
            );
        } catch (\Throwable $e) {
            $this->json(['error' => ['code' => 'error', 'message' => $e->getMessage()]], 422);
        }

        $this->logger->info('user.created', ['username' => $user['username'] ?? null, 'role' => $user['role'] ?? null, 'user_id' => $actor['id'] ?? null]);
        $this->json(['data' => $this->safeUser($user)], 201);
    }

    public function destroy(string $userId): never
    {
        $current = $this->requirePermission('users.delete', ['type' => 'user', 'user_id' => $userId]);
        $this->verifyCsrf();

        if ($current['id'] === $userId) {
            $this->json(['error' => ['code' => 'forbidden', 'message' => 'You cannot delete your own account.']], 403);
        }

        $target = $this->users->find($userId);
        $this->users->delete($userId);
        $this->logger->info('user.deleted', ['deleted_user_id' => $userId, 'username' => $target['username'] ?? null, 'user_id' => $current['id'] ?? null]);
        $this->json(['data' => ['ok' => true]]);
    }

    public function update(string $userId): never
    {
        $actor = $this->requirePermission('users.update', ['type' => 'user', 'user_id' => $userId]);
        $this->verifyCsrf();
        $body = $this->requestJson();

        $target = $this->users->find($userId);
        if ($target === null) {
            $this->json(['error' => ['code' => 'not_found', 'message' => 'User not found.']], 404);
        }

        try {
            $user = $this->users->update($userId, $body);
        } catch (\InvalidArgumentException $e) {
            $this->json(['error' => ['code' => 'validation_failed', 'message' => $e->getMessage()]], 422);
        }

        $this->logger->info('user.updated', ['updated_user_id' => $userId, 'username' => $user['username'] ?? null, 'user_id' => $actor['id'] ?? null]);
        $this->json(['data' => $this->safeUser($user)]);
    }

    public function profileUpdate(): never
    {
        $current = $this->requireUser();
        $this->verifyCsrf();
        $body = $this->requestJson();

        if (isset($body['password']) && (string) $body['password'] !== '') {
            $oldPassword = (string) ($body['old_password'] ?? '');
            if ($oldPassword === '' || !password_verify($oldPassword, (string) ($current['password_hash'] ?? ''))) {
                $this->json(['error' => ['code' => 'invalid_password', 'message' => 'Current password is incorrect.']], 422);
            }
        }

        $allowed = array_intersect_key($body, array_flip(['display_name', 'email', 'password', 'theme', 'language', 'show_api_footer']));

        try {
            $user = $this->users->update((string) $current['id'], $allowed);
        } catch (\InvalidArgumentException $e) {
            $this->json(['error' => ['code' => 'validation_failed', 'message' => $e->getMessage()]], 422);
        }

        $this->json(['data' => $this->safeUser($user)]);
    }

    public function avatarServe(string $userId): never
    {
        $this->requireUser();
        Security::assertSafeName($userId);

        $path = $this->avatarPath($userId);

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

    public function avatarUpload(): never
    {
        $user = $this->requireUser();
        $this->verifyCsrf();

        $file = $_FILES['file'] ?? null;
        $uploadError = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;

        if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
            $this->json(['error' => ['code' => 'file_too_large', 'message' => 'File exceeds the server upload limit. Resize the image or contact your administrator.']], 422);
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

        $dir = COMET_STORAGE . '/users/avatars/';

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        foreach (array_values($extMap) as $oldExt) {
            $old = $dir . $user['id'] . '.' . $oldExt;
            if (is_file($old)) {
                unlink($old);
            }
        }

        $target = $dir . $user['id'] . '.' . $extMap[$mime];

        if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
            $this->json(['error' => ['code' => 'upload_failed', 'message' => 'Could not store the uploaded file.']], 500);
        }

        $this->json(['data' => ['ok' => true]]);
    }

    public function avatarDelete(): never
    {
        $user = $this->requireUser();
        $this->verifyCsrf();

        $dir = COMET_STORAGE . '/users/avatars/';

        foreach (['jpg', 'png', 'webp', 'gif'] as $ext) {
            $path = $dir . $user['id'] . '.' . $ext;
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->json(['data' => ['ok' => true]]);
    }
}
