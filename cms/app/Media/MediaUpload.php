<?php

declare(strict_types=1);

namespace CometCMS\Media;

use CometCMS\Core\MimeDetector;
use CometCMS\Core\Security;

/** The same validated upload workflow for the admin and REST API. */
final class MediaUpload
{
    public function __construct(private readonly MediaRepository $media) {}

    /** Normalize PHP's single-file and multiple-file multipart representations. */
    public static function files(mixed $source): array
    {
        if (!is_array($source) || !array_key_exists('name', $source)) return [];
        if (!is_array($source['name'])) return [$source];
        $files = [];
        foreach ($source['name'] as $index => $name) {
            $files[] = [
                'name' => $name, 'type' => $source['type'][$index] ?? '',
                'tmp_name' => $source['tmp_name'][$index] ?? '',
                'error' => $source['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $source['size'][$index] ?? 0,
            ];
        }
        return $files;
    }

    public function upload(array $uploads, string $category, string $userId, int $contentLength = 0, ?callable $onUploaded = null): array
    {
        if ($uploads === []) {
            $postMax = ini_parse_quantity((string) ini_get('post_max_size'));
            throw new UploadException('upload_failed', $postMax > 0 && $contentLength > $postMax
                ? 'The upload exceeds the server\'s post_max_size limit.' : 'Upload failed.');
        }
        $max = (int) comet_config('media.max_upload_bytes', 1073741824);
        $allowed = (array) comet_config('media.allowed_mime_types', []);
        $items = [];
        foreach ($uploads as $file) {
            $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
            if ($error !== UPLOAD_ERR_OK) {
                throw new UploadException('upload_failed', in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    ? 'File exceeds the server\'s upload size limit.' : 'Upload failed.');
            }
            $size = (int) ($file['size'] ?? 0);
            if ($size <= 0 || $size > $max) {
                throw new UploadException('file_too_large', 'File is too large. The CMS limit is ' . (string) ceil($max / 1048576) . ' MiB.');
            }
            $original = (string) ($file['name'] ?? 'upload');
            $mime = MimeDetector::detect((string) $file['tmp_name'], $original);
            if (!in_array($mime, $allowed, true)) throw new UploadException('file_type_not_allowed', 'File type is not allowed.');
            try {
                $extension = '.' . MimeDetector::validatedExtension($mime, $original);
            } catch (\InvalidArgumentException $error) {
                throw new UploadException('file_type_not_allowed', $error->getMessage());
            }
            $base = Security::slug(pathinfo($original, PATHINFO_FILENAME)) ?: 'upload';
            $name = $base;
            $suffix = 2;
            while (file_exists($this->media->directory() . '/' . $name . $extension)) $name = $base . '-' . $suffix++;
            $stored = $name . $extension;
            if (!move_uploaded_file((string) $file['tmp_name'], $this->media->directory() . '/' . $stored)) {
                throw new UploadException('upload_failed', 'Could not store uploaded file.', 500);
            }
            $this->media->registerUpload($stored, $category, $userId);
            $this->media->generateSizes($stored);
            $item = $this->media->item($stored);
            $item['mime'] = $mime;
            $items[] = $item;
            if ($onUploaded !== null) $onUploaded($item);
        }
        return $items;
    }
}
