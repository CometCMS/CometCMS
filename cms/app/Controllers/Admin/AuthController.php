<?php

declare(strict_types=1);

namespace CometCMS\Controllers\Admin;

use CometCMS\Auth\LoginThrottle;
use CometCMS\Core\Http;
use CometCMS\Core\Security;
use CometCMS\Storage\SettingsStore;
use CometCMS\Workspaces\WorkspaceContext;

final class AuthController extends BaseController
{
    private LoginThrottle $loginThrottle;

    public function __construct(Http $http)
    {
        parent::__construct($http);
        $this->loginThrottle = new LoginThrottle();
    }

    public function me(): never
    {
        if (!$this->users->hasUsers()) {
            $this->json(['error' => ['code' => 'not_set_up', 'message' => 'CometCMS has not been set up yet.']], 503);
        }

        $user = $this->auth->user();

        if ($user === null) {
            $this->json(['error' => ['code' => 'unauthenticated', 'message' => 'Not logged in.']], 401);
        }

        $this->json(['data' => $this->safeUser($user)]);
    }

    public function login(): never
    {
        $body = $this->requestJson();
        $username = (string) ($body['username'] ?? '');
        $password = (string) ($body['password'] ?? '');
        $ip = $this->clientIp();
        $throttle = $this->loginThrottle->status($username, $ip);

        if ($throttle['limited']) {
            $this->json(['error' => [
                'code' => 'rate_limited',
                'message' => 'Too many login attempts. Please wait before trying again.',
                'retry_after' => $throttle['retry_after'],
            ]], 429);
        }

        if (!$this->auth->attempt($username, $password)) {
            $throttle = $this->loginThrottle->recordFailure($username, $ip);

            if ($throttle['limited']) {
                $this->json(['error' => [
                    'code' => 'rate_limited',
                    'message' => 'Too many login attempts. Please wait before trying again.',
                    'retry_after' => $throttle['retry_after'],
                ]], 429);
            }

            $this->json(['error' => ['code' => 'invalid_credentials', 'message' => 'Invalid username or password.']], 401);
        }

        $this->loginThrottle->clear($username, $ip);
        $this->json(['data' => $this->safeUser($this->auth->user())], 200, true);
    }

    public function logout(): never
    {
        $user = $this->auth->user();
        $this->verifyCsrf();
        $this->auth->logout();
        $this->logger->info('logout', ['user_id' => $user['id'] ?? null]);
        // Session destroyed; no CSRF token available until next request starts a fresh session.
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['data' => ['ok' => true]], JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function setup(): never
    {
        if ($this->users->hasUsers()) {
            $this->json(['error' => ['code' => 'already_set_up', 'message' => 'CometCMS is already set up.']], 409);
        }

        $body = $this->requestJson();
        $username = trim((string) ($body['username'] ?? 'admin'));
        $password = (string) ($body['password'] ?? '');

        if ($username === '' || strlen($password) < 8) {
            $this->json(['error' => ['code' => 'validation_failed', 'message' => 'Choose a username and a password with at least 8 characters.']], 422);
        }

        // Determine workspace label and slug
        $workspaceInput = trim((string) ($body['workspace'] ?? ''));
        $workspaceLabel = $workspaceInput !== '' ? $workspaceInput : 'Default';
        $slugInput      = trim((string) ($body['workspace_slug'] ?? ''));
        $workspaceSlug  = $slugInput !== '' ? Security::slug($slugInput) : Security::slug($workspaceLabel);

        if ($workspaceSlug === '') {
            $this->json(['error' => ['code' => 'validation_failed', 'message' => 'Invalid workspace slug.']], 422);
        }

        try {
            $this->users->create($username, $password, 'admin');
        } catch (\Throwable $e) {
            $this->json(['error' => ['code' => 'error', 'message' => $e->getMessage()]], 422);
        }

        // Initialise the workspace, replacing any auto-created 'default' entry
        $store    = new SettingsStore();
        $settings = $store->all();
        $now      = Security::now();
        $settings['workspaces'] = [[
            'slug'       => $workspaceSlug,
            'label'      => $workspaceLabel,
            'archived'   => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]];
        $settings['default_workspace'] = $workspaceSlug;
        $store->save($settings);

        $context = new WorkspaceContext($workspaceSlug);
        $context->ensure();
        WorkspaceContext::setActive($workspaceSlug);

        $this->auth->attempt($username, $password);
        $this->json(['data' => $this->safeUser($this->auth->user()), 'meta' => ['workspace' => $workspaceSlug]], 201, true);
    }
}
