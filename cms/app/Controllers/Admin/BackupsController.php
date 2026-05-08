<?php

declare(strict_types=1);

namespace CometCMS\Controllers\Admin;

use CometCMS\Backups\BackupService;
use CometCMS\Backups\RestoreService;

final class BackupsController extends BaseController
{
    public function index(): never
    {
        $this->requirePermission('backups.read', ['resource' => 'backups:*']);
        $service = new BackupService();
        $this->json(['data' => [
            'backups' => $service->all(),
            'allowed_parts' => BackupService::allowedParts(),
            'default_parts' => BackupService::defaultParts(),
        ]]);
    }

    public function store(): never
    {
        $user = $this->requirePermission('backups.create', ['resource' => 'backups:*']);
        $this->verifyCsrf();
        $body = $this->requestJson();
        $parts = $this->partsFromPayload($body);

        if ($parts === []) {
            $this->json(['error' => ['code' => 'validation_failed', 'message' => 'Select at least one part to include.']], 422);
        }

        try {
            $backup = (new BackupService())->create($user, $parts);
        } catch (\Throwable $e) {
            $this->json(['error' => ['code' => 'backup_failed', 'message' => $e->getMessage()]], 500);
        }

        $this->json(['data' => ['backup' => $backup]], 201);
    }

    public function upload(): never
    {
        $user = $this->requirePermission('backups.create', ['resource' => 'backups:*']);
        $this->verifyCsrf();
        $file = $_FILES['backup'] ?? null;

        if (!is_array($file)) {
            // $_FILES is empty when post_max_size is exceeded — PHP provides no error code in that case.
            $message = (int) ini_get('post_max_size') > 0 && isset($_SERVER['CONTENT_LENGTH'])
                && (int) $_SERVER['CONTENT_LENGTH'] > (int) ini_get('post_max_size') * 1024 * 1024
                ? 'The backup file exceeds the server\'s upload size limit (post_max_size).'
                : 'Upload a ZIP backup file.';
            $this->json(['error' => ['code' => 'upload_failed', 'message' => $message]], 422);
        }

        $backups = new BackupService();
        $backup = null;

        try {
            $backup = $backups->saveUploaded($file, $user);
            $inspection = (new RestoreService())->inspect($backups->path((string) $backup['name']));
        } catch (\Throwable $e) {
            if (is_array($backup) && is_string($backup['name'] ?? null)) {
                try {
                    $backups->delete((string) $backup['name']);
                } catch (\Throwable) {
                }
            }
            $this->json(['error' => ['code' => 'upload_failed', 'message' => $e->getMessage()]], 500);
        }

        $this->json(['data' => ['backup' => $backup, 'inspection' => $inspection]], 201);
    }

    public function inspect(string $name): never
    {
        $this->requirePermission('backups.read', ['resource' => 'backups:*']);

        try {
            $path = (new BackupService())->path($name);
            $inspection = (new RestoreService())->inspect($path);
        } catch (\Throwable $e) {
            $this->json(['error' => ['code' => 'backup_inspect_failed', 'message' => $e->getMessage()]], 500);
        }

        $this->json(['data' => ['inspection' => $inspection]]);
    }

    public function restore(string $name): never
    {
        $user = $this->requirePermission('backups.restore', ['resource' => 'backups:*']);
        $this->verifyCsrf();
        $body = $this->requestJson();
        $parts = $this->partsFromPayload($body);

        if ($parts === []) {
            $this->json(['error' => ['code' => 'validation_failed', 'message' => 'Select at least one part to restore.']], 422);
        }

        try {
            $path = (new BackupService())->path($name);
            $summary = (new RestoreService())->restore($path, (bool) ($body['overwrite'] ?? false), $user, $parts);
        } catch (\Throwable $e) {
            $this->json(['error' => ['code' => 'restore_failed', 'message' => $e->getMessage()]], 500);
        }

        $this->json(['data' => ['summary' => $summary]]);
    }

    public function note(string $name): never
    {
        $user = $this->requirePermission('backups.create', ['resource' => 'backups:*']);
        $this->verifyCsrf();
        $body = $this->requestJson();

        try {
            $backup = (new BackupService())->setNote($name, (string) ($body['note'] ?? ''), $user);
        } catch (\InvalidArgumentException $e) {
            $this->json(['error' => ['code' => 'validation_failed', 'message' => $e->getMessage()]], 422);
        } catch (\Throwable $e) {
            $this->json(['error' => ['code' => 'backup_note_failed', 'message' => $e->getMessage()]], 500);
        }

        $this->json(['data' => ['backup' => $backup]]);
    }

    public function download(string $name): never
    {
        $this->requirePermission('backups.read', ['resource' => 'backups:*']);

        try {
            $path = (new BackupService())->path($name);
        } catch (\Throwable $e) {
            $this->json(['error' => ['code' => 'backup_not_found', 'message' => $e->getMessage()]], 404);
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . (string) filesize($path));

        $fh = fopen($path, 'rb');
        if ($fh === false) {
            $this->json(['error' => ['code' => 'backup_read_failed', 'message' => 'Could not open backup file.']], 500);
        }

        while (!feof($fh)) {
            print fread($fh, 8192);
            ob_flush();
            flush();
        }

        fclose($fh);
        exit;
    }

    public function destroy(string $name): never
    {
        $this->requirePermission('backups.delete', ['resource' => 'backups:*']);
        $this->verifyCsrf();

        try {
            (new BackupService())->delete($name);
        } catch (\Throwable $e) {
            $this->json(['error' => ['code' => 'backup_delete_failed', 'message' => $e->getMessage()]], 500);
        }

        $this->json(['data' => ['ok' => true]]);
    }

    private function partsFromPayload(array $payload): ?array
    {
        if (!array_key_exists('parts', $payload)) {
            return null;
        }

        $raw = $payload['parts'];

        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }

        if (!is_array($raw)) {
            return [];
        }

        $allowed = BackupService::allowedParts();

        return array_values(array_unique(array_filter(
            array_map('strval', $raw),
            static fn(string $part): bool => in_array($part, $allowed, true),
        )));
    }
}
