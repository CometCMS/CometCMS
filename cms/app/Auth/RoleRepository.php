<?php

declare(strict_types=1);

namespace CometCMS\Auth;

use CometCMS\Core\Security;
use CometCMS\Storage\JsonStore;

final class RoleRepository
{
    private const DEFAULT_ROLES = [
        'admin' => [
            'id' => 'admin',
            'name' => 'admin',
            'label' => 'Admin',
            'system' => true,
            'locked' => true,
            'permissions' => [
                ['effect' => 'allow', 'actions' => [
                    'dashboard.read',
                    'activity.read',
                    'updates.read',
                    'updates.check',
                    'updates.download',
                    'updates.install',
                    'profile.read',
                    'profile.update',
                    'backups.read',
                    'backups.create',
                    'backups.restore',
                    'backups.delete',
                    'webhooks.manage',
                    'workspaces.read',
                    'workspaces.manage',
                ], 'resources' => ['*']],
                ['effect' => 'allow', 'actions' => [
                    'schema.read',
                    'schema.create',
                    'schema.update',
                    'schema.delete',
                ], 'resources' => ['schema:*']],
                ['effect' => 'allow', 'actions' => [
                    'content.read',
                    'content.create',
                    'content.update',
                    'content.publish',
                    'content.delete',
                    'content.restore',
                    'content.revisions.read',
                    'content.revisions.restore',
                ], 'resources' => ['content:*']],
                ['effect' => 'allow', 'actions' => [
                    'media.read',
                    'media.upload',
                    'media.update',
                    'media.delete',
                ], 'resources' => ['media:*']],
                ['effect' => 'allow', 'actions' => [
                    'users.read',
                    'users.create',
                    'users.update',
                    'users.delete',
                    'tokens.read',
                    'tokens.create',
                    'tokens.revoke',
                    'roles.read',
                    'roles.create',
                    'roles.update',
                    'roles.delete',
                ], 'resources' => ['*']],
            ],
        ],
        'editor' => [
            'id' => 'editor',
            'name' => 'editor',
            'label' => 'Editor',
            'system' => true,
            'locked' => false,
            'permissions' => [
                ['effect' => 'allow', 'actions' => [
                    'dashboard.read',
                    'activity.read',
                    'updates.read',
                    'profile.read',
                    'profile.update',
                ], 'resources' => ['*']],
                ['effect' => 'allow', 'actions' => [
                    'schema.read',
                ], 'resources' => ['schema:*']],
                ['effect' => 'allow', 'actions' => [
                    'content.read',
                    'content.create',
                    'content.update',
                    'content.publish',
                    'content.delete',
                    'content.restore',
                    'content.revisions.read',
                    'content.revisions.restore',
                ], 'resources' => ['content:*']],
                ['effect' => 'allow', 'actions' => [
                    'media.read',
                    'media.upload',
                    'media.update',
                    'media.delete',
                ], 'resources' => ['media:*']],
            ],
        ],
        'viewer' => [
            'id' => 'viewer',
            'name' => 'viewer',
            'label' => 'Viewer',
            'system' => true,
            'locked' => false,
            'permissions' => [
                ['effect' => 'allow', 'actions' => [
                    'dashboard.read',
                    'activity.read',
                    'updates.read',
                    'profile.read',
                    'profile.update',
                ], 'resources' => ['*']],
                ['effect' => 'allow', 'actions' => [
                    'schema.read',
                ], 'resources' => ['schema:*']],
                ['effect' => 'allow', 'actions' => [
                    'content.read',
                ], 'resources' => ['content:*']],
                ['effect' => 'allow', 'actions' => [
                    'media.read',
                ], 'resources' => ['media:*']],
            ],
        ],
    ];

    private JsonStore $store;

    public function __construct()
    {
        $this->store = new JsonStore(COMET_STORAGE . '/roles');
    }

    public function all(): array
    {
        $roles = self::DEFAULT_ROLES;

        foreach ($this->store->all() as $role) {
            if (!is_array($role)) {
                continue;
            }

            $id = (string) ($role['id'] ?? $role['name'] ?? '');
            if ($id === '') {
                continue;
            }

            if (!empty($role['deleted_at'])) {
                unset($roles[$id]);
                continue;
            }

            $roles[$id] = $this->normalizeRole($role, $id);
        }

        $items = array_map(fn(array $role): array => $this->normalizeRole($role, (string) $role['id']), $roles);

        usort($items, static function (array $a, array $b): int {
            $order = ['admin' => 0, 'editor' => 1, 'viewer' => 2];
            $left = $order[(string) ($a['id'] ?? '')] ?? 10;
            $right = $order[(string) ($b['id'] ?? '')] ?? 10;

            return $left === $right
                ? strcmp((string) ($a['label'] ?? $a['id'] ?? ''), (string) ($b['label'] ?? $b['id'] ?? ''))
                : $left <=> $right;
        });

        return $items;
    }

    public function find(string $id): ?array
    {
        $id = $this->normalizeId($id);
        $stored = $this->store->read($id);

        if (is_array($stored)) {
            if (!empty($stored['deleted_at'])) {
                return null;
            }

            return $this->normalizeRole($stored, $id);
        }

        return isset(self::DEFAULT_ROLES[$id]) ? $this->normalizeRole(self::DEFAULT_ROLES[$id], $id) : null;
    }

    public function exists(string $id): bool
    {
        return $this->find($id) !== null;
    }

    public function permissions(string $id): array
    {
        $role = $this->find($id);

        return is_array($role) ? (array) ($role['permissions'] ?? []) : self::defaultPermissions('viewer');
    }

    public function seed(): void
    {
        foreach (self::DEFAULT_ROLES as $id => $role) {
            if ($this->store->read($id) === null) {
                $this->save($this->normalizeRole($role, $id));
            }
        }
    }

    public function create(array $data): array
    {
        $label = trim((string) ($data['label'] ?? $data['name'] ?? ''));
        $rawId = trim((string) ($data['id'] ?? ''));
        $id = $this->normalizeId($rawId !== '' ? $rawId : (string) ($data['name'] ?? $label));

        if ($id === '') {
            throw new \InvalidArgumentException('Role name is required.');
        }

        if ($this->exists($id)) {
            throw new \RuntimeException('A role with that name already exists.');
        }

        $role = $this->normalizeRole([
            'id' => $id,
            'name' => $id,
            'label' => $label !== '' ? $label : ucfirst($id),
            'system' => false,
            'locked' => false,
            'permissions' => $data['permissions'] ?? [],
            'created_at' => Security::now(),
        ], $id);

        $this->save($role);

        return $role;
    }

    public function update(string $id, array $data): array
    {
        $id = $this->normalizeId($id);
        $existing = $this->find($id);

        if ($existing === null) {
            throw new \RuntimeException('Role not found.');
        }

        if (!empty($existing['locked'])) {
            throw new \InvalidArgumentException('This role is locked and cannot be modified.');
        }

        $role = $this->normalizeRole([
            ...$existing,
            'label' => array_key_exists('label', $data) ? trim((string) $data['label']) : ($existing['label'] ?? $id),
            'permissions' => array_key_exists('permissions', $data) ? $data['permissions'] : ($existing['permissions'] ?? []),
            'updated_at' => Security::now(),
        ], $id);

        $this->save($role);

        return $role;
    }

    public function delete(string $id): void
    {
        $id = $this->normalizeId($id);

        if ($id === 'admin') {
            throw new \InvalidArgumentException('The admin role cannot be deleted.');
        }

        if ($this->find($id) === null) {
            throw new \RuntimeException('Role not found.');
        }

        if (isset(self::DEFAULT_ROLES[$id])) {
            $role = $this->normalizeRole(self::DEFAULT_ROLES[$id], $id);
            $role['deleted_at'] = Security::now();
            $this->save($role);
            return;
        }

        $this->store->delete($id);
    }

    public static function defaultPermissions(string $id): array
    {
        return self::DEFAULT_ROLES[$id]['permissions'] ?? self::DEFAULT_ROLES['viewer']['permissions'];
    }

    private function save(array $role): void
    {
        $this->store->write($role, (string) $role['id']);
    }

    private function normalizeRole(array $role, string $fallbackId): array
    {
        $id = $this->normalizeId((string) ($role['id'] ?? $role['name'] ?? $fallbackId));
        $default = self::DEFAULT_ROLES[$id] ?? [];
        $label = trim((string) ($role['label'] ?? $default['label'] ?? ucfirst($id)));

        return array_filter([
            'id' => $id,
            'name' => $id,
            'label' => $label !== '' ? $label : ucfirst($id),
            'system' => (bool) ($default['system'] ?? $role['system'] ?? false),
            'locked' => $id === 'admin' || (bool) ($default['locked'] ?? $role['locked'] ?? false),
            'permissions' => $this->normalizePermissions($role['permissions'] ?? $default['permissions'] ?? []),
            'created_at' => $role['created_at'] ?? null,
            'updated_at' => $role['updated_at'] ?? null,
        ], static fn(mixed $value): bool => $value !== null);
    }

    private function normalizePermissions(mixed $permissions): array
    {
        if (!is_array($permissions)) {
            return [];
        }

        $normalized = [];

        foreach ($permissions as $grant) {
            if (!is_array($grant)) {
                continue;
            }

            $actions = $grant['actions'] ?? $grant['action'] ?? [];
            $resources = $grant['resources'] ?? $grant['resource'] ?? [];

            $item = [
                'effect' => ($grant['effect'] ?? 'allow') === 'deny' ? 'deny' : 'allow',
                'actions' => array_values(array_filter(array_map('strval', (array) $actions))),
                'resources' => array_values(array_filter(array_map('strval', (array) $resources))),
            ];

            if (array_key_exists('fields', $grant)) {
                $item['fields'] = array_values(array_filter(array_map('strval', (array) $grant['fields'])));
            }

            if (is_array($grant['conditions'] ?? null)) {
                $item['conditions'] = $grant['conditions'];
            }

            if ($item['actions'] !== [] && $item['resources'] !== []) {
                $normalized[] = $item;
            }
        }

        return $normalized;
    }

    private function normalizeId(string $value): string
    {
        $id = Security::slug(trim($value));

        if ($id !== '') {
            Security::assertSafeName($id);
        }

        return $id;
    }
}
