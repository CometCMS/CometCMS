<?php

declare(strict_types=1);

namespace CometCMS\Media;

use CometCMS\Core\MimeDetector;
use CometCMS\Workspaces\WorkspaceContext;

final class MediaRepository
{
    private string $mediaDir;
    private string $thumbDir;
    private string $metadataPath;
    private WorkspaceContext $workspace;

    public function __construct(?WorkspaceContext $workspace = null)
    {
        $this->workspace = $workspace ?? WorkspaceContext::active();
        WorkspaceContext::setActive($this->workspace->slug());
        $this->workspace->ensure();
        $this->mediaDir = $this->workspace->path('media');
        $this->thumbDir = $this->workspace->path('media-thumbs');
        $this->metadataPath = $this->workspace->path('media-meta') . '/index.json';

        if (!is_dir($this->thumbDir)) {
            mkdir($this->thumbDir, 0775, true);
        }

        if (!is_dir(dirname($this->metadataPath))) {
            mkdir(dirname($this->metadataPath), 0775, true);
        }
    }

    public function directory(): string
    {
        return $this->mediaDir;
    }

    public function filePath(string $file): string
    {
        return $this->path($file);
    }

    public function files(string $query = '', ?string $category = null, string $type = 'all', string $sort = 'newest', ?string $visibility = null): array
    {
        $metadata = $this->metadata();
        $files = $this->matchingFiles($query, $category, $type, $sort, $metadata, $visibility);

        return array_map(fn(string $file): array => $this->item($file, $metadata), $files);
    }

    public function limitedFiles(string $query = '', ?string $category = null, ?int $limit = null, int $offset = 0, string $type = 'all', string $sort = 'newest', ?string $visibility = null): array
    {
        $metadata = $this->metadata();
        $files = $this->matchingFiles($query, $category, $type, $sort, $metadata, $visibility);
        $total = count($files);
        $offset = max(0, $offset);
        $limit = $limit === null ? max(0, $total - $offset) : max(1, $limit);
        $limitedFiles = array_slice($files, $offset, $limit);

        return [
            'data' => array_map(fn(string $file): array => $this->item($file, $metadata), $limitedFiles),
            'meta' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ],
        ];
    }

    private function matchingFiles(string $query = '', ?string $category = null, string $type = 'all', string $sort = 'newest', ?array $metadata = null, ?string $visibility = null): array
    {
        $files = array_values(array_filter(
            scandir($this->mediaDir) ?: [],
            fn(string $file): bool => $file[0] !== '.' && is_file($this->path($file))
        ));

        $query = trim(strtolower($query));
        $metadata ??= $this->metadata();

        $files = array_values(array_filter($files, function (string $file) use ($query, $category, $metadata, $type, $visibility): bool {
            if ($query !== '' && !str_contains(strtolower($file), $query)) {
                return false;
            }

            if ($category !== null && !$this->categoryMatches($this->categoryFor($file, $metadata), $category)) {
                return false;
            }

            if (!$this->matchesType($file, $type)) {
                return false;
            }

            if ($visibility !== null) {
                $fileVisibility = (string) ($metadata['files'][$file]['visibility'] ?? 'public');
                if ($fileVisibility !== $visibility) {
                    return false;
                }
            }

            return true;
        }));

        $this->sortFiles($files, $sort, $metadata);

        return $files;
    }

    private function sortFiles(array &$files, string $sort, array $metadata): void
    {
        usort($files, function (string $a, string $b) use ($sort, $metadata): int {
            return match ($sort) {
                'oldest' => $this->uploadedTimestamp($a, $metadata) <=> $this->uploadedTimestamp($b, $metadata),
                'name' => strcasecmp($a, $b),
                'size' => ((int) filesize($this->path($b))) <=> ((int) filesize($this->path($a))),
                default => $this->uploadedTimestamp($b, $metadata) <=> $this->uploadedTimestamp($a, $metadata),
            };
        });
    }

    private function uploadedTimestamp(string $file, array $metadata): int
    {
        $uploadedAt = (string) ($metadata['files'][$file]['uploaded_at'] ?? '');
        $time = $uploadedAt !== '' ? strtotime($uploadedAt) : false;

        if ($time !== false) {
            return $time;
        }

        $modifiedAt = filemtime($this->path($file));

        return $modifiedAt === false ? 0 : $modifiedAt;
    }

    private function matchesType(string $file, string $type): bool
    {
        if ($type === '' || $type === 'all') {
            return true;
        }

        $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));

        return match ($type) {
            'images' => in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'], true),
            'video' => in_array($ext, ['mp4', 'webm', 'mov', 'm4v', 'avi', 'mkv', 'mpeg', 'mpg', 'ogv', '3gp', '3g2'], true),
            'audio' => in_array($ext, ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'], true),
            'documents' => in_array($ext, ['pdf', 'doc', 'docx', 'odt', 'xls', 'xlsx', 'ods', 'csv', 'ppt', 'pptx', 'odp', 'txt', 'md', 'rtf'], true),
            'archives' => in_array($ext, ['zip', 'rar', '7z', 'tar', 'gz', 'bz2'], true),
            'other' => !$this->matchesType($file, 'images')
                && !$this->matchesType($file, 'video')
                && !$this->matchesType($file, 'audio')
                && !$this->matchesType($file, 'documents')
                && !$this->matchesType($file, 'archives'),
            default => true,
        };
    }

    public function categories(): array
    {
        return $this->metadata()['categories'];
    }

    public function addCategory(string $name, string $parent = ''): array
    {
        $name = $this->normalizeCategory($parent === '' ? $name : $parent . ' / ' . $name);

        if ($name === '') {
            throw new \InvalidArgumentException('Category name cannot be empty.');
        }

        $metadata = $this->metadata();

        $changed = false;
        foreach ($this->categoryPathsFor($name) as $category) {
            if (!in_array($category, $metadata['categories'], true)) {
                $metadata['categories'][] = $category;
                $changed = true;
            }
        }

        if ($changed) {
            $metadata['categories'] = $this->sortCategories($metadata['categories']);
            $this->writeMetadata($metadata);
        }

        return $metadata['categories'];
    }

    public function renameCategory(string $oldName, string $newName): array
    {
        $oldName = $this->normalizeCategory($oldName);
        $newName = $this->normalizeCategory($newName);

        if ($oldName === '' || $newName === '') {
            throw new \InvalidArgumentException('Category name cannot be empty.');
        }

        $metadata = $this->metadata();

        if (!in_array($oldName, $metadata['categories'], true)) {
            throw new \InvalidArgumentException('Category not found.');
        }

        $renamedCategories = [];
        foreach ($metadata['categories'] as $category) {
            $renamedCategories[] = $this->renameCategoryPath($category, $oldName, $newName);
        }

        foreach ($metadata['files'] as $file => $item) {
            $category = (string) ($item['category'] ?? '');
            if ($this->categoryMatches($category, $oldName)) {
                $metadata['files'][$file]['category'] = $this->renameCategoryPath($category, $oldName, $newName);
            }
        }

        $metadata['categories'] = $this->sortCategories(array_merge(
            $renamedCategories,
            $this->categoryPathsFor($newName)
        ));
        $this->writeMetadata($metadata);

        return $metadata['categories'];
    }

    public function deleteCategory(string $name): array
    {
        $name = $this->normalizeCategory($name);

        if ($name === '') {
            throw new \InvalidArgumentException('Category name cannot be empty.');
        }

        $metadata = $this->metadata();

        if (!in_array($name, $metadata['categories'], true)) {
            throw new \InvalidArgumentException('Category not found.');
        }

        $metadata['categories'] = array_values(array_filter(
            $metadata['categories'],
            fn(string $category): bool => !$this->categoryMatches($category, $name)
        ));

        foreach ($metadata['files'] as $file => $item) {
            if ($this->categoryMatches((string) ($item['category'] ?? ''), $name)) {
                unset($metadata['files'][$file]['category']);

                if (($metadata['files'][$file] ?? []) === []) {
                    unset($metadata['files'][$file]);
                }
            }
        }

        $this->writeMetadata($metadata);

        return $metadata['categories'];
    }

    public function assignCategory(string $file, string $category): ?array
    {
        $file = basename(rawurldecode($file));
        $path = $this->path($file);

        if (!is_file($path)) {
            return null;
        }

        $category = $this->normalizeCategory($category);
        $metadata = $this->metadata();

        $changed = false;
        foreach ($this->categoryPathsFor($category) as $categoryPath) {
            if (!in_array($categoryPath, $metadata['categories'], true)) {
                $metadata['categories'][] = $categoryPath;
                $changed = true;
            }
        }

        if ($changed) {
            $metadata['categories'] = $this->sortCategories($metadata['categories']);
        }

        if ($category === '') {
            unset($metadata['files'][$file]['category']);

            if (($metadata['files'][$file] ?? []) === []) {
                unset($metadata['files'][$file]);
            }
        } else {
            $metadata['files'][$file] ??= [];
            $metadata['files'][$file]['category'] = $category;
        }

        $this->writeMetadata($metadata);

        return $this->item($file, $metadata);
    }

    public function assignCategoryToMany(array $files, string $category): array
    {
        $category = $this->normalizeCategory($category);
        $metadata = $this->metadata();
        $updated = [];

        $changed = false;
        foreach ($this->categoryPathsFor($category) as $categoryPath) {
            if (!in_array($categoryPath, $metadata['categories'], true)) {
                $metadata['categories'][] = $categoryPath;
                $changed = true;
            }
        }

        if ($changed) {
            $metadata['categories'] = $this->sortCategories($metadata['categories']);
        }

        foreach ($this->normalizeFiles($files) as $file) {
            if (!is_file($this->path($file))) {
                continue;
            }

            if ($category === '') {
                unset($metadata['files'][$file]['category']);

                if (($metadata['files'][$file] ?? []) === []) {
                    unset($metadata['files'][$file]);
                }
            } else {
                $metadata['files'][$file] ??= [];
                $metadata['files'][$file]['category'] = $category;
            }

            $updated[] = $file;
        }

        if ($updated !== []) {
            $this->writeMetadata($metadata);
        }

        return array_map(fn(string $file): array => $this->item($file, $metadata), $updated);
    }

    public function delete(string $file): void
    {
        $file = basename(rawurldecode($file));
        $path = $this->path($file);

        if (is_file($path)) {
            unlink($path);
        }

        $this->deleteThumbnail($file);

        $metadata = $this->metadata();
        unset($metadata['files'][$file]);
        $this->writeMetadata($metadata);
    }

    public function deleteMany(array $files): array
    {
        $deleted = [];

        foreach ($this->normalizeFiles($files) as $file) {
            $path = $this->path($file);

            if (!is_file($path)) {
                continue;
            }

            unlink($path);
            $this->deleteThumbnail($file);
            $deleted[] = $file;
        }

        if ($deleted !== []) {
            $metadata = $this->metadata();

            foreach ($deleted as $file) {
                unset($metadata['files'][$file]);
            }

            $this->writeMetadata($metadata);
        }

        return $deleted;
    }

    public function rename(string $file, string $newName): ?array
    {
        $file = basename(rawurldecode($file));
        $path = $this->path($file);

        if (!is_file($path)) {
            return null;
        }

        $newName = $this->normalizeFilename($newName, $file);
        $target = $this->path($newName);

        if ($newName === $file) {
            return $this->item($file);
        }

        if (is_file($target)) {
            throw new \InvalidArgumentException('A media file with that name already exists.');
        }

        $metadata = $this->metadata();
        $fileMeta = $metadata['files'][$file] ?? null;

        if (!rename($path, $target)) {
            throw new \RuntimeException('Could not rename media file.');
        }

        $this->deleteThumbnail($file);

        if (is_array($fileMeta)) {
            $metadata['files'][$newName] = $fileMeta;
            unset($metadata['files'][$file]);
            $this->writeMetadata($metadata);
        }

        return $this->item($newName, $metadata);
    }

    public function item(string $file, ?array $metadata = null): array
    {
        $path = $this->path($file);
        $metadata ??= $this->metadata();
        $fileMeta = $metadata['files'][$file] ?? [];
        $uploadedAt = $fileMeta['uploaded_at'] ?? null;

        if ($uploadedAt === null || $uploadedAt === '') {
            $modifiedAt = filemtime($path);
            $uploadedAt = $modifiedAt === false ? null : gmdate('c', $modifiedAt);
        }

        $dimensions = $this->imageDimensions($path);

        return [
            'name'        => $file,
            'filename'    => $file,
            'size'        => (int) filesize($path),
            'mime'        => MimeDetector::detect($path),
            'thumb'       => $this->thumbnailDescriptor($file),
            'category'    => $this->categoryFor($file, $metadata),
            'uploaded_by' => $fileMeta['uploaded_by'] ?? null,
            'uploaded_at' => $uploadedAt,
            'width'       => $dimensions['width'],
            'height'      => $dimensions['height'],
            'alt'         => $fileMeta['alt'] ?? '',
            'title'       => $fileMeta['title'] ?? '',
            'visibility'  => $fileMeta['visibility'] ?? 'public',
        ];
    }

    public function setUploadedBy(string $file, string $userId): void
    {
        $file = basename($file);
        $metadata = $this->metadata();
        $metadata['files'][$file] ??= [];
        $metadata['files'][$file]['uploaded_by'] = $userId;
        $metadata['files'][$file]['uploaded_at'] = gmdate('c');
        $this->writeMetadata($metadata);
    }

    public function updateMeta(string $file, string $alt, string $title): ?array
    {
        $file = basename(rawurldecode($file));
        $path = $this->path($file);

        if (!is_file($path)) {
            return null;
        }

        $alt   = substr(trim($alt), 0, 500);
        $title = substr(trim($title), 0, 500);

        $metadata = $this->metadata();
        $metadata['files'][$file] ??= [];

        if ($alt !== '') {
            $metadata['files'][$file]['alt'] = $alt;
        } else {
            unset($metadata['files'][$file]['alt']);
        }

        if ($title !== '') {
            $metadata['files'][$file]['title'] = $title;
        } else {
            unset($metadata['files'][$file]['title']);
        }

        if ($metadata['files'][$file] === []) {
            unset($metadata['files'][$file]);
        }

        $this->writeMetadata($metadata);

        return $this->item($file, $metadata);
    }

    public function updateVisibility(string $file, string $visibility): ?array
    {
        $file = basename(rawurldecode($file));
        $path = $this->path($file);

        if (!is_file($path)) {
            return null;
        }

        $visibility = $visibility === 'private' ? 'private' : 'public';

        $metadata = $this->metadata();
        $metadata['files'][$file] ??= [];

        if ($visibility === 'private') {
            $metadata['files'][$file]['visibility'] = 'private';
        } else {
            unset($metadata['files'][$file]['visibility']);

            if ($metadata['files'][$file] === []) {
                unset($metadata['files'][$file]);
            }
        }

        $this->writeMetadata($metadata);

        return $this->item($file, $metadata);
    }

    public function updateVisibilityForMany(array $files, string $visibility): array
    {
        $visibility = $visibility === 'private' ? 'private' : 'public';
        $metadata = $this->metadata();
        $updated = [];

        foreach ($this->normalizeFiles($files) as $file) {
            if (!is_file($this->path($file))) {
                continue;
            }

            $metadata['files'][$file] ??= [];

            if ($visibility === 'private') {
                $metadata['files'][$file]['visibility'] = 'private';
            } else {
                unset($metadata['files'][$file]['visibility']);

                if ($metadata['files'][$file] === []) {
                    unset($metadata['files'][$file]);
                }
            }

            $updated[] = $file;
        }

        if ($updated !== []) {
            $this->writeMetadata($metadata);
        }

        return array_map(fn(string $file): array => $this->item($file, $metadata), $updated);
    }

    public function isPrivate(string $file): bool
    {
        $file = basename(rawurldecode($file));
        $metadata = $this->metadata();

        return ($metadata['files'][$file]['visibility'] ?? 'public') === 'private';
    }

    public function thumbnailPath(string $file): ?string
    {
        $file = basename(rawurldecode($file));

        if (!is_file($this->path($file))) {
            return null;
        }

        return $this->ensureThumbnail($file);
    }

    public function regenerateThumbnails(array $files = []): array
    {
        $generated = 0;
        $failed = 0;
        $skipped = 0;
        $enabled = (bool) comet_config('media.thumbnails.enabled', true);
        $targets = $files === [] ? (scandir($this->mediaDir) ?: []) : $files;

        foreach ($targets as $file) {
            if (!is_string($file) || $file === '') {
                continue;
            }

            $file = basename(rawurldecode($file));

            if ($file === '' || $file[0] === '.' || !is_file($this->path($file))) {
                continue;
            }

            if (!$enabled || !$this->isThumbnailSource($file)) {
                $skipped++;
                continue;
            }

            if (!$this->hasThumbnailGenerator($file)) {
                $failed++;
                continue;
            }

            $this->deleteThumbnail($file);
            $path = $this->ensureThumbnail($file);

            if ($path !== null) {
                $generated++;
            } else {
                $failed++;
            }
        }

        return [
            'generated' => $generated,
            'failed' => $failed,
            'skipped' => $skipped,
        ];
    }

    public function setUploadedCategory(string $file, string $category): array
    {
        return $this->assignCategory($file, $category) ?? $this->item($file);
    }

    private function path(string $file): string
    {
        return $this->mediaDir . '/' . basename($file);
    }

    private function imageDimensions(string $path): array
    {
        $size = @getimagesize($path);

        if (!is_array($size)) {
            return ['width' => null, 'height' => null];
        }

        return [
            'width' => (int) ($size[0] ?? 0) ?: null,
            'height' => (int) ($size[1] ?? 0) ?: null,
        ];
    }

    private function thumbnailDescriptor(string $file): ?array
    {
        if (!(bool) comet_config('media.thumbnails.enabled', true) || !$this->isThumbnailSource($file)) {
            return null;
        }

        return [
            'max_size' => max(64, min(4096, (int) comet_config('media.thumbnails.size', 512))),
            'mime' => 'image/jpeg',
        ];
    }

    private function ensureThumbnail(string $file): ?string
    {
        if (!(bool) comet_config('media.thumbnails.enabled', true)) {
            return null;
        }

        $source = $this->path($file);

        if (!is_file($source) || !$this->isThumbnailSource($file)) {
            return null;
        }

        $target = $this->thumbnailStoragePath($file);

        if (is_file($target) && (filemtime($target) ?: 0) >= (filemtime($source) ?: 0)) {
            return $target;
        }

        return $this->generateThumbnail($source, $target, $file) ? $target : null;
    }

    private function isThumbnailSource(string $file): bool
    {
        $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));

        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'], true);
    }

    private function thumbnailStoragePath(string $file): string
    {
        return $this->thumbDir . '/' . sha1(basename($file)) . '.jpg';
    }

    private function deleteThumbnail(string $file): void
    {
        $path = $this->thumbnailStoragePath($file);

        if (is_file($path)) {
            unlink($path);
        }
    }

    private function generateThumbnail(string $source, string $target, string $file): bool
    {
        if (!$this->hasThumbnailGenerator($file)) {
            return false;
        }

        $sourceImage = $this->createImageResource($source, $file);

        if (!$sourceImage instanceof \GdImage) {
            return false;
        }

        $sourceImage = $this->applyExifOrientation($sourceImage, $source, $file);
        $width = imagesx($sourceImage);
        $height = imagesy($sourceImage);

        if ($width <= 0 || $height <= 0) {
            imagedestroy($sourceImage);

            return false;
        }

        $maxSize = max(64, min(4096, (int) comet_config('media.thumbnails.size', 512)));
        $scale = min(1, $maxSize / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $thumbnail = imagecreatetruecolor($targetWidth, $targetHeight);

        if (!$thumbnail instanceof \GdImage) {
            imagedestroy($sourceImage);

            return false;
        }

        $background = imagecolorallocate($thumbnail, 255, 255, 255);
        imagefilledrectangle($thumbnail, 0, 0, $targetWidth, $targetHeight, $background === false ? 0 : $background);
        imagecopyresampled($thumbnail, $sourceImage, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        $quality = max(1, min(100, (int) comet_config('media.thumbnails.quality', 82)));
        $tmp = $target . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $saved = imagejpeg($thumbnail, $tmp, $quality);

        imagedestroy($thumbnail);
        imagedestroy($sourceImage);

        if (!$saved) {
            if (is_file($tmp)) {
                unlink($tmp);
            }

            return false;
        }

        rename($tmp, $target);

        return true;
    }

    private function applyExifOrientation(\GdImage $image, string $sourcePath, string $file): \GdImage
    {
        $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));

        if (!in_array($ext, ['jpg', 'jpeg'], true) || !function_exists('exif_read_data')) {
            return $image;
        }

        $rawExif = @exif_read_data($sourcePath);

        if (!is_array($rawExif)) {
            return $image;
        }

        $orientation = (int) ($rawExif['Orientation'] ?? 1);

        return match ($orientation) {
            2 => $this->flipImage($image, IMG_FLIP_HORIZONTAL),
            3 => $this->rotateImage($image, 180),
            4 => $this->flipImage($image, IMG_FLIP_VERTICAL),
            5 => $this->rotateImage($this->flipImage($image, IMG_FLIP_HORIZONTAL), -90),
            6 => $this->rotateImage($image, -90),
            7 => $this->rotateImage($this->flipImage($image, IMG_FLIP_HORIZONTAL), 90),
            8 => $this->rotateImage($image, 90),
            default => $image,
        };
    }

    private function rotateImage(\GdImage $image, int $degrees): \GdImage
    {
        if (!function_exists('imagerotate') || $degrees === 0) {
            return $image;
        }

        $background = imagecolorallocate($image, 255, 255, 255);
        $rotated = @imagerotate($image, $degrees, $background === false ? 0 : $background);

        if (!$rotated instanceof \GdImage) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }

    private function flipImage(\GdImage $image, int $mode): \GdImage
    {
        if (!function_exists('imageflip')) {
            return $image;
        }

        if (!@imageflip($image, $mode)) {
            return $image;
        }

        return $image;
    }

    private function createImageResource(string $path, string $file): ?\GdImage
    {
        $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) ?: null : null,
            'png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) ?: null : null,
            'gif' => function_exists('imagecreatefromgif') ? @imagecreatefromgif($path) ?: null : null,
            'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) ?: null : null,
            'avif' => function_exists('imagecreatefromavif') ? @imagecreatefromavif($path) ?: null : null,
            default => null,
        };
    }

    private function hasThumbnailGenerator(string $file): bool
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagecopyresampled') || !function_exists('imagejpeg')) {
            return false;
        }

        $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => function_exists('imagecreatefromjpeg'),
            'png' => function_exists('imagecreatefrompng'),
            'gif' => function_exists('imagecreatefromgif'),
            'webp' => function_exists('imagecreatefromwebp'),
            'avif' => function_exists('imagecreatefromavif'),
            default => false,
        };
    }

    private function normalizeFiles(array $files): array
    {
        $names = array_map(
            static fn(mixed $file): string => basename(rawurldecode((string) $file)),
            $files
        );

        return array_values(array_unique(array_filter(
            $names,
            static fn(string $file): bool => $file !== ''
        )));
    }

    private function normalizeFilename(string $name, string $currentFile): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', trim($name)) ?? '';

        if ($name !== basename($name)) {
            throw new \InvalidArgumentException('Filename cannot contain folders.');
        }

        if ($name === '' || $name === '.' || $name === '..' || str_starts_with($name, '.')) {
            throw new \InvalidArgumentException('Filename cannot be empty or hidden.');
        }

        if (pathinfo($name, PATHINFO_EXTENSION) === '') {
            $extension = pathinfo($currentFile, PATHINFO_EXTENSION);
            if ($extension !== '') {
                $name .= '.' . $extension;
            }
        }

        if (strlen($name) > 180) {
            throw new \InvalidArgumentException('Filename is too long.');
        }

        return $name;
    }

    private function categoryFor(string $file, array $metadata): string
    {
        $category = (string) ($metadata['files'][$file]['category'] ?? '');

        return in_array($category, $metadata['categories'], true) ? $category : '';
    }

    private function categoryMatches(string $category, string $categoryPath): bool
    {
        $category = $this->normalizeCategory($category);
        $categoryPath = $this->normalizeCategory($categoryPath);

        if ($categoryPath === '') {
            return $category === '';
        }

        return $category === $categoryPath || str_starts_with($category, $categoryPath . ' / ');
    }

    private function renameCategoryPath(string $category, string $oldName, string $newName): string
    {
        if ($category === $oldName) {
            return $newName;
        }

        if (str_starts_with($category, $oldName . ' / ')) {
            return $newName . substr($category, strlen($oldName));
        }

        return $category;
    }

    private function metadata(): array
    {
        $decoded = is_file($this->metadataPath)
            ? json_decode((string) file_get_contents($this->metadataPath), true)
            : [];

        $metadata = is_array($decoded) ? $decoded : [];
        $categories = [];
        foreach ((array) ($metadata['categories'] ?? []) as $category) {
            $categories = array_merge($categories, $this->categoryPathsFor((string) $category));
        }
        $categories = $this->sortCategories($categories);

        $files = [];
        foreach ((array) ($metadata['files'] ?? []) as $file => $item) {
            $file = basename((string) $file);
            $category = $this->normalizeCategory((string) ($item['category'] ?? ''));

            if ($file !== '' && is_file($this->path($file))) {
                if ($category !== '' && !in_array($category, $categories, true)) {
                    $categories = array_merge($categories, $this->categoryPathsFor($category));
                }

                $entry = [];
                if ($category !== '') {
                    $entry['category'] = $category;
                }
                if (!empty($item['uploaded_by'])) {
                    $entry['uploaded_by'] = (string) $item['uploaded_by'];
                }
                if (!empty($item['uploaded_at'])) {
                    $entry['uploaded_at'] = (string) $item['uploaded_at'];
                }
                if (isset($item['alt']) && $item['alt'] !== '') {
                    $entry['alt'] = (string) $item['alt'];
                }
                if (isset($item['title']) && $item['title'] !== '') {
                    $entry['title'] = (string) $item['title'];
                }
                if (isset($item['visibility']) && $item['visibility'] === 'private') {
                    $entry['visibility'] = 'private';
                }

                if ($entry !== []) {
                    $files[$file] = $entry;
                }
            }
        }

        $categories = $this->sortCategories($categories);

        return [
            'categories' => array_values($categories),
            'files' => $files,
        ];
    }

    private function writeMetadata(array $metadata): void
    {
        $payload = [
            'categories' => $this->sortCategories((array) ($metadata['categories'] ?? [])),
            'files' => $metadata['files'] ?? [],
        ];
        $tmp = $this->metadataPath . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new \RuntimeException('Could not encode media metadata.');
        }

        file_put_contents($tmp, $json . PHP_EOL, LOCK_EX);
        rename($tmp, $this->metadataPath);
    }

    private function normalizeCategory(string $category): string
    {
        $category = str_replace('\\', '/', $category);
        $category = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $category) ?? '';
        $parts = array_filter(array_map(
            function (string $part): string {
                $part = preg_replace('/\s+/u', ' ', trim($part)) ?? '';

                return substr($part, 0, 80);
            },
            explode('/', $category)
        ), static fn(string $part): bool => $part !== '');

        return substr(implode(' / ', $parts), 0, 240);
    }

    private function categoryPathsFor(string $category): array
    {
        $category = $this->normalizeCategory($category);
        if ($category === '') {
            return [];
        }

        $paths = [];
        $current = [];

        foreach (explode(' / ', $category) as $part) {
            $current[] = $part;
            $paths[] = implode(' / ', $current);
        }

        return $paths;
    }

    private function sortCategories(array $categories): array
    {
        $normalized = array_values(array_unique(array_filter(
            array_map(fn(mixed $category): string => $this->normalizeCategory((string) $category), $categories),
            static fn(string $category): bool => $category !== ''
        )));

        usort($normalized, static function (string $a, string $b): int {
            return strcasecmp(str_replace(' / ', "\0", $a), str_replace(' / ', "\0", $b));
        });

        return $normalized;
    }
}
