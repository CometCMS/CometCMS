<?php

declare(strict_types=1);

namespace CometCMS\Controllers;

use CometCMS\Core\Http;
use CometCMS\Core\RateLimiter;
use CometCMS\Site\Blocks\BlockRegistry;
use CometCMS\Site\Flags;
use CometCMS\Site\FormCaptcha;
use CometCMS\Site\FormRepository;
use CometCMS\Site\FormSubmission;
use CometCMS\Site\FormUploads;
use CometCMS\Site\LayoutSchema;
use CometCMS\Site\PlaceholderPage;
use CometCMS\Site\SiteContext;
use CometCMS\Site\SiteRepository;
use CometCMS\Site\SiteSearch;
use CometCMS\Site\SiteScripts;
use CometCMS\Site\SiteService;
use CometCMS\Site\Theme;
use CometCMS\Workspaces\WorkspaceContext;
use CometCMS\Workspaces\WorkspaceRepository;

/**
 * Public website. Only answers when a workspace's website is live (it has a
 * homepage or frontend pages); otherwise the request falls through to the
 * headless behaviour.
 */
final class SiteController
{
    /**
     * Pages block scripts except for bundled interactions that are present;
     * their exact hashes and any CAPTCHA providers used on the page are allowed.
     */
    private const CONTENT_SECURITY_POLICY = "default-src 'self'; img-src 'self' data: https:; media-src 'self' https:; frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; style-src 'self' 'unsafe-inline' https:; font-src 'self' data: https:; script-src 'none'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'";

    public function __construct(private readonly Http $http) {}

    /** Whether $path may belong to the website rather than to CometCMS itself. */
    public static function handles(string $path): bool
    {
        foreach (SiteRepository::RESERVED_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return false;
            }
        }

        return true;
    }

    /** Send the page and exit, or return when no website answers this host. */
    public function page(string $path): void
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $workspace = SiteService::workspaceForHost($host);

        if ($workspace === null) {
            return;
        }

        $site = new SiteRepository($workspace);
        // The first domain is the primary one: the others redirect to it, so each page has one canonical URL.
        $primary = $site->settings()['domains'][0] ?? null;
        if ($primary !== null && strtolower((string) preg_replace('/:\d+$/', '', $host)) !== $primary) {
            $this->http->redirect($this->scheme() . '://' . $primary . ($_SERVER['REQUEST_URI'] ?? '/'), 301);
        }

        WorkspaceContext::setActive($workspace->slug());
        $context = SiteContext::make($site, rtrim($this->http->url('/'), '/'), $this->origin());
        $response = (new SiteService($context))->respondCached($path, $_GET);
        $this->send($response, $response['cache_status'], $context);
    }

    public function font(string $workspace, string $hash): never
    {
        if (!(new WorkspaceRepository())->exists($workspace)) {
            $this->http->notFound();
        }
        $fonts = new \CometCMS\Site\FontRegistry(new WorkspaceContext($workspace));
        $path = $fonts->asset($hash);
        if ($path === null) {
            $this->http->notFound();
        }
        $etag = '"' . $hash . '"';
        header('ETag: ' . $etag);
        header('Cache-Control: public, max-age=31536000, immutable');
        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            exit;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
            header('Content-Type: font/woff2');
            header('Content-Length: ' . filesize($path));
            header('X-Content-Type-Options: nosniff');
            exit;
        }
        $this->http->streamFile($path, 'font/woff2');
    }

    /** Block package assets are content-addressed, so they never change under one URL. */
    public function blockAsset(string $workspace, string $file): never
    {
        if (!(new WorkspaceRepository())->exists($workspace)) {
            $this->http->notFound();
        }
        $asset = (new BlockRegistry(new WorkspaceContext($workspace)))->asset($file);
        if ($asset === null) {
            $this->http->notFound();
        }
        $etag = '"' . substr($file, 0, 64) . '"';
        $headers = [
            'ETag' => $etag,
            'Cache-Control' => 'public, max-age=31536000, immutable',
            // SVG opened directly must not run scripts with the site's origin.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ];
        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            foreach ($headers as $name => $value) header($name . ': ' . $value);
            exit;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
            foreach ($headers + ['Content-Type' => $asset['type'], 'Content-Length' => (string) filesize($asset['path']), 'X-Content-Type-Options' => 'nosniff'] as $name => $value) {
                header($name . ': ' . $value);
            }
            exit;
        }
        $this->http->streamFile($asset['path'], $asset['type'], $headers);
    }

    public function stylesheet(string $workspace, bool $custom = false): never
    {
        if (!(new WorkspaceRepository())->exists($workspace)) {
            $this->http->notFound();
        }

        $site = new SiteRepository(new WorkspaceContext($workspace));
        $version = $custom ? $site->customStylesheetVersion() : $site->stylesheetVersion();
        $theme = $site->theme();
        $css = $custom ? $theme['custom_css'] : Theme::stylesheet([...$theme, 'custom_css' => ''], new \CometCMS\Site\FontRegistry($site->workspace()), rtrim($this->http->url('/'), '/'));
        $etag = '"' . $version . '"';

        header('Content-Type: text/css; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('ETag: ' . $etag);
        header(($_GET['v'] ?? '') === $version ? 'Cache-Control: public, max-age=31536000, immutable' : 'Cache-Control: public, max-age=300');

        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            exit;
        }

        echo $css;
        exit;
    }

    /**
     * A website form was sent: store it as an entry of the form's content
     * type, then send the visitor back to the page the form is on. The
     * outcome travels in the URL fragment (#{anchor}-sent or -error), where
     * the form shows it with CSS alone, so the page itself stays cacheable.
     * Requests that accept JSON get the outcome as JSON instead.
     */
    public function submitForm(string $workspace, string $formId): never
    {
        if (!(new WorkspaceRepository())->exists($workspace)) {
            $this->http->notFound();
        }

        $site = new SiteRepository(new WorkspaceContext($workspace));
        $form = (new FormRepository($site))->find($formId);
        if ($form === null) {
            $this->http->notFound();
        }
        $this->rejectDuringMaintenance($site);

        $maxBytes = max(1024, (int) comet_config('site.forms.max_body_bytes', 262144));
        foreach ((new FormRepository($site))->inputs($form) as $field) {
            if (($field['config']['type'] ?? '') === 'media') {
                $maxBytes += FormUploads::maxBytes();
                break;
            }
        }
        $result = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBytes
            ? ['status' => FormSubmission::ERROR, 'errors' => ['form' => 'The submission is too large.']]
            : FormSubmission::make($site)->submit($form, $_POST, (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LayoutSchema::key($_POST['_locale'] ?? ''), $_FILES);

        if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
            $sent = $result['status'] === FormSubmission::SENT;
            $this->http->json($sent ? ['data' => ['status' => 'sent']] : ['error' => ['code' => 'submission_failed', 'fields' => $result['errors'] ?? []]], $sent ? 200 : 422);
        }

        $anchor = LayoutSchema::key($_POST['_anchor'] ?? '');
        $this->http->redirect($this->returnPath($_POST['_return'] ?? null) . ($anchor !== '' ? '#' . $anchor . '-' . $result['status'] : ''), 303);
    }

    /**
     * Hits for a search element as JSON: ?q= the text, types= and fields=
     * comma-separated keys (empty for all), limit= and locale=. Only pages a
     * visitor could open are searched (see SiteSearch), so the request may
     * name any type or field without revealing more than the website does.
     */
    public function search(string $workspace): never
    {
        if (!(new WorkspaceRepository())->exists($workspace)) {
            $this->http->notFound();
        }

        $site = new SiteRepository(new WorkspaceContext($workspace));
        if (!$site->settings()['enabled']) {
            $this->http->notFound();
        }
        $this->rejectDuringMaintenance($site);

        $limits = new RateLimiter($site->workspace()->path('cache') . '/search-limits');
        if ($limits->hit('site-search', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), max(1, (int) comet_config('site.search.rate_limit_per_minute', 120)), 60)) {
            header('Retry-After: 60');
            $this->http->json(['error' => ['code' => 'rate_limited', 'message' => 'Too many searches. Please wait a moment.']], 429);
        }

        $context = SiteContext::make($site, rtrim($this->http->url('/'), '/'), $this->origin());
        $locale = LayoutSchema::key($_GET['locale'] ?? '');
        if ($locale !== '' && in_array($locale, $context->locales(), true)) {
            $context->locale = $locale;
        }
        $keys = static fn(mixed $value): array => array_values(array_filter(array_map(LayoutSchema::key(...), explode(',', is_string($value) ? $value : '', 50)), static fn(string $key): bool => $key !== ''));
        $query = is_string($_GET['q'] ?? null) ? $_GET['q'] : '';
        $limit = (int) ($_GET['limit'] ?? SiteSearch::MAX_RESULTS);
        $hits = (new SiteSearch($context))->search($query, $keys($_GET['types'] ?? ''), $keys($_GET['fields'] ?? ''), $limit);

        header('Cache-Control: public, max-age=60');
        header('X-Content-Type-Options: nosniff');
        $this->http->json(['data' => $hits]);
    }

    /** Only a path on this site is followed, never another host. */
    private function returnPath(mixed $path): string
    {
        $path = is_string($path) ? strtok($path, '#') : '';
        if (!is_string($path) || !str_starts_with($path, '/') || str_starts_with($path, '//') || preg_match('/[\\\\\x00-\x1f]/', $path)) {
            return $this->http->url('/');
        }

        return $path;
    }

    /** A language flag of the language switcher; the same for every workspace. */
    public function flag(string $country): never
    {
        $file = Flags::file($country);
        if ($file === null) {
            $this->http->notFound();
        }

        header('Content-Type: image/svg+xml');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
        header('Cache-Control: public, max-age=604800');
        readfile($file);
        exit;
    }

    /** The public root of a headless install stays separate from the admin. */
    public function placeholder(): never
    {
        $this->send([
            'status' => 200,
            'body' => PlaceholderPage::render(),
            'content_type' => 'text/html; charset=utf-8',
        ], 'BYPASS');
    }

    /** Forms and search stay closed with the pages they belong to. */
    private function rejectDuringMaintenance(SiteRepository $site): void
    {
        if (!empty($site->settings()['maintenance'])) {
            header('Retry-After: 3600');
            $this->http->json(['error' => ['code' => 'maintenance', 'message' => 'The website is undergoing maintenance.']], 503);
        }
    }

    /** $context, for website pages, lets installed block scripts through the CSP. */
    private function send(array $response, string $cacheStatus, ?SiteContext $context = null): never
    {
        $etag = '"' . substr(sha1($response['body']), 0, 20) . '"';

        http_response_code((int) $response['status']);
        header('Content-Type: ' . $response['content_type']);
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-CometCMS-Cache: ' . $cacheStatus);
        header('Cache-Control: public, max-age=0, must-revalidate');
        header('ETag: ' . $etag);
        if ($response['status'] === 503) {
            header('Retry-After: 3600');
        }

        if (str_starts_with($response['content_type'], 'text/html')) {
            $policy = str_replace("script-src 'none'", SiteScripts::cspDirective($response['body'], $context), self::CONTENT_SECURITY_POLICY);
            $policy = FormCaptcha::extendPolicy($policy, $response['body']);
            header('Content-Security-Policy: ' . $policy);
        }

        if ($response['status'] === 200 && trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            exit;
        }

        echo $response['body'];
        exit;
    }

    private function origin(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');

        if ($host === '' || !preg_match('/^[A-Za-z0-9.-]+(:\d+)?$/', $host)) {
            return '';
        }

        return $this->scheme() . '://' . $host;
    }

    private function scheme(): string
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    }
}
