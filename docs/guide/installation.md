# Installation

## Requirements

**Server (production):**
- PHP 8.2 or later (with `json`, `mbstring`, `fileinfo` extensions)
  CometCMS checks PHP 8.2, `json` and `mbstring` on every request. If one is missing, the admin, the API and the website answer with an error that names it and how to install it, instead of failing later in unrelated places. `fileinfo` is recommended for reliable upload type detection.
- A web server (Apache, Nginx, Caddy)

**Development machine:**
- Node.js 24 LTS, pinned in `.nvmrc` (only needed for local development and builds)
- PHP 8.2+ for local development and backend tests

The PHP `zip` extension enables ZIP backups, restore, and release installation. GD enables image thumbnails and responsive variants.

## Deployment

### Install a release

Download the built ZIP from [GitHub Releases](https://github.com/GetCometCMS/CometCMS/releases/latest), extract it, and upload its contents to your server. Include hidden files such as `.htaccess` and `.user.ini`. Continue with the web-server configuration below. No local build is needed for a release ZIP.

### 1. Build from source (optional)

```bash
git clone https://github.com/GetCometCMS/CometCMS.git
cd CometCMS
nvm use
npm ci
```

### 2. Build a deployment package

```bash
make build
```

This compiles the Vue admin frontend and assembles everything into a `dist/` folder. The output is a self-contained PHP application — no Node.js is needed on the server.

### 3. Upload to your server

Upload the **contents** of `dist/` to your server's web root (or a subdirectory). The structure will look like:

```
index.php
router.php
app/
config/
admin/        ← compiled Vue frontend
storage/      ← empty, writable directory for content/users/etc.
```

### 4. Configure your web server

Application requests must be routed through `index.php`. Block direct access to `app/`, `config/`, and `storage/`; the included Apache configuration already does this. Serve the compiled admin assets normally.

**Apache** (`.htaccess` is included in the build):
```apache
Options -Indexes
RewriteEngine On
RewriteRule ^(app|config|storage)(/|$) - [F,L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [QSA,L]
```

**Nginx:**
```nginx
location ~ ^/(app|config|storage)(/|$) {
    deny all;
}

location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

### 5. Ensure `storage/` is writable

Grant the PHP process write access to `storage/` using the ownership and permissions supported by your host. A mode of `755` is sufficient only when PHP runs as the directory owner. Release installation also needs write access to the application files it replaces.

### 6. Open the admin

Navigate to `https://yourdomain.com/admin`. The first-run setup screen will appear.

---

## Configuration (optional)

`config/config.php` lets you adjust:
- `app.timezone` — default `UTC`
- `content.max_revisions` — revision snapshots kept per entry, default `55`
- `site.max_revisions` — revision snapshots kept per website layout, region, component or theme, default `55`
- `cache.ttl` — API cache TTL in seconds
- `security.login_throttle` — brute-force protection limits
- `updates.repository_url` — GitHub repository used by the admin update page
- `updates.require_checksum` — require a `.sha256` release asset before installing
- `updates.preserved_paths` — paths skipped when installing release ZIPs
- `webhooks` — fallback outbound webhook configuration; the admin saves changes in `storage/settings.json`

The URL is **auto-detected** from the HTTP request — no `base_url` setting is needed.

Open the admin update page from **Administration → Updates** in the sidebar, or choose **Version** in the account menu.
Update checks use GitHub releases. Updates are downloaded and verified first,
then installed from the staged package. Installing an update replaces
release-owned application files while preserving the configured paths, including
`storage/` for content, content types, media, users, revisions and other local
data.

CometCMS supports public GitHub releases for update checks and downloads.

---

## Local development

```bash
npm install
make dev
```

This starts the PHP built-in server and the Vite dev server simultaneously. The admin is available at `http://localhost:8000/admin`.
