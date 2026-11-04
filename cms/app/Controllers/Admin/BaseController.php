<?php

declare(strict_types=1);

namespace CometCMS\Controllers\Admin;

use CometCMS\Auth\Auth;
use CometCMS\Auth\ApiTokenRepository;
use CometCMS\Auth\PermissionService;
use CometCMS\Auth\UserRepository;
use CometCMS\Cache\ApiCache;
use CometCMS\Content\ContentTypeRepository;
use CometCMS\Core\Http;
use CometCMS\Core\Security;
use CometCMS\Logging\Logger;
use CometCMS\Workspaces\WorkspaceContext;
use CometCMS\Workspaces\WorkspaceRepository;

abstract class BaseController
{
    protected UserRepository $users;
    protected ApiTokenRepository $tokens;
    protected Auth $auth;
    protected PermissionService $permissions;
    protected ApiCache $cache;
    protected Logger $logger;
    private ?string $csrfToken = null;

    public function __construct(protected readonly Http $http)
    {
        $this->users = new UserRepository();
        $this->tokens = new ApiTokenRepository();
        $this->auth = new Auth($this->users);
        $this->permissions = new PermissionService();
        $this->logger = new Logger();

        // Normalised path, so installs in a sub-directory (/cms/admin/api/...) match too.
        $path = $this->http->path();
        $preSetupAuthRoute = !$this->users->hasUsers() && in_array($path, [
            '/admin/api/me',
            '/admin/api/login',
            '/admin/api/setup',
        ], true);

        if (!$preSetupAuthRoute) {
            try {
                WorkspaceContext::fromRequest();
            } catch (\Throwable $e) {
                $workspaceExempt = str_starts_with($path, '/admin/api/workspaces')
                    || $path === '/admin/api/me'
                    || $path === '/admin/api/login'
                    || $path === '/admin/api/logout'
                    || $path === '/admin/api/setup';
                if (!$workspaceExempt) {
                    http_response_code(404);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['error' => ['code' => 'not_found', 'message' => $e->getMessage()]], JSON_UNESCAPED_SLASHES);
                    exit;
                }

                WorkspaceContext::reset();
            }
        }

        $this->cache = ApiCache::fromConfig();
    }

    protected function requireUser(string $role = 'viewer'): array
    {
        if (!$this->users->hasUsers()) {
            $this->json(['error' => ['code' => 'not_set_up', 'message' => 'CometCMS has not been set up yet.']], 503);
        }

        $user = $this->auth->user();

        if ($user === null) {
            $this->json(['error' => ['code' => 'unauthenticated', 'message' => 'Authentication required.']], 401);
        }

        if ($role !== 'viewer' && !Auth::allows($user, $role)) {
            $this->json(['error' => ['code' => 'forbidden', 'message' => 'You do not have permission to perform this action.']], 403);
        }

        $this->releaseReadOnlySession();

        return $user;
    }

    protected function can(array $user, string $action, array $context = []): bool
    {
        $context['principal'] = $user;
        $context['workspace'] ??= WorkspaceContext::active()->slug();

        return $this->permissions->allows($user, $action, $context);
    }

    protected function requirePermission(string $action, array $context = []): array
    {
        $user = $this->requireUser();

        if (!$this->can($user, $action, $context)) {
            $this->json(['error' => ['code' => 'forbidden', 'message' => 'You do not have permission to perform this action.']], 403);
        }

        return $user;
    }

    /**
     * Refuse to hand out permissions the acting user does not hold, whether by
     * assigning a role, editing a role, or minting an access token.
     */
    protected function requireDelegable(array $actor, array $grants, string $message): void
    {
        $uncovered = $this->permissions->firstUncovered($actor, $grants);

        if ($uncovered !== null) {
            $this->json(['error' => [
                'code' => 'forbidden',
                'message' => $message,
                'details' => $uncovered,
            ]], 403);
        }
    }

    protected function verifyCsrf(): void
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

        if (!is_string($token) || !Security::verifyCsrf($token)) {
            $this->json(['error' => ['code' => 'csrf_mismatch', 'message' => 'Invalid or missing CSRF token.']], 419);
        }
    }

    protected function requestJson(): array
    {
        $body = json_decode((string) file_get_contents('php://input'), true);

        return is_array($body) ? $body : [];
    }

    protected function clientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        return is_string($ip) && $ip !== '' ? $ip : 'unknown';
    }

    protected function json(array $data, int $status = 200, bool $regenerateCsrf = false): never
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            if ($regenerateCsrf) {
                $key = (string) comet_config('security.csrf_key', 'cometcms_csrf');
                unset($_SESSION[$key]);
            }

            $this->csrfToken = Security::csrfToken();
        }

        if ($this->csrfToken !== null) {
            header('X-CSRF-Token: ' . $this->csrfToken);
        }

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function releaseReadOnlySession(): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        if (in_array($method, ['GET', 'HEAD'], true)) {
            $this->releaseSession();
        }
    }

    /** Release the session lock before slow work that no longer writes session data. */
    protected function releaseSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $this->csrfToken = Security::csrfToken();
        session_write_close();
    }

    protected function safeUser(?array $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $accessibleContentTypes = [];
        try {
            foreach ((new ContentTypeRepository())->all() as $type) {
                $name = (string) ($type['name'] ?? '');
                if ($name === '') continue;
                if ($this->permissions->allows($user, 'content.read', [
                    'type' => 'content',
                    'collection' => $name,
                    'principal' => $user,
                ])) {
                    $accessibleContentTypes[] = [
                        'name' => $name,
                        'label' => $type['label'] ?? $name,
                        'icon' => $type['icon'] ?? null,
                    ];
                }
            }
        } catch (\Throwable) {
            // Non-fatal: sidebar degrades gracefully if types can't be loaded.
        }

        return [
            'id' => $user['id'],
            'username' => $user['username'],
            'display_name' => $user['display_name'] ?? null,
            'email' => $user['email'] ?? null,
            'role' => $user['role'],
            'capabilities' => $this->permissions->capabilities($user),
            ...UserRepository::appearance($user),
            'language' => $user['language'] ?? 'en',
            'show_api_footer' => $user['show_api_footer'] ?? true,
            'has_avatar' => $this->avatarPath((string) $user['id']) !== null,
            'accessible_content_types' => $accessibleContentTypes,
            // Every user needs this to address the API before (or without)
            // being allowed to list workspaces.
            'default_workspace' => (new WorkspaceRepository())->getDefault(),
        ];
    }

    protected function avatarPath(string $userId): ?string
    {
        $dir = COMET_STORAGE . '/users/avatars/';

        foreach (['jpg', 'png', 'webp', 'gif'] as $ext) {
            $path = $dir . $userId . '.' . $ext;
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
