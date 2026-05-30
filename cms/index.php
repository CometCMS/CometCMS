<?php

declare(strict_types=1);

ob_start();

// ─── Bootstrap helpers ───────────────────────────────────────────────────────

function comet_entry_path(): string
{
    $scriptBase  = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $requestPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

    if ($scriptBase !== '' && $scriptBase !== '/' && str_starts_with($requestPath, $scriptBase)) {
        $requestPath = substr($requestPath, strlen($scriptBase));
    }

    return '/' . ltrim($requestPath, '/');
}

function comet_entry_log(string $title, string $message, array $details = []): void
{
    error_log('CometCMS fatal error: ' . $title . ' - ' . $message . ' ' . json_encode($details, JSON_UNESCAPED_SLASHES));
}

function comet_entry_failure(string $title, string $message, array $details = [], int $status = 500): never
{
    $path    = comet_entry_path();
    $isAdmin = $path === '/admin' || str_starts_with($path, '/admin/');
    $isJson  = str_starts_with($path, '/api/')
        || str_starts_with($path, '/admin/api/')
        || str_starts_with($path, '/mcp/')
        || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

    comet_entry_log($title, $message, $details);

    if (ob_get_level() > 0) ob_clean();
    if (!headers_sent()) http_response_code($status);

    if ($isJson || !$isAdmin) {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => compact('title', 'message', 'details') + ['code' => 'server_error']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $items = implode('', array_map(
        static fn($k, $v) => '<dt>' . $e($k) . '</dt><dd><code>' . $e(is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_SLASHES)) . '</code></dd>',
        array_keys($details),
        $details
    ));

    if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>CometCMS server error</title>'
        . '<style>body{font:15px system-ui,-apple-system,Segoe UI,sans-serif;line-height:1.5;margin:0;background:#f7f7f8;color:#18181b}'
        . 'main{max-width:780px;margin:8vh auto;padding:32px;background:white;border:1px solid #ddd;border-radius:8px}'
        . 'h1{font-size:24px;margin:0 0 12px}p{margin:0 0 20px;color:#3f3f46}dl{display:grid;grid-template-columns:minmax(120px,190px)1fr;gap:10px 16px;margin:22px 0}'
        . 'dt{font-weight:700}dd{margin:0;min-width:0}code{display:inline-block;max-width:100%;overflow:auto;background:#f1f1f3;padding:2px 6px;border-radius:4px}</style></head><body><main>'
        . '<h1>' . $e($title) . '</h1>'
        . '<p>' . $e($message) . '</p>'
        . ($items !== '' ? '<dl>' . $items . '</dl>' : '')
        . '</main></body></html>';
    exit;
}

set_exception_handler(static function (Throwable $ex): void {
    comet_entry_failure('CometCMS could not start', $ex->getMessage(), [
        'type' => $ex::class,
        'file' => $ex->getFile(),
        'line' => $ex->getLine(),
        'path' => comet_entry_path(),
    ]);
});

register_shutdown_function(static function (): void {
    $error      = error_get_last();
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    if (is_array($error) && in_array($error['type'] ?? null, $fatalTypes, true)) {
        comet_entry_failure('CometCMS stopped unexpectedly', (string) ($error['message'] ?? 'A fatal PHP error occurred.'), [
            'file' => $error['file'] ?? '',
            'line' => $error['line'] ?? '',
            'path' => comet_entry_path(),
        ]);
    }
});

// ─── Imports ─────────────────────────────────────────────────────────────────

use CometCMS\Controllers\Admin\{
    AuthController,
    BackupsController,
    ContentController,
    ContentTypesController,
    DashboardController,
    MediaController,
    RolesController,
    TokensController,
    TrashController,
    UsersController,
    WebhooksController,
    WorkspacesController,
};
use CometCMS\Controllers\{AdminController, ApiController};
use CometCMS\Core\Http;
use CometCMS\Logging\Logger;
use CometCMS\Mcp\McpController;

// ─── Bootstrap ───────────────────────────────────────────────────────────────

try {
    require __DIR__ . '/app/bootstrap.php';
} catch (Throwable $e) {
    comet_entry_failure('CometCMS bootstrap failed', $e->getMessage(), [
        'type' => $e::class,
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'path' => comet_entry_path(),
    ]);
}

// ─── Route dispatch ──────────────────────────────────────────────────────────

$http   = new Http();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = $http->path();

// Segment pattern aliases for route table readability
const SEG = '[A-Za-z0-9_-]+';

/**
 * Route table: [METHOD, pattern, handler]
 * Handler is either a callable or [ControllerClass, method, ...extraArgs].
 * Patterns are matched against $path; named groups become positional args.
 */
$routes = [
    // Root redirect
    ['GET', '#^/$#', static fn() => $http->redirect($http->url('/admin'))],

    // ── Auth ──────────────────────────────────────────────────────────────
    ['GET',  '#^/admin/api/me$#',     [AuthController::class, 'me']],
    ['POST', '#^/admin/api/login$#',  [AuthController::class, 'login']],
    ['POST', '#^/admin/api/logout$#', [AuthController::class, 'logout']],
    ['POST', '#^/admin/api/setup$#',  [AuthController::class, 'setup']],

    // ── Workspaces ────────────────────────────────────────────────────────
    ['GET',    '#^/admin/api/workspaces$#',                                    [WorkspacesController::class, 'index']],
    ['POST',   '#^/admin/api/workspaces$#',                                    [WorkspacesController::class, 'store']],
    ['PUT',    '#^/admin/api/workspaces/(' . SEG . ')$#',                      [WorkspacesController::class, 'update']],
    ['DELETE', '#^/admin/api/workspaces/(' . SEG . ')$#',                      [WorkspacesController::class, 'archive']],
    ['POST',   '#^/admin/api/workspaces/(' . SEG . ')/default$#',              [WorkspacesController::class, 'setDefault']],
    ['GET',    '#^/admin/api/workspaces/(' . SEG . ')/icon$#',                 [WorkspacesController::class, 'iconServe']],
    ['POST',   '#^/admin/api/workspaces/(' . SEG . ')/icon$#',                 [WorkspacesController::class, 'iconUpload']],
    ['DELETE', '#^/admin/api/workspaces/(' . SEG . ')/icon$#',                 [WorkspacesController::class, 'iconDelete']],

    // ── Dashboard ─────────────────────────────────────────────────────────
    ['GET',  '#^/admin/api/app$#',            [DashboardController::class, 'appInfo']],
    ['GET',  '#^/admin/api/update$#',         [DashboardController::class, 'updateStatus']],
    ['POST', '#^/admin/api/update/check$#',   [DashboardController::class, 'updateCheck']],
    ['POST', '#^/admin/api/update/download$#', [DashboardController::class, 'updateDownload']],
    ['POST', '#^/admin/api/update/install$#', [DashboardController::class, 'updateInstall']],
    ['GET',  '#^/admin/api/dashboard$#',      [DashboardController::class, 'dashboard']],
    ['GET',  '#^/admin/api/activity$#',       [DashboardController::class, 'activityLog']],

    // ── Content types ─────────────────────────────────────────────────────
    ['GET',   '#^/admin/api/content-types$#',                       [ContentTypesController::class, 'index']],
    ['POST',  '#^/admin/api/content-types$#',                       [ContentTypesController::class, 'store']],
    ['PATCH', '#^/admin/api/content-types/order$#',                 [ContentTypesController::class, 'reorder']],
    ['GET',   '#^/admin/api/content-types/(' . SEG . ')$#',         [ContentTypesController::class, 'show']],
    ['PUT',   '#^/admin/api/content-types/(' . SEG . ')$#',         [ContentTypesController::class, 'update']],
    ['DELETE', '#^/admin/api/content-types/(' . SEG . ')$#',         [ContentTypesController::class, 'destroy']],

    // ── Content ───────────────────────────────────────────────────────────
    ['GET',    '#^/admin/api/content/(' . SEG . ')$#',                                                              [ContentController::class, 'index']],
    ['POST',   '#^/admin/api/content/(' . SEG . ')$#',                                                              [ContentController::class, 'store']],
    ['PATCH',  '#^/admin/api/content/(' . SEG . ')/bulk$#',                                                         [ContentController::class, 'bulkUpdate']],
    ['DELETE', '#^/admin/api/content/(' . SEG . ')/bulk$#',                                                         [ContentController::class, 'bulkDelete']],
    ['GET',    '#^/admin/api/content/(' . SEG . ')/(' . SEG . ')$#',                                                [ContentController::class, 'show']],
    ['PUT',    '#^/admin/api/content/(' . SEG . ')/(' . SEG . ')$#',                                                [ContentController::class, 'update']],
    ['DELETE', '#^/admin/api/content/(' . SEG . ')/(' . SEG . ')$#',                                                [ContentController::class, 'destroy']],
    ['POST',   '#^/admin/api/content/(' . SEG . ')/(' . SEG . ')/duplicate$#',                                      [ContentController::class, 'duplicate']],
    ['GET',    '#^/admin/api/content/(' . SEG . ')/(' . SEG . ')/revisions$#',                                      [ContentController::class, 'revisions']],
    ['POST',   '#^/admin/api/content/(' . SEG . ')/(' . SEG . ')/revisions/(' . SEG . ')/restore$#',               [ContentController::class, 'revisionRestore']],
    ['DELETE', '#^/admin/api/content/(' . SEG . ')/(' . SEG . ')/translations/(' . SEG . ')$#',                    [ContentController::class, 'destroyTranslation']],

    // ── Trash ─────────────────────────────────────────────────────────────
    ['GET',    '#^/admin/api/trash/(' . SEG . ')$#',                             [TrashController::class, 'index']],
    ['DELETE', '#^/admin/api/trash/(' . SEG . ')$#',                             [TrashController::class, 'empty']],
    ['POST',   '#^/admin/api/trash/(' . SEG . ')/(' . SEG . ')/restore$#',      [TrashController::class, 'restore']],
    ['DELETE', '#^/admin/api/trash/(' . SEG . ')/(' . SEG . ')$#',              [TrashController::class, 'purge']],

    // ── Media ─────────────────────────────────────────────────────────────
    ['GET',  '#^/admin/api/media$#',                            [MediaController::class, 'index']],
    ['POST', '#^/admin/api/media$#',                            [MediaController::class, 'store']],
    ['GET',  '#^/admin/api/media/usages$#',                     [MediaController::class, 'usages']],
    ['POST', '#^/admin/api/media/thumbnails/regenerate$#',      [MediaController::class, 'regenerateThumbnails']],
    ['POST', '#^/admin/api/media/categories$#',                 [MediaController::class, 'categoryStore']],
    ['PUT',  '#^/admin/api/media/categories/(.+)$#',            [MediaController::class, 'categoryRename']],
    ['DELETE', '#^/admin/api/media/categories/(.+)$#',           [MediaController::class, 'categoryDelete']],
    ['PUT',  '#^/admin/api/media/bulk-category$#',              [MediaController::class, 'bulkCategoryUpdate']],
    ['POST', '#^/admin/api/media/bulk-delete$#',                [MediaController::class, 'bulkDelete']],
    ['PUT',  '#^/admin/api/media/bulk-visibility$#',            [MediaController::class, 'bulkUpdateVisibility']],
    [['PUT', 'PATCH'], '#^/admin/api/media/(.+)/category$#',     [MediaController::class, 'categoryUpdate']],
    [['PUT', 'PATCH'], '#^/admin/api/media/(.+)/meta$#',         [MediaController::class, 'updateMeta']],
    [['PUT', 'PATCH'], '#^/admin/api/media/(.+)/visibility$#',   [MediaController::class, 'updateVisibility']],
    ['PUT',  '#^/admin/api/media/(.+)/rename$#',                [MediaController::class, 'rename']],
    ['DELETE', '#^/admin/api/media/(.+)$#',                      [MediaController::class, 'destroy']],

    // ── Users ─────────────────────────────────────────────────────────────
    ['GET',    '#^/admin/api/users$#',                              [UsersController::class, 'index']],
    ['POST',   '#^/admin/api/users$#',                              [UsersController::class, 'store']],
    ['GET',    '#^/admin/api/users/(' . SEG . ')$#',               [UsersController::class, 'show']],
    ['PUT',    '#^/admin/api/users/(' . SEG . ')$#',               [UsersController::class, 'update']],
    ['DELETE', '#^/admin/api/users/(' . SEG . ')$#',               [UsersController::class, 'destroy']],

    // ── Tokens ────────────────────────────────────────────────────────────
    ['GET',    '#^/admin/api/tokens$#',                              [TokensController::class, 'index']],
    ['POST',   '#^/admin/api/tokens$#',                              [TokensController::class, 'store']],
    ['DELETE', '#^/admin/api/tokens/(' . SEG . ')$#',               [TokensController::class, 'destroy']],

    // ── Roles ─────────────────────────────────────────────────────────────
    ['GET',    '#^/admin/api/roles$#',                              [RolesController::class, 'index']],
    ['POST',   '#^/admin/api/roles$#',                              [RolesController::class, 'store']],
    ['PUT',    '#^/admin/api/roles/(' . SEG . ')$#',               [RolesController::class, 'update']],
    ['DELETE', '#^/admin/api/roles/(' . SEG . ')$#',               [RolesController::class, 'destroy']],

    // ── Backups ───────────────────────────────────────────────────────────
    ['GET',  '#^/admin/api/backups$#',                                              [BackupsController::class, 'index']],
    ['POST', '#^/admin/api/backups$#',                                              [BackupsController::class, 'store']],
    ['POST', '#^/admin/api/backups/upload$#',                                       [BackupsController::class, 'upload']],
    ['GET',  '#^/admin/api/backups/([A-Za-z0-9_.-]+\.zip)/inspect$#',              [BackupsController::class, 'inspect']],
    ['POST', '#^/admin/api/backups/([A-Za-z0-9_.-]+\.zip)/restore$#',              [BackupsController::class, 'restore']],
    ['PUT',  '#^/admin/api/backups/([A-Za-z0-9_.-]+\.zip)/note$#',                 [BackupsController::class, 'note']],
    ['GET',  '#^/admin/api/backups/([A-Za-z0-9_.-]+\.zip)/download$#',             [BackupsController::class, 'download']],
    ['DELETE', '#^/admin/api/backups/([A-Za-z0-9_.-]+\.zip)$#',                     [BackupsController::class, 'destroy']],

    // ── Webhooks ──────────────────────────────────────────────────────────
    ['GET',  '#^/admin/api/webhooks$#',         [WebhooksController::class, 'index']],
    ['PUT',  '#^/admin/api/webhooks$#',         [WebhooksController::class, 'update']],
    ['POST', '#^/admin/api/webhooks/run$#',     [WebhooksController::class, 'run']],

    // ── Profile & avatar ──────────────────────────────────────────────────
    ['POST',   '#^/admin/api/profile/avatar$#', [UsersController::class, 'avatarUpload']],
    ['DELETE', '#^/admin/api/profile/avatar$#', [UsersController::class, 'avatarDelete']],
    ['PUT',    '#^/admin/api/profile$#',        [UsersController::class, 'profileUpdate']],
    ['GET',    '#^/admin/api/users/(' . SEG . ')/avatar$#', [UsersController::class, 'avatarServe']],
];

/**
 * Dispatch: iterate the route table, instantiate the controller lazily,
 * call the handler with any regex capture groups as arguments.
 */
try {
    foreach ($routes as [$routeMethod, $pattern, $handler]) {
        $methods = (array) $routeMethod;
        if (!in_array($method, $methods, true)) continue;
        if (!preg_match($pattern, $path, $m)) continue;

        $args = array_slice($m, 1); // capture groups → method args

        if (is_callable($handler)) {
            $handler(...$args);
        } else {
            [$class, $action] = $handler;
            (new $class($http))->{$action}(...$args);
        }

        // A matched route either exits or returns — if we reach here, keep going
        // (allows fall-through to SPA shell for /admin/*)
        break;
    }

    // ── Admin SPA shell ───────────────────────────────────────────────────
    if ($path === '/admin' || str_starts_with($path, '/admin/')) {
        (new AdminController($http))->shell();
    }

    // ── Embedded MCP endpoint (workspace-scoped) ──────────────────────────
    if (preg_match('#^/mcp/v1/workspaces/(' . SEG . ')$#', $path, $m)) {
        (new McpController($http))->handle($m[1]);
    }

    // ── Public API v1 (workspace-scoped) ──────────────────────────────────
    $api = new ApiController($http);

    if (preg_match('#^/api/v1/workspaces/(' . SEG . ')(/.*)?$#', $path, $m)) {
        $workspaceSlug = $m[1];
        $routePath     = $m[2] ?? '';

        $api->useWorkspace($workspaceSlug, false, true);

        match (true) {
            $routePath === '/health'  && $method === 'GET'  => $api->health(),

            $routePath === '/content-types' && $method === 'GET'  => $api->contentTypes(),
            $routePath === '/content-types' && $method === 'POST' => $api->contentTypeStore(),
            (bool) preg_match('#^/content-types/(' . SEG . ')$#', $routePath, $m) && $method === 'GET'    => $api->contentTypeShow($m[1]),
            (bool) preg_match('#^/content-types/(' . SEG . ')$#', $routePath, $m) && $method === 'PUT'    => $api->contentTypeUpdate($m[1]),
            (bool) preg_match('#^/content-types/(' . SEG . ')$#', $routePath, $m) && $method === 'DELETE' => $api->contentTypeDelete($m[1]),

            (bool) preg_match('#^/content/(' . SEG . ')$#', $routePath, $m)                                            && $method === 'GET'                             => $api->contentIndex($m[1]),
            (bool) preg_match('#^/content/(' . SEG . ')$#', $routePath, $m)                                            && $method === 'POST'                            => $api->contentStore($m[1]),
            (bool) preg_match('#^/content/(' . SEG . ')/(' . SEG . ')$#', $routePath, $m)                              && $method === 'GET'                             => $api->contentShow($m[1], $m[2]),
            (bool) preg_match('#^/content/(' . SEG . ')/(' . SEG . ')$#', $routePath, $m)                              && in_array($method, ['PUT', 'PATCH'], true)     => $api->contentUpdate($m[1], $m[2]),
            (bool) preg_match('#^/content/(' . SEG . ')/(' . SEG . ')$#', $routePath, $m)                              && $method === 'DELETE'                          => $api->contentDelete($m[1], $m[2]),

            $routePath === '/media' && $method === 'GET'   => $api->mediaIndex(),
            $routePath === '/media' && $method === 'POST'  => $api->mediaStore(),
            $routePath === '/media/categories' && $method === 'POST'                                              => $api->mediaCategoryStore(),
            (bool) preg_match('#^/media/categories/(.+)$#', $routePath, $m) && in_array($method, ['PUT', 'PATCH'], true) => $api->mediaCategoryRename($m[1]),
            (bool) preg_match('#^/media/categories/(.+)$#', $routePath, $m) && $method === 'DELETE'              => $api->mediaCategoryDelete($m[1]),
            (bool) preg_match('#^/media/(.+)/category$#',   $routePath, $m) && in_array($method, ['PUT', 'PATCH'], true) => $api->mediaCategoryUpdate($m[1]),
            (bool) preg_match('#^/media/(.+)/meta$#',       $routePath, $m) && in_array($method, ['PUT', 'PATCH'], true) => $api->mediaUpdateMeta($m[1]),
            (bool) preg_match('#^/media/(.+)/visibility$#', $routePath, $m) && in_array($method, ['PUT', 'PATCH'], true) => $api->mediaUpdateVisibility($m[1]),
            $routePath === '/media/bulk-visibility' && $method === 'PUT'                                          => $api->mediaBulkUpdateVisibility(),
            (bool) preg_match('#^/media/(.+)$#',            $routePath, $m) && $method === 'DELETE'              => $api->mediaDelete($m[1]),

            default => null,
        };
    } elseif (str_starts_with($path, '/api/v1/')) {
        $http->json(['error' => ['code' => 'workspace_required', 'message' => 'Workspace scope is required. Use /api/v1/workspaces/{slug}/...']], 400);
    }

    // ── Workspace-scoped media file serving ───────────────────────────────
    if (preg_match('#^/media-thumbs/(' . SEG . ')/(.+)$#', $path, $m) && $method === 'GET') {
        $api->useWorkspace($m[1], true);
        $api->mediaThumbShow($m[2]);
    }

    if (preg_match('#^/media/(' . SEG . ')/(.+)$#', $path, $m) && $method === 'GET') {
        $api->useWorkspace($m[1], true);
        $api->mediaShow($m[2]);
    }

    $http->notFound();
} catch (Throwable $e) {
    $details = [
        'message' => $e->getMessage(),
        'type' => $e::class,
        'file'    => $e->getFile(),
        'line' => $e->getLine(),
        'path'    => $path,
        'method' => $method,
    ];

    try {
        (new Logger())->error('server_error', $details);
    } catch (Throwable $logError) {
        comet_entry_log('CometCMS log write failed', $logError->getMessage(), [
            'type' => $logError::class,
            'file' => $logError->getFile(),
            'line' => $logError->getLine(),
            'original' => $details,
        ]);
    }

    $debug   = (bool) comet_config('app.debug', false);
    $message = $debug ? $e->getMessage() : 'An internal server error occurred.';
    comet_entry_failure('CometCMS server error', $message, $debug ? $details : [
        'path' => $path,
        'method' => $method,
        'hint' => 'Enable app.debug in config/config.php or check the PHP error log for details.',
    ]);
}
