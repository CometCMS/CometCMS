<?php

declare(strict_types=1);

namespace CometCMS\Controllers\Admin;

use CometCMS\Core\Http;
use CometCMS\Site\FontFiles;
use CometCMS\Site\FontInstaller;
use CometCMS\Site\FontRegistry;
use CometCMS\Site\Fontsource;

final class FontsController extends BaseController
{
    private FontRegistry $fonts;
    private FontInstaller $installer;

    public function __construct(Http $http)
    {
        parent::__construct($http);
        $this->fonts = FontRegistry::active();
        $this->installer = new FontInstaller($this->fonts);
    }

    private function access(bool $mutating = false): void
    {
        // Fonts belong to the site's global design.
        $this->requirePermission('site.design', ['resource' => 'site:*']);
        if ($mutating) {
            $this->verifyCsrf();
        }
    }

    private function respond(callable $callback): never
    {
        try {
            $this->json(['data' => $callback()]);
        } catch (\InvalidArgumentException $e) {
            $this->json(['error' => ['code' => 'invalid_font', 'message' => $e->getMessage()]], 422);
        } catch (\RuntimeException $e) {
            $this->json(['error' => ['code' => 'font_unavailable', 'message' => $e->getMessage()]], 503);
        }
    }

    public function index(): never
    {
        $this->access();
        $this->respond(fn(): array => $this->fonts->metadata(rtrim($this->http->url('/'), '/')));
    }

    public function catalogue(): never
    {
        $this->access();
        $this->respond(function (): array {
            $provider = new Fontsource();
            $catalogue = $provider->catalogue();
            $query = strtolower(trim((string) ($_GET['q'] ?? '')));
            $category = (string) ($_GET['category'] ?? '');
            $families = array_values(array_filter($catalogue['families'], static fn(array $font): bool => ($query === '' || str_contains(strtolower($font['family']), $query)) && ($category === '' || $font['category'] === $category)));
            $popularity = ($_GET['sort'] ?? 'popular') === 'alphabetical'
                ? ['scores' => [], 'stale' => false]
                : $provider->popularity();
            $scores = $popularity['scores'];
            usort($families, static fn(array $a, array $b): int => (($scores[$b['id']] ?? 0) <=> ($scores[$a['id']] ?? 0)) ?: strcasecmp($a['family'], $b['family']));
            $installed = $this->fonts->families();
            $offset = max(0, (int) ($_GET['offset'] ?? 0));
            $slice = array_slice($families, $offset, 50);
            foreach ($slice as &$font) {
                $font['installed'] = isset($installed['fontsource-' . $font['id']]);
            }
            return ['families' => $slice, 'total' => count($families), 'offset' => $offset, 'stale' => $catalogue['stale'] || $popularity['stale']];
        });
    }

    public function details(string $id): never
    {
        $this->access();
        $this->respond(fn(): array => (new Fontsource())->details($id));
    }

    public function jobs(): never
    {
        $this->access();
        $this->respond(fn(): array => $this->installer->jobs());
    }

    public function install(): never
    {
        $this->access(true);
        $body = $this->requestJson();
        $this->respond(fn(): array => $this->installer->prepare((string) ($body['id'] ?? ''), is_array($body['subsets'] ?? null) ? $body['subsets'] : [], !empty($body['update'])));
    }

    public function step(string $id): never
    {
        $this->access(true);
        $body = $this->requestJson();
        $this->respond(fn(): array => $this->installer->step($id, (int) ($body['cursor'] ?? -1)));
    }

    public function cancel(string $id): never
    {
        $this->access(true);
        $this->respond(fn(): array => $this->installer->cancel($id));
    }

    public function destroy(string $id): never
    {
        $this->access(true);
        $this->respond(function () use ($id): array {
            $this->fonts->delete($id);
            return $this->fonts->metadata(rtrim($this->http->url('/'), '/'));
        });
    }

    private function uploadedFiles(string $field): array
    {
        $files = $_FILES[$field] ?? null;
        if (!is_array($files) || !array_key_exists('name', $files)) {
            return [];
        }
        if (!is_array($files['name'])) {
            return [$files];
        }
        $uploads = [];
        foreach (array_keys($files['name']) as $index) {
            $uploads[] = ['name' => $files['name'][$index] ?? '', 'tmp_name' => $files['tmp_name'][$index] ?? '', 'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE, 'size' => $files['size'][$index] ?? 0];
        }
        return $uploads;
    }

    public function upload(): never
    {
        $this->access(true);
        $this->respond(function (): array {
            if (($_POST['licensed'] ?? '') !== 'true') {
                throw new \InvalidArgumentException('Confirm permission to use these font files.');
            }
            $faces = json_decode((string) ($_POST['faces'] ?? ''), true);
            $files = $this->uploadedFiles('fonts');
            if (!is_array($faces) || count($faces) !== count($files) || count($files) > 100 || $files === []) {
                throw new \InvalidArgumentException('Provide metadata for each uploaded WOFF2 file.');
            }
            $uploads = [];
            $total = 0;
            foreach ($files as $index => $file) {
                if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])
                    || strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'woff2'
                    || $file['size'] > FontFiles::MAX_FILE || ($total += $file['size']) > FontFiles::MAX_INSTALL) {
                    throw new \InvalidArgumentException('Upload valid WOFF2 files, at most 10 MiB each and 100 MiB in total. Check the server upload limit.');
                }
                $uploads[] = ['bytes' => (string) file_get_contents($file['tmp_name']), 'weight' => $faces[$index]['weight'] ?? [], 'style' => $faces[$index]['style'] ?? ''];
            }
            $id = trim((string) ($_POST['family_id'] ?? '')) ?: null;
            $this->installer->upload((string) ($_POST['name'] ?? ''), (string) ($_POST['category'] ?? ''), $uploads, $id);
            return $this->fonts->metadata(rtrim($this->http->url('/'), '/'));
        });
    }

    public function preview(string $id): never
    {
        $mutating = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
        $this->access($mutating);
        if ($mutating) {
            // Library cards request previews in parallel; do not serialise them on the session lock.
            $this->releaseSession();
            $this->respond(function () use ($id): array {
                $this->installer->preview($id);
                return ['url' => $this->http->url('/admin/api/site/fonts/catalogue/' . rawurlencode($id) . '/preview') . '?workspace=' . rawurlencode($this->fonts->workspace->slug())];
            });
        }
        Fontsource::assertId($id);
        $path = $this->fonts->workspace->path('cache') . '/fonts/previews/' . $id . '.woff2';
        if (!is_file($path)) {
            $this->http->notFound();
        }
        $this->http->streamFile($path, 'font/woff2', ['Cache-Control' => 'private, max-age=3600']);
    }
}
