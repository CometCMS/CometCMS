<?php

declare(strict_types=1);

namespace CometCMS\Site;

use CometCMS\Content\ContentRepository;
use CometCMS\Content\PageTree;
use CometCMS\Media\MediaRepository;
use CometCMS\Localization\LocalizedValue;

/**
 * Everything one website render needs: configuration, content access, URL
 * building and the current locale. Builder previews pass unsaved settings,
 * theme, menus and layouts as overrides so they render exactly like the
 * live site will after saving.
 *
 * Content is read the way the public API reads it: only public content types
 * and only published (or due scheduled) entries, unless a preview explicitly
 * allows the one entry being previewed.
 */
final class SiteContext
{
    public string $locale = '';

    /** Builder previews mark nodes with data attributes and show placeholders. */
    public bool $annotate = false;

    /** Draft previews and builder previews are never indexed or cached. */
    public bool $preview = false;

    /** Draft previews of an entry carry a notice that the page is not live yet. */
    public bool $previewBanner = false;

    /**
     * The page builder of one entry: the page field it edits and the draft
     * sections ({field, nodes}), shown instead of the entry's saved content.
     */
    public ?array $pageDraft = null;

    /** Visitor filters of entry lists, by list (see ListFilters::fromQuery). */
    public array $listParams = [];

    /** Where the search results page answers; it takes precedence over entries with the same path. */
    public const SEARCH_PATH = '/search';

    /** The query the search results page was opened with ('' elsewhere; see SiteSearch::normalizeQuery). */
    public string $searchQuery = '';

    /** Whether the search results page is rendered: the only page whose lists can show search results. */
    public bool $searchPage = false;

    /**
     * Pages opened in the link modal (?embed=1): only the page's main content,
     * without header, footer or modals of its own.
     */
    public bool $embed = false;

    /** Signed-in full-page previews keep navigation and admin actions together. */
    public bool $browsePreview = false;
    public string $previewToken = '';
    private string $previewReturnUrl = '';
    public ?string $previewTime = null;
    private array $previewEntries = [];
    public ?array $adminUser = null;
    public ?\Closure $adminCan = null;

    private array $overrides = [];
    private bool $overridesRead = false;
    private array $mediaItems = [];
    private array $entries = [];
    private array $trees = [];
    private array $components = [];
    private array $cardTemplates = [];
    private array $templateLists = [];
    private ?FormRepository $forms = null;
    private array $formCache = [];

    public function __construct(
        public readonly SiteRepository $site,
        public readonly ContentRepository $content,
        public readonly MediaRepository $media,
        public readonly string $basePath = '',
        public readonly string $origin = '',
    ) {}

    public static function make(SiteRepository $site, string $basePath = '', string $origin = ''): self
    {
        $workspace = $site->workspace();

        return new self($site, ContentRepository::make($workspace), new MediaRepository($workspace), rtrim($basePath, '/'), rtrim($origin, '/'));
    }

    /** The same preview navigation and permissions, using only current saved data. */
    public function withoutOverrides(): self
    {
        $saved = new self($this->site, $this->content, $this->media, $this->basePath, $this->origin);
        $saved->locale = $this->locale;
        $saved->preview = $this->preview;
        $saved->annotate = $this->annotate;
        $saved->browsePreview = $this->browsePreview;
        $saved->embed = $this->embed;
        $saved->previewToken = $this->previewToken;
        $saved->previewReturnUrl = $this->previewReturnUrl;
        $saved->previewTime = $this->previewTime;
        $saved->adminUser = $this->adminUser;
        $saved->adminCan = $this->adminCan;
        // No banner: the comparison must exclude the status label itself.
        return $saved;
    }

    // ─── Configuration (with preview overrides) ──────────────────────────────

    /** $url as this page shows it: embedded pages stay embedded, e.g. after a form is sent. */
    public function embedUrl(string $url): string
    {
        return $this->embed ? $url . (str_contains($url, '?') ? '&' : '?') . 'embed=1' : $url;
    }

    public function override(string $key, mixed $value): void
    {
        $this->overrides[$key] = $value;
    }

    /** Whether the page rendered so far used any preview override or unsaved entry. */
    public function readsOverrides(): bool
    {
        return $this->overridesRead;
    }

    private function overridden(string $key): mixed
    {
        if (!array_key_exists($key, $this->overrides)) {
            return null;
        }
        $this->overridesRead = true;

        return $this->overrides[$key];
    }

    public function settings(): array
    {
        $raw = $this->overridden('settings') ?? $this->site->settings();
        $resolved = LocalizedValue::tree($raw, $this->locale ?: $this->defaultLocale(), $this->defaultLocale());
        foreach ($raw as $key => $value) if (LocalizedValue::is($value)) $resolved['_localized'][$key] = true;
        return $resolved;
    }

    public function theme(): array
    {
        return $this->overridden('theme') ?? $this->site->theme();
    }

    public function menus(): array
    {
        return LocalizedValue::tree($this->overridden('menus') ?? $this->site->menus(), $this->htmlLang(), $this->defaultLocale());
    }

    public function menu(string $name): ?array
    {
        foreach ($this->menus() as $menu) {
            if (($menu['name'] ?? '') === $name) {
                return $menu;
            }
        }

        return null;
    }

    public function region(string $region): array
    {
        return $this->overridden('region:' . $region) ?? $this->site->region($region);
    }

    /** A region document's nodes when it is being edited or was customized, else null. */
    public function customRegion(string $region): ?array
    {
        return $this->overridden('region:' . $region) ?? ($this->site->regionCustomized($region) ? $this->site->region($region) : null);
    }

    /** A page kind's templates in order, with unsaved ones while the builder previews them. */
    public function templates(string $type, string $variant): array
    {
        return ($this->overridden('templates') ?? [])[$type][$variant]
            ?? $this->templateLists[$type . ':' . $variant] ??= $this->site->templates($type, $variant);
    }

    /**
     * The template a page of $type's $variant uses for $entry and why (see
     * SiteRepository::resolveTemplate()).
     *
     * @return array{template: array, reason: string}
     */
    public function template(string $type, string $variant, ?array $entry): array
    {
        return SiteRepository::resolveTemplate($this->templates($type, $variant), $entry, $entry !== null && is_string($entry['page_template'] ?? null) ? $entry['page_template'] : '');
    }

    /**
     * The template the builder shows around the content area of template
     * $area for $entry. `live` is the id of the template $entry uses on the
     * website; when that is another one, the reason is "edited".
     *
     * @return array{template: array, reason: string, live: string}
     */
    public function areaTemplate(string $type, string $variant, ?array $entry, string $area): array
    {
        $resolved = $this->template($type, $variant, $entry);
        $live = (string) $resolved['template']['id'];
        if ($live !== $area) {
            foreach ($this->templates($type, $variant) as $template) {
                if ($template['id'] === $area) return ['template' => $template, 'reason' => 'edited', 'live' => $live];
            }
        }

        return $resolved + ['live' => $live];
    }

    /**
     * What a template's header or footer shows: the site's own region, a
     * placed section component, or null for none. A component that is gone
     * (or no longer fits) falls back to the region.
     */
    public function partNodes(string $region, string $value, string $type = ''): ?array
    {
        if ($value === LayoutSchema::FRAME_NONE) {
            return null;
        }
        if ($value !== LayoutSchema::FRAME_DEFAULT && SiteRepository::fitsFrame($this->component($value))) {
            $node = LayoutSchema::node(LayoutSchema::COMPONENT, ['component' => $value, 'root' => 'section']);
            $node['id'] = 'frame-' . $region;

            return [$node];
        }

        return $this->region($region);
    }

    /** A template's content area; the builder overrides the one being edited. */
    public function layout(string $type, string $variant, string $area = SiteRepository::DEFAULT_TEMPLATE): array
    {
        return $this->overridden('layout:' . $type . ':' . $variant . ':' . $area) ?? $this->site->layout($type, $variant, $area);
    }

    /** The standard card of a content type (see SiteRepository::cardTemplate()). */
    public function cardTemplate(string $type): array
    {
        return $this->cardTemplates[$type] ??= $this->site->cardTemplate($type);
    }

    /** A reusable component; the builder overrides the one being edited. */
    public function component(string $id): ?array
    {
        if (array_key_exists('component:' . $id, $this->overrides)) {
            return $this->overridden('component:' . $id);
        }
        if (!array_key_exists($id, $this->components)) {
            $this->components[$id] = $this->site->component($id);
        }

        return $this->components[$id];
    }

    /** A website form; null when it does not exist (any more). */
    public function form(string $id): ?array
    {
        if ($id === '') {
            return null;
        }
        if (!array_key_exists($id, $this->formCache)) {
            $this->formCache[$id] = $this->forms()->find($id);
        }

        $form = LocalizedValue::tree($this->formCache[$id], $this->htmlLang(), $this->defaultLocale());
        if ($form !== null) foreach ($this->formCache[$id] as $key => $value) if (LocalizedValue::is($value)) $form['_localized'][$key] = true;
        return $form;
    }

    public function forms(): FormRepository
    {
        return $this->forms ??= new FormRepository($this->site);
    }

    /** Where a form posts to (see SiteController::submitForm()). */
    public function formAction(string $id): string
    {
        return $this->basePath . '/_site/forms/' . rawurlencode($this->site->workspace()->slug()) . '/' . rawurlencode($id);
    }

    /** Where search elements look up their hits (see SiteController::search()). */
    public function searchUrl(): string
    {
        if ($this->browsePreview) {
            return $this->adminUrl('/api/site/preview-search', $this->previewNavigationQuery());
        }
        return $this->basePath . '/_site/search/' . rawurlencode($this->site->workspace()->slug());
    }

    public function siteTitle(): string
    {
        $title = (string) ($this->settings()['title'] ?? '');

        return $title !== '' ? $title : $this->site->workspaceLabel();
    }

    public function type(string $name): ?array
    {
        return LocalizedValue::tree($this->site->types()[$name] ?? null, $this->htmlLang(), $this->defaultLocale());
    }

    /** Public content types can be shown; private ones never leave the API. */
    public function isPublicType(string $name): bool
    {
        $type = $this->type($name);

        return $type !== null && ($type['visibility'] ?? 'public') !== 'private';
    }

    public function route(string $type): ?array
    {
        $route = $this->settings()['routes'][$type] ?? null;

        return is_array($route) && $route['enabled'] && $this->isPublicType($type) && in_array($this->locale ?: $this->defaultLocale(), $this->locales(), true) ? $route : null;
    }

    public function defaultLocale(): string
    {
        return (string) (($this->overrides['settings'] ?? $this->site->settings())['default_locale'] ?? 'en');
    }

    /** @return list<string> */
    public function locales(): array
    {
        return array_values((array) ($this->settings()['locales'] ?? []));
    }

    public function htmlLang(): string
    {
        $locale = $this->locale !== '' ? $this->locale : $this->defaultLocale();

        return $locale !== '' ? $locale : 'en';
    }

    // ─── URLs ────────────────────────────────────────────────────────────────

    /** Site-relative path (without locale prefix) to a full URL path. */
    public function path(string $path, ?string $locale = null): string
    {
        $locale ??= $this->locale;
        $prefix = $locale !== '' && $locale !== $this->defaultLocale() && in_array($locale, $this->locales(), true) ? '/' . $locale : '';
        $path = '/' . ltrim($path, '/');

        if ($this->browsePreview) {
            $parts = parse_url($prefix . $path);
            $query = [];
            parse_str($parts['query'] ?? '', $query);
            return $this->basePath . '/admin/site-preview?' . http_build_query(array_merge($query, [
                'workspace' => $this->site->workspace()->slug(),
                'path' => $parts['path'] ?? '/',
                ...$this->previewNavigationQuery(),
            ])) . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
        }

        if ($prefix !== '' && $path === '/') {
            return $this->basePath . $prefix;
        }

        return $this->basePath . $prefix . ($path === '/' && $this->basePath !== '' ? '/' : $path);
    }

    public function absolute(string $url): string
    {
        return str_starts_with($url, '/') && !str_starts_with($url, '//') ? $this->origin . $url : $url;
    }

    /** The search results page, below the locale prefix like every page. */
    public function searchPageUrl(?string $locale = null): string
    {
        return $this->path(self::SEARCH_PATH, $locale);
    }

    public function homeUrl(?string $locale = null): string
    {
        return $this->path('/', $locale);
    }

    public function entryPath(string $type, array $entry): ?string
    {
        $home = $this->settings()['homepage'] ?? [];
        $slug = (string) ($entry['slug'] ?? $entry['id'] ?? '');
        $schema = $this->type($type);

        if ($schema === null || !$this->isPublicType($type)) {
            return null;
        }

        if (($home['type'] ?? '') === $type && ($home['entry'] ?? '') === ($entry['uid'] ?? $entry['id'])) {
            return '/';
        }

        $route = $this->route($type);
        if ($route === null || $slug === '') {
            return null;
        }

        if ($this->isHierarchical($type)) {
            $path = $this->treePath($type, $entry);
            return $path === null ? null : str_replace('{slug}', PageTree::encode($path), $route['pattern']);
        }

        return str_replace('{slug}', rawurlencode($slug), $route['pattern']);
    }

    public function isHierarchical(string $type): bool
    {
        return !empty($this->type($type)['hierarchical']);
    }

    /**
     * The slugs from the top-level page down to $entry (already resolved for
     * the current locale), or null when a parent page is not public.
     */
    private function treePath(string $type, array $entry): ?string
    {
        $tree = $this->tree($type);
        $locale = $this->locale ?: $this->defaultLocale();
        $segments = [(string) ($entry['slug'] ?? '')];
        foreach (PageTree::ancestors($tree, $entry) as $uid) {
            $ancestor = $tree[$uid];
            if (!$this->preview && !$this->browsePreview && !$this->content->isPubliclyVisible($ancestor, $locale)) return null;
            array_unshift($segments, (string) $this->content->resolveLocaleFields($ancestor, $locale)['slug']);
        }
        return implode('/', $segments);
    }

    /** @return array<string, array> saved entries of a hierarchical type by uid */
    private function tree(string $type): array
    {
        return $this->trees[$type] ??= PageTree::byUid($this->content->all($type));
    }

    /** A visible entry of a hierarchical type by its full slug path, e.g. "about/team". */
    public function findEntryByPath(string $type, string $path): ?array
    {
        $slugs = explode('/', $path);
        $candidates = $this->tree($type);
        $locale = $this->locale ?: $this->defaultLocale();
        $parent = '';
        $match = null;
        // Walk down from the top level, one slug per level.
        foreach ($slugs as $slug) {
            $match = null;
            foreach ($candidates as $uid => $candidate) {
                if (PageTree::parent($candidate) === $parent && (string) $this->content->resolveLocaleFields($candidate, $locale)['slug'] === $slug) {
                    $match = (string) $uid;
                    break;
                }
            }
            if ($match === null) return null;
            $parent = $match;
        }
        $entry = $this->findEntry($type, $match);
        return $entry !== null && $this->entryPath($type, $entry) !== null ? $entry : null;
    }

    public function entryUrl(string $type, array $entry, ?string $locale = null): ?string
    {
        $before = $this->locale;
        $this->locale = $locale ?? ($this->locale ?: $this->defaultLocale());
        try {
            if (!in_array($this->locale, $this->locales(), true)) return null;
            $entry = $this->content->resolveLocaleFields($entry, $this->locale, !$this->preview);
            $path = $this->entryPath($type, $entry);
            return $path === null ? null : $this->path($path, $this->locale);
        } finally { $this->locale = $before; }
    }

    public function indexPath(string $type): ?string
    {
        $home = $this->settings()['homepage'] ?? [];
        $schema = $this->type($type);

        if ($schema === null || !$this->isPublicType($type) || !$this->hasListPage($type)) {
            return null;
        }

        if (($home['type'] ?? '') === $type && ($home['entry'] ?? '') === '') {
            return '/';
        }

        $route = $this->route($type);

        return $route !== null && $route['index_path'] !== '' ? $route['index_path'] : null;
    }

    /** Whether the type has its optional list page turned on. */
    public function hasListPage(string $type): bool
    {
        return !empty($this->settings()['routes'][$type]['index_enabled']);
    }

    public function indexUrl(string $type, ?string $locale = null): ?string
    {
        $before = $this->locale;
        $this->locale = $locale ?? ($this->locale ?: $this->defaultLocale());
        try {
            if (!in_array($this->locale, $this->locales(), true)) return null;
            $path = $this->indexPath($type);
            return $path === null ? null : $this->path($path, $this->locale);
        } finally { $this->locale = $before; }
    }

    public function mediaUrl(string $file, array $query = []): string
    {
        $url = $this->basePath . '/media/' . rawurlencode($this->site->workspace()->slug()) . '/' . rawurlencode($file);

        return $query === [] ? $url : $url . '?' . http_build_query($query);
    }

    /** The flag image of a language, or null when there is no flag for it. */
    public function flagUrl(string $locale): ?string
    {
        $country = Flags::country($locale);

        return $country !== '' ? $this->basePath . '/_site/flags/' . $country . '.svg' : null;
    }

    public function stylesheetUrl(): string
    {
        return $this->basePath . '/_site/' . rawurlencode($this->site->workspace()->slug()) . '/style.css?v=' . $this->site->stylesheetVersion();
    }

    public function customStylesheetUrl(): string
    {
        return $this->basePath . '/_site/' . rawurlencode($this->site->workspace()->slug()) . '/theme/style.css?v=' . $this->site->customStylesheetVersion();
    }

    // ─── Content ─────────────────────────────────────────────────────────────

    /** A visible entry by slug or id, with the current locale applied. */
    public function findEntry(string $type, string $identifier, bool $allowHidden = false): ?array
    {
        if (!$this->isPublicType($type) || !preg_match('/^[A-Za-z0-9_-]+$/', $identifier)) {
            return null;
        }

        if (!in_array($this->locale ?: $this->defaultLocale(), $this->locales(), true)) return null;
        $key = $type . '|' . $identifier . '|' . ($allowHidden ? '1' : '0') . '|' . $this->locale;
        if (array_key_exists($key, $this->entries)) {
            return $this->entries[$key];
        }

        $entry = $this->previewEntries[$type][$identifier] ?? null;
        $this->overridesRead = $this->overridesRead || $entry !== null;
        $entry ??= $this->content->findByIdentifier($type, $identifier, false, $this->locale);
        if ($entry !== null && $this->browsePreview && !$this->adminAllows('content.read', $type, $entry)) {
            $entry = null;
        }
        if ($entry !== null && !$allowHidden && !$this->browsePreview && !$this->content->isPubliclyVisible($entry, $this->locale ?: $this->defaultLocale())) {
            $entry = null;
        }

        return $this->entries[$key] = $entry === null ? null : $this->localize($type, $entry);
    }

    public function overrideEntry(string $type, array $entry, string $original = ''): void
    {
        foreach (array_filter([$original, $entry['id'] ?? '', $entry['slug'] ?? '', $entry['uid'] ?? '']) as $identifier) {
            $this->previewEntries[$type][$identifier] = $entry;
        }
        $this->entries = [];
    }

    public function adminAllows(string $action, string $type = '', array $entry = [], array $fields = []): bool
    {
        return $this->adminCan !== null && ($this->adminCan)($action, [
            'type' => 'content', 'collection' => $type, 'entry' => $entry, 'locale' => $this->locale,
            ...($fields !== [] ? ['fields' => $fields] : []),
        ]);
    }

    /** Accept only local admin UI paths; preview URLs must never become return loops. */
    public function setPreviewReturnUrl(mixed $url): void
    {
        $this->previewReturnUrl = '';
        if (!is_string($url) || strlen($url) > 8192 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) return;
        $prefix = $this->basePath . '/admin/';
        if (!str_starts_with($url, $prefix)) return;
        $parts = parse_url($url);
        if ($parts === false || isset($parts['host']) || isset($parts['scheme'])) return;
        $path = rawurldecode($parts['path'] ?? '');
        if (!str_starts_with($path, $prefix)) return;
        $route = substr($path, strlen($prefix));
        if (!preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*/?$~D', $route)
            || in_array(explode('/', $route)[0], ['api', 'site-preview', 'login', 'logout', 'setup'], true)) return;
        parse_str($parts['query'] ?? '', $query);
        // Returning to this workspace must not depend on another tab's stored selection.
        $query['workspace'] = $this->site->workspace()->slug();
        $this->previewReturnUrl = $parts['path'] . '?' . http_build_query($query)
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }

    public function previewReturnUrl(): string
    {
        return $this->previewReturnUrl !== '' ? $this->previewReturnUrl : $this->adminUrl('/dashboard');
    }

    public function previewNavigationQuery(): array
    {
        return [
            ...($this->previewToken !== '' ? ['snapshot' => $this->previewToken] : []),
            ...($this->previewReturnUrl !== '' ? ['returnTo' => $this->previewReturnUrl] : []),
        ];
    }

    public function adminUrl(string $path, array $query = []): string
    {
        return $this->basePath . '/admin' . $path . '?' . http_build_query([
            'workspace' => $this->site->workspace()->slug(), 'locale' => $this->locale, ...$query,
        ]);
    }

    /**
     * Published entries of a public type, localized, filtered and sorted.
     *
     * @return array{data: list<array>, total: int}
     */
    public function query(string $type, array $params): array
    {
        if (!$this->isPublicType($type)) {
            return ['data' => [], 'total' => 0];
        }

        if (!in_array($this->locale ?: $this->defaultLocale(), $this->locales(), true)) return ['data' => [], 'total' => 0];
        if ($this->locale !== '') {
            $params['locale'] = $this->locale;
        }

        if ($this->browsePreview) {
            $offset = max(0, (int) ($params['offset'] ?? 0));
            $limit = isset($params['limit']) ? max(1, (int) $params['limit']) : null;
            unset($params['offset'], $params['limit'], $params['trash']);
            $overrides = [];
            foreach ($this->previewEntries[$type] ?? [] as $entry) $overrides[$entry['uid'] ?? $entry['id']] = $entry;
            $this->overridesRead = $this->overridesRead || $overrides !== [];
            $result = $this->content->query($type, $params, true, array_values($overrides));
            $entries = array_values(array_filter($result['data'], fn(array $entry): bool => $this->adminAllows('content.read', $type, $entry)));
            return ['data' => array_slice($entries, $offset, $limit), 'total' => count($entries)];
        }

        $result = $this->content->query($type, $params, false);

        return ['data' => $result['data'], 'total' => (int) $result['meta']['total']];
    }

    public function localize(string $type, array $entry): array
    {
        if ($this->locale !== '' && in_array($this->locale, $this->locales(), true)) {
            return $this->content->resolveLocaleFields($entry, $this->locale, !$this->preview);
        }

        return $this->content->resolveLocaleFields($entry, $this->defaultLocale(), !$this->preview);
    }

    /** Public media metadata, or null for missing and private files. */
    public function mediaItem(string $file): ?array
    {
        $file = basename($file);

        if ($file === '') {
            return null;
        }

        if (!array_key_exists($file, $this->mediaItems)) {
            $item = $this->media->find($file);
            $this->mediaItems[$file] = $item !== null && ($item['visibility'] ?? 'public') !== 'private' ? $item : null;
        }

        return LocalizedValue::tree($this->mediaItems[$file], $this->htmlLang(), $this->defaultLocale());
    }

    public function variantDescriptor(string $file): ?array
    {
        return $this->media->variantDescriptor($file);
    }
}
