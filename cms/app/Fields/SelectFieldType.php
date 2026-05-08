<?php

declare(strict_types=1);

namespace CometCMS\Fields;

use CometCMS\Core\Security;

final class SelectFieldType extends AbstractFieldType
{
    public function validate(mixed $value, array $config, array $context = []): array
    {
        $multiple = (bool) ($config['multiple'] ?? false);

        if ($multiple && !$this->isEmpty($value) && !is_array($value)) {
            return $this->error('Select value must be an array.', 'invalid_select');
        }

        if (!$multiple && is_array($value)) {
            return $this->error('Select value must be a single value.', 'invalid_select');
        }

        $required = parent::validate($value, $config, $context);

        if (($required['valid'] ?? false) === false || $this->isEmpty($value)) {
            return $required;
        }

        $rawOptions = $config['options'] ?? [];
        $isMap      = is_array($rawOptions) && !array_is_list($rawOptions);
        $options    = $isMap
            ? array_keys($rawOptions)
            : array_map('strval', (array) $rawOptions);

        if ($multiple) {
            foreach ($this->values($value) as $v) {
                if ($options !== [] && !in_array((string) $v, $options, true)) {
                    return $this->error($this->invalidOptionMessage($options), 'invalid_option');
                }
            }
            return ['valid' => true];
        }

        return $options === [] || in_array((string) $value, $options, true)
            ? ['valid' => true]
            : $this->error($this->invalidOptionMessage($options), 'invalid_option');
    }

    public function normalize(mixed $value, array $config, array $context = []): mixed
    {
        if ((bool) ($config['multiple'] ?? false)) {
            return array_values(array_unique($this->values($value)));
        }

        return is_string($value) ? trim($value) : $value;
    }

    public function renderInput(string $name, mixed $value, array $config, array $context = []): string
    {
        $html = '<select name="' . Security::e($name) . '"><option value=""></option>';

        foreach ((array) ($config['options'] ?? []) as $option) {
            $option = (string) $option;
            $html .= '<option value="' . Security::e($option) . '"' . ((string) $value === $option ? ' selected' : '') . '>' . Security::e($option) . '</option>';
        }

        return $html . '</select>';
    }

    private function values(mixed $value): array
    {
        return array_values(array_filter(array_map(
            static fn(mixed $item): string => trim((string) $item),
            is_array($value) ? $value : []
        ), static fn(string $item): bool => $item !== ''));
    }

    private function invalidOptionMessage(array $options): string
    {
        if ($options === []) {
            return 'Choose a valid option.';
        }

        return 'Choose a valid option. Valid values: ' . implode(', ', $options) . '.';
    }
}
