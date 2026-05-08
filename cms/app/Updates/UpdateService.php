<?php

declare(strict_types=1);

namespace CometCMS\Updates;

use CometCMS\Logging\Logger;

final class UpdateService
{
    private const LOCK_FILE = 'update.lock';

    public function status(bool $checkLatest = false): array
    {
        $config = $this->config();
        $status = [
            'current_version' => $this->currentVersion(),
            'repository_url' => $config['repository_url'],
            'releases_api_url' => $config['releases_api_url'],
            'enabled' => $config['enabled'],
            'preserved_paths' => $config['preserved_paths'],
            'latest' => null,
            'staged_update' => $this->latestStagedUpdate(),
            'update_available' => false,
            'message' => $config['enabled'] ? null : 'Update checks are disabled in config/config.php.',
        ];

        if (!$checkLatest || !$config['enabled']) {
            return $status;
        }

        try {
            $latest = $this->latestRelease();
            $status['latest'] = $latest;
            $status['update_available'] = $this->isNewer((string) $latest['version'], (string) $status['current_version']);
            $status['message'] = $status['update_available'] ? 'A newer CometCMS release is available.' : 'CometCMS is up to date.';
        } catch (\Throwable $e) {
            $status['message'] = $e->getMessage();
        }

        return $status;
    }

    public function latestRelease(): array
    {
        $config = $this->config();

        if (!$config['enabled']) {
            throw new \RuntimeException('Update checks are disabled.');
        }

        $json = $this->httpGet((string) $config['releases_api_url']);
        $data = json_decode($json, true);

        if (!is_array($data) || !isset($data['tag_name'])) {
            throw new \RuntimeException('GitHub did not return a release.');
        }

        $assets = is_array($data['assets'] ?? null) ? $data['assets'] : [];
        $asset = $this->selectAsset($assets, (string) $config['release_asset_pattern']);
        $checksumAsset = $this->selectAsset($assets, (string) $config['checksum_asset_pattern']);

        return [
            'version' => ltrim((string) $data['tag_name'], "vV \t\n\r\0\x0B"),
            'tag' => (string) $data['tag_name'],
            'name' => (string) ($data['name'] ?? $data['tag_name']),
            'published_at' => $data['published_at'] ?? null,
            'url' => (string) ($data['html_url'] ?? $config['repository_url']),
            'changelog' => trim((string) ($data['body'] ?? '')),
            'asset' => $asset,
            'checksum_asset' => $checksumAsset,
        ];
    }

    public function downloadLatest(array $user): array
    {
        return $this->withLock(function () use ($user): array {
            $this->assertCanStage();

            $latest = $this->latestRelease();
            $asset = is_array($latest['asset'] ?? null) ? $latest['asset'] : null;
            $checksumAsset = is_array($latest['checksum_asset'] ?? null) ? $latest['checksum_asset'] : null;
            $downloadUrl = (string) ($asset['download_url'] ?? '');

            if (!$this->isNewer((string) $latest['version'], comet_version())) {
                throw new \RuntimeException('No newer release is available.');
            }

            if ($downloadUrl === '') {
                throw new \RuntimeException('The latest release does not include an installable ZIP asset.');
            }

            if ($this->config()['require_checksum'] && (string) ($checksumAsset['download_url'] ?? '') === '') {
                throw new \RuntimeException('The latest release does not include a checksum asset.');
            }

            $stageId = 'update-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
            $workDir = COMET_STORAGE . '/updates/' . $stageId;
            $zipPath = $workDir . '/release.zip';
            $extractDir = $workDir . '/package';

            if (!is_dir($workDir)) {
                mkdir($workDir, 0775, true);
            }

            file_put_contents($zipPath, $this->httpGet($downloadUrl, 'application/octet-stream'), LOCK_EX);

            $checksum = null;
            if (is_array($checksumAsset) && (string) ($checksumAsset['download_url'] ?? '') !== '') {
                $checksum = $this->expectedChecksum(
                    $this->httpGet((string) $checksumAsset['download_url'], 'text/plain'),
                    (string) ($asset['name'] ?? '')
                );
                $actual = hash_file('sha256', $zipPath);

                if (!hash_equals($checksum, $actual)) {
                    throw new \RuntimeException('Downloaded update ZIP did not match the release checksum.');
                }
            }

            $packageRoot = $this->extractPackage($zipPath, $extractDir);
            $packageVersion = $this->packageVersion($packageRoot);

            if (!$this->isNewer($packageVersion, comet_version())) {
                throw new \RuntimeException('The downloaded package is not newer than the installed version.');
            }

            if ($packageVersion !== (string) $latest['version']) {
                throw new \RuntimeException('The downloaded package version does not match the GitHub release.');
            }

            $metadata = [
                'id' => $stageId,
                'version' => $packageVersion,
                'tag' => $latest['tag'] ?? null,
                'name' => $latest['name'] ?? null,
                'url' => $latest['url'] ?? null,
                'asset_name' => (string) ($asset['name'] ?? 'release.zip'),
                'asset_size' => (int) ($asset['size'] ?? filesize($zipPath)),
                'checksum_sha256' => $checksum ?? hash_file('sha256', $zipPath),
                'package_root' => $this->relativeToUpdates($packageRoot),
                'downloaded_at' => gmdate(DATE_ATOM),
            ];

            $this->writeJson($workDir . '/update.json', $metadata);

            (new Logger())->info('update downloaded', [
                'version' => $packageVersion,
                'stage_id' => $stageId,
                'user_id' => $user['id'] ?? null,
            ]);

            return $metadata;
        });
    }

    public function installStaged(array $user, ?string $stageId = null): array
    {
        return $this->withLock(function () use ($user, $stageId): array {
            $metadata = $this->stagedUpdate($stageId);

            if ($metadata === null) {
                throw new \RuntimeException('Download an update before installing it.');
            }

            $packageRoot = COMET_STORAGE . '/updates/' . (string) $metadata['package_root'];

            if (!$this->isPackageRoot($packageRoot)) {
                throw new \RuntimeException('The staged update package is no longer valid.');
            }

            $packageVersion = $this->packageVersion($packageRoot);

            if ($packageVersion !== (string) $metadata['version']) {
                throw new \RuntimeException('The staged update package version changed unexpectedly.');
            }

            if (!$this->isNewer($packageVersion, comet_version())) {
                throw new \RuntimeException('The staged update is not newer than the installed version.');
            }

            $this->assertWritableInstallTargets($packageRoot);
            $backup = $this->backupInstallTargets((string) $metadata['id'], $packageRoot);
            $installed = $this->replacePackage($packageRoot, COMET_ROOT, (array) $this->config()['preserved_paths']);

            (new Logger())->info('update installed', [
                'version' => $metadata['version'] ?? null,
                'stage_id' => $metadata['id'] ?? null,
                'installed_items' => $installed,
                'backup' => $backup,
                'user_id' => $user['id'] ?? null,
            ]);

            $metadata['installed_at'] = gmdate(DATE_ATOM);
            $metadata['backup'] = $backup;
            $this->writeJson(COMET_STORAGE . '/updates/' . (string) $metadata['id'] . '/update.json', $metadata);

            return [
                'installed_version' => $metadata['version'] ?? null,
                'installed_items' => $installed,
                'backup' => $backup,
                'preserved_paths' => $this->config()['preserved_paths'],
            ];
        });
    }

    private function config(): array
    {
        $repositoryUrl = rtrim((string) comet_config('updates.repository_url', 'https://github.com/CometCMS/CometCMS'), '/');
        $apiUrl = (string) comet_config('updates.releases_api_url', '');

        if ($apiUrl === '') {
            $apiUrl = $this->githubApiUrl($repositoryUrl);
        }

        $preserved = comet_config('updates.preserved_paths', ['storage']);

        return [
            'enabled' => (bool) comet_config('updates.enabled', true),
            'repository_url' => $repositoryUrl,
            'releases_api_url' => $apiUrl,
            'release_asset_pattern' => (string) comet_config('updates.release_asset_pattern', '/cometcms.*\.zip$/i'),
            'checksum_asset_pattern' => (string) comet_config('updates.checksum_asset_pattern', '/cometcms.*\.zip\.sha256$/i'),
            'require_checksum' => (bool) comet_config('updates.require_checksum', true),
            'preserved_paths' => is_array($preserved) ? array_values(array_map('strval', $preserved)) : ['storage'],
        ];
    }

    private function githubApiUrl(string $repositoryUrl): string
    {
        $path = (string) parse_url($repositoryUrl, PHP_URL_PATH);
        $path = trim($path, '/');

        if ($path === '' || substr_count($path, '/') < 1) {
            return '';
        }

        [$owner, $repo] = explode('/', $path, 3);

        return 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode(preg_replace('/\.git$/', '', $repo)) . '/releases/latest';
    }

    private function httpGet(string $url, string $accept = 'application/vnd.github+json'): string
    {
        if ($url === '') {
            throw new \RuntimeException('No GitHub releases API URL is configured.');
        }

        $config = $this->config();
        $headers = [
            'Accept: ' . $accept,
            'User-Agent: CometCMS-Updater/' . comet_version(),
        ];

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $status = $this->responseStatus($http_response_header ?? []);

        if ($body === false || $status >= 400) {
            if ($status === 404) {
                throw new \RuntimeException('No public GitHub release was found for the configured repository.');
            }

            throw new \RuntimeException('Could not download release information or assets.');
        }

        return $body;
    }

    private function assertCanStage(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is required to download updates.');
        }

        if (!is_dir(COMET_STORAGE . '/updates') && !mkdir(COMET_STORAGE . '/updates', 0775, true)) {
            throw new \RuntimeException('Could not create the update storage folder.');
        }

        if (!is_writable(COMET_STORAGE . '/updates')) {
            throw new \RuntimeException('The update storage folder is not writable.');
        }
    }

    private function extractPackage(string $zipPath, string $extractDir): string
    {
        $zip = new \ZipArchive();

        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Could not open update ZIP.');
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));

            if ($this->unsafePath($name)) {
                $zip->close();
                throw new \RuntimeException('Update ZIP contains an unsafe path.');
            }

            if (str_ends_with($name, '/')) {
                continue;
            }

            $target = $extractDir . '/' . $name;

            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0775, true);
            }

            copy('zip://' . $zipPath . '#' . $name, $target);
        }

        $zip->close();

        return $this->packageRoot($extractDir);
    }

    private function packageRoot(string $extractDir): string
    {
        if ($this->isPackageRoot($extractDir)) {
            return $extractDir;
        }

        foreach (glob($extractDir . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            if ($this->isPackageRoot($directory)) {
                return $directory;
            }
        }

        throw new \RuntimeException('Update ZIP is not a built CometCMS package.');
    }

    private function isPackageRoot(string $directory): bool
    {
        return is_file($directory . '/index.php')
            && is_dir($directory . '/app')
            && is_dir($directory . '/admin')
            && is_file($directory . '/app/version.php');
    }

    private function packageVersion(string $packageRoot): string
    {
        $version = require $packageRoot . '/app/version.php';

        if (!is_string($version) || $version === '') {
            throw new \RuntimeException('Update package does not contain a valid version.');
        }

        return $version;
    }

    private function latestStagedUpdate(): ?array
    {
        return $this->stagedUpdate(null);
    }

    private function stagedUpdate(?string $stageId): ?array
    {
        $files = [];

        if ($stageId !== null && $stageId !== '') {
            $safeId = basename($stageId);
            $files[] = COMET_STORAGE . '/updates/' . $safeId . '/update.json';
        } else {
            $files = glob(COMET_STORAGE . '/updates/update-*/update.json') ?: [];
            rsort($files);
        }

        foreach ($files as $file) {
            $data = json_decode((string) @file_get_contents($file), true);

            if (!is_array($data) || isset($data['installed_at'])) {
                continue;
            }

            if (!isset($data['id'], $data['version'], $data['package_root'])) {
                continue;
            }

            return $data;
        }

        return null;
    }

    private function replacePackage(string $source, string $targetRoot, array $preservedPaths): int
    {
        $installed = 0;

        foreach (scandir($source) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $relative = $name;

            if ($this->isPreserved($relative, $preservedPaths)) {
                continue;
            }

            $sourcePath = $source . '/' . $name;
            $targetPath = $targetRoot . '/' . $name;

            if (is_dir($sourcePath)) {
                if (is_dir($targetPath) && !$this->isPartiallyPreserved($relative, $preservedPaths)) {
                    $this->removePath($targetPath);
                }

                $installed += $this->copyDirectory($sourcePath, $targetPath, $relative, $preservedPaths);
                continue;
            }

            if (!is_dir(dirname($targetPath))) {
                mkdir(dirname($targetPath), 0775, true);
            }

            if (!copy($sourcePath, $targetPath)) {
                throw new \RuntimeException('Could not install update file: ' . $relative);
            }

            $installed++;
        }

        return $installed;
    }

    private function copyDirectory(string $source, string $target, string $baseRelative, array $preservedPaths): int
    {
        $copied = 0;

        if (!is_dir($target)) {
            mkdir($target, 0775, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            $relativeInside = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($source))), '/');
            $relative = $baseRelative . ($relativeInside === '' ? '' : '/' . $relativeInside);

            if ($this->isPreserved($relative, $preservedPaths)) {
                continue;
            }

            $targetPath = $target . ($relativeInside === '' ? '' : '/' . $relativeInside);

            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0775, true);
                }
                continue;
            }

            if (!is_dir(dirname($targetPath))) {
                mkdir(dirname($targetPath), 0775, true);
            }

            if (!copy($item->getPathname(), $targetPath)) {
                throw new \RuntimeException('Could not install update file: ' . $relative);
            }

            $copied++;
        }

        return $copied;
    }

    private function backupInstallTargets(string $stageId, string $packageRoot): ?string
    {
        if (!class_exists(\ZipArchive::class)) {
            return null;
        }

        $backupPath = COMET_STORAGE . '/updates/' . $stageId . '/backup-before-install.zip';
        $zip = new \ZipArchive();

        if ($zip->open($backupPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return null;
        }

        foreach (scandir($packageRoot) ?: [] as $name) {
            if ($name === '.' || $name === '..' || $this->isPreserved($name, (array) $this->config()['preserved_paths'])) {
                continue;
            }

            $target = COMET_ROOT . '/' . $name;

            if (is_file($target)) {
                $zip->addFile($target, $name);
                continue;
            }

            if (is_dir($target)) {
                $this->addDirectoryToZip($zip, $target, $name);
            }
        }

        $zip->close();

        return str_replace(COMET_ROOT . '/', '', $backupPath);
    }

    private function addDirectoryToZip(\ZipArchive $zip, string $directory, string $prefix): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo || $item->isDir()) {
                continue;
            }

            $relative = $prefix . '/' . ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($directory))), '/');
            $zip->addFile($item->getPathname(), $relative);
        }
    }

    private function assertWritableInstallTargets(string $packageRoot): void
    {
        foreach (scandir($packageRoot) ?: [] as $name) {
            if ($name === '.' || $name === '..' || $this->isPreserved($name, (array) $this->config()['preserved_paths'])) {
                continue;
            }

            $target = COMET_ROOT . '/' . $name;
            $check = file_exists($target) ? $target : dirname($target);

            if (!is_writable($check)) {
                throw new \RuntimeException('The update target is not writable: ' . $name);
            }
        }
    }

    private function isPreserved(string $relative, array $preservedPaths): bool
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');

        foreach ($preservedPaths as $path) {
            $path = trim(str_replace('\\', '/', (string) $path), '/');

            if ($path !== '' && ($relative === $path || str_starts_with($relative, $path . '/'))) {
                return true;
            }
        }

        return false;
    }

    private function isPartiallyPreserved(string $relative, array $preservedPaths): bool
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');

        foreach ($preservedPaths as $path) {
            $path = trim(str_replace('\\', '/', (string) $path), '/');

            if ($relative !== '' && $path !== '' && str_starts_with($path, $relative . '/')) {
                return true;
            }
        }

        return false;
    }

    private function removePath(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }

    private function unsafePath(string $path): bool
    {
        return str_starts_with($path, '/') || str_contains($path, '../') || str_contains($path, '..\\') || $path === '..';
    }

    private function responseStatus(array $headers): int
    {
        $line = (string) ($headers[0] ?? '');

        if (preg_match('#\s(\d{3})\s#', $line, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }

    private function selectAsset(array $assets, string $pattern): ?array
    {
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }

            $name = (string) ($asset['name'] ?? '');

            if ($name !== '' && @preg_match($pattern, $name) === 1) {
                return [
                    'name' => $name,
                    'size' => (int) ($asset['size'] ?? 0),
                    'download_url' => (string) ($asset['browser_download_url'] ?? ''),
                ];
            }
        }

        return null;
    }

    private function expectedChecksum(string $content, string $assetName): string
    {
        foreach (preg_split('/\R/', trim($content)) ?: [] as $line) {
            if (preg_match('/^([a-fA-F0-9]{64})(?:\s+\*?(.+))?$/', trim($line), $matches) !== 1) {
                continue;
            }

            $name = trim((string) ($matches[2] ?? ''));

            if ($name === '' || basename($name) === basename($assetName)) {
                return strtolower($matches[1]);
            }
        }

        throw new \RuntimeException('The checksum asset does not contain a SHA-256 checksum for the update ZIP.');
    }

    private function relativeToUpdates(string $path): string
    {
        return ltrim(str_replace('\\', '/', substr($path, strlen(COMET_STORAGE . '/updates'))), '/');
    }

    private function writeJson(string $path, array $data): void
    {
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    }

    private function withLock(callable $callback): array
    {
        $lockPath = COMET_STORAGE . '/updates/' . self::LOCK_FILE;
        $handle = fopen($lockPath, 'c');

        if ($handle === false) {
            throw new \RuntimeException('Could not open the update lock.');
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new \RuntimeException('Another update operation is already running.');
        }

        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function isNewer(string $latest, string $current): bool
    {
        return version_compare($this->normalizeVersion($latest), $this->normalizeVersion($current), '>');
    }

    private function normalizeVersion(string $version): string
    {
        $version = ltrim(trim($version), 'vV');

        return $version === '' ? '0.0.0' : $version;
    }

    private function currentVersion(): string
    {
        return comet_version();
    }
}
