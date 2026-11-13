<?php

declare(strict_types=1);

namespace CometCMS\Mcp;

use CometCMS\Auth\PermissionService;
use CometCMS\Content\ContentRepository;
use CometCMS\Content\ContentTypeRepository;
use CometCMS\Core\ConflictException;
use CometCMS\Core\HtmlSanitizer;
use CometCMS\Core\ValidationException;
use CometCMS\Extensions\ExtensionRegistry;
use CometCMS\Localization\LocalizedValue;
use CometCMS\Site\ElementConditions;
use CometCMS\Site\LayoutSchema;
use CometCMS\Site\SiteContext;
use CometCMS\Site\SiteRepository;
use CometCMS\Site\SmartValue;
use CometCMS\Site\StyleVariants;
use CometCMS\Workspaces\WorkspaceContext;

/** Shared-layout tools; transport and authorization belong to McpServer. */
final class PageBuilderTools
{
    public const NAMES = ['get_builder_schema', 'list_extensions', 'get_layout', 'get_section', 'get_element', 'set_layout', 'update_section', 'update_element', 'list_templates', 'set_template_parts'];

    public static function schemas(): array
    {
        $string = ['type' => 'string', 'minLength' => 1];
        $map = ['type' => 'object', 'additionalProperties' => true];
        $page = ['collection' => $string, 'variant' => ['type' => 'string', 'enum' => LayoutSchema::VARIANTS]];
        $target = $page + [
            'template' => $string + ['description' => 'Template id (list_templates); its content area is shared by its entries. Default: default.'],
            'entry' => $string + ['description' => 'Entry id or slug: edit only this entry\'s own content instead of a template. Detail only.'],
            'area' => $string + ['description' => 'With entry: Entry content area (page field key). Default: the first.'],
        ];
        $revision = ['revision' => $string + ['description' => 'Required revision from the latest layout/section/element read. A stale revision fails with 409.']];
        $nodes = ['type' => 'array', 'maxItems' => LayoutSchema::MAX_NODES, 'items' => ['$ref' => '#/$defs/node']];
        $overrides = ['responsive' => $map + ['description' => '{tablet?, mobile?: {props?, style?}}: values that differ on smaller screens; mobile follows tablet. Replaces the whole map.'], 'states' => $map + ['description' => '{hover?, focus?: {props?, style?}}: values while hovered or focused. Replaces the whole map.']];
        $patch = self::object(['name' => ['type' => 'string'], 'props' => $map, 'style' => $map, ...$overrides, 'children' => $nodes]);
        $patch['description'] = 'Object with any of name (string), props (object), style (object), responsive (object), states (object), children (node array). Example: {"style":{"padding_top":"lg"}}. Supply the object directly, not a JSON string or array.';
        $patch['examples'] = [['style' => ['padding_top' => 'lg']]];
        $read = [
            'depth' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Child levels to include below each section root; 0 returns roots only. Omit for all descendants. Pruned nodes have child_count and children_truncated: true.'],
            'mode' => ['type' => 'string', 'enum' => ['full', 'summary'], 'description' => 'full: nondefault props, styles and descendants. summary: IDs, kinds, names and stored styles only (including responsive/state styles). On get_layout, omitting both mode and depth keeps the section overview.'],
        ];
        $definitions = ['node' => self::object([
            'id' => $string + ['pattern' => '^[A-Za-z0-9_-]+$'],
            'kind' => ['type' => 'string', 'enum' => array_keys(LayoutSchema::spec(true)['kinds'])],
            'name' => ['type' => 'string', 'maxLength' => LayoutSchema::MAX_NODE_NAME_LENGTH],
            'props' => $map, 'style' => $map, ...$overrides, 'children' => $nodes,
        ], ['kind'])];
        $contracts = [
            'get_builder_schema' => ['Discover builder vocabulary', 'Without kind: kinds, allowed children, every style key\'s values and limits; call this first. With kind (one or a list): props with defaults and the style keys each kind takes.', ['kind' => ['anyOf' => [$string, ['type' => 'array', 'items' => $string, 'minItems' => 1]]]], []],
            'list_extensions' => ['List installed extensions', 'Installed extensions with their status, settings and blocks: each block\'s kind for layouts, what it is for, its palette category, whether it holds child elements (slot) and its variations. Blocks with background: true are section backgrounds: set them on a section, row or column as style.background_fx = {kind, props} instead of placing them. Disabled extensions\' blocks do not render. Use get_builder_schema with a kind for its props.', [], []],
            'get_layout' => ['Read layout overview or scoped tree', 'Read ordered section IDs, kinds, counts and revision. Use section_ids to select top-level sections; depth or mode opts into a tree. Summary mode omits props. Reads always return the whole layout revision. Start here before reading or writing sections.', $target + $read + ['section_ids' => ['type' => 'array', 'items' => $string, 'minItems' => 1, 'uniqueItems' => true, 'description' => 'Only these top-level section IDs, returned in layout order. Unknown IDs fail with 404; call get_layout without selectors to discover IDs.']], ['collection', 'variant']],
            'get_section' => ['Read one section', 'Read one top-level section subtree and whole layout revision. Limit child levels with depth or use mode: summary for IDs, kinds, names and styles without props. Default props are omitted in full mode; use get_builder_schema(kind) for defaults.', $target + ['section_id' => $string] + $read, ['collection', 'variant', 'section_id']],
            'get_element' => ['Read one element', 'Read a node by ID, its props/style and immediate child summaries, enclosing section and layout revision.', $target + ['element_id' => $string], ['collection', 'variant', 'element_id']],
            'set_layout' => ['Replace layout', 'Replace all sections, including empty layouts (with entry: an empty list resets the entry to its template\'s default content). Requires revision. Changes affect published pages immediately. Discover valid props with get_builder_schema.', $target + $revision + ['sections' => $nodes], ['collection', 'variant', 'revision', 'sections']],
            'update_section' => ['Patch one section', 'Merge supplied props/style keys and name into one top-level section; children explicitly replaces its subtree. Omitted keys and other sections are preserved. Returns the section summary and new revision. Requires revision; saves live.', $target + $revision + ['section_id' => $string, 'patch' => $patch], ['collection', 'variant', 'revision', 'section_id', 'patch']],
            'list_templates' => ['List templates', 'Read the templates of a collection/variant in order (the first whose conditions match an entry is used; the last is the default; index pages have only the default) and the components that can be a header or footer. Each template has its own content area: pass its id as template to the layout tools.', $page, ['collection', 'variant']],
            'set_template_parts' => ['Assign template parts', 'Set the header and/or footer of one template (default: the default template): "default" (the site\'s own), "none" or a section component id. Omitted parts stay. Edit its content with the layout tools. Saves live.', $page + ['template' => $string, 'header' => $string, 'footer' => $string], ['collection', 'variant']],
            'update_element' => ['Patch one element', 'Merge props and/or style keys on any node by ID (supply at least one of props, style, responsive, states); children are preserved. Object-valued properties (including localized maps) replace the whole property; responsive and states replace their whole maps. Requires revision; saves live.', $target + $revision + ['element_id' => $string, 'props' => $map, 'style' => $map, ...$overrides], ['collection', 'variant', 'revision', 'element_id']],
        ];
        $result = [];
        foreach ($contracts as $name => [$title, $description, $properties, $required]) {
            $write = in_array($name, ['set_layout', 'update_section', 'update_element', 'set_template_parts'], true);
            $schema = self::object($properties, $required);
            if (in_array($name, ['set_layout', 'update_section'], true)) $schema['$defs'] = $definitions;
            $result[$name] = [
                'title' => $title, 'description' => $description, 'inputSchema' => $schema,
                'annotations' => ['readOnlyHint' => !$write, 'destructiveHint' => $write, 'idempotentHint' => !$write || $name === 'set_template_parts', 'openWorldHint' => false],
            ];
        }
        return $result;
    }

    private static function object(array $properties, array $required = []): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false];
    }

    /** Content areas of the components placed so far: id => [{id, name}], null when the component is missing. */
    private array $componentAreas = [];
    private ?array $builderSpec = null;

    public function __construct(private readonly WorkspaceContext $workspace, private readonly array $principal, private readonly PermissionService $permissions = new PermissionService()) {}

    public function call(string $tool, array $args): array
    {
        $this->builderSpec = null;
        $contract = self::schemas()[$tool]['inputSchema'];
        ToolArguments::validate($tool, $args, $contract);
        if ($tool === 'get_builder_schema') return ['data' => $this->vocabulary($args)];
        if ($tool === 'list_extensions') return ['data' => $this->extensions()];

        $collection = $this->identifier($args['collection'], 'collection');
        $variant = $args['variant'];
        if (!is_string($variant) || !in_array($variant, LayoutSchema::VARIANTS, true)) $this->invalid('variant', 'Use detail or index.');
        if (!(new ContentTypeRepository($this->workspace))->exists($collection)) {
            throw new McpError('Content type not found.', 404, ['code' => 'not_found', 'collection' => $collection], [], ['Use list_content_types to find a collection, or create_content_type to create one first.']);
        }
        $site = new SiteRepository($this->workspace);
        if (!in_array($variant, $site->enabledVariantsFor($site->types()[$collection] ?? ['name' => $collection]), true)) $this->invalid('variant', 'This collection has no list page. Turn it on in the content type settings, or use detail.');
        if ($tool === 'list_templates') return ['data' => $this->templates($site, $collection, $variant)];
        if ($tool === 'set_template_parts') {
            $id = array_key_exists('template', $args) ? $this->identifier($args['template'], 'template') : SiteRepository::DEFAULT_TEMPLATE;
            $templates = $site->templates($collection, $variant);
            $index = array_search($id, array_column($templates, 'id'), true);
            if ($index === false) $this->invalid('template', 'Unknown template. Call list_templates for template ids.');
            foreach (LayoutSchema::REGIONS as $part) {
                if (!array_key_exists($part, $args)) continue;
                if (!is_string($args[$part]) || $args[$part] === '') $this->invalid($part, 'Use an id from list_templates.');
                $templates[$index][$part] = $args[$part];
            }
            try {
                $site->saveTemplates([$collection => [$variant => $templates]]);
            } catch (ValidationException $e) {
                $this->invalid('template', implode(' ', $e->fields()) . ' Call list_templates for valid parts.');
            }
            return ['data' => $this->templates($site, $collection, $variant) + ['effect' => 'Saved live for every page that uses this template.']];
        }
        $write = in_array($tool, ['set_layout', 'update_section', 'update_element'], true);
        $scope = array_key_exists('entry', $args) ? $this->entryScope($site, $collection, $variant, $args, $write) : $this->templateScope($site, $collection, $variant, $args);
        ['target' => $target, 'nodes' => $nodes] = $scope;
        $revision = LocalizedValue::hash($nodes);
        if ($tool === 'get_layout') {
            $result = $this->overview($scope, $nodes);
            if (isset($args['section_ids'])) {
                foreach ($args['section_ids'] as $id) $this->sectionIndex($nodes, $this->identifier($id, 'section_ids'));
                $nodes = array_values(array_filter($nodes, static fn(array $node): bool => in_array($node['id'], $args['section_ids'], true)));
            }
            $tree = isset($args['mode']) || isset($args['depth']);
            $result['sections'] = array_map(fn(array $node): array => $tree ? $this->readNode($node, $args) + $this->sectionSummary($node) : $this->sectionSummary($node), $nodes);
            return ['data' => $result];
        }
        if ($tool === 'get_section') {
            $index = $this->sectionIndex($nodes, $this->identifier($args['section_id'], 'section_id'));
            return ['data' => $target + ['revision' => $revision, 'section' => $this->readNode($nodes[$index], $args)]];
        }
        if ($tool === 'get_element') {
            [$node, $section] = $this->element($nodes, $this->identifier($args['element_id'], 'element_id'));
            return ['data' => $target + ['revision' => $revision, 'section_id' => $section, 'element' => $this->elementView($node)]];
        }

        if (!is_string($args['revision']) || $args['revision'] === '') $this->invalid('revision', 'Read get_layout or get_section and include its revision.');
        if ($args['revision'] !== $revision) $this->conflict();
        if ($tool === 'set_layout') {
            $candidate = $args['sections'];
        } elseif ($tool === 'update_section') {
            $index = $this->sectionIndex($nodes, $this->identifier($args['section_id'], 'section_id'));
            $nodes[$index] = $this->patch($nodes[$index], $args['patch'], true);
            $candidate = $nodes;
        } else {
            $id = $this->identifier($args['element_id'], 'element_id');
            $this->element($nodes, $id); // Fail before mutation if the ID does not exist.
            $candidate = $this->patchElement($nodes, $id, array_intersect_key($args, array_flip(['props', 'style', 'responsive', 'states'])));
        }
        $seen = [];
        $count = 0;
        $areas = [];
        $candidate = $this->prepare($candidate, 'sections', $seen, $count, ['parent' => 'root', 'page_content' => $scope['page_content'], 'rows' => 0, 'item' => false], $areas);
        try {
            $normalized = LayoutSchema::normalize($candidate, $scope['page_content']);
        } catch (ValidationException $e) {
            $this->invalid('sections', $e->getMessage() . ' Check localized values and workspace locales.');
        }
        $this->compareNodes($candidate, $normalized, 'sections');
        try {
            $scope = $scope['save']($normalized, $args['revision']);
        } catch (ConflictException) {
            $this->conflict();
        }
        $saved = $scope['nodes'];
        $target = $scope['target'];
        if ($tool === 'update_element') {
            [$node, $section] = $this->element($saved, $args['element_id']);
            return ['data' => $target + ['revision' => LocalizedValue::hash($saved), 'section_id' => $section, 'element' => $this->elementView($node), 'effect' => 'Saved live. ' . $scope['effect']]];
        }
        if ($tool === 'update_section') {
            // The caller sent the subtree; a summary confirms it without repeating it or the other sections.
            $section = $saved[$this->sectionIndex($saved, $args['section_id'])];
            return ['data' => $target + ['revision' => LocalizedValue::hash($saved), 'section' => $this->sectionSummary($section), 'effect' => 'Saved live. ' . $scope['effect']]];
        }
        return ['data' => $this->overview($scope, $saved)];
    }

    /**
     * A template's content area, shared by every entry that uses the
     * template. Each scope's save returns the scope as it is after saving.
     */
    private function templateScope(SiteRepository $site, string $collection, string $variant, array $args): array
    {
        if (array_key_exists('area', $args)) $this->invalid('area', 'Use area together with entry, to pick which Entry content area of that entry to edit.');
        $area = $args['template'] ?? SiteRepository::DEFAULT_TEMPLATE;
        if (!is_string($area) || !$site->hasTemplate($collection, $variant, $area)) $this->invalid('template', 'Unknown template. Call list_templates for template ids.');
        $nodes = $site->layout($collection, $variant, $area);
        $places = array_keys(LayoutSchema::pagePlaces($nodes));

        return [
            'target' => ['collection' => $collection, 'variant' => $variant, 'template' => $area],
            'nodes' => $nodes,
            'page_content' => $variant === 'detail',
            'effect' => 'Shared by entries that use this template; saves take effect on published pages immediately.',
            'next' => $places === [] ? null : 'Entry content areas (' . implode(', ', $places) . ') are filled by each entry with its own content. To edit one entry\'s content, pass entry (id or slug) instead of template.',
            'save' => function (array $nodes, string $revision) use ($site, $collection, $variant, $area, $args): array {
                return ['nodes' => $site->saveLayout($collection, $variant, $nodes, $this->principal, 'mcp.saved', $revision, $area)] + $this->templateScope($site, $collection, $variant, $args);
            },
        ];
    }

    /**
     * One entry's own content in an Entry content area that its template
     * shows, as the admin entry builder opens it. Without content of its own
     * the entry shows the area's default, so that is what is read and what
     * the first write starts from.
     */
    private function entryScope(SiteRepository $site, string $collection, string $variant, array $args, bool $write): array
    {
        if ($variant !== 'detail') $this->invalid('variant', 'Entries have their own content only on detail pages. Use variant detail.');
        if (array_key_exists('template', $args)) $this->invalid('template', 'Omit template with entry: an entry is edited in the template it is shown with.');
        $content = ContentRepository::make($this->workspace);
        $entry = $content->findByIdentifier($collection, $this->identifier($args['entry'], 'entry'));
        if ($entry === null) {
            throw new McpError('Entry not found.', 404, ['code' => 'not_found', 'entry' => $args['entry']], [], ['Use list_entries to find the entry id or slug.']);
        }
        $id = $content->stableId($entry);
        $context = SiteContext::make($site);
        $template = $context->template($collection, 'detail', $context->findEntry($collection, $id, true))['template'];
        $fields = (new ContentTypeRepository($this->workspace))->find($collection)['fields'] ?? [];
        $placed = array_filter(LayoutSchema::pagePlaces($site->layout($collection, 'detail', $template['id'])), static fn(string $key): bool => ($fields[$key]['type'] ?? '') === 'page', ARRAY_FILTER_USE_KEY);
        if ($placed === []) {
            throw new McpError('The template this entry uses has no Entry content area, so content of its own would not appear.', 409, ['code' => 'no_entry_content_area', 'template' => $template['id']], [], [
                'Edit the shared template instead: call get_layout with template ' . $template['id'] . ' and without entry.',
                'Or add a page_content node to that template first, to give its entries content of their own.',
            ]);
        }
        $field = $args['area'] ?? array_key_first($placed);
        if (!is_string($field) || !isset($placed[$field])) $this->invalid('area', 'The entry\'s template ' . $template['id'] . ' shows these Entry content areas: ' . implode(', ', array_keys($placed)) . '.');

        $action = $write ? 'content.update' : 'content.read';
        if (!$this->permissions->allows($this->principal, $action, ['type' => 'content', 'collection' => $collection, 'entry' => $entry, 'fields' => [$field], 'locale' => $content->locale(), 'principal' => $this->principal, 'workspace' => $this->workspace->slug()])) {
            throw new McpError('Forbidden.', 403, ['code' => 'forbidden'], [permissionGrant($action, $this->workspace->slug(), 'content:' . $collection . ':' . $id, [$field])], [
                'Editing an entry\'s content also needs ' . $action . ' on that entry. Update the token in Access Tokens.',
            ]);
        }
        $customized = LayoutSchema::hasPageContent($entry[$field] ?? null);
        $nodes = $customized ? LayoutSchema::pageNodes($entry[$field]) : self::copyIds($placed[$field]['children'] ?? [], $id . '|' . $field);

        return [
            'target' => ['collection' => $collection, 'variant' => 'detail', 'entry' => $id, 'area' => $field, 'template' => $template['id'], 'customized' => $customized],
            'nodes' => $nodes,
            'page_content' => false,
            'effect' => $customized
                ? 'Only this entry\'s page; saves take effect immediately. Writing an empty sections list resets it to the template\'s default content.'
                : 'This entry has no content of its own yet and shows the template\'s default content (shown here). The first write gives it its own copy; only this entry\'s page changes.',
            'next' => null,
            'save' => function (array $nodes) use ($content, $entry, $field, $collection, $variant, $site, $args): array {
                $content->savePage($collection, (string) $entry['id'], $field, $nodes, $this->principal, (string) ($entry['revision'] ?? ''));
                return $this->entryScope($site, $collection, $variant, $args, true);
            },
        ];
    }

    /**
     * A copy of a template's default content for an entry, with IDs derived
     * from $seed so every read of the same default has the same IDs and
     * revision.
     */
    private static function copyIds(array $nodes, string $seed): array
    {
        return array_map(static fn(array $node): array => ['id' => 'n' . substr(hash('sha256', $seed . '|' . $node['id']), 0, 10), 'children' => self::copyIds($node['children'] ?? [], $seed)] + $node, $nodes);
    }

    /** A page kind's templates in order and the components that can be a header or footer. */
    private function templates(SiteRepository $site, string $collection, string $variant): array
    {
        $options = array_values(array_filter($site->components(), static fn(array $component): bool => SiteRepository::fitsFrame($component)));

        return [
            'collection' => $collection,
            'variant' => $variant,
            'templates' => $site->templates($collection, $variant),
            'header_footer_options' => ['default', 'none', ...array_map(static fn(array $component): array => ['id' => $component['id'], 'name' => $component['name']], $options)],
            'rule' => 'The first template whose conditions all match an entry is used; the last one (default) always applies.',
        ];
    }

    /**
     * The builder vocabulary in two steps, so nothing is sent twice: without
     * kind the kinds, their children and every style key's values (keys that
     * share values listed together); with one or more kinds their props and
     * the names of the style keys they take.
     */
    private function vocabulary(array $args): array
    {
        $spec = $this->spec();
        $usable = $this->usableKinds();
        if (array_key_exists('kind', $args)) {
            $kinds = is_array($args['kind']) && array_is_list($args['kind']) && $args['kind'] !== [] ? $args['kind'] : [$args['kind']];
            $result = [];
            // Backgrounds are never children, but their props are what style.background_fx takes.
            $describable = [...$usable, ...($spec['backgrounds'] ?? [])];
            foreach ($kinds as $index => $kind) {
                if (!is_string($kind) || !in_array($kind, $describable, true)) $this->invalid('kind', 'Unknown kind. Call get_builder_schema without kind to list available kinds, and list_extensions for backgrounds.');
                $node = $spec['kinds'][$kind];
                // Icon names are listed once, without kind.
                $props = array_map(static fn(array $prop): array => $prop['type'] === 'icon' ? ['options' => in_array('', $prop['options'], true) ? 'icons, or "" for none' : 'icons'] + $prop : $prop, $node['props']);
                $result[$kind] = ['props' => $props, 'style' => $node['style'], 'children' => $node['children'], 'responsive' => ['devices' => $spec['devices']] + $node['responsive'], 'states' => ['states' => $spec['states']] + $node['states']];
            }
            $shared = [
                'bindings' => array_intersect_key($spec['bindings'], array_flip(['sources', 'date_formats'])),
                'localized_values' => 'Use {type: localized, values: {locale: value}}. Patches replace the entire property, so preserve other locales.',
                'style_values' => 'style lists the style keys a kind takes; get_builder_schema without kind lists their values and the icons.',
            ];
            return is_array($args['kind']) ? ['kinds' => $result] + $shared : ['kind' => array_key_first($result)] + $result[array_key_first($result)] + $shared;
        }
        $children = [];
        foreach ($usable as $kind) $children[$kind] = $spec['kinds'][$kind]['children'];
        $styles = array_map(static fn(array $value): array => array_diff_key($value, ['localized' => true, 'binding' => true]), $spec['style']);
        return [
            'format_version' => $spec['format_version'], 'variants' => $spec['variants'], 'root' => $spec['root'],
            'children' => self::grouped($children),
            'style' => self::grouped($styles),
            'icons' => $this->icons($spec),
            'max_nodes' => LayoutSchema::MAX_NODES, 'max_row_depth' => $spec['max_row_depth'],
            'next' => 'Call get_builder_schema with kind (one or a list) for props and style keys, then get_layout for section IDs and revision. Writes take effect immediately.',
        ];
    }

    /** Installed extensions as agents choose blocks from them; install and settings stay in the admin. */
    private function extensions(): array
    {
        $registry = new ExtensionRegistry($this->workspace);
        $metadata = $registry->metadata();
        $extensions = array_map(static function (array $extension) use ($registry): array {
            $blocks = array_map(static function (array $block) use ($registry): array {
                $spec = $registry->get('block:' . $block['name']) ?? [];
                return [
                    'kind' => 'block:' . $block['name'], 'title' => $block['title'], 'description' => $block['description'],
                    'category' => $spec['category'] ?? '', 'slot' => $spec['slot'] ?? false,
                    // Backgrounds are not placed as elements: a section, row or column shows one with style.background_fx.
                    'background' => $spec['background'] ?? false,
                    'variations' => array_map(static fn(array $variation): array => ['name' => $variation['name'], 'title' => $variation['title'], 'props' => $variation['props']], $spec['variations'] ?? []),
                    'script' => $block['script'], 'deprecated' => $block['deprecated'] !== null,
                ];
            }, $extension['blocks']);
            return [
                'name' => $extension['name'], 'title' => $extension['title'], 'description' => $extension['description'], 'version' => $extension['version'],
                'enabled' => $extension['enabled'] && $extension['dependency_errors'] === [], 'requires' => (object) $extension['requires'],
                'settings' => (object) $extension['settings_values'], 'blocks' => array_values($blocks),
            ];
        }, $metadata['extensions']);

        // Folder extensions that failed validation, as file:line problems.
        return ['extensions' => $extensions, 'failed' => $metadata['errors']];
    }

    /** The icon names icon props take. */
    private function icons(array $spec): array
    {
        foreach ($spec['kinds'] as $kind) foreach ($kind['props'] as $prop) if ($prop['type'] === 'icon') return array_values(array_filter($prop['options']));
        return [];
    }

    /** Kinds a layout or an entry's content can hold: those reachable from the top level, and component instances with their area content. */
    private function usableKinds(): array
    {
        $spec = $this->spec();
        $kinds = ['root' => true];
        for ($queue = ['root']; $queue !== [];) {
            $parent = array_shift($queue);
            foreach ($parent === 'root' ? $spec['root'] : ($spec['kinds'][$parent]['children'] ?? []) as $child) {
                if (isset($kinds[$child])) continue;
                $kinds[$child] = true;
                $queue[] = $child;
            }
        }
        unset($kinds['root']);
        return [...array_keys($kinds), LayoutSchema::COMPONENT, LayoutSchema::AREA_CONTENT];
    }

    private function spec(): array
    {
        return $this->builderSpec ??= LayoutSchema::spec(true, blocks: new ExtensionRegistry($this->workspace));
    }

    /** $map with keys of equal values joined into one "a, b" key. */
    private static function grouped(array $map): array
    {
        $groups = [];
        foreach ($map as $key => $value) $groups[json_encode($value)][] = $key;
        $result = [];
        foreach ($groups as $value => $keys) $result[implode(', ', $keys)] = json_decode($value, true);
        return $result;
    }

    private function overview(array $scope, array $nodes): array
    {
        return $scope['target'] + [
            'revision' => LocalizedValue::hash($nodes),
            'sections' => array_map($this->sectionSummary(...), $nodes),
            'effect' => $scope['effect'],
            'next' => trim('Use get_section(section_id) for a subtree or get_element(element_id) for props. Include revision in every write. ' . ($scope['next'] ?? '')),
        ];
    }

    private function sectionSummary(array $node): array
    {
        return $this->summary($node) + ($node['kind'] === LayoutSchema::PAGE_CONTENT ? ['area' => (string) ($node['props']['field'] ?? '')] : []) + ['node_count' => $this->countNodes([$node])];
    }

    private function summary(array $node): array
    {
        return array_intersect_key($node, array_flip(['id', 'kind', 'name']));
    }

    private function countNodes(array $nodes): int
    {
        $count = count($nodes);
        foreach ($nodes as $node) $count += $this->countNodes($node['children'] ?? []);
        return $count;
    }

    private function compact(array $node, bool $summary = false): array
    {
        $result = $this->summary($node);
        $defaults = array_map(static fn(array $spec): mixed => $spec['default'], $this->spec()['kinds'][$node['kind']]['props'] ?? []);
        $props = array_filter($node['props'] ?? [], static fn(mixed $value, string $key): bool => $value !== ($defaults[$key] ?? null), ARRAY_FILTER_USE_BOTH);
        if (!$summary && $props !== []) $result['props'] = $props;
        if (($node['style'] ?? []) !== []) $result['style'] = $node['style'];
        foreach (['responsive', 'states'] as $key) {
            if (!$summary && ($node[$key] ?? []) !== []) $result[$key] = $node[$key];
            if ($summary) foreach ($node[$key] ?? [] as $variant => $values) {
                if (($values['style'] ?? []) !== []) $result[$key][$variant]['style'] = $values['style'];
            }
        }
        return $result;
    }

    private function readNode(array $node, array $args): array
    {
        $result = $this->compact($node, ($args['mode'] ?? 'full') === 'summary');
        $children = $node['children'] ?? [];
        if ($children !== []) {
            if (($args['depth'] ?? null) === 0) {
                $result['child_count'] = count($children);
                $result['children_truncated'] = true;
            } else {
                if (isset($args['depth'])) $args['depth']--;
                $result['children'] = array_map(fn(array $child): array => $this->readNode($child, $args), $children);
            }
        }
        return $result;
    }

    private function elementView(array $node): array
    {
        $result = $this->compact(['children' => []] + $node);
        $result['children'] = array_map($this->summary(...), $node['children'] ?? []);
        if ($node['kind'] === LayoutSchema::COMPONENT && ($areas = $this->areasOf((string) ($node['props']['component'] ?? ''))) !== null) {
            $filled = array_column(array_map(static fn(array $child): array => ['area' => $child['props']['area'] ?? '', 'id' => $child['id']], $node['children'] ?? []), 'id', 'area');
            $result['content_areas'] = array_map(static fn(array $area): array => $area + ['content_id' => $filled[$area['id']] ?? null], $areas);
        }
        return $result;
    }

    /** The content areas of component $id, or null when there is no such component. */
    private function areasOf(string $id): ?array
    {
        if (!array_key_exists($id, $this->componentAreas)) {
            $this->componentAreas[$id] = $id !== '' ? (new SiteRepository($this->workspace))->component($id)['areas'] ?? null : null;
        }
        return $this->componentAreas[$id];
    }

    private function sectionIndex(array $nodes, string $id): int
    {
        foreach ($nodes as $index => $node) if ($node['id'] === $id) return $index;
        throw new McpError('Section ID not found at the layout root.', 404, ['code' => 'section_not_found', 'section_id' => $id], [], ['Call get_layout to list top-level section IDs. For nested nodes use get_element and update_element.']);
    }

    private function element(array $nodes, string $id): array
    {
        foreach ($nodes as $section) {
            $node = $this->find([$section], $id);
            if ($node !== null) return [$node, $section['id']];
        }
        throw new McpError('Element ID not found in this layout.', 404, ['code' => 'element_not_found', 'element_id' => $id], [], ['Read get_layout and get_section to find current node IDs. A component\'s own elements are not part of the layout; an instance\'s children are what it shows in the component\'s content areas.']);
    }

    private function find(array $nodes, string $id): ?array
    {
        foreach ($nodes as $node) {
            if ($node['id'] === $id) return $node;
            $found = $this->find($node['children'] ?? [], $id);
            if ($found !== null) return $found;
        }
        return null;
    }

    private function patch(array $node, mixed $patch, bool $section = false): array
    {
        $this->map($patch, 'patch');
        $this->keys($patch, $section ? ['name', 'props', 'style', 'responsive', 'states', 'children'] : ['props', 'style', 'responsive', 'states'], 'patch');
        if ($patch === []) $this->invalid($section ? 'patch' : 'props', 'Supply ' . ($section ? 'props, style, responsive, states, name or children' : 'props, style, responsive or states') . ' to change.');
        foreach (['props', 'style'] as $key) {
            if (array_key_exists($key, $patch)) {
                $this->map($patch[$key], 'patch.' . $key);
                $node[$key] = array_replace($node[$key] ?? [], $patch[$key]);
            }
        }
        foreach (['name', 'responsive', 'states', 'children'] as $key) if (array_key_exists($key, $patch)) $node[$key] = $patch[$key];
        return $node;
    }

    private function patchElement(array $nodes, string $id, array $patch): array
    {
        foreach ($nodes as &$node) {
            if ($node['id'] === $id) $node = $this->patch($node, $patch);
            else $node['children'] = $this->patchElement($node['children'] ?? [], $id, $patch);
        }
        return $nodes;
    }

    /**
     * Check shapes, supplied IDs and placement before using the lenient
     * editor normalizer, which drops what it cannot place. $at describes
     * where the nodes go, as LayoutSchema::normalize tracks it.
     */
    private function prepare(mixed $nodes, string $path, array &$seen, int &$count, array $at, array &$areas, int $depth = 0): array
    {
        if (!is_array($nodes) || !array_is_list($nodes)) $this->invalid($path, 'Expected an array of nodes. Use [] for an empty layout or children.');
        if ($depth > 32) $this->invalid($path, 'Tree is too deep. Reduce nested rows/templates.');
        foreach ($nodes as $index => &$node) {
            $nodePath = $path . '.' . $index;
            if (++$count > LayoutSchema::MAX_NODES) $this->invalid($nodePath, 'Layout exceeds the ' . LayoutSchema::MAX_NODES . ' node limit.');
            $this->map($node, $nodePath);
            $this->keys($node, ['id', 'kind', 'name', 'props', 'style', 'responsive', 'states', 'children'], $nodePath);
            if (!is_string($node['kind'] ?? null) || !in_array($node['kind'], $this->usableKinds(), true)) $this->invalid($nodePath . '.kind', 'Unknown or missing kind.');
            $this->placement($node, $nodePath, $at, $areas);
            if (!array_key_exists('id', $node)) $node['id'] = LayoutSchema::newId();
            $id = $this->identifier($node['id'], $nodePath . '.id');
            if (isset($seen[$id])) $this->invalid($nodePath . '.id', 'Duplicate node ID. Use a unique ID or omit IDs on new nodes.');
            $seen[$id] = true;
            if (array_key_exists('name', $node) && !is_string($node['name'])) $this->invalid($nodePath . '.name', 'Expected a string.');
            foreach (['props', 'style'] as $key) {
                if (!array_key_exists($key, $node)) continue;
                $this->map($node[$key], $nodePath . '.' . $key);
                $specs = $key === 'props' ? $this->spec()['kinds'][$node['kind']]['props'] : array_intersect_key($this->spec()['style'], array_flip($this->spec()['kinds'][$node['kind']]['style']));
                foreach ($node[$key] as $property => $value) {
                    $valuePath = $nodePath . '.' . $key . '.' . $property;
                    if (!isset($specs[$property])) $this->invalid($valuePath, 'Unknown ' . $key . ' key for ' . $node['kind'] . '.');
                    $this->valueShape($value, $specs[$property], $valuePath, !empty($specs[$property]['localized']), isset($specs[$property]['binding']));
                }
            }
            foreach (['responsive' => array_keys(StyleVariants::DEVICES), 'states' => StyleVariants::STATES] as $group => $variants) {
                if (!array_key_exists($group, $node)) continue;
                $this->map($node[$group], $nodePath . '.' . $group);
                $this->keys($node[$group], $variants, $nodePath . '.' . $group);
                foreach ($node[$group] as $variant => $values) {
                    $this->map($values, $nodePath . '.' . $group . '.' . $variant);
                    $this->keys($values, ['props', 'style'], $nodePath . '.' . $group . '.' . $variant);
                    foreach ($values as $part => $items) $this->map($items, $nodePath . '.' . $group . '.' . $variant . '.' . $part);
                }
            }
            $kind = $node['kind'];
            if ($kind === LayoutSchema::COMPONENT) $node['children'] = $this->areaContents($node, $nodePath);
            $node['children'] = $this->prepare($node['children'] ?? [], $nodePath . '.children', $seen, $count, [
                // A repeater's item template may not contain another one.
                'parent' => ($this->spec()['kinds'][$kind]['slot'] ?? false) ? ($at['item'] ? 'item-slot' : 'slot') : ($kind === 'field' ? ($at['item'] ? '' : 'field') : $kind),
                // Cards, item templates, an area's default content and an instance's content areas hold no Entry content area.
                'page_content' => $at['page_content'] && !in_array($kind, ['field', 'collection', LayoutSchema::PAGE_CONTENT, LayoutSchema::COMPONENT], true),
                'rows' => match ($kind) {
                    LayoutSchema::PAGE_CONTENT => 0,
                    LayoutSchema::COMPONENT => $at['rows'] + (($node['props']['root'] ?? '') === 'column' ? 0 : 1),
                    default => $at['rows'] + ($kind === 'row' ? 1 : 0),
                },
                'item' => $at['item'] || $kind === 'field',
            ], $areas, $depth + 1);
        }
        return $nodes;
    }

    /**
     * An instance's children: one area_content node per content area of its
     * component, holding what this placement shows there. Areas left out
     * start empty.
     */
    private function areaContents(array $node, string $path): array
    {
        $children = $node['children'] ?? [];
        $areas = $this->areasOf(is_string($node['props']['component'] ?? null) ? $node['props']['component'] : '');
        if ($areas === null || !is_array($children) || !array_is_list($children)) return $children;
        $ids = array_column($areas, 'id');
        $list = $ids === [] ? 'The component has no content areas, so its instances have no children.' : 'Its content areas: ' . implode(', ', array_map(static fn(array $area): string => $area['id'] . ($area['name'] !== '' ? ' (' . $area['name'] . ')' : ''), $areas)) . '.';
        $filled = [];
        foreach ($children as $index => $child) {
            $area = is_array($child) && ($child['kind'] ?? null) === LayoutSchema::AREA_CONTENT ? $child['props']['area'] ?? null : null;
            if (!is_string($area) || !in_array($area, $ids, true)) $this->invalid($path . '.children.' . $index, 'An instance\'s children are area_content nodes with props.area naming one of its component\'s content areas. ' . $list);
            if (isset($filled[$area])) $this->invalid($path . '.children.' . $index . '.props.area', 'Each content area has one area_content node per instance.');
            $filled[$area] = true;
        }
        foreach ($ids as $id) {
            if (!isset($filled[$id])) $children[] = ['kind' => LayoutSchema::AREA_CONTENT, 'props' => ['area' => $id], 'children' => []];
        }
        return $children;
    }

    /** Name the node the normalizer would drop for its placement, and how to place it instead. */
    private function placement(array $node, string $path, array $at, array &$areas): void
    {
        $kind = $node['kind'];
        $placed = LayoutSchema::placementKind($node);
        if ($placed === '') $this->invalid($path . '.props.root', 'A component instance needs props.root: ' . implode(', ', LayoutSchema::STRUCTURE) . '.');
        $allowed = LayoutSchema::childrenFor($at['parent'], $at['page_content'], false);
        if (!in_array($placed, $allowed, true)) {
            $where = $at['parent'] === 'root' ? 'at the top level' : ($at['parent'] === '' ? 'in an item template inside another item template' : 'inside a ' . $at['parent']);
            $holds = $allowed === [] ? 'It holds no children.' : 'Allowed there: ' . implode(', ', $allowed) . '.';
            $wrap = $this->wrapping($at['parent'], $placed);
            $areaHint = match ($kind) {
                LayoutSchema::PAGE_CONTENT => ' Entry content areas exist only in detail templates, outside cards, item templates and other areas, never in an entry\'s own content.',
                LayoutSchema::AREA_CONTENT => ' area_content nodes are the children of a component instance, one per content area of its component.',
                default => '',
            };
            $this->invalid($path, 'A ' . $placed . ' cannot be placed ' . $where . '. ' . $holds . ($wrap !== [] ? ' Wrap it in ' . implode(' > ', $wrap) . '.' : '') . $areaHint);
        }
        if ($placed === 'row' && $at['rows'] > LayoutSchema::MAX_ROW_DEPTH) {
            $this->invalid($path, 'Rows nest at most ' . LayoutSchema::MAX_ROW_DEPTH . ' levels inside other rows. Flatten this row into its parent column.');
        }
        if ($kind === LayoutSchema::PAGE_CONTENT && is_string($node['props']['field'] ?? null) && $node['props']['field'] !== '') {
            if (isset($areas[$node['props']['field']])) $this->invalid($path . '.props.field', 'Each Entry content area can be placed once per template.');
            $areas[$node['props']['field']] = true;
        }
        if ($kind === 'collection' && is_array($node['children'] ?? null) && count($node['children']) > 1) {
            $this->invalid($path . '.children', 'A collection holds a single card (one column) that repeats for each entry.');
        }
    }

    /** The shortest chain of section/row/column wrappers that lets $parent hold $kind, e.g. [row, column]; empty if none. */
    private function wrapping(string $parent, string $kind): array
    {
        $structure = static fn(string $of): array => array_values(array_intersect(LayoutSchema::allowedChildren($of), LayoutSchema::STRUCTURE));
        $queue = array_map(static fn(string $child): array => [$child], $structure($parent));
        while ($queue !== []) {
            $chain = array_shift($queue);
            if (count($chain) > 3) break;
            if (in_array($kind, LayoutSchema::allowedChildren(end($chain)), true)) return $chain;
            foreach ($structure(end($chain)) as $child) if (!in_array($child, $chain, true)) $queue[] = [...$chain, $child];
        }
        return [];
    }

    /** Reject malformed compound values before lenient normalizers can cast them. */
    private function valueShape(mixed $value, array $spec, string $path, bool $localized = false, bool $binding = false): void
    {
        $type = $spec['type'];
        if ($localized && LocalizedValue::is($value)) {
            $this->map($value['values'] ?? null, $path . '.values');
            foreach ($value['values'] as $locale => $item) $this->valueShape($item, $spec, $path . '.values.' . $locale, false, $binding);
            return;
        }
        if ($binding && SmartValue::isDynamic($value)) return; // SmartValue validates its own structure.
        if ($type === 'list') {
            if (!is_array($value) || !array_is_list($value)) $this->invalid($path, 'Expected an array of item objects.');
            foreach ($value as $index => $item) {
                $this->map($item, $path . '.' . $index);
                $this->keys($item, array_keys($spec['fields']), $path . '.' . $index);
                foreach ($item as $key => $field) $this->valueShape($field, $spec['fields'][$key], $path . '.' . $index . '.' . $key);
            }
            return;
        }
        if (in_array($type, ['set', 'media_list', 'content_types', 'search_fields', 'sort_fields'], true)) {
            if (!is_array($value) || !array_is_list($value)) $this->invalid($path, 'Expected an array of strings.');
            foreach ($value as $index => $item) if (!is_string($item)) $this->invalid($path . '.' . $index, 'Expected a string.');
            return;
        }
        if ($type === 'table_rows') {
            if (!is_array($value) || !array_is_list($value)) $this->invalid($path, 'Expected an array of rows, each an array of cells: [["Plan","Price"],["Basic","9 €"]].');
            if (count($value) > LayoutSchema::TABLE_MAX_ROWS) $this->invalid($path, 'At most ' . LayoutSchema::TABLE_MAX_ROWS . ' rows.');
            foreach ($value as $r => $row) {
                if (!is_array($row) || !array_is_list($row)) $this->invalid($path . '.' . $r, 'Expected an array of cells.');
                if (count($row) > LayoutSchema::TABLE_MAX_COLUMNS) $this->invalid($path . '.' . $r, 'At most ' . LayoutSchema::TABLE_MAX_COLUMNS . ' columns.');
                if (count($row) !== count($value[0])) $this->invalid($path . '.' . $r, 'Every row needs the same number of cells as the first row (' . count($value[0]) . ').');
                foreach ($row as $c => $cell) {
                    if (!is_string($cell) && !SmartValue::isDynamic($cell)) $this->invalid($path . '.' . $r . '.' . $c, 'Expected a string of inline HTML or a binding.');
                }
            }
            return;
        }
        if ($type === 'list_filters') {
            if (!is_array($value) || !array_is_list($value)) $this->invalid($path, 'Expected an array of {field, label?, multiple?} objects.');
            foreach ($value as $index => $row) {
                $this->map($row, $path . '.' . $index);
                if (!is_string($row['field'] ?? null)) $this->invalid($path . '.' . $index . '.field', 'Expected a field name.');
                if (array_key_exists('label', $row) && !is_string($row['label'])) $this->invalid($path . '.' . $index . '.label', 'Expected a string.');
                if (array_key_exists('multiple', $row) && !is_bool($row['multiple'])) $this->invalid($path . '.' . $index . '.multiple', 'Expected a boolean.');
            }
            return;
        }
        if ($type === 'conditions') {
            if ($value === []) return;
            $this->map($value, $path);
            if (array_key_exists('match', $value) && !in_array($value['match'], ['all', 'any'], true)) $this->invalid($path . '.match', 'Use all or any.');
            if (!is_array($value['conditions'] ?? null) || !array_is_list($value['conditions'])) $this->invalid($path . '.conditions', 'Expected an array of {field, op, value?} objects.');
            foreach ($value['conditions'] as $index => $condition) {
                $this->map($condition, $path . '.conditions.' . $index);
                $this->map($condition['field'] ?? null, $path . '.conditions.' . $index . '.field');
                if (!in_array($condition['op'] ?? null, ElementConditions::OPS, true)) $this->invalid($path . '.conditions.' . $index . '.op', 'Use one of: ' . implode(', ', ElementConditions::OPS) . '.');
                if (isset($condition['value']) && !is_scalar($condition['value'])) $this->invalid($path . '.conditions.' . $index . '.value', 'Expected a string, number or boolean.');
            }
            return;
        }
        if ($type === 'background_fx') {
            if ($value === []) return;
            $this->map($value, $path);
            $this->keys($value, ['kind', 'props'], $path);
            if (!is_string($value['kind'] ?? null)) $this->invalid($path . '.kind', 'Expected a background kind such as "block:vendor/name"; list_extensions lists them with background: true.');
            if (array_key_exists('props', $value)) $this->map($value['props'], $path . '.props');
            return;
        }
        if (in_array($type, ['link', 'gradient', 'spans'], true)) {
            $this->map($value, $path);
            if ($type === 'link') {
                foreach (['type', 'url', 'anchor', 'collection', 'entry'] as $key) if (array_key_exists($key, $value) && !is_string($value[$key])) $this->invalid($path . '.' . $key, 'Expected a string.');
                if (isset($value['new_tab']) && !is_bool($value['new_tab'])) $this->invalid($path . '.new_tab', 'Expected a boolean.');
                if (isset($value['open_modal']) && !is_bool($value['open_modal'])) $this->invalid($path . '.open_modal', 'Expected a boolean.');
            }
            return;
        }
        $valid = match ($type) {
            'bool' => is_bool($value),
            'int' => is_int($value),
            default => is_string($value),
        };
        if (!$valid) $this->invalid($path, 'Wrong value type; inspect get_builder_schema(kind).');
    }

    /** Defaults can be added, but no submitted nodes or values may disappear. */
    private function compareNodes(array $input, array $output, string $path): void
    {
        if (count($input) !== count($output)) $this->invalid($path, 'Invalid child placement, nesting limit, duplicate page placeholder or collection card count. Inspect allowed children with get_builder_schema.');
        $styles = LayoutSchema::styleSpec();
        foreach ($input as $index => $node) {
            $props = $this->spec()['kinds'][$node['kind']]['props'];
            $actual = $output[$index];
            $nodePath = $path . '.' . $index;
            foreach (['id', 'kind', 'name'] as $key) {
                if (array_key_exists($key, $node) && $node[$key] !== ($actual[$key] ?? ($key === 'name' ? '' : null))) $this->invalid($nodePath . '.' . $key, 'Value would be changed or removed by the layout schema.');
            }
            foreach ($node['props'] ?? [] as $key => $value) {
                if (!array_key_exists($key, $actual['props'])) $this->invalid($nodePath . '.props.' . $key, 'Unknown property for ' . $node['kind'] . '.');
                $this->compareValue($value, $actual['props'][$key], $nodePath . '.props.' . $key, $this->allowed($props[$key] ?? []));
            }
            $allowedStyle = array_flip(LayoutSchema::styleKeys($node['kind']));
            foreach ($node['style'] ?? [] as $key => $value) {
                if (!isset($allowedStyle[$key])) $this->invalid($nodePath . '.style.' . $key, 'Unknown style for ' . $node['kind'] . '.');
                $this->compareValue($value, $actual['style'][$key] ?? $styles[$key]['default'], $nodePath . '.style.' . $key, $this->allowed($styles[$key]));
            }
            foreach (['responsive', 'states'] as $group) {
                if (array_key_exists($group, $node)) $this->compareValue($node[$group], $actual[$group] ?? [], $nodePath . '.' . $group);
            }
            $this->compareNodes($node['children'], $actual['children'], $nodePath . '.children');
        }
    }

    private function compareValue(mixed $input, mixed $output, string $path, string $allowed = ''): void
    {
        if (is_array($input) && is_array($output)) {
            if (array_is_list($input) && $input !== [] && count($input) !== count($output)) $this->invalid($path, trim('Some supplied values would be discarded. ' . $allowed));
            foreach ($input as $key => $value) {
                if (!array_key_exists($key, $output)) $this->invalid($path . '.' . $key, 'Unknown or invalid value would be discarded.');
                $this->compareValue($value, $output[$key], $path . '.' . $key, $allowed);
            }
            return;
        }
        if ($input !== $output) $this->invalid($path, 'Value would be sanitized, clamped or replaced by a default. ' . ($allowed !== '' ? $allowed : 'Use the allowed type/value from get_builder_schema(kind).'));
    }

    /** What a prop/style spec accepts, for value errors. */
    private function allowed(array $spec): string
    {
        return match ($spec['type'] ?? '') {
            'icon' => 'Use a name from icons in get_builder_schema without kind.',
            'enum', 'set' => 'Use ' . (($spec['type'] === 'set') ? 'a list of: ' : 'one of: ') . implode(', ', array_map(static fn(string $option): string => $option === '' ? '"" (default)' : $option, $spec['options'])) . '.',
            'color' => 'Use one of: ' . implode(', ', array_filter($spec['options'])) . ', or "" (default).',
            'int' => 'Use an integer from ' . $spec['min'] . ' to ' . $spec['max'] . '.',
            'string' => 'Use plain text of at most ' . ($spec['max'] ?? 200) . ' characters, without line breaks.',
            'html' => 'Use HTML with only these tags: ' . implode(', ', array_keys(HtmlSanitizer::ALLOWED_TAGS)) . '; no style or event attributes (span/div take class).',
            'conditions' => 'Use {match: "all"|"any", conditions: [{field: {type: "reference", source, path}, op, value}]}; references take no filters, and empty/not_empty take no value.',
            'table_rows' => 'Use cells of inline HTML with only these tags: ' . implode(', ', LayoutSchema::TABLE_CELL_TAGS) . '; block tags are removed.',
            'raw_html' => 'Use HTML source of at most ' . $spec['max'] . ' characters. Markup and embedded style blocks are preserved.',
            default => '',
        };
    }

    private function map(mixed $value, string $path): void
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) $this->invalid($path, 'Expected an object with named keys.');
    }

    private function keys(array $value, array $allowed, string $path): void
    {
        foreach (array_keys($value) as $key) if (!in_array($key, $allowed, true)) $this->invalid($path . '.' . $key, 'Unknown key. Allowed: ' . implode(', ', $allowed) . '.');
    }

    private function identifier(mixed $value, string $path): string
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9_-]+$/D', $value)) $this->invalid($path, 'Use a nonempty ID containing letters, numbers, underscores and dashes.');
        return $value;
    }

    private function invalid(string $path, string $message): never
    {
        throw new McpError('Invalid builder input at ' . $path . ': ' . $message, 422, ['code' => 'validation_failed', 'path' => $path], [], ['Read get_builder_schema(kind) for allowed properties, styles, values and children. No layout changes were saved.']);
    }

    private function conflict(): never
    {
        throw new McpError('Content changed since it was read. No changes were saved.', 409, ['code' => 'conflict'], [], ['Read get_layout or get_section again, reconcile your changes, then retry with the new revision. Do not blindly replay a stale full replacement.']);
    }
}
