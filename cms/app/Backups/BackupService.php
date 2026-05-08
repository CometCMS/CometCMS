<?php

declare(strict_types=1);

namespace CometCMS\Backups;

use CometCMS\Content\ContentTypeRepository;
use CometCMS\Auth\ApiTokenRepository;
use CometCMS\Auth\RoleRepository;
use CometCMS\Core\Security;
use CometCMS\Logging\Logger;
use CometCMS\Storage\SettingsStore;
use CometCMS\Workspaces\WorkspaceContext;

final class BackupService
{
    private WorkspaceContext $workspace;

    public function __construct(?WorkspaceContext $workspace = null)
    {
        $this->workspace = $workspace ?? WorkspaceContext::active();
        WorkspaceContext::setActive($this->workspace->slug());
        $this->workspace->ensure();
    }

    private const ALLOWED_PARTS = [
        'content_types',
        'content',
        'media',
        'users',
        'api_tokens',
        'webhooks',
    ];

    private const DEFAULT_PARTS = [
        'content_types',
        'content',
        'media',
        'api_tokens',
        'webhooks',
    ];

    public function create(array $user, ?array $parts = null): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is required for ZIP backup creation.');
        }

        $parts = self::normalizeParts($parts);
        $path = $this->backupPath('cometcms-backup-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3)) . '.zip');
        $zip = new \ZipArchive();

        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create backup ZIP.');
        }

        $contentCount = 0;
        $revisionCount = 0;
        $mediaCount = 0;
        $mediaMetaCount = 0;
        $contentTypeCount = 0;
        $userCount = 0;
        $roleCount = 0;
        $tokenCount = 0;
        $webhookCount = 0;

        if (in_array('content_types', $parts, true)) {
            $contentTypeCount = $this->addDirectory($zip, $this->workspace->path('content-types'), 'content-types');
        }

        if (in_array('content', $parts, true)) {
            $contentCount = $this->addDirectory($zip, $this->workspace->path('content'), 'content');
            $revisionCount = $this->addDirectory($zip, $this->workspace->path('revisions'), 'revisions');
        }

        if (in_array('media', $parts, true)) {
            $mediaCount = $this->addDirectory($zip, $this->workspace->path('media'), 'media');
            $mediaMetaCount = $this->addDirectory($zip, $this->workspace->path('media-meta'), 'media-meta');
        }

        if (in_array('users', $parts, true)) {
            $roleCount = $this->addRoles($zip);
            $userCount = $this->addUsers($zip);
        }

        if (in_array('api_tokens', $parts, true)) {
            $tokenCount = $this->addApiTokens($zip);
        }

        if (in_array('webhooks', $parts, true)) {
            $webhookCount = $this->addWebhooks($zip);
        }

        $manifest = [
            'cms' => 'CometCMS',
            'version' => comet_version(),
            'workspace' => $this->workspace->slug(),
            'created_at' => Security::now(),
            'parts' => $parts,
            'content_types' => array_map(static fn(array $type): string => (string) $type['name'], (new ContentTypeRepository($this->workspace))->all()),
            'content_type_count' => $contentTypeCount,
            'media_count' => $mediaCount,
            'media_meta_count' => $mediaMetaCount,
            'content_count' => $contentCount,
            'revision_count' => $revisionCount,
            'user_count' => $userCount,
            'role_count' => $roleCount,
            'token_count' => $tokenCount,
            'webhook_count' => $webhookCount,
            'includes_password_hashes' => (bool) comet_config('backups.include_password_hashes', false),
        ];
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        $zip->close();
        (new Logger())->info('backup created', ['path' => basename($path), 'parts' => $parts, 'user_id' => $user['id'] ?? null]);

        return $this->backupInfo(basename($path));
    }

    public function all(): array
    {
        $items = [];

        foreach (glob($this->root() . '/*.zip') ?: [] as $path) {
            $items[] = $this->backupInfo(basename($path));
        }

        usort($items, static fn(array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return $items;
    }

    public function saveUploaded(array $file, array $user): array
    {
        $errorCode = $file['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($errorCode !== UPLOAD_ERR_OK) {
            $message = match ($errorCode) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The backup file exceeds the server\'s upload size limit.',
                UPLOAD_ERR_PARTIAL                       => 'The backup file was only partially uploaded.',
                UPLOAD_ERR_NO_FILE                       => 'No backup file was submitted.',
                UPLOAD_ERR_NO_TMP_DIR                    => 'Server is missing a temporary folder for uploads.',
                UPLOAD_ERR_CANT_WRITE                    => 'Server failed to write the uploaded file to disk.',
                default                                  => 'Upload a ZIP backup file.',
            };
            throw new \InvalidArgumentException($message);
        }

        if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new \InvalidArgumentException('Upload a ZIP backup file.');
        }

        if (strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'zip') {
            throw new \InvalidArgumentException('Backup files must use the .zip extension.');
        }

        $original = pathinfo((string) ($file['name'] ?? ''), PATHINFO_FILENAME);
        $slug = Security::slug($original !== '' ? $original : 'uploaded-backup');
        $name = 'uploaded-' . gmdate('YmdHis') . '-' . $slug . '.zip';
        $target = $this->backupPath($name);

        if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
            throw new \RuntimeException('Could not save uploaded backup.');
        }

        (new Logger())->info('backup uploaded', ['path' => $name, 'user_id' => $user['id'] ?? null]);

        return $this->backupInfo($name);
    }

    public function delete(string $name): void
    {
        $path = $this->backupPath($name);

        if ($this->backupInfo($name)['is_protected']) {
            throw new \RuntimeException('Backups with notes cannot be deleted.');
        }

        if (is_file($path)) {
            unlink($path);
        }

        $metadataPath = $this->metadataPath($name);
        if (is_file($metadataPath)) {
            unlink($metadataPath);
        }
    }

    public function setNote(string $name, string $note, array $user): array
    {
        $path = $this->path($name);
        $note = trim($note);

        if (strlen($note) > 500) {
            throw new \InvalidArgumentException('Backup notes may not be longer than 500 characters.');
        }

        $metadataPath = $this->metadataPath(basename($path));

        if ($note === '') {
            if (is_file($metadataPath)) {
                unlink($metadataPath);
            }
        } else {
            $metadata = [
                'note' => $note,
                'updated_at' => Security::now(),
                'updated_by' => $user['id'] ?? null,
            ];

            if (file_put_contents($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL, LOCK_EX) === false) {
                throw new \RuntimeException('Could not save backup note.');
            }
        }

        (new Logger())->info('backup note updated', ['path' => basename($path), 'user_id' => $user['id'] ?? null]);

        return $this->backupInfo(basename($path));
    }

    public function path(string $name): string
    {
        $path = $this->backupPath($name);

        if (!is_file($path)) {
            throw new \RuntimeException('Backup not found.');
        }

        return $path;
    }

    public function backupInfo(string $name): array
    {
        $path = $this->backupPath($name);
        $metadata = $this->metadata($name);
        $note = (string) ($metadata['note'] ?? '');

        return [
            'name' => basename($path),
            'size' => is_file($path) ? filesize($path) : 0,
            'created_at' => is_file($path) ? gmdate('Y-m-d\TH:i:s\Z', filemtime($path) ?: time()) : null,
            'note' => $note,
            'is_protected' => $note !== '',
        ];
    }

    public static function defaultParts(): array
    {
        return self::DEFAULT_PARTS;
    }

    public static function allowedParts(): array
    {
        return self::ALLOWED_PARTS;
    }

    public static function normalizeParts(?array $parts): array
    {
        if ($parts === null) {
            return self::DEFAULT_PARTS;
        }

        $normalized = array_values(array_unique(array_intersect(
            array_map('strval', $parts),
            self::ALLOWED_PARTS,
        )));

        if ($normalized === []) {
            throw new \InvalidArgumentException('Select at least one part.');
        }

        return $normalized;
    }

    private function root(): string
    {
        $root = COMET_STORAGE . '/backups';

        if (!is_dir($root)) {
            mkdir($root, 0775, true);
        }

        return $root;
    }

    private function backupPath(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_.-]+\.zip$/', $name)) {
            throw new \InvalidArgumentException('Invalid backup name.');
        }

        return $this->root() . '/' . basename($name);
    }

    private function metadataPath(string $name): string
    {
        return $this->backupPath($name) . '.meta.json';
    }

    private function metadata(string $name): array
    {
        $metadataPath = $this->metadataPath($name);

        if (!is_file($metadataPath)) {
            return [];
        }

        $metadata = json_decode((string) file_get_contents($metadataPath), true);

        return is_array($metadata) ? $metadata : [];
    }

    private function addDirectory(\ZipArchive $zip, string $source, string $prefix): int
    {
        if (!is_dir($source)) {
            return 0;
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || str_starts_with($file->getFilename(), '.')) {
                continue;
            }

            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($source))), '/');
            $zip->addFile($file->getPathname(), $prefix . '/' . $relative);
            $count++;
        }

        return $count;
    }

    private function addUsers(\ZipArchive $zip): int
    {
        $users = [];

        foreach (glob(COMET_STORAGE . '/users/*.json') ?: [] as $file) {
            $user = json_decode((string) file_get_contents($file), true);

            if (!is_array($user)) {
                continue;
            }

            unset($user['permissions']);

            if (!(bool) comet_config('backups.include_password_hashes', false)) {
                unset($user['password_hash'], $user['api_tokens']);
            }

            $users[] = $user;
        }

        $zip->addFromString('users.json', json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        return count($users);
    }

    private function addApiTokens(\ZipArchive $zip): int
    {
        $tokens = [];

        foreach ((new ApiTokenRepository())->all() as $token) {
            unset($token['_principal_type']);

            if (!(bool) comet_config('backups.include_password_hashes', false)) {
                unset($token['hash']);
            }

            $tokens[] = $token;
        }

        $zip->addFromString('tokens.json', json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        return count($tokens);
    }

    private function addRoles(\ZipArchive $zip): int
    {
        $roles = (new RoleRepository())->all();
        $zip->addFromString('roles.json', json_encode($roles, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        return count($roles);
    }

    private function addWebhooks(\ZipArchive $zip): int
    {
        $settings = (new SettingsStore())->all();
        $webhooks = $settings['webhooks'] ?? comet_config('webhooks', []);
        $webhooks = is_array($webhooks) ? array_values($webhooks) : [];

        $zip->addFromString('webhooks.json', json_encode($webhooks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        return count($webhooks);
    }
}
