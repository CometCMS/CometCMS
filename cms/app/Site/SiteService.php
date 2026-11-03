<?php

declare(strict_types=1);

namespace CometCMS\Site;

use CometCMS\Cache\ApiCache;
use CometCMS\Core\Security;
use CometCMS\Workspaces\WorkspaceContext;
use CometCMS\Workspaces\WorkspaceRepository;

/**
 * Maps a request path to a website response.
 *
 * Matching order: robots.txt / sitemap.xml, an optional locale prefix, the
 * homepage, list pages, then collection patterns with a {slug} (a slug path
 * like about/team for hierarchical types). Anything
 * else renders the site's 404 page.
 */
final class SiteService
{
    public function __construct(private readonly SiteContext $ctx) {}

    /** Public pages, including 404s, share the workspace's invalidation lifecycle. */
    public function respondCached(string $path, array $query): array
    {
        $path = '/' . trim($path, '/');
        $page = max(1, min(10000, (int) ($query['page'] ?? 1)));
        $cache = ApiCache::fromConfig($this->ctx->site->workspace());
        $lists = ListFilters::fromQuery($query['c'] ?? null);
        $embed = ($query['embed'] ?? '') === '1';
        $search = SiteSearch::normalizeQuery($query['q'] ?? null);
        // Only pagination, list filters and embedding change the output. Include every URL component
        // used by links and canonical URLs, even for subdirectory installs.
        // File edits also need to refresh the versioned stylesheet links.
        $key = $cache->key('site|' . json_encode([
            $this->ctx->origin, $this->ctx->basePath, $path, $this->ctx->site->stylesheetVersion(), $this->ctx->site->blocks()->fingerprint(), GalleryLightbox::cspSource(), LinkModal::cspSource(), Tabs::cspSource(), SiteSearch::cspSource(), ThemeToggle::cspSource(), ListFilters::cspSource(), (new FormCaptcha($this->ctx->site))->cacheVersion(),
        ]), http_build_query(array_filter(['page' => $page > 1 ? $page : null, 'c' => $lists ?: null, 'embed' => $embed ? 1 : null, 'q' => $search !== '' ? $search : null])));
        $cached = $this->ctx->preview ? null : $cache->get($key);

        if ($cached !== null && isset($cached['status'], $cached['body'], $cached['content_type'])) {
            return $cached + ['cache_status' => 'HIT'];
        }

        $startedAt = time();
        $response = $this->respond($path, ['page' => $page, 'c' => $lists, 'embed' => $embed ? '1' : '', 'q' => $search]);

        if ($response['cacheable']) {
            $cache->put($key, $response, $this->nextPublicationAt($startedAt));
        }

        return $response + ['cache_status' => $this->ctx->preview ? 'BYPASS' : 'MISS'];
    }

    /**
     * A scheduled entry can affect any page through lists, menus or relations,
     * including entries of types without their own website route. End the
     * cache lifetime at the first publication, rather than waiting for TTL.
     */
    private function nextPublicationAt(int $after): ?int
    {
        $next = null;

        foreach ($this->ctx->site->types() as $name => $type) {
            if (!$this->ctx->isPublicType($name)) {
                continue;
            }

            foreach ($this->ctx->content->all($name) as $rawEntry) foreach ($rawEntry['publication'] as $entry) {
                if (($entry['status'] ?? '') !== 'published') {
                    continue;
                }

                $at = strtotime((string) ($entry['published_at'] ?? ''));
                if ($at !== false && $at > $after && ($next === null || $at < $next)) {
                    $next = $at;
                }
            }
        }

        return $next;
    }

    /**
     * The workspace whose website answers for $host: a workspace that lists
     * the host among its domains, otherwise the default workspace when its
     * website is live and not limited to other domains.
     */
    public static function workspaceForHost(string $host): ?WorkspaceContext
    {
        $host = strtolower((string) preg_replace('/:\d+$/', '', trim($host)));
        $registry = new WorkspaceRepository();
        $default = $registry->getDefault();
        $fallback = null;

        foreach ($registry->all() as $workspace) {
            $slug = (string) ($workspace['slug'] ?? '');
            if ($slug === '' || !empty($workspace['archived'])) {
                continue;
            }

            $context = new WorkspaceContext($slug);
            if (!is_file($context->path('site') . '/settings.json')) {
                continue;
            }

            $settings = (new SiteRepository($context))->settings();
            if (!$settings['enabled']) {
                continue;
            }

            if ($host !== '' && in_array($host, $settings['domains'], true)) {
                return $context;
            }

            if ($slug === $default && $settings['domains'] === []) {
                $fallback = $context;
            }
        }

        return $fallback;
    }

    /** @return array{status: int, body: string, content_type: string, cacheable: bool} */
    public function respond(string $path, array $query): array
    {
        $path = '/' . trim($path, '/');

        if (!$this->ctx->preview && !empty($this->ctx->settings()['maintenance'])) {
            [$this->ctx->locale] = $this->splitLocale($path);
            return $this->response(503, (new PageRenderer($this->ctx))->maintenance(), 'text/html; charset=utf-8');
        }

        if ($path === '/robots.txt') {
            return $this->response(200, $this->robots(), 'text/plain; charset=utf-8');
        }

        if ($path === '/sitemap.xml') {
            return $this->response(200, $this->sitemap(), 'application/xml; charset=utf-8');
        }

        [$locale, $path] = $this->splitLocale($path);
        $this->ctx->locale = $locale;
        $this->ctx->embed = ($query['embed'] ?? '') === '1';
        $page = max(1, min(10000, (int) ($query['page'] ?? 1)));
        $this->ctx->listParams = ListFilters::fromQuery($query['c'] ?? null);
        $renderer = new PageRenderer($this->ctx);

        if ($path === '/') {
            return $this->home($renderer, $page);
        }

        if ($path === SiteContext::SEARCH_PATH) {
            $this->ctx->searchQuery = SiteSearch::normalizeQuery($query['q'] ?? null);
            return $this->html($renderer->searchResults($page));
        }

        foreach ($this->ctx->site->types() as $name => $type) {
            $route = $this->ctx->route($name);
            if ($route === null) {
                continue;
            }

            if ($route['index_enabled'] && $route['index_path'] !== '' && $route['index_path'] === $path) {
                return $this->html($renderer->indexPage($type, $page), $renderer->status);
            }
        }

        foreach ($this->ctx->site->types() as $name => $type) {
            $route = $this->ctx->route($name);
            if ($route === null) {
                continue;
            }

            // Pages of hierarchical types sit below their parents: {slug} spans several segments.
            $hierarchical = $this->ctx->isHierarchical($name);
            $slug = $hierarchical ? '([A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*)' : '([A-Za-z0-9_-]+)';
            $regex = '#^' . str_replace(preg_quote('{slug}', '#'), $slug, preg_quote($route['pattern'], '#')) . '$#';
            if (preg_match($regex, $path, $match)) {
                $entry = $hierarchical ? $this->ctx->findEntryByPath($name, $match[1]) : $this->ctx->findEntry($name, $match[1]);
                if ($entry !== null && ($hierarchical || ($entry['slug'] ?? '') === $match[1])) {
                    return $this->html($renderer->entryPage($type, $entry, $page), $renderer->status);
                }
            }
        }

        return $this->notFound($renderer, $path);
    }

    /** Draft preview of one entry for signed-in editors. */
    public function previewEntry(string $type, string $identifier, string $locale = ''): ?string
    {
        $schema = $this->ctx->type($type);
        $this->ctx->preview = true;
        $this->ctx->previewBanner = true;

        if ($schema === null || !$this->ctx->isPublicType($type)) {
            return null;
        }

        $this->ctx->locale = $this->ctx->content->locale($locale);

        $entry = $this->ctx->findEntry($type, $identifier, true);

        return $entry === null ? null : (new PageRenderer($this->ctx))->entryPage($schema, $entry);
    }

    /** A type's index page as it looks with its saved layout, live or not; null for private types. */
    public function previewIndex(string $type, string $locale = ''): ?string
    {
        $schema = $this->ctx->type($type);
        $this->ctx->preview = true;
        $this->ctx->previewBanner = true;

        if ($schema === null || !$this->ctx->isPublicType($type)) {
            return null;
        }

        $this->ctx->locale = $this->ctx->content->locale($locale);

        return (new PageRenderer($this->ctx))->indexPage($schema);
    }

    /** The homepage with the saved header and footer, also while the website is off. */
    public function previewHome(string $locale = ''): string
    {
        $this->ctx->preview = true;
        $this->ctx->previewBanner = true;
        $this->ctx->locale = $this->ctx->content->locale($locale);
        $renderer = new PageRenderer($this->ctx);
        $type = $this->ctx->settings()['homepage']['type'];

        // Without a homepage the placeholder page has no header or footer; show them around a stand-in.
        return $type !== '' && $this->ctx->isPublicType($type) ? $this->home($renderer, 1)['body'] : $renderer->regionPreview('header');
    }

    private function home(PageRenderer $renderer, int $page): array
    {
        $home = $this->ctx->settings()['homepage'];
        $type = $home['type'] !== '' && $this->ctx->isPublicType($home['type']) ? $this->ctx->type($home['type']) : null;

        if ($type === null) {
            return $this->html($renderer->autoHome());
        }

        if ($home['entry'] !== '') {
            $entry = $this->ctx->findEntry($home['type'], $home['entry']);

            return $entry !== null ? $this->html($renderer->entryPage($type, $entry, $page), $renderer->status) : ($this->ctx->locale === $this->ctx->defaultLocale() ? $this->html($renderer->autoHome()) : $this->notFound($renderer, '/'));
        }

        return $this->ctx->hasListPage($home['type']) ? $this->html($renderer->indexPage($type, $page), $renderer->status) : $this->html($renderer->autoHome());
    }

    /** @return array{0: string, 1: string} locale ('' for the default) and the remaining path */
    private function splitLocale(string $path): array
    {
        $segments = explode('/', trim($path, '/'), 2);
        $first = $segments[0] ?? '';

        if ($first !== '' && $first !== $this->ctx->defaultLocale() && in_array($first, $this->ctx->locales(), true)) {
            return [$first, '/' . ($segments[1] ?? '')];
        }

        return [$this->ctx->defaultLocale(), $path];
    }

    private function robots(): string
    {
        $rules = "User-agent: *\nAllow: /\nDisallow: " . $this->ctx->basePath . "/admin\n";

        // Hidden sites stay crawlable, so search engines can read the pages' noindex and drop them.
        if (!empty($this->ctx->settings()['noindex'])) {
            return $rules;
        }

        return $rules . "\nSitemap: " . $this->ctx->absolute($this->ctx->basePath . '/sitemap.xml') . "\n";
    }

    private function sitemap(): string
    {
        $locales = $this->ctx->locales() ?: [''];
        $urls = [];

        foreach ($locales as $locale) {
            $this->ctx->locale = $locale;
            $home = $this->ctx->settings()['homepage'];
            if ($home['type'] !== '') {
                if ($home['entry'] === '' || $this->ctx->findEntry($home['type'], $home['entry']) !== null) $urls[$this->ctx->homeUrl($locale)] = null;
            }

            foreach ($this->ctx->site->types() as $name => $type) {
                $route = $this->ctx->route($name);
                if ($route === null || !$route['sitemap']) {
                    continue;
                }

                $index = $this->ctx->indexUrl($name, $locale);
                if ($index !== null) {
                    $urls[$index] ??= null;
                }

                foreach ($this->ctx->content->query($name, ['locale' => $locale], false)['data'] as $entry) {
                    $url = $this->ctx->entryUrl($name, $entry, $locale);
                    if ($url !== null) {
                        $urls[$url] = (string) ($entry['updated_at'] ?? '');
                    }
                }
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($urls as $url => $modified) {
            $xml .= '  <url><loc>' . Security::e($this->ctx->absolute($url)) . '</loc>'
                . ($modified ? '<lastmod>' . Security::e(substr($modified, 0, 10)) . '</lastmod>' : '')
                . '</url>' . "\n";
        }

        return $xml . '</urlset>' . "\n";
    }

    private function notFound(PageRenderer $renderer, string $path): array
    {
        return $this->response(404, $renderer->notFound($this->ctx->path($path)), 'text/html; charset=utf-8');
    }

    private function html(string $body, int $status = 200): array
    {
        return $this->response($status, $body, 'text/html; charset=utf-8');
    }

    private function response(int $status, string $body, string $contentType): array
    {
        return ['status' => $status, 'body' => $body, 'content_type' => $contentType, 'cacheable' => !$this->ctx->preview];
    }
}
