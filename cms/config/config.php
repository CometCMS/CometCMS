<?php

return [
    'app' => [
        'name' => 'CometCMS',
        'version' => '2.0.1',
        'debug' => false,
        'timezone' => 'UTC',
    ],
    'updates' => [
        'enabled' => true,
        'repository_url' => 'https://github.com/GetCometCMS/CometCMS',
        'fallback_repository_urls' => [],
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
        'persistent_session_seconds' => 30 * 24 * 60 * 60,
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
        'max_revisions' => 55,
    ],
    'content_types' => [],
    'site' => [
        'max_revisions' => 55,
        'forms' => [
            // Per-file and total attachment budget for public website forms.
            'max_upload_bytes' => 10 * 1024 * 1024,
            'captcha' => [
                // Secrets stay on the server. Register the website domains with each provider.
                'turnstile' => [
                    'site_key' => getenv('COMET_TURNSTILE_SITE_KEY') ?: '',
                    'secret_key' => getenv('COMET_TURNSTILE_SECRET_KEY') ?: '',
                    'hostnames' => [],
                ],
                // Use reCAPTCHA v2 checkbox keys.
                'recaptcha' => [
                    'site_key' => getenv('COMET_RECAPTCHA_SITE_KEY') ?: '',
                    'secret_key' => getenv('COMET_RECAPTCHA_SECRET_KEY') ?: '',
                    'hostnames' => [],
                ],
                // Use separate reCAPTCHA v3 keys. Scores below this threshold are rejected.
                'recaptcha_v3' => [
                    'site_key' => getenv('COMET_RECAPTCHA_V3_SITE_KEY') ?: '',
                    'secret_key' => getenv('COMET_RECAPTCHA_V3_SECRET_KEY') ?: '',
                    'hostnames' => [],
                    'min_score' => 0.5,
                ],
                // Optional: workspace slug => provider => complete credentials and hostnames.
                'workspaces' => [],
            ],
        ],
    ],
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
        'max_upload_bytes' => 1024 * 1024 * 1024,
        // Every uploaded image gets these sizes (longest side in pixels, proportions kept).
        // Defaults for workspaces that have not set their own in the media library.
        'sizes' => [
            'thumbnail' => 400,
            'medium' => 960,
            'large' => 1920,
        ],
        'variants' => [
            'enabled' => true,
            // The named sizes are always accepted as widths (`?w=`).
            // Enable custom sizes to also accept arbitrary bounded w/h values.
            'allow_custom_sizes' => false,
            'max_dimension' => 4096,
            'max_pixels' => 16000000,
            'max_variants_per_image' => 20,
            'formats' => ['jpeg', 'png', 'webp'],
            'default_format' => 'webp',
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
