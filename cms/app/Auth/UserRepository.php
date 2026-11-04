<?php

declare(strict_types=1);

namespace CometCMS\Auth;

use CometCMS\Core\Security;
use CometCMS\Storage\JsonStore;

final class UserRepository
{
    public const ADMIN_ROLE = 'admin';
    /** Admin UI accent colors; each combines with any color mode. */
    public const THEMES = ['blue', 'green', 'purple', 'orange', 'red', 'cyan'];
    /** Admin UI color modes; auto follows the browser's preference. */
    public const COLOR_MODES = ['light', 'dark', 'auto'];
    public const BUILDER_PREFERENCES = ['show_parent_outlines' => false];
    private const LAST_ADMIN_MESSAGE = 'At least one user must keep the Admin role. Give another user the Admin role first.';

    private JsonStore $store;
    private RoleRepository $roles;

    public function __construct()
    {
        $this->store = new JsonStore(COMET_STORAGE . '/users');
        $this->roles = new RoleRepository();
    }

    /**
     * The user's admin UI appearance as a valid accent color and color mode.
     * Users saved before modes existed may hold the former "dark" theme,
     * which is dark mode with the default color.
     *
     * @return array{theme: string, color_mode: string}
     */
    public static function appearance(array $user): array
    {
        $theme = strtolower((string) ($user['theme'] ?? ''));
        $mode = strtolower((string) ($user['color_mode'] ?? ''));

        return [
            'theme' => in_array($theme, self::THEMES, true) ? $theme : 'blue',
            'color_mode' => in_array($mode, self::COLOR_MODES, true) ? $mode : ($theme === 'dark' ? 'dark' : 'light'),
        ];
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
            'color_mode' => 'light',
            'language' => 'en',
            'show_api_footer' => true,
            'builder_preferences' => self::BUILDER_PREFERENCES,
            'created_at' => Security::now(),
        ];

        $this->save($user);

        return $user;
    }

    public function delete(string $id): void
    {
        Security::assertSafeName($id);

        if (($this->find($id)['role'] ?? null) === self::ADMIN_ROLE && $this->adminCount() <= 1) {
            throw new \InvalidArgumentException(self::LAST_ADMIN_MESSAGE);
        }

        $this->store->delete($id);
    }

    /** Number of users holding the locked Admin role, which is the only role that can always manage the install. */
    public function adminCount(): int
    {
        return count(array_filter($this->all(), static fn(array $user): bool => ($user['role'] ?? null) === self::ADMIN_ROLE));
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
            if (($user['role'] ?? null) === self::ADMIN_ROLE && $data['role'] !== self::ADMIN_ROLE && $this->adminCount() <= 1) {
                throw new \InvalidArgumentException(self::LAST_ADMIN_MESSAGE);
            }
            $user['role'] = (string) $data['role'];
        }

        if (isset($data['theme']) || isset($data['color_mode'])) {
            $user = array_replace($user, self::appearance($user));
        }

        if (isset($data['theme'])) {
            $theme = (string) $data['theme'];
            if ($theme === 'dark') {
                // Former combined theme, still accepted from older clients.
                $user['color_mode'] = 'dark';
            } elseif (in_array($theme, self::THEMES, true)) {
                $user['theme'] = $theme;
            } else {
                throw new \InvalidArgumentException('Invalid theme.');
            }
        }

        if (isset($data['color_mode'])) {
            $mode = (string) $data['color_mode'];
            if (!in_array($mode, self::COLOR_MODES, true)) {
                throw new \InvalidArgumentException('Invalid color mode.');
            }
            $user['color_mode'] = $mode;
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

        if (array_key_exists('builder_preferences', $data)) {
            $preferences = $data['builder_preferences'];
            if (!is_array($preferences)) {
                throw new \InvalidArgumentException('Invalid page builder preferences.');
            }
            foreach ($preferences as $key => $value) {
                if (!array_key_exists($key, self::BUILDER_PREFERENCES) || !is_bool($value)) {
                    throw new \InvalidArgumentException('Invalid page builder preference.');
                }
            }
            $user['builder_preferences'] = array_replace(self::BUILDER_PREFERENCES, $user['builder_preferences'] ?? [], $preferences);
        }

        if (isset($data['password']) && (string) $data['password'] !== '') {
            if (strlen((string) $data['password']) < 8) {
                throw new \InvalidArgumentException('Password must be at least 8 characters.');
            }
            $user['password_hash'] = password_hash((string) $data['password'], PASSWORD_DEFAULT);
            $user['session_epoch'] = (int) ($user['session_epoch'] ?? 0) + 1;
        }

        $user['updated_at'] = Security::now();
        $this->save($user);

        return $user;
    }

}
