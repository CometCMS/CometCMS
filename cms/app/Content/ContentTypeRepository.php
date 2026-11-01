<?php

declare(strict_types=1);

namespace CometCMS\Content;

use CometCMS\Cache\ApiCache;
use CometCMS\Core\Security;
use CometCMS\Core\ValidationException;
use CometCMS\Extensions\ExtensionRegistry;
use CometCMS\Localization\LanguageSettings;
use CometCMS\Localization\LocalizedValue;
use CometCMS\Site\FormRepository;
use CometCMS\Site\SiteRepository;
use CometCMS\Storage\JsonStore;
use CometCMS\Storage\SettingsStore;
use CometCMS\Workspaces\WorkspaceContext;

final class ContentTypeRepository
{
    private JsonStore $store;
    private SettingsStore $settings;
    private array $disabledTypes = [];
    private int $extensionGeneration = -1;

    private WorkspaceContext $workspace;

    public function __construct(?WorkspaceContext $workspace = null, private readonly bool $includeDisabled = false)
    {
        $this->workspace = $workspace ?? WorkspaceContext::active();
        WorkspaceContext::setActive($this->workspace->slug());
        $this->workspace->ensure();
        $this->store = new JsonStore($this->workspace->path('content-types'));
        $this->settings = new SettingsStore();
    }

    public function isDisabled(string $name): bool
    {
        return !$this->includeDisabled && in_array($name, $this->disabledTypes(), true);
    }

    private function disabledTypes(): array
    {
        if ($this->extensionGeneration !== JsonStore::generation()) {
            $this->disabledTypes = ExtensionRegistry::disabledContentTypes($this->workspace);
            $this->extensionGeneration = JsonStore::generation();
        }
        return $this->disabledTypes;
    }

    public function all(): array
    {
        $types = [];

        foreach ((array) comet_config('content_types', []) as $name => $schema) {
            if (is_array($schema)) {
                $types[$name] = $this->normalize($name, $schema);
            }
        }

        foreach ($this->store->all() as $schema) {
            $name = (string) ($schema['name'] ?? '');

            if ($name !== '') {
                $types[$name] = $this->normalize($name, $schema);
            }
        }

        foreach ((new JsonStore($this->workspace->path('content')))->directories() as $collection) {
            $types[$collection] ??= $this->fallback($collection);
        }

        if (!$this->includeDisabled) {
            foreach ($this->disabledTypes() as $name) unset($types[$name]);
        }
        $order = array_flip($this->contentTypeOrder());

        uasort($types, static function (array $a, array $b) use ($order): int {
            $aOrder = $order[(string) ($a['name'] ?? '')] ?? PHP_INT_MAX;
            $bOrder = $order[(string) ($b['name'] ?? '')] ?? PHP_INT_MAX;

            return $aOrder <=> $bOrder
                ?: strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''))
                ?: strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
        });

        return array_values($types);
    }

    public function find(string $name): array
    {
        Security::assertSafeName($name);
        $stored = $this->store->read($name);

        if ($stored !== null) {
            return $this->normalize($name, $stored);
        }

        $configured = comet_config('content_types.' . $name);

        if (is_array($configured)) {
            return $this->normalize($name, $configured);
        }

        return $this->fallback($name);
    }

    public function exists(string $name, bool $includeDisabled = false): bool
    {
        Security::assertSafeName($name);
        if (!$includeDisabled && $this->isDisabled($name)) return false;

        if ($this->store->read($name) !== null) {
            return true;
        }

        if (is_array(comet_config('content_types.' . $name))) {
            return true;
        }

        return is_dir($this->workspace->path('content') . '/' . $name);
    }

    public function save(array $schema): void
    {
        $name = Security::slug((string) ($schema['name'] ?? ''));
        $this->store->synchronized(fn() => $this->saveUnlocked($schema), $name);
    }

    /**
     * Remove content area $key (a page field) and with it every entry's
     * design stored there. Areas leave only this way: saving a type keeps them.
     */
    public function removeArea(string $name, string $key): void
    {
        $this->store->synchronized(function () use ($name, $key): void {
            $schema = $this->find($name);
            if (($schema['fields'][$key]['type'] ?? '') !== 'page') throw new ValidationException(['field' => 'Not a content area.']);
            unset($schema['fields'][$key], $schema['revision']);
            $this->saveUnlocked($schema, [$key]);
        }, $name);
    }

    /**
     * Content areas (page fields) belong to the type's templates, not to the
     * field list: a save that leaves one out or changes its type keeps the
     * stored one, only its label may change. $drop names areas to let go.
     */
    private function keepAreas(array $schema, ?array $previous, array $drop): array
    {
        $fields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];
        foreach ($previous['fields'] ?? [] as $key => $config) {
            if (($config['type'] ?? '') !== 'page' || in_array($key, $drop, true)) continue;
            $label = ($fields[$key]['type'] ?? '') === 'page' && array_key_exists('label', $fields[$key]) ? $fields[$key]['label'] : ($config['label'] ?? null);
            $fields[$key] = ['label' => $label] + $config;
        }
        $schema['fields'] = $fields;

        return $schema;
    }

    private function saveUnlocked(array $schema, array $dropAreas = []): void
    {
        $name = Security::slug((string) ($schema['name'] ?? ''));
        if (!empty($schema['api_enabled'])) {
            $schema['visibility'] = 'public';
        }
        if ($this->isDisabled($name)) throw new ValidationException(['name' => 'Enable the extension before editing this content type.']);
        $previous = $this->exists($name) ? $this->find($name) : null;
        if ($previous !== null && isset($schema['revision']) && $schema['revision'] !== $previous['revision']) throw new \CometCMS\Core\ConflictException();
        $schema['api_enabled'] ??= $previous['api_enabled'] ?? false;
        $schema['allow_submissions'] ??= $previous['allow_submissions'] ?? false;
        if (!array_key_exists('submission_settings', $schema)) {
            $schema['submission_settings'] = $previous['submission_settings'] ?? null;
        }
        $schema = $this->normalize($name, $this->keepAreas($schema, $previous, $dropAreas));
        // Language settings are workspace-owned; discard old per-type overrides on save.
        $stored = array_diff_key($schema, array_flip(['revision', 'locales', 'default_locale']));
        $this->store->write($stored, $name);
        $this->ensureInOrder($name);
        ApiCache::fromConfig($this->workspace)->clear();
    }

    public function reorder(array $names): array
    {
        $names = array_values(array_unique(array_map(
            static fn(mixed $name): string => Security::slug((string) $name),
            $names
        )));
        $names = array_values(array_filter($names, static fn(string $name): bool => $name !== ''));

        $current = [];
        foreach ($this->all() as $type) {
            $current[(string) $type['name']] = $type;
        }

        $orderedNames = [];
        foreach ($names as $name) {
            if (isset($current[$name])) {
                $orderedNames[] = $name;
            }
        }

        foreach (array_keys($current) as $name) {
            if (!in_array($name, $orderedNames, true)) {
                $orderedNames[] = $name;
            }
        }

        $this->saveContentTypeOrder($orderedNames);
        ApiCache::fromConfig($this->workspace)->clear();

        return $this->all();
    }

    public function delete(string $name): void
    {
        Security::assertSafeName($name);
        $this->store->delete($name);
        $this->removeFromOrder($name);

        // Also remove the content directory so the type does not reappear
        // as a phantom fallback type in all().
        $this->deleteDirectory($this->workspace->path('content') . '/' . $name);
        $this->deleteDirectory($this->workspace->path('trash') . '/content/' . $name);
        $this->deleteDirectory($this->workspace->path('revisions') . '/content/' . $name);
        (new SiteRepository($this->workspace, $this))->forgetType($name);
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (glob($path . '/*') ?: [] as $file) {
            if (is_dir($file)) {
                $this->deleteDirectory($file);
            } else {
                unlink($file);
            }
        }

        rmdir($path);
    }

    public function normalize(string $name, array $schema): array
    {
        $name = Security::slug((string) ($schema['name'] ?? $name));
        $fields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];
        $languages = (new LanguageSettings($this->workspace))->all();

        $fields['title'] ??= ['type' => 'text', 'required' => true];
        $fields['slug'] ??= ['type' => 'slug', 'required' => true, 'unique' => true];
        foreach ($fields as $key => &$config) {
            if (in_array($key, ['values', 'publication', 'revision', 'reset_fields', 'locale', 'translations', 'page_template'], true)) throw new ValidationException(['fields' => 'This field key is reserved: ' . $key]);
            // Hierarchical entries keep their place in the tree under these keys.
            if (!empty($schema['hierarchical']) && in_array($key, ['parent', 'position'], true)) throw new ValidationException(['fields' => 'This field key is reserved for page hierarchies: ' . $key]);
            $config = $this->fieldMetadata($config, $languages['locales']);
        }
        unset($config);

        $normalized = [
            'name' => $name,
            'label' => (string) ($schema['label'] ?? ucfirst(str_replace(['-', '_'], ' ', $name))),
            'icon' => $this->normalizeIcon($schema['icon'] ?? 'mdi:file-document-outline'),
            'api_enabled' => (bool) ($schema['api_enabled'] ?? false),
            'visibility' => ($schema['visibility'] ?? 'public') === 'private' ? 'private' : 'public',
            'hierarchical' => (bool) ($schema['hierarchical'] ?? false),
            'allow_submissions' => (bool) ($schema['allow_submissions'] ?? false),
            'submission_settings' => is_array($schema['submission_settings'] ?? null) ? FormRepository::settings($schema['submission_settings']) : null,
            'slug_field' => (string) ($schema['slug_field'] ?? 'slug'),
            'slug_source' => (string) ($schema['slug_source'] ?? 'title'),
            'locales' => $languages['locales'],
            'default_locale' => $languages['default_locale'],
            'fields' => $fields,
        ];
        if ($normalized['allow_submissions']) {
            $fillable = array_filter($normalized['fields'], static fn(array $config, string $key): bool => FormRepository::accepts($key, $config, $normalized), ARRAY_FILTER_USE_BOTH);
            $unsupported = array_filter(FormRepository::unsupported($normalized), static fn(array $config): bool => !empty($config['required']));
            if ($fillable === [] || $unsupported !== []) {
                throw new ValidationException(['allow_submissions' => 'Submissions need at least one custom field visitors can fill in, and all required custom fields must support forms.']);
            }
        }
        return $normalized + ['revision' => LocalizedValue::hash($normalized)];
    }

    private function fieldMetadata(array $config, array $locales): array
    {
        $text = static fn(mixed $value): string => is_scalar($value) ? \CometCMS\Site\Text::limit(trim((string) $value), 2000) : '';
        foreach (['label', 'description', 'placeholder'] as $key) {
            if (array_key_exists($key, $config)) $config[$key] = LocalizedValue::normalize($config[$key], $text, $locales);
        }
        if (($config['type'] ?? '') === 'select' && is_array($config['options'] ?? null) && !array_is_list($config['options'])) {
            foreach ($config['options'] as &$label) $label = LocalizedValue::normalize($label, $text, $locales);
            unset($label);
        }
        foreach ($config['subfields'] ?? [] as $index => $subfield) $config['subfields'][$index] = $this->fieldMetadata($subfield, $locales);
        // Page content is one design for all languages; its texts are translated inside it.
        if (($config['type'] ?? '') === 'page') {
            // Fields added by an early builder stored this untranslated key as their label.
            if (($config['label'] ?? null) === 'site.page.fieldLabel') $config['label'] = 'Page content';
            $config['localized'] = false;
            unset($config['required'], $config['default'], $config['unique']);
        }
        return $config;
    }

    private function contentTypeOrder(): array
    {
        $settings = $this->settings->all();
        $key = 'content_type_order_' . $this->workspace->slug();
        $order = is_array($settings[$key] ?? null) ? $settings[$key] : [];

        return array_values(array_filter(array_map(
            static fn(mixed $name): string => Security::slug((string) $name),
            $order
        ), static fn(string $name): bool => $name !== ''));
    }

    private function saveContentTypeOrder(array $order): void
    {
        $settings = $this->settings->all();
        $settings['content_type_order_' . $this->workspace->slug()] = array_values($order);
        $this->settings->save($settings);
    }

    private function ensureInOrder(string $name): void
    {
        $order = $this->contentTypeOrder();

        if (!in_array($name, $order, true)) {
            $order[] = $name;
            $this->saveContentTypeOrder($order);
        }
    }

    private function removeFromOrder(string $name): void
    {
        $this->saveContentTypeOrder(array_values(array_filter(
            $this->contentTypeOrder(),
            static fn(string $orderedName): bool => $orderedName !== $name
        )));
    }

    private function normalizeIcon(mixed $icon): string
    {
        $icon = strtolower(trim((string) $icon));
        $icon = preg_replace('/[^a-z0-9:._-]/', '', $icon) ?? '';

        return $icon !== '' ? $icon : 'mdi:file-document-outline';
    }

    private function fallback(string $name): array
    {
        return $this->normalize($name, [
            'name' => $name,
            'icon' => 'mdi:file-document-outline',
            'fields' => [
                'title' => ['type' => 'text', 'required' => true],
                'slug' => ['type' => 'slug', 'required' => true, 'unique' => true],
                'summary' => ['type' => 'textarea'],
            ],
        ]);
    }
}
