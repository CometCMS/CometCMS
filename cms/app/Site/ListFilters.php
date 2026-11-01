<?php

declare(strict_types=1);

namespace CometCMS\Site;

use CometCMS\Core\Security;
use CometCMS\Localization\Messages;

/**
 * Filters and sorting a visitor chooses above an entry list. The editor picks
 * the fields; each field's type decides its control:
 *
 *   boolean                      a chip that shows only entries where it is on
 *   select, relation             chips of the options or linked entries
 *   text and other written text  a search box (contains)
 *   number, date                 a from–to range
 *
 * Choices travel in the URL as c[<list id>][<field>], plus c[<list id>][sort],
 * so filtered pages can be shared, are cached separately and work without
 * JavaScript. Only fields the editor offered are read from the URL.
 */
final class ListFilters
{
    public const MAX_FILTERS = 8;
    public const MAX_SORTS = 10;
    private const MAX_OPTIONS = 50;
    private const TEXT_TYPES = ['text', 'slug', 'textarea', 'markdown', 'html', 'email', 'url'];
    private const RANGE_TYPES = ['number', 'range', 'date', 'datetime'];
    public const DATE_FIELDS = ['published_at', 'created_at', 'updated_at'];

    /**
     * The types of a list of several as one schema: every field of any of
     * them (the first type's settings win), with the options of a select
     * field joined across the types that have it.
     */
    public static function combinedSchema(array $schemas): array
    {
        $fields = [];
        foreach ($schemas as $schema) {
            foreach (is_array($schema['fields'] ?? null) ? $schema['fields'] : [] as $key => $config) {
                if (!is_array($config)) continue;
                if (!isset($fields[$key])) {
                    $fields[$key] = $config;
                } elseif (($fields[$key]['type'] ?? '') === 'select' && ($config['type'] ?? '') === 'select') {
                    $fields[$key]['options'] = self::optionMap($fields[$key]['options'] ?? []) + self::optionMap($config['options'] ?? []);
                }
            }
        }

        return ['fields' => $fields];
    }

    /** Select options keyed by value, whether stored as a list or a map. */
    private static function optionMap(mixed $options): array
    {
        $options = is_array($options) ? $options : [];

        return array_is_list($options) ? array_combine(array_map('strval', $options), $options) : $options;
    }

    public static function script(): string
    {
        return (string) file_get_contents(__DIR__ . '/assets/list-filters.js') . "\ninitListFilters(document);";
    }

    public static function cspSource(): string
    {
        return "'sha256-" . base64_encode(hash('sha256', self::script(), true)) . "'";
    }

    /** The editor's filter rows: distinct fields, each with an optional label. */
    public static function normalize(mixed $value): array
    {
        $rows = [];
        foreach (is_array($value) ? array_values($value) : [] as $row) {
            $field = LayoutSchema::key(is_array($row) ? ($row['field'] ?? '') : $row);
            if ($field === '' || isset($rows[$field])) continue;
            $rows[$field] = [
                'field' => $field,
                'label' => is_array($row) && is_scalar($row['label'] ?? null) ? trim(Text::limit(str_replace(["\r", "\n"], ' ', (string) $row['label']), 80)) : '',
                'multiple' => is_array($row) && ($row['multiple'] ?? false) === true,
            ];
            if (count($rows) === self::MAX_FILTERS) break;
        }

        return array_values($rows);
    }

    /**
     * The c parameter of a request, reduced to plain strings and short lists
     * keyed by list and field, so nothing else reaches queries or the cache.
     */
    public static function fromQuery(mixed $value): array
    {
        $lists = [];
        foreach (array_slice(is_array($value) ? $value : [], 0, 20, true) as $id => $params) {
            if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id) || !is_array($params)) continue;
            foreach (array_slice($params, 0, self::MAX_FILTERS * 2 + 1, true) as $key => $item) {
                $key = is_string($key) ? LayoutSchema::key($key) : '';
                $item = is_array($item)
                    ? array_values(array_unique(array_filter(array_map(self::text(...), array_slice(array_values($item), 0, self::MAX_OPTIONS)), 'strlen')))
                    : self::text($item);
                if ($key !== '' && $item !== '' && $item !== []) $lists[$id][$key] = $item;
            }
        }
        ksort($lists);

        return $lists;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim(Text::limit(str_replace(["\r", "\n", "\0"], ' ', (string) $value), 200)) : '';
    }

    public function __construct(
        private readonly SiteContext $ctx,
        private readonly array $node,
        private readonly array $schema,
    ) {}

    public function active(): bool
    {
        return $this->controls() !== [] || $this->sorts() !== [];
    }

    /** Whatever the visitor chose, limited to what this list offers. */
    public function chosen(): array
    {
        if ($this->chosen !== null) return $this->chosen;
        if ($this->ctx->annotate) return $this->chosen = [];
        $params = $this->ctx->listParams[$this->node['id'] ?? ''] ?? [];
        $chosen = [];

        foreach ($this->controls() as $control) {
            $field = $control['field'];
            if ($control['kind'] === 'range') {
                foreach (['from', 'to'] as $end) {
                    $value = $params[$field . '_' . $end] ?? '';
                    if (is_string($value) && $value !== '' && ($control['date'] ? preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) : is_numeric($value))) {
                        $chosen[$field . '_' . $end] = $value;
                    }
                }
                continue;
            }
            $value = $params[$field] ?? null;
            if ($control['kind'] === 'options') {
                $values = array_values(array_intersect(is_array($value) ? $value : [(string) $value], array_keys($control['options'])));
                if ($values !== []) $chosen[$field] = $control['multiple'] ? $values : [$values[0]];
            } elseif ($control['kind'] === 'toggle') {
                if ($value === '1') $chosen[$field] = '1';
            } elseif (is_string($value) && $value !== '') {
                $chosen[$field] = $value;
            }
        }

        $sort = $params['sort'] ?? '';
        if (is_string($sort) && in_array(ltrim($sort, '-'), $this->sorts(), true)) {
            $chosen['sort'] = $sort;
        }

        return $this->chosen = $chosen;
    }

    /** Query conditions for the chosen filters, by field. */
    public function conditions(): array
    {
        $chosen = $this->chosen();
        $conditions = [];

        foreach ($this->controls() as $control) {
            $field = $control['field'];
            $condition = match ($control['kind']) {
                'toggle' => isset($chosen[$field]) ? ['eq' => true] : [],
                'options' => isset($chosen[$field]) ? ['in' => $chosen[$field]] : [],
                'search' => isset($chosen[$field]) ? ['contains' => $chosen[$field]] : [],
                'range' => array_filter([
                    'gte' => $chosen[$field . '_from'] ?? null,
                    // A day of a date and time field ends at its last second.
                    'lte' => isset($chosen[$field . '_to']) && $control['date'] && $control['time'] ? $chosen[$field . '_to'] . 'T23:59:59' : ($chosen[$field . '_to'] ?? null),
                ], static fn(mixed $value): bool => $value !== null),
            };
            if ($condition !== []) $conditions[$field] = $condition;
        }

        return $conditions;
    }

    /** The chosen sort as a query sort parameter, or null for the list's own. */
    public function sort(): ?string
    {
        return $this->chosen()['sort'] ?? null;
    }

    /** Query string parts that keep this list's choices, e.g. on page links. */
    public function queryParams(): array
    {
        $chosen = $this->chosen();

        return $chosen === [] ? [] : ['c' => [$this->node['id'] => $chosen]];
    }

    public function html(string $path): string
    {
        if (!$this->active()) return '';
        $lang = $this->ctx->htmlLang();
        $id = (string) $this->node['id'];
        $chosen = $this->chosen();
        $name = static fn(string $key): string => Security::e('c[' . $id . '][' . $key . ']');
        $builder = $this->ctx->annotate ? ' tabindex="-1"' : '';
        $controls = '';

        foreach ($this->controls() as $control) {
            $field = $control['field'];
            $label = Security::e($control['label']);
            $controls .= match ($control['kind']) {
                'toggle' => '<label class="c-filter-chip"><input type="checkbox" name="' . $name($field) . '" value="1"' . (isset($chosen[$field]) ? ' checked' : '') . $builder . '><span>' . $label . '</span></label>',
                'search' => '<label class="c-filter-search"><span class="c-visually-hidden">' . $label . '</span>'
                    . '<input type="search" name="' . $name($field) . '" value="' . Security::e($chosen[$field] ?? '') . '" placeholder="' . $label . '" maxlength="200" enterkeyhint="search"' . ($this->ctx->annotate ? ' readonly' : '') . $builder . '></label>',
                'range' => '<fieldset class="c-filter-range"><legend>' . $label . '</legend>'
                    . $this->rangeInput($control, $name($field . '_from'), $chosen[$field . '_from'] ?? '', Messages::get('filter_from', $lang), $builder)
                    . '<span aria-hidden="true">–</span>'
                    . $this->rangeInput($control, $name($field . '_to'), $chosen[$field . '_to'] ?? '', Messages::get('filter_to', $lang), $builder)
                    . '</fieldset>',
                'options' => $this->optionsHtml($control, $name($field) . ($control['multiple'] ? '[]' : ''), $chosen[$field] ?? [], $builder),
            };
        }

        $sort = '';
        if ($this->sorts() !== []) {
            $options = '<option value="">' . Security::e(Messages::get('sort_default', $lang)) . '</option>';
            foreach ($this->sorts() as $field) {
                [$up, $down] = $this->orderLabels($field);
                foreach ([$field => $up, '-' . $field => $down] as $value => $order) {
                    $options .= '<option value="' . Security::e($value) . '"' . (($chosen['sort'] ?? '') === $value ? ' selected' : '') . '>'
                        . Security::e($this->fieldLabel($field) . ': ' . $order) . '</option>';
                }
            }
            $sort = '<label class="c-filter-sort"><span>' . Security::e(Messages::get('sort', $lang)) . '</span><select name="' . $name('sort') . '"' . $builder . '>' . $options . '</select></label>';
        }

        $anchor = 'c-list-' . $id;
        $chosenFilters = array_diff_key($chosen, ['sort' => true]);
        $reset = $chosen !== [] ? '<a class="c-filter-reset" href="' . Security::e($this->url($path, []) . '#' . $anchor) . '">' . Security::e(Messages::get('filter_reset', $lang)) . '</a>' : '';

        // Opening the results scrolls back to the list; other lists keep their choices.
        return '<form class="c-list-filters" id="' . Security::e($anchor) . '" method="get" action="' . Security::e(self::split($path)[0] . '#' . $anchor) . '" role="search" aria-label="' . Security::e(Messages::get('filters', $lang)) . '"' . ($this->ctx->annotate ? '' : ' data-list-filters') . '>'
            . $this->keptInputs($path)
            . ($controls !== '' ? '<div class="c-filter-controls">' . $controls . '</div>' : '')
            . '<div class="c-filter-actions">' . $sort
            . '<button type="submit" class="c-filter-apply"' . $builder . '>' . Security::e(Messages::get('filter_apply', $lang)) . '</button>'
            . $reset . '</div>'
            . ($chosenFilters !== [] ? '<p class="c-visually-hidden" role="status">' . Security::e(Messages::get('filters_active', $lang, ['{count}' => (string) count($chosenFilters)])) . '</p>' : '')
            . '</form>';
    }

    /** $path with the other lists' choices and $own for this one. */
    public function url(string $path, array $own): string
    {
        [$base, $query] = self::split($path);
        $lists = $this->ctx->listParams;
        unset($lists[$this->node['id']]);
        if ($own !== []) $lists[$this->node['id']] = $own;
        $query = http_build_query($query + ($lists === [] ? [] : ['c' => $lists]));

        return $query === '' ? $base : $base . '?' . $query;
    }

    /**
     * A page path and its own query, without list choices and page number,
     * e.g. the path and token parameters of an authenticated preview.
     *
     * @return array{string, array}
     */
    private static function split(string $path): array
    {
        $base = strtok($path, '?') ?: $path;
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        unset($query['c'], $query['page']);

        return [$base, array_filter($query, 'is_string')];
    }

    /** Hidden fields that keep the page's own query and the other lists' choices. */
    private function keptInputs(string $path): string
    {
        $inputs = '';
        foreach (self::split($path)[1] as $key => $value) {
            $inputs .= '<input type="hidden" name="' . Security::e((string) $key) . '" value="' . Security::e($value) . '">';
        }
        foreach ($this->ctx->listParams as $list => $params) {
            if ($list === $this->node['id']) continue;
            foreach ($params as $key => $value) {
                foreach (is_array($value) ? $value : [$value] as $item) {
                    $inputs .= '<input type="hidden" name="' . Security::e('c[' . $list . '][' . $key . ']' . (is_array($value) ? '[]' : '')) . '" value="' . Security::e($item) . '">';
                }
            }
        }

        return $inputs;
    }

    private function rangeInput(array $control, string $name, string $value, string $label, string $builder): string
    {
        return '<label><span class="c-visually-hidden">' . Security::e($control['label'] . ' ' . $label) . '</span>'
            . '<input type="' . ($control['date'] ? 'date' : 'number') . '" name="' . $name . '" value="' . Security::e($value) . '"'
            . ($control['date'] ? '' : ' step="any" placeholder="' . Security::e($label) . '"') . $builder . '></label>';
    }

    private function optionsHtml(array $control, string $name, array $chosen, string $builder): string
    {
        $multiple = $control['multiple'];
        $type = $multiple ? 'checkbox' : 'radio';
        $chips = $multiple ? '' : '<label class="c-filter-chip"><input type="radio" name="' . $name . '" value=""' . ($chosen === [] ? ' checked' : '') . $builder . '><span>' . Security::e(Messages::get('filter_all', $this->ctx->htmlLang())) . '</span></label>';
        foreach ($control['options'] as $value => $text) {
            $chips .= '<label class="c-filter-chip"><input type="' . $type . '" name="' . $name . '" value="' . Security::e((string) $value) . '"'
                . (in_array((string) $value, $chosen, true) ? ' checked' : '') . $builder . '><span>' . Security::e($text) . '</span></label>';
        }

        return '<fieldset class="c-filter-options"><legend>' . Security::e($control['label']) . '</legend><div class="c-filter-chips">' . $chips . '</div></fieldset>';
    }

    private ?array $controls = null;
    private ?array $chosen = null;

    /** The editor's rows that fit a field of the list's type, ready to render. */
    private function controls(): array
    {
        if ($this->controls !== null) return $this->controls;
        $this->controls = [];

        foreach (self::normalize($this->node['props']['filters'] ?? []) as $row) {
            $field = $row['field'];
            $config = $this->fieldConfig($field);
            if ($config === null) continue;
            $type = (string) ($config['type'] ?? 'text');
            $control = [
                'field' => $field,
                'label' => $row['label'] !== '' ? $row['label'] : $this->fieldLabel($field),
                'multiple' => $row['multiple'],
                'date' => in_array($type, ['date', 'datetime'], true),
                'time' => $type === 'datetime',
            ];

            if ($type === 'boolean') {
                $control['kind'] = 'toggle';
            } elseif (in_array($type, self::TEXT_TYPES, true)) {
                $control['kind'] = 'search';
            } elseif (in_array($type, self::RANGE_TYPES, true)) {
                $control['kind'] = 'range';
            } elseif ($type === 'select' || $type === 'relation') {
                $control['kind'] = 'options';
                $control['options'] = $type === 'select' ? $this->selectOptions($config) : $this->relationOptions($config);
                if ($control['options'] === []) continue;
            } else {
                continue;
            }

            $this->controls[] = $control;
        }

        return $this->controls;
    }

    /** Sort choices that still name a sortable field. */
    private function sorts(): array
    {
        return array_values(array_filter(
            $this->node['props']['sort_choices'] ?? [],
            fn(string $field): bool => in_array($field, self::DATE_FIELDS, true) || $field === 'title' || in_array((string) ($this->fieldConfig($field)['type'] ?? ''), ['text', 'slug', 'select', 'boolean', ...self::RANGE_TYPES], true),
        ));
    }

    private function fieldConfig(string $field): ?array
    {
        return match (true) {
            $field === 'title' => ['type' => 'text'],
            in_array($field, self::DATE_FIELDS, true) => ['type' => 'datetime'],
            default => is_array($this->schema['fields'][$field] ?? null) ? $this->schema['fields'][$field] : null,
        };
    }

    private function fieldLabel(string $field): string
    {
        $label = trim((string) ($this->schema['fields'][$field]['label'] ?? ''));
        if ($label !== '' && !in_array($field, ['title', ...self::DATE_FIELDS], true)) return $label;

        return Messages::get('field_' . $field, $this->ctx->htmlLang()) !== 'field_' . $field
            ? Messages::get('field_' . $field, $this->ctx->htmlLang())
            : ($label !== '' ? $label : ucfirst(str_replace(['_', '-'], ' ', $field)));
    }

    /** @return array{string, string} The ascending and descending order, named for the field. */
    private function orderLabels(string $field): array
    {
        $type = (string) ($this->fieldConfig($field)['type'] ?? 'text');
        $lang = $this->ctx->htmlLang();
        [$up, $down] = match (true) {
            in_array($type, ['date', 'datetime'], true) => ['order_old', 'order_new'],
            in_array($type, ['number', 'range'], true) => ['order_low', 'order_high'],
            $type === 'boolean' => ['order_no_first', 'order_yes_first'],
            default => ['order_az', 'order_za'],
        };

        return [Messages::get($up, $lang), Messages::get($down, $lang)];
    }

    private function selectOptions(array $config): array
    {
        $options = is_array($config['options'] ?? null) ? $config['options'] : [];
        $list = [];
        foreach (array_slice($options, 0, self::MAX_OPTIONS, true) as $key => $label) {
            $value = array_is_list($options) ? (string) $label : (string) $key;
            if ($value !== '') $list[$value] = (string) (is_scalar($label) ? $label : $value);
        }

        return $list;
    }

    /** The published entries a relation can point to, by title. */
    private function relationOptions(array $config): array
    {
        $target = (string) ($config['target'] ?? '');
        if ($target === '' || !$this->ctx->isPublicType($target)) return [];
        $list = [];
        foreach ($this->ctx->query($target, ['sort' => 'title', 'limit' => self::MAX_OPTIONS])['data'] as $entry) {
            $entry = $this->ctx->localize($target, $entry);
            $value = (string) ($entry['uid'] ?? $entry['id'] ?? '');
            if ($value !== '') $list[$value] = (string) ($entry['title'] ?? $value);
        }

        return $list;
    }
}
