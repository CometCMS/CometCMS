<?php

declare(strict_types=1);

namespace CometCMS\Auth;

use CometCMS\Core\Security;
use CometCMS\Storage\JsonStore;

final class UserRepository
{
    private JsonStore $store;
    private RoleRepository $roles;

    public function __construct()
    {
        $this->store = new JsonStore(COMET_STORAGE . '/users');
        $this->roles = new RoleRepository();
    }

    public function hasUsers(): bool
    {
        return count($this->all()) > 0;
    }

    public function all(): array
    {
        return $this->store->all();
    }

    public function find(string $id): ?array
    {
        return $this->store->read($id);
    }

    public function findByUsername(string $username): ?array
    {
        foreach ($this->all() as $user) {
            if (($user['username'] ?? '') === $username) {
                return $user;
            }
        }

        return null;
    }

    public function save(array $user): void
    {
        Security::assertSafeName((string) $user['id']);
        unset($user['permissions']);
        $this->store->write($user, (string) $user['id']);
    }

    public function create(string $username, string $password, string $role): array
    {
        $username = trim($username);

        if ($username === '') {
            throw new \InvalidArgumentException('Username is required.');
        }

        $id = Security::slug($username);

        if ($this->find($id) !== null) {
            throw new \RuntimeException('A user with that id already exists.');
        }

        if (!$this->roles->exists($role)) {
            throw new \InvalidArgumentException('Invalid role.');
        }

        $user = [
            'id' => $id,
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'theme' => 'blue',
            'language' => 'en',
            'show_api_footer' => true,
            'created_at' => Security::now(),
        ];

        $this->save($user);

        return $user;
    }

    public function delete(string $id): void
    {
        Security::assertSafeName($id);
        $this->store->delete($id);
    }

    public function update(string $id, array $data): array
    {
        $user = $this->find($id);

        if ($user === null) {
            throw new \RuntimeException('User not found.');
        }

        if (isset($data['display_name'])) {
            $user['display_name'] = trim((string) $data['display_name']);
        }

        if (array_key_exists('email', $data)) {
            $email = trim((string) $data['email']);
            $user['email'] = $email !== '' ? filter_var($email, FILTER_VALIDATE_EMAIL) ?: throw new \InvalidArgumentException('Invalid email address.') : '';
        }

        if (isset($data['role'])) {
            if (!$this->roles->exists((string) $data['role'])) {
                throw new \InvalidArgumentException('Invalid role.');
            }
            $user['role'] = (string) $data['role'];
        }

        if (isset($data['theme'])) {
            $theme = (string) $data['theme'];
            if (!in_array($theme, ['blue', 'green', 'purple', 'orange', 'cyan', 'dark'], true)) {
                throw new \InvalidArgumentException('Invalid theme.');
            }
            $user['theme'] = $theme;
        }

        if (isset($data['language'])) {
            $language = strtolower(str_replace('_', '-', trim((string) $data['language'])));
            if (!preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/', $language)) {
                throw new \InvalidArgumentException('Invalid language.');
            }
            $user['language'] = $language;
        }

        if (array_key_exists('show_api_footer', $data)) {
            $user['show_api_footer'] = (bool) $data['show_api_footer'];
        }

        if (isset($data['password']) && (string) $data['password'] !== '') {
            if (strlen((string) $data['password']) < 8) {
                throw new \InvalidArgumentException('Password must be at least 8 characters.');
            }
            $user['password_hash'] = password_hash((string) $data['password'], PASSWORD_DEFAULT);
        }

        $user['updated_at'] = Security::now();
        $this->save($user);

        return $user;
    }

}
