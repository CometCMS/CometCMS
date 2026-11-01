<?php

declare(strict_types=1);

namespace CometCMS\Controllers\Admin;

use CometCMS\Auth\Auth;
use CometCMS\Content\ContentRepository;
use CometCMS\Core\Http;
use CometCMS\Core\ValidationException;
use CometCMS\Site\FormRepository;
use CometCMS\Site\FormCaptchaSettings;
use CometCMS\Site\LayoutSchema;
use CometCMS\Site\PageRenderer;
use CometCMS\Site\PreviewRepository;
use CometCMS\Site\SiteContext;
use CometCMS\Site\SiteRepository;
use CometCMS\Site\SiteScripts;
use CometCMS\Site\SiteSearch;
use CometCMS\Site\SiteService;
use CometCMS\Site\Theme;
use CometCMS\Workspaces\WorkspaceContext;

/**
 * Admin API for the optional website: settings, theme, menus, header and
 * footer, per-type layouts, reusable components and the live previews used
 * by the builder.
 */
final class SiteController extends BaseController
{
    private SiteRepository $site;
    private FormRepository $forms;

    public function __construct(Http $http)
    {
        parent::__construct($http);
        $this->site = new SiteRepository(WorkspaceContext::active());
        $this->forms = new FormRepository($this->site);
    }

    public function index(): never
    {
        $user = $this->requirePermission('site.manage', ['resource' => 'site:*']);

        $layouts = [];
        foreach ($this->site->types() as $name => $type) {
            foreach ($this->site->enabledVariantsFor($type) as $variant) {
                $layouts[$name][$variant] = $this->site->layoutCustomized($name, $variant);
            }
        }
        $templates = $this->site->allTemplates();

        $this->json(['data' => [
            'settings' => $this->site->settings(),
            'form_settings' => (new FormCaptchaSettings($this->site->workspace()))->admin(),
            'theme' => $this->site->theme(),
            'theme_presets' => $this->site->themePresets(),
            'menus' => $this->site->menus(),
            'menus_revision' => \CometCMS\Localization\LocalizedValue::hash($this->site->menus()),
            'menu_limits' => SiteRepository::MENU_LIMITS,
            // Objects, also when empty: the admin assigns into them by type.
            'templates' => $templates ?: new \stdClass(),
            'templates_revision' => \CometCMS\Localization\LocalizedValue::hash($templates),
            'regions' => array_combine(LayoutSchema::REGIONS, array_map(fn(string $region): bool => $this->site->regionCustomized($region), LayoutSchema::REGIONS)),
            'special_pages' => array_combine(LayoutSchema::SPECIAL_PAGES, array_map(fn(string $page): bool => $this->site->regionCustomized($page), LayoutSchema::SPECIAL_PAGES)),
            'layouts' => $layouts,
            'types' => array_values($this->site->types()),
            'available_locales' => $this->site->availableLocales(),
            ...(new \CometCMS\Site\FontRegistry($this->site->workspace()))->metadata(rtrim($this->http->url('/'), '/')),
            'theme_defaults' => Theme::defaults(),
            'site_url' => $this->siteUrl(),
            'workspace' => $this->site->workspace()->slug(),
            'workspace_label' => $this->site->workspaceLabel(),
            'can_edit_css' => Auth::allows($user, 'admin'),
        ]]);
    }

    /** Lightweight summary for editors: is the website live, which types are published. */
    public function status(): never
    {
        $user = $this->requireUser();
        $settings = $this->site->settings();
        $types = [];

        foreach ($settings['routes'] as $name => $route) {
            $types[$name] = [
                // A type with frontend pages always makes the website live.
                'enabled' => $route['enabled'],
                'pages_enabled' => $route['enabled'],
                'routed' => $route['enabled'] || ($settings['homepage']['type'] ?? '') === $name,
                'pattern' => $route['pattern'],
                'index_enabled' => $route['index_enabled'],
                'index_path' => $route['index_path'],
            ];
        }

        $homepageEditor = $this->homepageEditor($settings);
        $this->json(['data' => [
            'enabled' => $settings['enabled'],
            'can_manage' => $this->can($user, 'site.manage', ['resource' => 'site:*']),
            'site_url' => $this->siteUrl(),
            'homepage_editor' => $homepageEditor,
            'homepage_preview' => $homepageEditor !== null
                ? $this->http->url('/admin/site-preview') . '?' . http_build_query(['workspace' => $this->site->workspace()->slug()])
                : null,
            'types' => $types,
        ]]);
    }

    /** Start editing the actual homepage, including its selected entry. */
    private function homepageEditor(array $settings): ?string
    {
        $homepage = $settings['homepage'];
        $type = $this->site->types()[$homepage['type']] ?? null;
        if ($type === null || ($type['visibility'] ?? 'public') === 'private') {
            return null;
        }

        $variant = $homepage['entry'] !== '' ? 'detail' : 'index';
        $path = '/page-builder/builder/layout/' . rawurlencode($type['name']) . '/' . $variant;
        if ($homepage['entry'] !== '') {
            $entry = $this->findAdminEntry($type['name'], $homepage['entry']);
            if ($entry === null) {
                return null;
            }
            // Builder samples are selected by stable ID, the same in every language.
            $path .= '?entry=' . rawurlencode(ContentRepository::make($this->site->workspace())->stableId($entry));
        }

        return $path;
    }

    /** Live URL and draft preview URL for one entry. */
    public function links(string $type, string $id): never
    {
        $user = $this->requireUser();
        $settings = $this->site->settings();
        $context = $this->context();
        $entry = $context->content->findByIdentifier($type, $id);
        $this->requirePermission('content.read', ['type' => 'content', 'collection' => $type, 'entry' => $entry ?? []]);
        $url = null;

        if ($settings['enabled'] && $entry !== null && $context->content->isPubliclyVisible($entry, (string) ($_GET['locale'] ?? $context->defaultLocale()))) {
            $locale = (string) ($_GET['locale'] ?? '');
            $url = $this->liveUrl($context->entryUrl($type, $entry, in_array($locale, $context->locales(), true) ? $locale : null), $context);
        }

        $routed = $context->route($type) !== null || ($settings['homepage']['type'] ?? '') === $type;

        $this->json(['data' => [
            'enabled' => $settings['enabled'],
            'routed' => $routed,
            'url' => $url,
            'url_conflict' => $this->liveUrlConflict($url),
            'previewable' => $entry !== null && $context->isPublicType($type),
            'can_manage' => $this->can($user, 'site.manage', ['resource' => 'site:*']),
            // Page fields with a place in the content area this entry's template shows.
            'page_fields' => $routed && isset($this->site->types()[$type]) ? $this->placedPageFields($this->site->types()[$type], $type, $entry) : [],
            'template' => $routed && $entry !== null ? $this->entryTemplate($type, $entry) : null,
            // The templates an entry can choose instead of the automatic one.
            'templates' => $routed && $context->isPublicType($type) ? array_map(static fn(array $template): array => ['id' => $template['id'], 'name' => $template['name']], $this->site->templates($type, 'detail')) : [],
        ]]);
    }

    public function updateSettings(): never
    {
        $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $this->verifyCsrf();

        try {
            $settings = $this->site->saveSettings($this->requestJson());
        } catch (ValidationException $e) {
            $this->json(['error' => ['code' => 'validation_failed', 'message' => $e->getMessage(), 'fields' => $e->fields()]], 422);
        }

        $this->logger->info('site.settings_updated', ['workspace' => $this->site->workspace()->slug()]);
        $this->json(['data' => $settings]);
    }

    public function updateFormSettings(): never
    {
        $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $this->verifyCsrf();
        $settings = $this->validated(fn() => (new FormCaptchaSettings($this->site->workspace()))->save($this->requestJson()));
        $this->logger->info('site.form_settings_updated', ['workspace' => $this->site->workspace()->slug()]);
        $this->json(['data' => $settings]);
    }

    public function updateTheme(): never
    {
        $user = $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $this->verifyCsrf();
        $input = $this->requestJson();
        $current = $this->site->theme();

        // Custom CSS is a free-form value; only administrators may change it.
        if (!Auth::allows($user, 'admin')) {
            $input['custom_css'] = $current['custom_css'];
        }

        $theme = $this->site->saveTheme($input, $user);
        $this->logger->info('site.theme_updated', ['workspace' => $this->site->workspace()->slug()]);
        $this->json(['data' => $theme]);
    }

    /** Body: {name, theme}: the design to keep under that name. */
    public function createThemePreset(): never
    {
        $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $this->verifyCsrf();
        $input = $this->requestJson();
        $preset = $this->validated(fn() => $this->site->createThemePreset($input['name'] ?? '', $input['theme'] ?? null));
        $this->logger->info('site.theme_preset_created', ['preset' => $preset['id']]);
        $this->json(['data' => $preset], 201);
    }

    public function deleteThemePreset(string $id): never
    {
        $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $this->verifyCsrf();
        if ($this->site->themePreset($id) === null) {
            $this->json(['error' => ['code' => 'not_found', 'message' => 'Preset not found.']], 404);
        }
        $this->site->deleteThemePreset($id);
        $this->logger->info('site.theme_preset_deleted', ['preset' => $id]);
        $this->json(['data' => ['deleted' => true]]);
    }

    public function updateMenus(): never
    {
        $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $this->verifyCsrf();
        try {
            $menus = $this->site->saveMenus($this->requestJson()['menus'] ?? [], $this->requestJson()['revision'] ?? null);
        } catch (ValidationException $e) {
            $this->json(['error' => ['code' => 'validation_failed', 'message' => $e->getMessage(), 'fields' => $e->fields()]], 422);
        }
        $this->logger->info('site.menus_updated', ['count' => count($menus)]);
        $this->json(['data' => $menus, 'meta' => ['revision' => \CometCMS\Localization\LocalizedValue::hash($menus)]]);
    }

    /** Body: {templates: {type: {variant: [template, …]}}, revision}; page kinds left out stay as they are. */
    public function updateTemplates(): never
    {
        $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $this->verifyCsrf();
        $templates = $this->validated(fn() => $this->site->saveTemplates($this->requestJson()['templates'] ?? [], $this->requestJson()['revision'] ?? null));
        $this->logger->info('site.templates_updated', ['workspace' => $this->site->workspace()->slug()]);
        $this->json(['data' => $templates ?: new \stdClass(), 'meta' => ['revision' => \CometCMS\Localization\LocalizedValue::hash($templates)]]);
    }

    /**
     * Body: {name, from?, parts?: {header, footer, conditions}}: a new template
     * of the page kind, starting as a copy of `from` (default: the default
     * template). Saved right away, so its content can be designed at once.
     */
    public function createTemplate(string $type, string $variant): never
    {
        $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $this->verifyCsrf();
        $this->requireType($type, $variant);
        $body = $this->requestJson();
        $from = is_string($body['from'] ?? null) ? $body['from'] : '';
        $template = $this->validated(fn() => $this->site->createTemplate($type, $variant, $body['name'] ?? '', $from, is_array($body['parts'] ?? null) ? $body['parts'] : []));
        $this->logger->info('site.template_created', ['type' => $type, 'variant' => $variant, 'template' => $template['id']]);
        $templates = $this->site->allTemplates();
        $this->json(['data' => $template, 'meta' => ['templates' => $templates ?: new \stdClass(), 'revision' => \CometCMS\Localization\LocalizedValue::hash($templates)]], 201);
    }

    /** The standard card of a type, for a collection that gets a card of its own. */
    public function cardTemplate(string $type): never
    {
        $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $this->requireType($type, 'detail');

        $this->json(['data' => ['nodes' => LayoutSchema::withNewIds($this->site->cardTemplate($type))]]);
    }

    /**
     * A template's content area for the builder. Query: template (default:
     * the entry's template, else the default template), entry.
     */
    public function layout(string $type, string $variant): never
    {
        $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $schema = $this->requireType($type, $variant);
        $identifier = LayoutSchema::key($_GET['entry'] ?? '');
        $sample = $variant === 'detail' && $identifier !== '' && ($_GET['template'] ?? '') === '' ? $this->findAdminEntry($type, $identifier) : null;
        $area = $sample !== null ? $this->entryTemplate($type, $sample)['id'] : $this->requestedTemplate($type, $variant, $_GET['template'] ?? null);

        $context = $this->context();
        $samples = $this->samples($type, $identifier);
        $sampleId = $variant === 'detail' ? $this->sampleUsing($type, $area, $samples) : $this->requestedSample($type);
        $shownEntry = $variant === 'detail' && $sampleId !== '' ? $context->findEntry($type, $sampleId, true) : null;

        $this->json(['data' => [
            'template' => $area,
            // What the canvas shows around the area before its first preview arrives.
            'shown_template' => self::shown($context->areaTemplate($type, $variant, $shownEntry, $area)),
            'nodes' => $this->site->layout($type, $variant, $area),
            'revision' => \CometCMS\Localization\LocalizedValue::hash($this->site->layout($type, $variant, $area)),
            'upgrade_required' => $this->site->needsLayoutUpgrade($type, $variant, $area),
            'customized' => $this->site->layoutCustomized($type, $variant, $area),
            // Reset to default loads this as an edit, so it can be undone before saving.
            'default_nodes' => LayoutSchema::defaultLayout($schema, $variant),
            'type' => $schema,
            'samples' => $samples,
            'sample' => $sampleId,
            'index_url' => $this->liveUrl($context->indexUrl($type), $context),
            'home_url' => $this->liveUrl($context->homeUrl(), $context),
            ...$this->builderContext($variant === 'detail'),
        ]]);
    }

    public function updateLayout(string $type, string $variant): never
    {
        $user = $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $this->verifyCsrf();
        $this->requireType($type, $variant);
        $area = $this->requestedTemplate($type, $variant, $this->requestJson()['template'] ?? null);
        $nodes = $this->validated(fn() => $this->site->saveLayout($type, $variant, $this->requestJson()['nodes'] ?? [], $user, 'saved', $this->requestJson()['revision'] ?? null, $area));
        $this->logger->info('site.layout_updated', ['type' => $type, 'variant' => $variant, 'template' => $area]);
        // Saving may have given areas back their storage (see SiteRepository::ensureAreas()).
        $this->json(['data' => ['nodes' => $nodes, 'revision' => \CometCMS\Localization\LocalizedValue::hash($nodes), 'customized' => true, 'type' => $this->site->types()[$type] ?? null]]);
    }

    public function resetLayout(string $type, string $variant): never
    {
        $user = $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $this->verifyCsrf();
        $this->requireType($type, $variant);
        $area = $this->requestedTemplate($type, $variant, $_GET['template'] ?? null);
        $nodes = $this->site->resetLayout($type, $variant, $user, 'reset', $area);
        $this->logger->info('site.layout_reset', ['type' => $type, 'variant' => $variant, 'template' => $area]);
        $this->json(['data' => ['nodes' => $nodes, 'revision' => \CometCMS\Localization\LocalizedValue::hash($nodes), 'customized' => false]]);
    }

    // ─── Content areas ───────────────────────────────────────────────────────

    /** The type's Entry content areas, where its templates place them and how many entries designed each. */
    public function areas(string $type): never
    {
        $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $this->requireType($type, 'detail');
        $this->json(['data' => $this->site->areas($type)]);
    }

    /** Delete an area and every entry's design in it; templates placing it show it as missing. */
    public function deleteArea(string $type, string $key): never
    {
        $user = $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $this->requirePermission('schema.update', ['type' => 'schema', 'name' => $type]);
        $this->verifyCsrf();
        $this->requireType($type, 'detail');
        $this->validated(fn() => $this->site->deleteArea($type, $key));
        $this->logger->info('site.area_deleted', ['type' => $type, 'field' => $key, 'user_id' => $user['id'] ?? null]);
        $this->json(['data' => $this->site->areas($type)]);
    }

    // ─── Page content of one entry ───────────────────────────────────────────

    /**
     * An entry's page content for the page builder: its own sections, or a
     * copy of the layout's default content to start from. Query: field (the
     * type's first page field when empty).
     */
    public function page(string $type, string $id): never
    {
        [$schema, $entry, $field] = $this->requirePage($type, $id, (string) ($_GET['field'] ?? ''), true);
        $context = $this->context();
        $value = $entry[$field] ?? null;
        $customized = LayoutSchema::hasPageContent($value);

        $this->json(['data' => [
            'nodes' => $customized ? LayoutSchema::pageNodes($value) : $this->defaultPage($type, $field, $entry),
            'revision' => (string) ($entry['revision'] ?? ''),
            'customized' => $customized,
            'default_nodes' => $customized ? $this->defaultPage($type, $field, $entry) : null,
            'upgrade_required' => LayoutSchema::pageNeedsUpgrade($value),
            'shown_template' => self::shown($context->template($type, 'detail', $context->findEntry($type, $this->content()->stableId($entry), true))),
            'field' => $field,
            'samples' => $this->samples($type, $this->content()->stableId($entry)),
            'type' => $schema,
            'entry' => ['id' => $this->content()->stableId($entry), 'slug' => (string) ($entry['slug'] ?? $entry['id']), 'title' => self::entryLabel($entry), 'status' => (string) ($entry['status'] ?? 'draft')],
            'home_url' => $this->liveUrl($context->homeUrl(), $context),
            ...$this->builderContext(),
        ]]);
    }

    /** Body: {field, nodes, revision}. Empty nodes go back to the layout's default content. */
    public function updatePage(string $type, string $id): never
    {
        $body = $this->requestJson();
        [, $entry, $field, $user] = $this->requirePage($type, $id, (string) ($body['field'] ?? ''));
        $this->verifyCsrf();
        $nodes = is_array($body['nodes'] ?? null) ? $body['nodes'] : [];
        $saved = $this->validated(fn() => $this->content()->savePage($type, (string) $entry['id'], $field, $nodes, $user, isset($body['revision']) ? (string) $body['revision'] : null));
        $this->logger->info('site.page_updated', ['type' => $type, 'id' => $entry['id'], 'field' => $field]);
        $this->sendPage($type, $field, $saved);
    }

    /** Back to the layout's default content. Body: {field, revision}. */
    public function resetPage(string $type, string $id): never
    {
        $body = $this->requestJson();
        [, $entry, $field, $user] = $this->requirePage($type, $id, (string) ($body['field'] ?? ''));
        $this->verifyCsrf();
        $saved = $this->validated(fn() => $this->content()->savePage($type, (string) $entry['id'], $field, null, $user, isset($body['revision']) ? (string) $body['revision'] : null));
        $this->logger->info('site.page_reset', ['type' => $type, 'id' => $entry['id'], 'field' => $field]);
        $this->sendPage($type, $field, $saved);
    }

    /** The entry's saved versions, newest first, as the builder's history lists them. Query: field. */
    public function pageRevisions(string $type, string $id): never
    {
        [, $entry, $field] = $this->requirePage($type, $id, (string) ($_GET['field'] ?? ''));
        $revisions = array_map(static fn(array $revision): array => [
            'id' => $revision['id'],
            'created_at' => $revision['created_at'],
            'created_by' => $revision['created_by'],
            'event' => $revision['event'] === 'content.revision_restore' ? 'restored' : 'saved',
            'customized' => LayoutSchema::hasPageContent($revision['entry'][$field] ?? null),
        ], $this->content()->revisions($type, (string) $entry['id']));
        usort($revisions, static fn(array $a, array $b): int => strcmp($b['id'], $a['id']));

        $this->json(['data' => $revisions]);
    }

    public function pageRevision(string $type, string $id, string $revisionId): never
    {
        [, $entry, $field] = $this->requirePage($type, $id, (string) ($_GET['field'] ?? ''));
        foreach ($this->content()->revisions($type, (string) $entry['id']) as $revision) {
            if ($revision['id'] === $revisionId) {
                $value = $revision['entry'][$field] ?? null;
                $customized = LayoutSchema::hasPageContent($value);
                $this->json(['data' => ['nodes' => $customized ? LayoutSchema::pageNodes($value) : $this->defaultPage($type, $field, $entry), 'customized' => $customized]]);
            }
        }

        $this->json(['error' => ['code' => 'not_found', 'message' => 'Revision not found.']], 404);
    }

    /**
     * The type, entry and page field the page builder edits, for those who
     * manage the website and may change this entry's page field. With
     * $placed, only an area the entry's template shows can be opened: another
     * field gives way to the first one placed, and with none the request
     * fails with "page_not_placed", so no entry is designed where its
     * content would not appear.
     *
     * @return array{0: array, 1: array, 2: string, 3: array}
     */
    private function requirePage(string $type, string $id, string $field, bool $placed = false): array
    {
        $user = $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $schema = $this->requireType($type, 'detail');
        $entry = $this->findAdminEntry($type, $id);
        if ($entry === null) {
            $this->json(['error' => ['code' => 'not_found', 'message' => 'Entry not found.']], 404);
        }
        $fields = array_keys(array_filter($schema['fields'], static fn(mixed $config): bool => ($config['type'] ?? '') === 'page'));
        if ($placed) {
            $places = $this->placedPageFields($schema, $type, $entry);
            if ($places === []) {
                $this->json(['error' => ['code' => 'page_not_placed', 'message' => 'The template this entry uses has no Entry content area.', 'template' => $this->entryTemplate($type, $entry)['id']]], 409);
            }
            $field = in_array($field, $places, true) ? $field : $places[0];
        }
        $field = $field !== '' ? $field : (string) ($fields[0] ?? '');
        if (!in_array($field, $fields, true)) {
            $this->json(['error' => ['code' => 'not_found', 'message' => 'This content type has no page content field.']], 404);
        }
        $this->requirePermission('content.update', ['type' => 'content', 'collection' => $type, 'entry' => $entry, 'fields' => [$field], 'locale' => $this->content()->locale()]);

        return [$schema, $entry, $field, $user];
    }

    /**
     * The type's page fields that the content area of the entry's template
     * shows, in canvas order: the areas the entry can design.
     *
     * @return list<string>
     */
    private function placedPageFields(array $schema, string $type, ?array $entry): array
    {
        $places = array_map('strval', array_keys(LayoutSchema::pagePlaces($this->site->layout($type, 'detail', $this->entryTemplate($type, $entry)['id']))));

        return array_values(array_filter($places, static fn(string $key): bool => ($schema['fields'][$key]['type'] ?? '') === 'page'));
    }

    /** A copy of the default content the entry's template shows in $field's place. */
    private function defaultPage(string $type, string $field, ?array $entry): array
    {
        return LayoutSchema::withNewIds(LayoutSchema::pagePlaces($this->site->layout($type, 'detail', $this->entryTemplate($type, $entry)['id']))[$field]['children'] ?? []);
    }

    /**
     * The template an entry's page uses, as the website resolves it:
     * {id, name, header, footer, reason, conditions}.
     */
    private function entryTemplate(string $type, ?array $entry): array
    {
        $context = $this->context();
        $found = $entry !== null ? $context->findEntry($type, $this->content()->stableId($entry), true) : null;
        $resolved = $context->template($type, 'detail', $found);

        return $resolved['template'] + ['reason' => $resolved['reason']];
    }

    /** A template of the page kind, whose content area is edited; none asked for means the default. */
    private function requestedTemplate(string $type, string $variant, mixed $template): string
    {
        if ($template === null || $template === '') return SiteRepository::DEFAULT_TEMPLATE;
        if (!is_string($template) || !$this->site->hasTemplate($type, $variant, $template)) {
            $this->json(['error' => ['code' => 'not_found', 'message' => 'Template not found.']], 404);
        }

        return $template;
    }

    private function sendPage(string $type, string $field, array $entry): never
    {
        $customized = LayoutSchema::hasPageContent($entry[$field] ?? null);
        $this->json(['data' => [
            'nodes' => $customized ? LayoutSchema::pageNodes($entry[$field]) : $this->defaultPage($type, $field, $entry),
            'revision' => (string) ($entry['revision'] ?? ''),
            'customized' => $customized,
        ]]);
    }

    private function content(): ContentRepository
    {
        return ContentRepository::make($this->site->workspace());
    }

    public function region(string $region): never
    {
        $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $this->requireRegion($region);

        $context = $this->context();

        $this->json(['data' => [
            'nodes' => $this->site->region($region),
            'revision' => \CometCMS\Localization\LocalizedValue::hash($this->site->region($region)),
            'upgrade_required' => $this->site->needsRegionUpgrade($region),
            'customized' => $this->site->regionCustomized($region),
            'default_nodes' => LayoutSchema::defaultRegion($region),
            'home_url' => $this->liveUrl($context->homeUrl(), $context),
            ...$this->builderContext(false, $region === LayoutSchema::MODAL),
        ]]);
    }

    public function updateRegion(string $region): never
    {
        $user = $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $this->verifyCsrf();
        $this->requireRegion($region);
        $nodes = $this->validated(fn() => $this->site->saveRegion($region, $this->requestJson()['nodes'] ?? [], $user, 'saved', $this->requestJson()['revision'] ?? null));
        $this->logger->info('site.region_updated', ['region' => $region]);
        $this->json(['data' => ['nodes' => $nodes, 'revision' => \CometCMS\Localization\LocalizedValue::hash($nodes), 'customized' => true]]);
    }

    public function resetRegion(string $region): never
    {
        $user = $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $this->verifyCsrf();
        $this->requireRegion($region);
        $nodes = $this->site->resetRegion($region, $user);
        $this->logger->info('site.region_reset', ['region' => $region]);
        $this->json(['data' => ['nodes' => $nodes, 'revision' => \CometCMS\Localization\LocalizedValue::hash($nodes), 'customized' => false]]);
    }

    /** Components with the number of places each one is used. */
    public function components(): never
    {
        $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $this->json(['data' => array_map(fn(array $component): array => $component + [
            'usages' => count($this->site->componentUsages($component['id'])),
        ], $this->site->components())]);
    }

    /** Body: {name, nodes: [one section, row or column], type}. */
    public function createComponent(): never
    {
        $user = $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $this->verifyCsrf();
        $input = $this->requestJson();
        $component = $this->validated(fn() => $this->site->createComponent($input['name'] ?? '', $input['nodes'] ?? [], $input['type'] ?? '', $user));
        $this->logger->info('site.component_created', ['component' => $component['id']]);
        $this->json(['data' => $component], 201);
    }

    public function component(string $id): never
    {
        $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $component = $this->requireComponent($id);
        $context = $this->context();
        // Components fit every type, so they preview with entries of any of them: the requested one, else the component's own type first.
        $names = array_values(array_filter(array_keys($this->site->types()), $context->isPublicType(...)));
        usort($names, static fn(string $a, string $b): int => ($b === $component['type']) <=> ($a === $component['type']));
        $sample = '';
        $type = null;
        foreach ($names as $name) {
            $sample = $this->requestedSample($name);
            if ($sample !== '') {
                $type = $name;
                break;
            }
        }
        $samples = [];
        foreach ($names as $name) {
            foreach ($this->samples($name, $name === $type ? LayoutSchema::key($_GET['entry'] ?? '') : '', 20) as $item) $samples[] = $item + ['type' => $name];
        }
        $type ??= $component['type'] !== '' && in_array($component['type'], $names, true) ? $component['type'] : ($samples[0]['type'] ?? null);

        $this->json(['data' => [
            'component' => $component,
            'nodes' => $component['nodes'], 'revision' => \CometCMS\Localization\LocalizedValue::hash($component['nodes']),
            'customized' => true,
            'usages' => $this->site->componentUsages($id),
            'type' => $type !== null ? $this->site->types()[$type] : null,
            'samples' => $samples,
            'sample' => $sample,
            'home_url' => $this->liveUrl($context->homeUrl(), $context),
            ...$this->builderContext(component: true),
        ]]);
    }

    public function updateComponent(string $id): never
    {
        $user = $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $this->verifyCsrf();
        $this->requireComponent($id);
        $component = $this->validated(fn() => $this->site->saveComponent($id, $this->requestJson()['nodes'] ?? [], $user, 'saved', $this->requestJson()['revision'] ?? null));
        $this->logger->info('site.component_updated', ['component' => $id]);
        $this->json(['data' => ['nodes' => $component['nodes'], 'revision' => \CometCMS\Localization\LocalizedValue::hash($component['nodes']), 'customized' => true, 'component' => $component]]);
    }

    public function patchComponent(string $id): never
    {
        $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $this->verifyCsrf();
        $this->requireComponent($id);
        $input = $this->requestJson();
        $component = $this->validated(fn() => $this->site->patchComponent($id, $input));
        $this->logger->info(array_key_exists('name', $input) ? 'site.component_renamed' : 'site.component_updated', ['component' => $id]);
        $this->json(['data' => $component]);
    }

    public function deleteComponent(string $id): never
    {
        $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $this->verifyCsrf();
        $this->requireComponent($id);
        $component = $this->site->deleteComponent($id);
        $this->logger->info('site.component_deleted', ['component' => $id]);
        $this->json(['data' => ['deleted' => true, 'component' => $component]]);
    }

    public function componentRevisions(string $id): never
    {
        $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $this->requireComponent($id);
        $this->json(['data' => $this->site->componentRevisions($id)]);
    }

    public function restoreComponentRevision(string $id, string $revision): never
    {
        $user = $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $this->verifyCsrf();
        $this->requireComponent($id);
        $result = $this->validated(fn() => $this->site->restoreComponentRevision($id, $revision, $user));
        $this->sendRestoredRevision($result, ['target' => 'component', 'component' => $id, 'revision' => $revision]);
    }

    // ─── Forms ───────────────────────────────────────────────────────────────

    /** Forms with the places each one is used, and the content types they can submit to. */
    public function forms(): never
    {
        $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $this->json(['data' => [
            'forms' => array_map(fn(array $form): array => $form + ['usages' => $this->site->formUsages($form['id'])], $this->forms->all()),
            'types' => array_values($this->forms->types()),
            'field_types' => FormRepository::FIELD_TYPES,
            'statuses' => FormRepository::STATUSES,
            'captchas' => FormRepository::CAPTCHAS,
        ]]);
    }

    public function themeRevisions(): never
    {
        $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $this->json(['data' => $this->site->themeRevisions()]);
    }

    public function themeRevision(string $id): never
    {
        $user = $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $this->sendRevision($this->site->themeRevision($id, Auth::allows($user, 'admin')));
    }

    public function layoutRevision(string $type, string $variant, string $id): never
    {
        $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $this->requireType($type, $variant);
        $this->sendRevision($this->site->layoutRevision($type, $variant, $id, $this->requestedTemplate($type, $variant, $_GET['template'] ?? null)));
    }

    public function regionRevision(string $region, string $id): never
    {
        $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $this->requireRegion($region);
        $this->sendRevision($this->site->regionRevision($region, $id));
    }

    public function componentRevision(string $component, string $id): never
    {
        $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $this->requireComponent($component);
        $this->sendRevision($this->site->componentRevision($component, $id));
    }

    private function sendRevision(?array $result): never
    {
        if ($result === null) {
            $this->json(['error' => ['code' => 'not_found', 'message' => 'Revision not found.']], 404);
        }
        $this->json(['data' => $result]);
    }

    public function restoreThemeRevision(string $id): never
    {
        $user = $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $this->verifyCsrf();
        $theme = $this->site->restoreThemeRevision($id, $user, Auth::allows($user, 'admin'));
        $this->sendRestoredRevision($theme, ['target' => 'theme', 'revision' => $id]);
    }

    public function layoutRevisions(string $type, string $variant): never
    {
        $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $this->requireType($type, $variant);
        $this->json(['data' => $this->site->layoutRevisions($type, $variant, $this->requestedTemplate($type, $variant, $_GET['template'] ?? null))]);
    }

    public function restoreLayoutRevision(string $type, string $variant, string $id): never
    {
        $user = $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $this->verifyCsrf();
        $this->requireType($type, $variant);
        $area = $this->requestedTemplate($type, $variant, $_GET['template'] ?? null);
        $result = $this->site->restoreLayoutRevision($type, $variant, $id, $user, $area);
        $this->sendRestoredRevision($result, ['target' => 'layout', 'type' => $type, 'variant' => $variant, 'template' => $area, 'revision' => $id]);
    }

    public function regionRevisions(string $region): never
    {
        $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $this->requireRegion($region);
        $this->json(['data' => $this->site->regionRevisions($region)]);
    }

    public function restoreRegionRevision(string $region, string $id): never
    {
        $user = $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $this->verifyCsrf();
        $this->requireRegion($region);
        $result = $this->site->restoreRegionRevision($region, $id, $user);
        $this->sendRestoredRevision($result, ['target' => 'region', 'region' => $region, 'revision' => $id]);
    }

    private function sendRestoredRevision(?array $result, array $details): never
    {
        if ($result === null) {
            $this->json(['error' => ['code' => 'not_found', 'message' => 'Revision not found.']], 404);
        }
        $this->logger->info('site.revision_restored', $details);
        $this->json(['data' => $result]);
    }

    /**
     * Render unsaved work. Body: {target: layout|region|component|entry_page|page,
     * type, variant, template, region, component, field, nodes, entry,
     * locale, theme, menus, templates, path}. A layout preview shows the
     * content area of `template` (default: the default template). Layout and
     * entry page previews also return the template shown ({id, name, …, reason, live}).
     */
    public function preview(): never
    {
        $user = $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $this->verifyCsrf();
        $body = $this->requestJson();
        $context = $this->context();
        $context->preview = true;
        if (!empty($body['entries'])) {
            $this->validated(fn() => $this->applySnapshot($context, [
                'target' => 'entry', 'entries' => $body['entries'], 'locale' => $body['locale'] ?? '',
            ], $user));
        }

        if (is_array($body['theme'] ?? null)) {
            $context->override('theme', Theme::normalize($body['theme']));
        }
        if (is_array($body['menus'] ?? null)) {
            try {
                $this->site->validateMenus($body['menus'], false);
            } catch (ValidationException $e) {
                $this->json(['error' => ['code' => 'validation_failed', 'message' => $e->getMessage(), 'fields' => $e->fields()]], 422);
            }
            $context->override('menus', $this->site->normalizeMenus($body['menus']));
        }
        if (is_array($body['templates'] ?? null)) {
            $context->override('templates', $this->validated(fn() => $this->site->validateTemplates($body['templates'])));
        }

        $locale = (new \CometCMS\Localization\LanguageSettings($this->site->workspace()))->requireLocale((string) ($body['locale'] ?? ''));
        $target = (string) ($body['target'] ?? 'page');

        if ($target === 'page') {
            $response = (new SiteService($context))->respond((string) ($body['path'] ?? '/'), []);
            $this->json(['data' => ['html' => $response['body']]]);
        }

        $context->annotate = true;
        $renderer = new PageRenderer($context);

        if ($target === 'region') {
            $region = (string) ($body['region'] ?? '');
            $this->requireRegion($region);
            if (array_key_exists('nodes', $body)) {
                $context->override('region:' . $region, $this->validated(static fn() => LayoutSchema::normalizeRegion($region, $body['nodes'])));
            }
            $context->locale = in_array($locale, $context->locales(), true) ? $locale : $context->defaultLocale();

            $html = match ($region) {
                'not_found' => $renderer->notFound($context->homeUrl()),
                LayoutSchema::MAINTENANCE => $renderer->maintenance(),
                LayoutSchema::SEARCH_RESULTS => $renderer->searchResults(),
                default => $renderer->regionPreview($region),
            };
            $this->json(['data' => ['html' => $html]]);
        }

        if ($target === 'component') {
            $component = $this->requireComponent((string) ($body['component'] ?? ''));
            if (array_key_exists('nodes', $body)) {
                $component['nodes'] = $this->validated(static fn() => LayoutSchema::normalizeComponent($body['nodes'], $component['root']));
            }
            // Instances inside other components show the unsaved edit, too.
            $context->override('component:' . $component['id'], $component);
            // The previewed entry may be of any type; the component's own type is the fallback.
            $typeName = LayoutSchema::key($body['entry_type'] ?? '') ?: $component['type'];
            $schema = $typeName !== '' && $context->isPublicType($typeName) ? ($this->site->types()[$typeName] ?? null) : null;
            $context->locale = in_array($locale, $context->locales(), true) ? $locale : $context->defaultLocale();
            $entry = null;
            if ($schema !== null) {
                $identifier = LayoutSchema::key($body['entry'] ?? '');
                $entry = $identifier !== '' ? $context->findEntry((string) $schema['name'], $identifier, true) : null;
            }

            $this->json(['data' => ['html' => $renderer->componentPreview($component, $schema, $entry)]]);
        }

        if ($target === 'entry_page') {
            $type = (string) ($body['type'] ?? '');
            [$schema, , $field] = $this->requirePage($type, LayoutSchema::key($body['entry'] ?? ''), (string) ($body['field'] ?? ''));
            $context->locale = $locale;
            $context->pageDraft = ['field' => $field, 'nodes' => $this->validated(static fn() => LayoutSchema::normalize($body['nodes'] ?? []))];
            $entry = $context->findEntry($type, $this->content()->stableId($this->findAdminEntry($type, LayoutSchema::key($body['entry'] ?? '')) ?? []), true);
            if ($entry === null) {
                $this->json(['error' => ['code' => 'not_found', 'message' => 'Entry not found.']], 404);
            }

            $html = $renderer->pagePreview($schema, $entry, $field);
            $this->json(['data' => ['html' => $html, 'template' => self::shownTemplate($renderer)]]);
        }

        $type = (string) ($body['type'] ?? '');
        $variant = (string) ($body['variant'] ?? 'detail');
        $schema = $this->requireType($type, $variant);

        $area = $this->requestedTemplate($type, $variant, $body['template'] ?? null);

        if (array_key_exists('nodes', $body)) {
            $context->override('layout:' . $type . ':' . $variant . ':' . $area, $this->validated(static fn() => LayoutSchema::normalize($body['nodes'], $variant === 'detail')));
        }

        $context->locale = $locale;

        $identifier = LayoutSchema::key($body['entry'] ?? '');
        $entry = $identifier !== '' ? $context->findEntry($type, $identifier, true) : null;

        $html = $renderer->layoutPreview($schema, $variant, $entry, $area);
        $this->json(['data' => ['html' => $html, 'template' => self::shownTemplate($renderer)]]);
    }

    /** The template a builder preview showed around its content, for the builder's template indicator. */
    private static function shownTemplate(PageRenderer $renderer): ?array
    {
        $shown = $renderer->template();

        return $shown === null ? null : self::shown($shown);
    }

    /** A resolved template as the builder names it: {…template, reason, live}. */
    private static function shown(array $resolved): array
    {
        return $resolved['template'] + ['reason' => $resolved['reason'], 'live' => $resolved['live'] ?? $resolved['template']['id']];
    }

    /** Open unsaved editor state as a navigable, private snapshot. */
    public function createPreview(): never
    {
        $user = $this->requireUser();
        $this->verifyCsrf();
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 2097152) {
            $this->json(['error' => ['code' => 'preview_too_large', 'message' => 'The preview is too large.']], 413);
        }
        $body = $this->requestJson();
        $context = $this->adminPreviewContext($user);
        $this->validated(fn() => $this->applySnapshot($context, $body, $user));
        $token = (new PreviewRepository($this->site))->create((string) $user['id'], $body);
        $this->json(['data' => ['url' => $this->http->url('/admin/site-preview') . '?' . http_build_query([
            'workspace' => $this->site->workspace()->slug(), 'snapshot' => $token,
        ])]]);
    }

    /** Recheck permissions on every visit, including after roles or entries change. */
    private function applySnapshot(SiteContext $context, array $body, array $user): array
    {
        $target = (string) ($body['target'] ?? 'entry');
        if (!in_array($target, ['entry', 'entry_page', 'layout', 'region', 'component'], true)) {
            throw new ValidationException(['target' => 'Choose a valid preview target.']);
        }
        if ($target !== 'entry' || isset($body['theme']) || isset($body['menus']) || isset($body['templates'])) {
            $this->requirePermission('site.manage', ['resource' => 'site:*']);
        }
        if ((isset($body['theme']) && !is_array($body['theme'])) || (isset($body['menus']) && !is_array($body['menus']))) {
            throw new ValidationException(['preview' => 'Invalid preview settings.']);
        }
        if (isset($body['theme'])) $context->override('theme', Theme::normalize($body['theme']));
        if (isset($body['menus'])) {
            $this->site->validateMenus($body['menus'], false);
            $context->override('menus', $this->site->normalizeMenus($body['menus']));
        }
        if (isset($body['templates'])) $context->override('templates', $this->site->validateTemplates($body['templates']));
        $locale = (string) ($body['locale'] ?? $context->defaultLocale());
        $context->locale = in_array($locale, $context->locales(), true) ? $locale : $context->defaultLocale();
        $drafts = [];
        $bases = [];
        foreach ((array) ($body['entries'] ?? []) as $draft) {
            if (!is_array($draft) || !is_array($draft['values'] ?? null)) {
                throw new ValidationException(['entries' => 'Invalid entry preview.']);
            }
            $type = (string) ($draft['type'] ?? '');
            if (!$context->isPublicType($type)) $this->http->notFound();
            $id = (string) ($draft['id'] ?? '');
            if ($id !== '' && !preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
                throw new ValidationException(['entries' => 'Invalid entry identifier.']);
            }
            $existing = $id !== '' ? $context->content->findByIdentifier($type, $id) : null;
            if ($id !== '' && $existing === null) $this->http->notFound();
            $values = $draft['values'];
            $scope = ['type' => 'content', 'collection' => $type, 'entry' => $existing ?? [],
                'locale' => $values['locale'] ?? null, 'fields' => array_values(array_diff(array_keys($values), ['locale']))];
            if ($existing !== null) $this->requirePermission('content.read', $scope);
            $this->requirePermission($existing !== null ? 'content.update' : 'content.create', $scope);
            // Earlier locale drafts in this snapshot can provide shared field values.
            $base = $bases[$type][$id] ?? $existing;
            $entry = $context->content->preview($type, $values, $user, $base);
            $bases[$type][$id] = $entry;
            $context->overrideEntry($type, $entry, $id);
            $drafts[] = $entry;
        }
        if ($target === 'entry_page') {
            $type = (string) ($body['type'] ?? '');
            [, $saved, $field] = $this->requirePage($type, LayoutSchema::key($body['entry'] ?? ''), (string) ($body['field'] ?? ''));
            $entry = $context->findEntry($type, $this->content()->stableId($saved), true);
            if ($entry === null) $this->http->notFound();
            $entry['values'][$field] = LayoutSchema::pageValue($body['nodes'] ?? []);
            $context->overrideEntry($type, $entry);
        } elseif ($target === 'layout') {
            $type = (string) ($body['type'] ?? '');
            $variant = (string) ($body['variant'] ?? 'detail');
            $this->requireType($type, $variant);
            if (!$context->isPublicType($type)) $this->http->notFound();
            $area = is_string($body['template'] ?? null) && $body['template'] !== '' ? $body['template'] : SiteRepository::DEFAULT_TEMPLATE;
            if (!$this->site->hasTemplate($type, $variant, $area)) throw new ValidationException(['template' => 'Unknown template.']);
            $context->override('layout:' . $type . ':' . $variant . ':' . $area, LayoutSchema::normalize($body['nodes'] ?? []));
        } elseif ($target === 'region') {
            $region = (string) ($body['region'] ?? '');
            $this->requireRegion($region);
            $context->override('region:' . $region, LayoutSchema::normalizeRegion($region, $body['nodes'] ?? []));
        } elseif ($target === 'component') {
            $component = $this->requireComponent((string) ($body['component'] ?? ''));
            $component['nodes'] = LayoutSchema::normalizeComponent($body['nodes'] ?? [], $component['root']);
            $context->override('component:' . $component['id'], $component);
        }
        return $drafts;
    }

    private function snapshot(SiteContext $context, array $user): ?array
    {
        $token = is_string($_GET['snapshot'] ?? null) ? $_GET['snapshot'] : '';
        if ($token === '') return null;
        $body = (new PreviewRepository($this->site))->find($token, (string) $user['id']);
        if ($body === null) {
            $this->sendFullPreview((new PageRenderer($context))->previewExpired(), 410);
        }
        $context->previewToken = $token;
        $drafts = $this->validated(fn() => $this->applySnapshot($context, $body, $user));
        return ['body' => $body, 'entries' => $drafts];
    }

    private function initialSnapshot(SiteContext $context, array $snapshot): string
    {
        $body = $snapshot['body'];
        $renderer = new PageRenderer($context);
        $type = (string) ($body['type'] ?? '');
        $schema = $context->type($type);
        $target = $body['target'] ?? 'entry';
        if ($target === 'entry' || $target === 'entry_page' || ($target === 'layout' && ($body['variant'] ?? 'detail') === 'detail')) {
            $entry = $context->findEntry($type, (string) ($body['entry'] ?? $type), true);
            if ($entry === null && $target === 'entry') {
                $entry = isset($snapshot['entries'][0]) ? $context->localize($type, $snapshot['entries'][0]) : null;
            }
            if ($schema === null || !$context->isPublicType($type) || $entry === null) $this->http->notFound();
            $this->requirePermission('content.read', ['type' => 'content', 'collection' => $type, 'entry' => $entry, 'locale' => $context->locale]);
            return $renderer->entryPage($schema, $entry);
        }
        if ($target === 'layout' && $schema !== null) return $renderer->indexPage($schema);
        if ($target === 'component') {
            $component = $context->component((string) $body['component']);
            $typeName = LayoutSchema::key($body['entry_type'] ?? '') ?: (string) ($component['type'] ?? '');
            $schema = $context->isPublicType($typeName) ? $context->type($typeName) : null;
            $entry = $schema !== null ? $context->findEntry($schema['name'], (string) ($body['entry'] ?? $schema['name']), true) : null;
            return $renderer->componentPreview($component, $schema, $entry);
        }
        if ($target === 'region' && ($body['region'] ?? '') === LayoutSchema::MODAL) return $renderer->modalPreview();
        // The results page needs a query to show anything; a word of the newest entry finds at least that one.
        if ($target === 'region' && ($body['region'] ?? '') === LayoutSchema::SEARCH_RESULTS) {
            $context->searchQuery = (new SiteSearch($context))->sampleQuery();
            return $renderer->searchResults();
        }
        return (new SiteService($context))->respond('/', [])['body'];
    }

    /** Full-page draft preview of one entry, opened from the entry editor. */
    public function previewEntry(string $type, string $id): never
    {
        $user = $this->requireUser();
        $context = $this->adminPreviewContext($user);
        $this->snapshot($context, $user);
        $locale = $context->content->locale((string) ($_GET['locale'] ?? $context->locale));
        $context->locale = $locale;
        $entry = $context->content->findByIdentifier($type, $id, false, $locale) ?? $context->findEntry($type, $id, true);
        $this->requirePermission('content.read', ['type' => 'content', 'collection' => $type, 'entry' => $entry ?? []]);
        $this->sendPreview((new SiteService($context))->previewEntry($type, $id, $locale), 'This entry cannot be previewed on the website.');
    }

    /** A type's index page with its saved layout, for the builder's Preview action. */
    public function previewIndex(string $type): never
    {
        $user = $this->requirePermission('content.read', ['type' => 'content', 'collection' => $type]);
        $this->sendPreview((new SiteService($this->adminPreviewContext($user)))->previewIndex($type, (string) ($_GET['locale'] ?? '')), 'This content type cannot be previewed on the website.');
    }

    /** The homepage with the saved header and footer, for the builder's Preview action. */
    public function previewHome(): never
    {
        $user = $this->requirePermission('site.manage', ['resource' => 'site:*']);
        $this->sendPreview((new SiteService($this->adminPreviewContext($user)))->previewHome((string) ($_GET['locale'] ?? '')), '');
    }

    private function sendPreview(?string $html, string $missing): never
    {
        if ($html === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo $missing;
            exit;
        }

        $this->sendFullPreview($html);
    }

    /** Browse the frontend without leaving the authenticated preview. */
    public function previewSite(): never
    {
        $user = $this->requireUser();
        $context = $this->adminPreviewContext($user);
        $snapshot = $this->snapshot($context, $user);
        if ($snapshot !== null && !isset($_GET['path'])) {
            $this->sendFullPreview($this->initialSnapshot($context, $snapshot));
        }
        $locale = $context->content->locale((string) ($_GET['locale'] ?? ''));
        $path = is_string($_GET['path'] ?? null) ? $_GET['path']
            : ($locale === $context->defaultLocale() ? '/' : '/' . $locale);
        $response = (new SiteService($context))->respond($path, $_GET);
        $this->sendFullPreview($response['body'], $response['status']);
    }

    /** Search the same drafts and snapshot as the authenticated preview. */
    public function previewSearch(): never
    {
        $user = $this->requireUser();
        $context = $this->adminPreviewContext($user);
        $this->snapshot($context, $user);
        $context->locale = $context->content->locale((string) ($_GET['locale'] ?? $context->locale));
        $keys = static fn(mixed $value): array => array_values(array_filter(array_map(LayoutSchema::key(...),
            explode(',', is_string($value) ? $value : '', 50)), static fn(string $key): bool => $key !== ''));
        $query = is_string($_GET['q'] ?? null) ? $_GET['q'] : '';
        $hits = (new SiteSearch($context))->search($query, $keys($_GET['types'] ?? ''), $keys($_GET['fields'] ?? ''),
            (int) ($_GET['limit'] ?? SiteSearch::MAX_RESULTS));
        header('Cache-Control: private, no-store');
        $this->json(['data' => $hits]);
    }

    private function adminPreviewContext(array $user): SiteContext
    {
        $context = $this->context();
        $context->preview = true;
        $context->previewBanner = true;
        $context->browsePreview = true;
        $context->adminUser = $user;
        $context->setPreviewReturnUrl($_GET['returnTo'] ?? null);
        $context->adminCan = fn(string $action, array $scope): bool => $this->can($user, $action,
            $action === 'site.manage' ? ['resource' => 'site:*'] : $scope);
        return $context;
    }

    private function sendFullPreview(string $html, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: private, no-store');
        header('X-Robots-Tag: noindex, nofollow');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; media-src 'self' https:; frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; style-src 'self' 'unsafe-inline' https:; font-src 'self' data: https:; "
            . SiteScripts::cspDirective($html) . "; object-src 'none'; base-uri 'self'; form-action 'none'; frame-ancestors 'self'");
        echo $html;
        exit;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function validated(callable $action): mixed
    {
        try {
            return $action();
        } catch (ValidationException $error) {
            $this->json(['error' => ['code' => 'validation_failed', 'message' => $error->getMessage(), 'fields' => $error->fields()]], 422);
        }
    }

    private function context(): SiteContext
    {
        return SiteContext::make($this->site, rtrim($this->http->url('/'), '/'), $this->requestOrigin());
    }

    /** What every builder page needs; $pageContent offers the page content place (detail layouts), $modal the modal template's elements, $component content areas. */
    private function builderContext(bool $pageContent = false, bool $modal = false, bool $component = false): array
    {
        return [
            'settings' => $this->site->settings(),
            'types' => array_values($this->site->types()),
            'menus' => array_map(static fn(array $menu): array => ['name' => $menu['name'], 'label' => $menu['label']], $this->site->menus()),
            'locales' => $this->site->settings()['locales'],
            'default_locale' => $this->site->settings()['default_locale'],
            // The website's address serves another workspace (or none), so live links would mislead.
            'live_conflict' => $this->site->settings()['enabled'] && $this->liveUrlConflict($this->siteUrl() . '/'),
            'spec' => LayoutSchema::spec($pageContent, $modal, $component),
            'components' => $this->site->components(),
            'forms' => array_map(fn(array $form): array => ['id' => $form['id'], 'name' => $form['name'], 'type' => $form['type'], 'fields' => count($this->forms->inputs($form))], $this->forms->all()),
        ];
    }

    private function requireComponent(string $id): array
    {
        $component = $this->site->component($id);

        if ($component === null) {
            $this->json(['error' => ['code' => 'not_found', 'message' => 'Component not found.']], 404);
        }

        return $component;
    }

    /**
     * Recent entries to preview with; `$ensure` keeps a requested entry in the
     * list. They are named by stable ID: slugs differ between languages and
     * may equal another entry's file name.
     */
    private function samples(string $type, string $ensure = '', int $limit = 50): array
    {
        $content = ContentRepository::make($this->site->workspace());
        $entries = $content->query($type, ['sort' => '-updated_at', 'limit' => $limit], true)['data'];

        if ($ensure !== '') {
            $requested = $content->findByIdentifier($type, $ensure);
            $ids = array_map($content->stableId(...), $entries);
            if ($requested !== null && !in_array($content->stableId($requested), $ids, true)) {
                array_unshift($entries, $requested);
            }
        }

        $context = $this->context();

        return array_map(static fn(array $entry): array => [
            'id' => $content->stableId($entry),
            'title' => self::entryLabel($entry),
            'status' => (string) ($entry['status'] ?? 'draft'),
            'template' => $context->template($type, 'detail', $entry)['template']['id'],
        ], $entries);
    }

    /** Admin navigation still needs a title after the default language changes. */
    private static function entryLabel(array $entry): string
    {
        $title = (string) ($entry['title'] ?? '');
        if (trim($title) !== '') return $title;

        foreach ($entry['values']['title']['values'] ?? [] as $translation) {
            if (is_string($translation) && trim($translation) !== '') return $translation;
        }

        return (string) ($entry['slug'] ?? $entry['id'] ?? '');
    }

    /** The stable ID of the entry asked for (?entry= may also be a slug or file name), or ''. */
    private function requestedSample(string $type): string
    {
        $identifier = LayoutSchema::key($_GET['entry'] ?? '');
        $entry = $identifier !== '' ? $this->findAdminEntry($type, $identifier) : null;

        return $entry !== null ? $this->content()->stableId($entry) : '';
    }

    /**
     * The entry to preview template $area with: the requested one if its page
     * uses that template, else the first sample whose page does (published
     * first), so the entry can be designed in that template's content area.
     * Without one, the requested entry (or '') as before.
     */
    private function sampleUsing(string $type, string $area, array $samples): string
    {
        $requested = $this->requestedSample($type);
        $published = array_filter($samples, static fn(array $sample): bool => $sample['status'] === 'published');
        $candidates = array_unique(array_merge($requested !== '' ? [$requested] : [], array_column($published, 'id'), array_column($samples, 'id')));
        $context = $this->context();
        foreach ($candidates as $id) {
            $entry = $context->findEntry($type, $id, true);
            if ($entry !== null && $context->template($type, 'detail', $entry)['template']['id'] === $area) return $id;
        }

        return $requested;
    }

    /**
     * An entry by stable ID, slug in the default language, or file name. Unlike
     * public URLs, the admin may use the file name: settings such as the
     * homepage store it, and it may differ from every slug.
     */
    private function findAdminEntry(string $type, string $identifier): ?array
    {
        return $this->content()->findByIdentifier($type, $identifier) ?? $this->content()->find($type, $identifier);
    }

    private function requireType(string $type, string $variant): array
    {
        $schema = $this->site->types()[$type] ?? null;

        if ($schema === null) {
            $this->json(['error' => ['code' => 'not_found', 'message' => 'Content type not found.']], 404);
        }

        if (!in_array($variant, $this->site->enabledVariantsFor($schema), true)) {
            $this->json(['error' => ['code' => 'not_found', 'message' => 'Unknown layout variant.']], 404);
        }

        return $schema;
    }

    private function requireRegion(string $region): void
    {
        if (!in_array($region, LayoutSchema::REGION_DOCUMENTS, true)) {
            $this->json(['error' => ['code' => 'not_found', 'message' => 'Unknown region.']], 404);
        }
    }

    /** Absolute live URL for a site path, or null while the website is headless. */
    private function liveUrl(?string $path, SiteContext $context): ?string
    {
        if ($path === null || !$this->site->settings()['enabled']) {
            return null;
        }

        return $this->siteUrl() . substr($path, strlen($context->basePath));
    }

    /** Check the public router without changing the editor's active workspace. */
    private function liveUrlConflict(?string $url): bool
    {
        if ($url === null) {
            return false;
        }

        $active = WorkspaceContext::active()->slug();
        try {
            return SiteService::workspaceForHost((string) parse_url($url, PHP_URL_HOST))?->slug() !== $this->site->workspace()->slug();
        } finally {
            WorkspaceContext::setActive($active);
        }
    }

    /** Public address of the website: its first domain, or this server. */
    private function siteUrl(): string
    {
        $domains = $this->site->settings()['domains'];
        $base = rtrim($this->http->url('/'), '/');

        return $domains !== [] ? 'https://' . $domains[0] . $base : $this->requestOrigin() . $base;
    }

    private function requestOrigin(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');

        if ($host === '' || !preg_match('/^[A-Za-z0-9.-]+(:\d+)?$/', $host)) {
            return '';
        }

        return ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . $host;
    }
}
