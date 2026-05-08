<?php

declare(strict_types=1);

namespace CometCMS\Controllers\Admin;

use CometCMS\Storage\SettingsStore;
use CometCMS\Webhooks\WebhookDispatcher;

final class WebhooksController extends BaseController
{
    private const WEBHOOK_EVENTS = [
        'content.created',
        'content.updated',
        'content.published',
        'content.unpublished',
        'content.deleted',
        'content.restored',
    ];

    public function index(): never
    {
        $this->requirePermission('webhooks.manage', ['resource' => 'webhooks:*']);
        $store = new SettingsStore();
        $settings = $store->all();
        $settings['webhooks'] ??= comet_config('webhooks', []);
        $this->json(['data' => ['webhooks' => $settings['webhooks']]]);
    }

    public function update(): never
    {
        $this->requirePermission('webhooks.manage', ['resource' => 'webhooks:*']);
        $this->verifyCsrf();
        $body = $this->requestJson();

        $webhooks = [];
        foreach ((array) ($body['webhooks'] ?? []) as $webhook) {
            if (!is_array($webhook)) {
                continue;
            }

            $sanitized = $this->sanitizeWebhook($webhook);
            if ($sanitized === null) {
                continue;
            }

            $webhooks[] = $sanitized;
        }

        $store = new SettingsStore();
        $current = $store->all();
        $current['webhooks'] = $webhooks;
        $store->save($current);

        $this->json(['data' => ['webhooks' => $webhooks]]);
    }

    public function run(): never
    {
        $this->requirePermission('webhooks.manage', ['resource' => 'webhooks:*']);
        $this->verifyCsrf();
        $body = $this->requestJson();

        $webhook = $this->sanitizeWebhook(is_array($body['webhook'] ?? null) ? $body['webhook'] : []);

        if ($webhook === null) {
            $this->json(['error' => ['code' => 'invalid_webhook', 'message' => 'A valid webhook URL is required.']], 422);
        }

        $user = $this->auth->user();
        $ok = (new WebhookDispatcher($this->logger))->sendWebhook($webhook, 'webhook.manual', [
            'manual' => true,
            'triggered_by' => [
                'id' => $user['id'] ?? null,
                'username' => $user['username'] ?? null,
                'role' => $user['role'] ?? null,
            ],
        ]);

        if (!$ok) {
            $this->logger->warning('webhook failed', ['event' => 'webhook.manual', 'url' => $webhook['url']]);
            $this->json(['error' => ['code' => 'webhook_failed', 'message' => 'Webhook request failed or returned a non-2xx response.']], 502);
        }

        $this->json(['data' => ['ok' => true]]);
    }

    private function sanitizeWebhook(array $webhook): ?array
    {
        $url = trim((string) ($webhook['url'] ?? ''));
        $secret = trim((string) ($webhook['secret'] ?? ''));
        $name = trim((string) ($webhook['name'] ?? ''));
        $events = array_values(array_filter(
            array_map('strval', (array) ($webhook['events'] ?? [])),
            fn(string $e): bool => in_array($e, self::WEBHOOK_EVENTS, true),
        ));

        if ($url === '') {
            return null;
        }

        if (!filter_var($url, FILTER_VALIDATE_URL) || (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://'))) {
            return null;
        }

        return ['url' => $url, 'secret' => $secret, 'name' => $name, 'events' => $events, 'enabled' => ($webhook['enabled'] ?? true) !== false];
    }
}
