<?php

declare(strict_types=1);

namespace CometCMS\Content;

use CometCMS\Core\Security;
use CometCMS\Storage\JsonStore;
use CometCMS\Storage\SettingsStore;
use CometCMS\Workspaces\WorkspaceContext;

final class ContentTypeRepository
{
    private JsonStore $store;
    private SettingsStore $settings;

    private WorkspaceContext $workspace;

    public function __construct(?WorkspaceContext $workspace = null)
    {
        $this->workspace = $workspace ?? WorkspaceContext::active();
        WorkspaceContext::setActive($this->workspace->slug());
        $this->workspace->ensure();
        $this->store = new JsonStore($this->workspace->path('content-types'));
        $this->settings = new SettingsStore();
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

    public function exists(string $name): bool
    {
        Security::assertSafeName($name);

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
        $schema = $this->normalize($name, $schema);
        $this->store->write($schema, $name);
        $this->ensureInOrder($name);
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

    private function normalize(string $name, array $schema): array
    {
        $name = Security::slug((string) ($schema['name'] ?? $name));
        $fields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];
        $locales = is_array($schema['locales'] ?? null)
            ? array_values(array_unique(array_filter(array_map(
                static fn(mixed $locale): string => self::normalizeLocale((string) $locale),
                $schema['locales']
            ), static fn(string $locale): bool => $locale !== '')))
            : [];
        $defaultLocale = self::normalizeLocale((string) ($schema['default_locale'] ?? ''));

        if ($locales === []) {
            $defaultLocale = '';
        } elseif ($defaultLocale === '' || !in_array($defaultLocale, $locales, true)) {
            $defaultLocale = $locales[0];
        }

        $fields['title'] ??= ['type' => 'text', 'required' => true];
        $fields['slug'] ??= ['type' => 'slug', 'required' => true, 'unique' => true];

        return [
            'name' => $name,
            'label' => (string) ($schema['label'] ?? ucfirst(str_replace(['-', '_'], ' ', $name))),
            'icon' => $this->normalizeIcon($schema['icon'] ?? 'mdi:file-document-outline'),
            'singleton' => (bool) ($schema['singleton'] ?? false),
            'slug_field' => (string) ($schema['slug_field'] ?? 'slug'),
            'slug_source' => (string) ($schema['slug_source'] ?? 'title'),
            'locales' => $locales,
            'default_locale' => $defaultLocale,
            'fields' => $fields,
        ];
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

    private static function normalizeLocale(string $locale): string
    {
        $locale = strtolower(trim($locale));
        $locale = str_replace('_', '-', $locale);
        $locale = preg_replace('/[^a-z0-9-]+/', '-', $locale) ?? '';

        return trim($locale, '-');
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
