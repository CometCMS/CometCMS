<?php

return [
    'app' => [
        'name' => 'CometCMS',
        'version' => '0.9.6',
        'debug' => false,
        'timezone' => 'UTC',
    ],
    'updates' => [
        'enabled' => true,
        'repository_url' => 'https://github.com/CometCMS/CometCMS',
        'fallback_repository_urls' => [
            'https://github.com/andreasjhagen/cometcms',
        ],
        'releases_api_url' => '',
        'release_asset_pattern' => '/cometcms.*\.zip$/i',
        'checksum_asset_pattern' => '/cometcms.*\.zip\.sha256$/i',
        'require_checksum' => true,
        'preserved_paths' => [
            'storage',
            'config/config.php',
        ],
    ],
    'security' => [
        'session_name' => 'cometcms_admin',
        'csrf_key' => 'cometcms_csrf',
        'login_throttle' => [
            'max_attempts' => 5,
            'window_seconds' => 300,
            'lockout_seconds' => 900,
        ],
    ],
    'content' => [
        'default_collection' => '',
        'default_limit' => 20,
        'max_revisions' => 50,
    ],
    'content_types' => [],
    'cache' => [
        'enabled' => true,
        'ttl' => 300,
        'path' => COMET_STORAGE . '/cache/api',
    ],
    'webhooks' => [],
    'backups' => [
        'include_password_hashes' => false,
    ],
    'media' => [
        'max_upload_bytes' => 256 * 1024 * 1024,
        'thumbnails' => [
            'enabled' => true,
            'size' => 512,
            'quality' => 82,
        ],
        'allowed_mime_types' => [
            // Images
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/svg+xml',
            'image/avif',
            // Video
            'video/mp4',
            'video/webm',
            'video/quicktime',
            'video/x-m4v',
            'video/x-msvideo',
            'video/x-matroska',
            'video/mpeg',
            'video/ogg',
            'video/3gpp',
            'video/3gpp2',
            // Documents
            'application/pdf',
            'text/plain',
            'text/csv',
            'text/markdown',
            'application/rtf',
            // Word
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.oasis.opendocument.text',
            // Excel / spreadsheets
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.oasis.opendocument.spreadsheet',
            // PowerPoint / presentations
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.oasis.opendocument.presentation',
            // Archives
            'application/zip',
            'application/x-zip-compressed',
            'application/x-7z-compressed',
            'application/x-rar-compressed',
            'application/gzip',
            'application/x-tar',
            // Audio
            'audio/mpeg',
            'audio/wav',
            'audio/x-wav',
            'audio/ogg',
            'audio/mp4',
            'audio/aac',
            'audio/flac',
            'audio/x-m4a',
        ],
    ],
];
