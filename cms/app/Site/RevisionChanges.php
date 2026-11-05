<?php

declare(strict_types=1);

namespace CometCMS\Site;

use CometCMS\Localization\LocalizedValue;

/**
 * What a save changed, so the history can say more than "saved".
 *
 * A revision keeps the state from before a save; comparing it with the next
 * newer state (or the current one) tells what that save did. Nodes are
 * matched by id: an element is added, removed, moved, has its content edited
 * (its localized props: texts, media, links) or its design changed (any other
 * prop, style, responsive or state value, or its name). Only the outermost of
 * added or removed elements is listed, so a new section reads as one change.
 */
final class RevisionChanges
{
    /** Changes listed per revision; the rest only count. */
    public const LIMIT = 12;

    public const ACTIONS = ['added', 'removed', 'edited', 'styled', 'moved'];

    /** Theme keys by the design group they belong to. */
    private const THEME_GROUPS = [
        'colors' => ['mode', 'colors', 'dark_colors', 'custom_colors'],
        'typography' => ['fonts', 'base_size', 'line_height', 'type_scale', 'heading_weight', 'heading_line_height', 'heading_letter_spacing', 'heading_transform', 'headings'],
        'shapes' => ['radius', 'radii', 'shadow_scale', 'shadows'],
        'links' => ['links'],
        'buttons' => ['buttons'],
        'fields' => ['fields'],
        'motion' => ['motion'],
        'css' => ['custom_css'],
        'theme' => ['active_theme'],
    ];

    private const IGNORED_THEME_KEYS = ['updated_at'];

    /**
     * Revisions (newest first, each with its snapshot) with a `changes`
     * summary of what the save after each one changed. $snapshot turns a
     * revision into what is compared (nodes or a theme); $current is the
     * saved state now, which the newest revision is compared with.
     *
     * @param list<array> $revisions
     * @param callable(array): mixed $snapshot
     * @param callable(mixed, mixed): array $compare
     * @return list<array>
     */
    public static function annotate(array $revisions, callable $snapshot, mixed $current, callable $compare): array
    {
        $after = $current;
        foreach ($revisions as &$revision) {
            $before = $snapshot($revision);
            $revision['changes'] = $before === null || $after === null ? null : self::summary($compare($before, $after));
            $after = $before;
        }

        return $revisions;
    }

    /**
     * @param list<array> $changes
     * @return array{items: list<array>, total: int}
     */
    public static function summary(array $changes): array
    {
        return ['items' => array_slice($changes, 0, self::LIMIT), 'total' => count($changes)];
    }

    /**
     * Changes between two node trees, in the order the elements appear.
     *
     * @return list<array{action: string, kind: string, label: string}>
     */
    public static function nodes(mixed $before, mixed $after): array
    {
        $old = self::flatten(is_array($before) ? $before : []);
        $new = self::flatten(is_array($after) ? $after : []);
        $moved = self::movedIds($old, $new);
        $changes = [];

        foreach ($new as $id => $entry) {
            $node = $entry['node'];
            if (!isset($old[$id])) {
                if ($entry['parent'] === null || isset($old[$entry['parent']])) $changes[] = self::change('added', $node);
                continue;
            }
            $previous = $old[$id]['node'];
            $content = array_flip(LayoutSchema::LOCALIZED_PROPS[(string) ($node['kind'] ?? '')] ?? []);
            $props = is_array($node['props'] ?? null) ? $node['props'] : [];
            $previousProps = is_array($previous['props'] ?? null) ? $previous['props'] : [];
            if (($node['kind'] ?? null) !== ($previous['kind'] ?? null) || array_intersect_key($props, $content) != array_intersect_key($previousProps, $content)) {
                $changes[] = self::change('edited', $node);
            } elseif (array_diff_key($props, $content) != array_diff_key($previousProps, $content) || self::design($node) != self::design($previous)) {
                $changes[] = self::change('styled', $node);
            } elseif (isset($moved[$id])) {
                $changes[] = self::change('moved', $node);
            }
        }
        foreach ($old as $id => $entry) {
            if (!isset($new[$id]) && ($entry['parent'] === null || isset($new[$entry['parent']]))) {
                $changes[] = self::change('removed', $entry['node']);
            }
        }

        return $changes;
    }

    /**
     * Design groups two themes differ in.
     *
     * @return list<array{action: string, group: string}>
     */
    public static function theme(mixed $before, mixed $after): array
    {
        $before = is_array($before) ? $before : [];
        $after = is_array($after) ? $after : [];
        $groups = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            if (in_array($key, self::IGNORED_THEME_KEYS, true) || ($before[$key] ?? null) == ($after[$key] ?? null)) continue;
            $groups[self::themeGroup((string) $key)] = true;
        }
        $order = [...array_keys(self::THEME_GROUPS), 'spacing'];
        $groups = array_keys($groups);
        usort($groups, static fn(string $a, string $b): int => array_search($a, $order, true) <=> array_search($b, $order, true));

        return array_map(static fn(string $group): array => ['action' => 'theme', 'group' => $group], $groups);
    }

    /**
     * Entry fields two entries differ in, leaving out the page field itself.
     *
     * @param array<string, string> $labels field name => label
     * @return list<array{action: string, kind: string, label: string}>
     */
    public static function fields(array $before, array $after, array $labels, string $skip): array
    {
        $changes = [];
        foreach ($labels as $name => $label) {
            if ($name === $skip || ($before[$name] ?? null) == ($after[$name] ?? null)) continue;
            $changes[] = ['action' => 'field', 'kind' => 'field', 'label' => $label];
        }

        return $changes;
    }

    private static function themeGroup(string $key): string
    {
        foreach (self::THEME_GROUPS as $group => $keys) {
            if (in_array($key, $keys, true)) return $group;
        }
        // Widths, paddings (per device) and the spacing scale.
        return 'spacing';
    }

    /** @return array{action: string, kind: string, label: string} */
    private static function change(string $action, array $node): array
    {
        return ['action' => $action, 'kind' => (string) ($node['kind'] ?? ''), 'label' => self::label($node)];
    }

    private static function design(array $node): array
    {
        return [$node['style'] ?? [], $node['responsive'] ?? [], $node['states'] ?? [], $node['name'] ?? ''];
    }

    /**
     * Every node by id with its parent and the node itself (without children).
     *
     * @return array<string, array{node: array, parent: ?string, children: list<string>}>
     */
    private static function flatten(array $nodes, ?string $parent = null, array &$map = []): array
    {
        foreach ($nodes as $node) {
            if (!is_array($node) || !isset($node['id'])) continue;
            $id = (string) $node['id'];
            $children = is_array($node['children'] ?? null) ? $node['children'] : [];
            unset($node['children']);
            $map[$id] = ['node' => $node, 'parent' => $parent, 'children' => []];
            if ($parent !== null) $map[$parent]['children'][] = $id;
            self::flatten($children, $id, $map);
        }
        if ($parent === null) {
            $map["\0root"] = ['node' => [], 'parent' => null, 'children' => array_values(array_filter(array_keys($map), static fn(string $id): bool => $map[$id]['parent'] === null && $id !== "\0root"))];
        }

        return $map;
    }

    /**
     * Ids that changed parent, or whose order among the siblings both trees
     * share changed. Of reordered siblings, the fewest are counted as moved:
     * those outside the longest run kept in order.
     *
     * @return array<string, true>
     */
    private static function movedIds(array &$old, array &$new): array
    {
        $moved = [];
        foreach ($new as $id => $entry) {
            if ($id === "\0root" || !isset($old[$id])) continue;
            if ($old[$id]['parent'] !== $entry['parent']) $moved[$id] = true;
        }
        foreach ($new as $id => $entry) {
            if (!isset($old[$id])) continue;
            $before = array_values(array_filter($old[$id]['children'], static fn(string $child): bool => ($new[$child]['parent'] ?? false) === ($id === "\0root" ? null : $id)));
            $after = array_values(array_filter($entry['children'], static fn(string $child): bool => ($old[$child]['parent'] ?? false) === ($id === "\0root" ? null : $id)));
            if ($before === $after) continue;
            $kept = array_flip(self::longestCommon($before, $after));
            foreach ($after as $child) {
                if (!isset($kept[$child])) $moved[$child] = true;
            }
        }
        unset($old["\0root"], $new["\0root"]);

        return $moved;
    }

    /** @return list<string> */
    private static function longestCommon(array $a, array $b): array
    {
        $rows = count($a);
        $cols = count($b);
        $table = array_fill(0, $rows + 1, array_fill(0, $cols + 1, 0));
        for ($i = $rows - 1; $i >= 0; $i--) {
            for ($j = $cols - 1; $j >= 0; $j--) {
                $table[$i][$j] = $a[$i] === $b[$j] ? $table[$i + 1][$j + 1] + 1 : max($table[$i + 1][$j], $table[$i][$j + 1]);
            }
        }
        $result = [];
        for ($i = 0, $j = 0; $i < $rows && $j < $cols;) {
            if ($a[$i] === $b[$j]) {
                $result[] = $a[$i];
                $i++;
                $j++;
            } elseif ($table[$i + 1][$j] >= $table[$i][$j + 1]) {
                $i++;
            } else {
                $j++;
            }
        }

        return $result;
    }

    /** A short text that tells the element apart: its name, or what it says. */
    private static function label(array $node): string
    {
        $name = trim((string) ($node['name'] ?? ''));
        if ($name !== '') return self::shorten($name);
        $props = is_array($node['props'] ?? null) ? $node['props'] : [];
        foreach (['text', 'label', 'title', 'caption', 'alt', 'html'] as $key) {
            $text = self::text($props[$key] ?? null);
            if ($text !== '') return self::shorten($text);
        }

        return '';
    }

    private static function text(mixed $value): string
    {
        if (LocalizedValue::is($value)) {
            $values = is_array($value['values'] ?? null) ? $value['values'] : [];
            $value = reset($values);
        }
        if (!is_string($value)) return '';
        $text = html_entity_decode(strip_tags(str_replace(['<br', '</p>'], [' <br', ' </p>'], $value)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function shorten(string $text): string
    {
        return mb_strlen($text) > 40 ? rtrim(mb_substr($text, 0, 39)) . '…' : $text;
    }
}
