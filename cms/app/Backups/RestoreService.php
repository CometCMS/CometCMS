<?php

declare(strict_types=1);

namespace CometCMS\Backups;

use CometCMS\Auth\ApiTokenRepository;
use CometCMS\Auth\RoleRepository;
use CometCMS\Auth\UserRepository;
use CometCMS\Core\Security;
use CometCMS\Logging\Logger;
use CometCMS\Storage\SettingsStore;
use CometCMS\Workspaces\WorkspaceContext;

final class RestoreService
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

    public function restore(string $zipPath, bool $overwrite, array $user, ?array $parts = null): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is required for backup restore.');
        }

        $parts = $this->normalizeParts($parts);

        $zip = new \ZipArchive();

        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Could not open backup ZIP.');
        }

        if ($zip->locateName('manifest.json') === false) {
            throw new \RuntimeException('Backup ZIP is missing manifest.json.');
        }

        $summary = [
            'selected_parts' => $parts,
            'restored_content_types' => 0,
            'restored_content' => 0,
            'restored_revisions' => 0,
            'restored_media' => 0,
            'restored_media_meta' => 0,
            'restored_users' => 0,
            'restored_roles' => 0,
            'restored_tokens' => 0,
            'restored_webhooks' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));

            if ($this->unsafePath($name) || str_ends_with($name, '/')) {
                $summary['errors'][] = 'Unsafe path skipped: ' . $name;
                continue;
            }

            $target = $this->targetPath($name, $parts);

            if ($target === null) {
                continue;
            }

            if (is_file($target) && !$overwrite) {
                $summary['skipped']++;
                continue;
            }

            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0775, true);
            }

            copy('zip://' . $zipPath . '#' . $name, $target);

            if (str_starts_with($name, 'content-types/')) {
                $summary['restored_content_types']++;
            } elseif (str_starts_with($name, 'content/')) {
                $summary['restored_content']++;
            } elseif (str_starts_with($name, 'revisions/')) {
                $summary['restored_revisions']++;
            } elseif (str_starts_with($name, 'media/')) {
                $summary['restored_media']++;
            } elseif (str_starts_with($name, 'media-meta/')) {
                $summary['restored_media_meta']++;
            }
        }

        if (in_array('users', $parts, true)) {
            $summary['restored_roles'] = $this->restoreRoles($zip, $overwrite, $summary);
            $summary['restored_users'] = $this->restoreUsers($zip, $overwrite, $summary);
        }

        if (in_array('api_tokens', $parts, true) || in_array('users', $parts, true)) {
            $summary['restored_tokens'] = $this->restoreApiTokens($zip, $overwrite, $summary);
        }

        if (in_array('webhooks', $parts, true)) {
            $summary['restored_webhooks'] = $this->restoreWebhooks($zip, $summary);
        }

        $zip->close();
        (new Logger())->info('backup restored', ['summary' => $summary, 'user_id' => $user['id'] ?? null]);

        return $summary;
    }

    public function inspect(string $zipPath): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is required for backup inspection.');
        }

        $zip = new \ZipArchive();

        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Could not open backup ZIP.');
        }

        if ($zip->locateName('manifest.json') === false) {
            throw new \RuntimeException('Backup ZIP is missing manifest.json.');
        }

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $manifest = is_array($manifest) ? $manifest : [];
        $counts = [
            'content_types' => 0,
            'content' => 0,
            'revisions' => 0,
            'media' => 0,
            'media_meta' => 0,
            'users' => 0,
            'roles' => 0,
            'tokens' => 0,
            'webhooks' => 0,
        ];
        $contentTypes = [];
        $errors = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));

            if ($this->unsafePath($name) || str_ends_with($name, '/')) {
                $errors[] = 'Unsafe path found: ' . $name;
                continue;
            }

            if (str_starts_with($name, 'content-types/')) {
                $counts['content_types']++;
                $contentTypes[] = basename($name, '.json');
            } elseif (str_starts_with($name, 'content/')) {
                $counts['content']++;
            } elseif (str_starts_with($name, 'revisions/')) {
                $counts['revisions']++;
            } elseif (str_starts_with($name, 'media/')) {
                $counts['media']++;
            } elseif (str_starts_with($name, 'media-meta/')) {
                $counts['media_meta']++;
            }
        }

        $users = json_decode((string) $zip->getFromName('users.json'), true);
        if (is_array($users)) {
            $counts['users'] = count(array_filter($users, 'is_array'));
        }

        $roles = json_decode((string) $zip->getFromName('roles.json'), true);
        if (is_array($roles)) {
            $counts['roles'] = count(array_filter($roles, 'is_array'));
        }

        $tokens = json_decode((string) $zip->getFromName('tokens.json'), true);
        if (is_array($tokens)) {
            $counts['tokens'] = count(array_filter($tokens, 'is_array'));
        }

        $webhooks = json_decode((string) $zip->getFromName('webhooks.json'), true);
        if (is_array($webhooks)) {
            $counts['webhooks'] = count(array_filter($webhooks, 'is_array'));
        }

        $zip->close();

        if (is_array($manifest['content_types'] ?? null)) {
            $contentTypes = array_values(array_unique(array_map('strval', $manifest['content_types'])));
        } else {
            $contentTypes = array_values(array_unique($contentTypes));
        }

        return [
            'manifest' => [
                'cms' => $manifest['cms'] ?? null,
                'version' => $manifest['version'] ?? null,
                'workspace' => $manifest['workspace'] ?? null,
                'created_at' => $manifest['created_at'] ?? null,
                'parts' => array_values(array_filter(
                    array_map('strval', (array) ($manifest['parts'] ?? [])),
                    static fn(string $part): bool => in_array($part, self::ALLOWED_PARTS, true),
                )),
                'includes_password_hashes' => (bool) ($manifest['includes_password_hashes'] ?? false),
            ],
            'counts' => $counts,
            'content_types' => $contentTypes,
            'available_parts' => $this->availableParts($counts),
            'default_parts' => array_values(array_filter(
                self::DEFAULT_PARTS,
                fn(string $part): bool => in_array($part, $this->availableParts($counts), true),
            )),
            'errors' => $errors,
        ];
    }

    private function unsafePath(string $path): bool
    {
        return str_starts_with($path, '/') || str_contains($path, '../') || str_contains($path, '..\\') || $path === '..';
    }

    private function targetPath(string $name, array $parts): ?string
    {
        foreach (
            [
                'content-types/' => ['content_types', $this->workspace->path('content-types') . '/'],
                'content/' => ['content', $this->workspace->path('content') . '/'],
                'revisions/' => ['content', $this->workspace->path('revisions') . '/'],
                'media/' => ['media', $this->workspace->path('media') . '/'],
                'media-meta/' => ['media', $this->workspace->path('media-meta') . '/'],
            ] as $prefix => [$part, $target]
        ) {
            if (str_starts_with($name, $prefix)) {
                if (!in_array($part, $parts, true)) {
                    return null;
                }

                return $target . substr($name, strlen($prefix));
            }
        }

        return null;
    }

    private function normalizeParts(?array $parts): array
    {
        if ($parts === null) {
            return self::DEFAULT_PARTS;
        }

        $normalized = array_values(array_unique(array_intersect(
            array_map('strval', $parts),
            self::ALLOWED_PARTS,
        )));

        if ($normalized === []) {
            throw new \InvalidArgumentException('Select at least one part to restore.');
        }

        return $normalized;
    }

    private function availableParts(array $counts): array
    {
        $parts = [];

        if (($counts['content_types'] ?? 0) > 0) {
            $parts[] = 'content_types';
        }

        if (($counts['content'] ?? 0) > 0 || ($counts['revisions'] ?? 0) > 0) {
            $parts[] = 'content';
        }

        if (($counts['media'] ?? 0) > 0 || ($counts['media_meta'] ?? 0) > 0) {
            $parts[] = 'media';
        }

        if (($counts['users'] ?? 0) > 0 || ($counts['roles'] ?? 0) > 0) {
            $parts[] = 'users';
        }

        if (($counts['tokens'] ?? 0) > 0) {
            $parts[] = 'api_tokens';
        }

        if (($counts['webhooks'] ?? 0) > 0) {
            $parts[] = 'webhooks';
        }

        return $parts;
    }

    private function restoreUsers(\ZipArchive $zip, bool $overwrite, array &$summary): int
    {
        $json = $zip->getFromName('users.json');

        if ($json === false) {
            return 0;
        }

        $users = json_decode($json, true);

        if (!is_array($users)) {
            $summary['errors'][] = 'Users backup could not be decoded.';
            return 0;
        }

        $repository = new UserRepository();
        $roles = new RoleRepository();
        $restored = 0;

        foreach ($users as $user) {
            if (!is_array($user)) {
                continue;
            }

            $id = trim((string) ($user['id'] ?? ''));

            if ($id === '') {
                $id = Security::slug((string) ($user['username'] ?? ''));
                $user['id'] = $id;
            }

            try {
                Security::assertSafeName($id);
            } catch (\Throwable) {
                $summary['errors'][] = 'Unsafe user id skipped: ' . $id;
                continue;
            }

            if ($repository->find($id) !== null && !$overwrite) {
                $summary['skipped']++;
                continue;
            }

            if (!isset($user['password_hash']) || (string) $user['password_hash'] === '') {
                $summary['errors'][] = 'User skipped because password hashes are not included: ' . $id;
                continue;
            }

            $role = (string) ($user['role'] ?? 'viewer');
            if (!$roles->exists($role)) {
                $user['role'] = 'viewer';
            }

            $user['username'] = trim((string) ($user['username'] ?? $id));
            unset($user['permissions'], $user['api_tokens']);
            $repository->save($user);
            $restored++;
        }

        return $restored;
    }

    private function restoreApiTokens(\ZipArchive $zip, bool $overwrite, array &$summary): int
    {
        $json = $zip->getFromName('tokens.json');

        if ($json === false) {
            return 0;
        }

        $items = json_decode($json, true);

        if (!is_array($items)) {
            $summary['errors'][] = 'API token backup could not be decoded.';
            return 0;
        }

        $repository = new ApiTokenRepository();
        $restored = 0;

        foreach ($items as $token) {
            if (!is_array($token)) {
                continue;
            }

            $id = trim((string) ($token['id'] ?? ''));

            try {
                Security::assertSafeName($id);
            } catch (\Throwable) {
                $summary['errors'][] = 'Unsafe API token id skipped: ' . $id;
                continue;
            }

            if ($repository->find($id) !== null && !$overwrite) {
                $summary['skipped']++;
                continue;
            }

            if (!isset($token['hash']) || (string) $token['hash'] === '') {
                $summary['errors'][] = 'API token skipped because token hashes are not included: ' . $id;
                continue;
            }

            $token['name'] = trim((string) ($token['name'] ?? 'API token'));
            $token['description'] = trim((string) ($token['description'] ?? ''));
            $repository->save($token);
            $restored++;
        }

        return $restored;
    }

    private function restoreRoles(\ZipArchive $zip, bool $overwrite, array &$summary): int
    {
        $json = $zip->getFromName('roles.json');

        if ($json === false) {
            return 0;
        }

        $items = json_decode($json, true);

        if (!is_array($items)) {
            $summary['errors'][] = 'Roles backup could not be decoded.';
            return 0;
        }

        $repository = new RoleRepository();
        $restored = 0;

        foreach ($items as $role) {
            if (!is_array($role)) {
                continue;
            }

            $id = (string) ($role['id'] ?? $role['name'] ?? '');
            if ($id === '') {
                continue;
            }

            if ($repository->find($id) !== null && !$overwrite) {
                $summary['skipped']++;
                continue;
            }

            try {
                $repository->update($id, $role);
            } catch (\RuntimeException) {
                try {
                    $repository->create($role);
                } catch (\Throwable $e) {
                    $summary['errors'][] = 'Role skipped: ' . $id . ' (' . $e->getMessage() . ')';
                    continue;
                }
            } catch (\Throwable $e) {
                $summary['errors'][] = 'Role skipped: ' . $id . ' (' . $e->getMessage() . ')';
                continue;
            }

            $restored++;
        }

        return $restored;
    }

    private function restoreWebhooks(\ZipArchive $zip, array &$summary): int
    {
        $json = $zip->getFromName('webhooks.json');

        if ($json === false) {
            return 0;
        }

        $webhooks = json_decode($json, true);

        if (!is_array($webhooks)) {
            $summary['errors'][] = 'Webhook backup could not be decoded.';
            return 0;
        }

        $clean = [];

        foreach ($webhooks as $webhook) {
            if (!is_array($webhook)) {
                continue;
            }

            $url = trim((string) ($webhook['url'] ?? ''));
            $secret = trim((string) ($webhook['secret'] ?? ''));

            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL) || (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://'))) {
                continue;
            }

            $clean[] = [
                'url' => $url,
                'secret' => $secret,
                'name' => trim((string) ($webhook['name'] ?? '')),
                'events' => array_values(array_unique(array_map('strval', (array) ($webhook['events'] ?? [])))),
                'enabled' => ($webhook['enabled'] ?? true) !== false,
            ];
        }

        $store = new SettingsStore();
        $settings = $store->all();
        $settings['webhooks'] = $clean;
        $store->save($settings);

        return count($clean);
    }
}
