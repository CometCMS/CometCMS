<?php

declare(strict_types=1);

namespace CometCMS\Content;

final class ContentQuery
{
    public static function applySearch(ContentTypeRepository $types, array $entries, string $query, string $collection): array
    {
        $query = trim(strtolower($query));

        if ($query === '') {
            return $entries;
        }

        $schema = $types->find($collection);
        $searchFields = ['title', 'slug', 'summary'];

        foreach ($schema['fields'] ?? [] as $name => $config) {
            if (in_array($config['type'] ?? '', ['text', 'textarea', 'markdown', 'html'], true)) {
                $searchFields[] = $name;
            }
        }
        $searchFields = array_unique($searchFields);

        return array_values(array_filter($entries, static function (array $entry) use ($query, $searchFields): bool {
            foreach ($searchFields as $field) {
                if (str_contains(strtolower((string) self::valueAt($entry, $field)), $query)) {
                    return true;
                }
            }

            return false;
        }));
    }

    public static function applyFilters(array $entries, array $filters): array
    {
        if ($filters === []) return $entries;
        return array_values(array_filter($entries, static function (array $entry) use ($filters): bool {
            foreach ($filters as $field => $condition) {
                if (!self::matchesFilter($entry, (string) $field, $condition)) return false;
            }
            return true;
        }));
    }

    public static function filtersFromParams(ContentTypeRepository $types, array $params, string $collection): array
    {
        $filters = [];
        $filterableFields = self::filterableFields($types, $collection);

        foreach (is_array($params['filter'] ?? null) ? $params['filter'] : [] as $field => $condition) {
            if (!is_string($field) || !in_array($field, $filterableFields, true)) {
                continue;
            }

            $filters[$field] = $condition;
        }

        return $filters;
    }

    public static function filterableFields(ContentTypeRepository $types, string $collection): array
    {
        $schema = $types->find($collection);
        $fields = array_keys(is_array($schema['fields'] ?? null) ? $schema['fields'] : []);

        return array_values(array_unique(array_merge([
            'id',
            'uid',
            'collection',
            'status',
            'published_at',
            'created_at',
            'updated_at',
            'author_id',
            'updated_by',
            'title',
            'slug',
            'parent',
            'position',
        ], $fields)));
    }

    public static function compareSortValues(mixed $a, mixed $b): int
    {
        return self::compareSortKeys(self::sortKey($a), self::sortKey($b));
    }

    public static function compareSortKeys(array $a, array $b): int
    {
        if ($a['value'] === $b['value']) {
            return 0;
        }

        if ($a['value'] === null || $a['value'] === '') {
            return -1;
        }

        if ($b['value'] === null || $b['value'] === '') {
            return 1;
        }

        if ($a['numeric'] && $b['numeric']) {
            return (float) $a['value'] <=> (float) $b['value'];
        }
        if ($a['time'] !== null && $b['time'] !== null) {
            return $a['time'] <=> $b['time'];
        }

        return strcasecmp($a['text'], $b['text']);
    }

    /** Parse date sort values once per entry rather than on every comparison. */
    public static function sortKey(mixed $value): array
    {
        return ['value' => $value, 'numeric' => is_numeric($value), 'time' => self::timestampValue($value), 'text' => self::filterValueToString($value)];
    }

    public static function valueAt(array $entry, string $field): mixed
    {
        if ($field === 'id') {
            return trim((string) ($entry['uid'] ?? ''));
        }

        return $entry[$field] ?? null;
    }

    private static function matchesFilter(array $entry, string $field, mixed $condition): bool
    {
        $value = self::valueAt($entry, $field);

        if (!is_array($condition)) {
            return self::compare($value, 'eq', $condition);
        }

        foreach ($condition as $operator => $expected) {
            if (!self::compare($value, (string) $operator, $expected)) {
                return false;
            }
        }

        return true;
    }

    private static function compare(mixed $value, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            'ne' => !self::equalsFilterValue($value, $expected),
            'gt' => self::compareOrdered($value, $expected) > 0,
            'gte' => self::compareOrdered($value, $expected) >= 0,
            'lt' => self::compareOrdered($value, $expected) < 0,
            'lte' => self::compareOrdered($value, $expected) <= 0,
            'contains' => self::containsFilterValue($value, $expected),
            'in' => self::matchesAnyFilterValue($value, self::expectedValues($expected)),
            default => self::equalsFilterValue($value, $expected),
        };
    }

    private static function equalsFilterValue(mixed $value, mixed $expected): bool
    {
        if (is_array($value)) {
            return self::matchesAnyFilterValue($value, [$expected]);
        }

        if (is_bool($value) || is_bool($expected) || is_int($value) || is_int($expected)) {
            $valueBool = self::booleanValue($value);
            $expectedBool = self::booleanValue($expected);

            if ($valueBool !== null || $expectedBool !== null) {
                return $valueBool !== null && $expectedBool !== null && $valueBool === $expectedBool;
            }
        }

        if (is_numeric($value) && is_numeric($expected)) {
            return (float) $value === (float) $expected;
        }

        if ($value === null) {
            return $expected === null || $expected === '';
        }

        return (string) $value === (string) $expected;
    }

    private static function matchesAnyFilterValue(mixed $value, array $expectedValues): bool
    {
        $values = is_array($value) ? array_values($value) : [$value];

        foreach ($values as $item) {
            foreach ($expectedValues as $expected) {
                if (self::equalsFilterValue($item, $expected)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function containsFilterValue(mixed $value, mixed $expected): bool
    {
        $needle = strtolower(self::filterValueToString($expected));
        $values = is_array($value) ? array_values($value) : [$value];

        foreach ($values as $item) {
            if (str_contains(strtolower(self::filterValueToString($item)), $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function compareOrdered(mixed $value, mixed $expected): int
    {
        if (is_numeric($value) && is_numeric($expected)) {
            return (float) $value <=> (float) $expected;
        }

        return self::filterValueToString($value) <=> self::filterValueToString($expected);
    }

    private static function timestampValue(mixed $value): ?int
    {
        if (!is_string($value)) {
            return null;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return null;
        }

        $time = strtotime($value);

        return $time === false ? null : $time;
    }

    private static function expectedValues(mixed $expected): array
    {
        if (is_array($expected)) {
            return array_values($expected);
        }

        return array_map('trim', explode(',', (string) $expected));
    }

    private static function booleanValue(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return match ($value) {
                0 => false,
                1 => true,
                default => null,
            };
        }

        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                '0', 'false', 'no', 'off' => false,
                '1', 'true', 'yes', 'on' => true,
                default => null,
            };
        }

        return null;
    }

    private static function filterValueToString(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return implode(',', array_map(static fn(mixed $item): string => self::filterValueToString($item), $value));
        }

        return (string) $value;
    }
}
