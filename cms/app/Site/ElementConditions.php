<?php

declare(strict_types=1);

namespace CometCMS\Site;

use CometCMS\Core\ValidationException;

/**
 * When an element shows: the style key visible_if holds
 *
 *   {match: all|any, conditions: [{field: <reference>, op, value}]}
 *
 * Each field is a smart value reference, so it reads the entry the element
 * shows (a card's own entry inside a list, a row inside a repeater template).
 * The comparisons are those of template conditions. A field that cannot be
 * read, e.g. without an entry, counts as empty.
 */
final class ElementConditions
{
    public const OPS = ['equals', 'not_equals', 'contains', 'empty', 'not_empty'];

    /** The valid conditions of $value; [] when none are left, so the key is not stored. */
    public static function normalize(mixed $value): array
    {
        if (!is_array($value) || !is_array($value['conditions'] ?? null)) return [];
        $conditions = [];
        foreach (array_slice(array_values($value['conditions']), 0, SiteRepository::MAX_CONDITIONS) as $condition) {
            $op = is_array($condition) ? ($condition['op'] ?? '') : '';
            if (!in_array($op, self::OPS, true)) continue;
            try {
                $field = SmartValue::normalize($condition['field'] ?? null, 'value');
            } catch (ValidationException) {
                continue;
            }
            if ($field['type'] !== 'reference') continue;
            $field['filters'] = [];
            $expected = in_array($op, ['empty', 'not_empty'], true) ? null : ($condition['value'] ?? '');
            if ($expected !== null && !is_scalar($expected)) continue;
            $conditions[] = ['field' => $field, 'op' => $op, 'value' => is_string($expected) ? mb_substr($expected, 0, 500) : $expected];
        }
        if ($conditions === []) return [];

        return ['match' => ($value['match'] ?? 'all') === 'any' ? 'any' : 'all', 'conditions' => $conditions];
    }

    /** Whether the element shows for the render state (its entry, card or repeater row). */
    public static function matches(array $visibleIf, SmartValue $smart, array $state): bool
    {
        $any = ($visibleIf['match'] ?? 'all') === 'any';
        foreach ($visibleIf['conditions'] ?? [] as $condition) {
            $value = $smart->resolve($condition['field'], $state)['value'];
            $holds = SiteRepository::conditionHolds($condition['op'], $value, $condition['value']);
            if ($holds === $any) return $any;
        }

        return !$any;
    }
}
