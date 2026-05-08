<?php

declare(strict_types=1);

namespace CometCMS\Fields;

use CometCMS\Workspaces\WorkspaceContext;

final class MediaFieldType extends AbstractFieldType
{
    public function validate(mixed $value, array $config, array $context = []): array
    {
        $required = parent::validate($value, $config, $context);

        if (($required['valid'] ?? false) === false || $this->isEmpty($value)) {
            return $required;
        }

        if (!is_array($value)) {
            return $this->error('Media value must be an array of filenames.', 'invalid_media');
        }

        foreach ($this->values($value, $config) as $file) {
            if ($file === '' || !is_file(WorkspaceContext::active()->path('media') . '/' . basename($file))) {
                return $this->error('Media file does not exist: ' . $file, 'missing_media');
            }
        }

        return ['valid' => true];
    }

    public function normalize(mixed $value, array $config, array $context = []): mixed
    {
        return array_values(array_unique(array_map(
            static fn(string $file): string => basename($file),
            $this->values($value, $config)
        )));
    }

    private function values(mixed $value, array $config): array
    {
        $values = is_array($value) ? $value : [];

        if (!($config['multiple'] ?? false)) {
            $values = array_slice($values, 0, 1);
        }

        return array_values(array_filter(array_map(
            fn(mixed $file): string => $this->sanitizeFile($file),
            $values
        )));
    }

    private function sanitizeFile(mixed $value): string
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return '';
        }

        return basename($raw);
    }
}
