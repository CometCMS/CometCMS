<?php

declare(strict_types=1);

namespace CometCMS\Controllers\Admin;

use CometCMS\Content\ContentRepository;
use CometCMS\Content\ContentTypeRepository;
use CometCMS\Core\Http;
use CometCMS\Updates\UpdateService;

final class DashboardController extends BaseController
{
    private ContentRepository $content;
    private ContentTypeRepository $types;

    public function __construct(Http $http)
    {
        parent::__construct($http);
        $this->content = ContentRepository::make();
        $this->types = new ContentTypeRepository();
    }

    public function appInfo(): never
    {
        $this->requirePermission('dashboard.read', ['resource' => 'dashboard:*']);
        $this->json(['data' => [
            'name' => comet_config('app.name', 'CometCMS'),
            'version' => comet_version(),
        ]]);
    }

    public function updateStatus(): never
    {
        $this->requirePermission('updates.read', ['resource' => 'updates:*']);
        $this->json(['data' => (new UpdateService())->status()]);
    }

    public function updateCheck(): never
    {
        $this->requirePermission('updates.check', ['resource' => 'updates:*']);
        $this->verifyCsrf();
        $this->json(['data' => (new UpdateService())->status(true)]);
    }

    public function updateDownload(): never
    {
        $user = $this->requirePermission('updates.download', ['resource' => 'updates:*']);
        $this->verifyCsrf();

        try {
            $result = (new UpdateService())->downloadLatest($user);
        } catch (\Throwable $e) {
            $this->json(['error' => ['code' => 'update_download_failed', 'message' => $e->getMessage()]], 500);
        }

        $this->json(['data' => $result]);
    }

    public function updateInstall(): never
    {
        $user = $this->requirePermission('updates.install', ['resource' => 'updates:*']);
        $this->verifyCsrf();
        $body = $this->requestJson();
        $stageId = isset($body['stage_id']) ? (string) $body['stage_id'] : null;

        try {
            $result = (new UpdateService())->installStaged($user, $stageId);
        } catch (\Throwable $e) {
            $this->json(['error' => ['code' => 'update_failed', 'message' => $e->getMessage()]], 500);
        }

        $this->json(['data' => $result]);
    }

    public function dashboard(): never
    {
        $this->requirePermission('dashboard.read', ['resource' => 'dashboard:*']);
        $collections = $this->content->collections();
        $count = 0;

        foreach ($collections as $collection) {
            $count += count($this->content->all($collection));
        }

        $this->json(['data' => [
            'collections' => count($collections),
            'entries' => $count,
            'content_types' => count($this->types->all()),
        ]]);
    }

    public function activityLog(): never
    {
        $this->requirePermission('activity.read', ['resource' => 'activity:*']);

        $logPath = COMET_STORAGE . '/logs/comet.log';
        $limit     = max(1, min(100, (int) ($_GET['limit'] ?? 25)));
        $offset    = max(0, (int) ($_GET['offset'] ?? 0));
        $level     = isset($_GET['level'])     && $_GET['level']     !== '' ? (string) $_GET['level']     : null;
        $type      = isset($_GET['type'])      && $_GET['type']      !== '' ? (string) $_GET['type']      : null;
        $workspace = isset($_GET['workspace']) && $_GET['workspace'] !== '' ? (string) $_GET['workspace'] : null;

        $typePrefixes = [
            'content' => ['content.'],
            'media'   => ['media.'],
            'user'    => ['user.', 'token.'],
            'auth'    => ['login', 'logout', 'failed login'],
            'schema'  => ['content_type.'],
            'system'  => ['backup', 'update', 'webhook'],
        ];

        $lines = [];

        if (is_file($logPath)) {
            $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $lines = array_reverse($lines);
        }

        $entries = [];

        foreach ($lines as $line) {
            $entry = json_decode($line, true);

            if (!is_array($entry)) {
                continue;
            }

            if ($level !== null && ($entry['level'] ?? '') !== $level) {
                continue;
            }

            if ($type !== null && isset($typePrefixes[$type])) {
                $msg = (string) ($entry['message'] ?? '');
                $matched = false;

                foreach ($typePrefixes[$type] as $prefix) {
                    if (str_starts_with($msg, $prefix)) {
                        $matched = true;
                        break;
                    }
                }

                if (!$matched) {
                    continue;
                }
            }

            if ($workspace !== null && ($entry['context']['workspace'] ?? '') !== $workspace) {
                continue;
            }

            $entries[] = $entry;
        }

        $total = count($entries);
        $page  = array_slice($entries, $offset, $limit);

        $this->json(['data' => $page, 'meta' => ['total' => $total, 'limit' => $limit, 'offset' => $offset]]);
    }
}
