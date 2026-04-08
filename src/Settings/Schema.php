<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Settings;

use InvalidArgumentException;

/**
 * Normalized representation of a module settings schema.
 *
 * Modules pass a plain associative array to Registry::registerSettings(). That
 * array is validated and converted into a Schema instance here, so downstream
 * renderers (the host app's ModuleSettingsController and BaseSchemaForm.vue)
 * can rely on a stable, predictable shape and never see arbitrary user input.
 *
 * The expected input shape is:
 *
 *   [
 *     'sections' => [
 *       [
 *         'title'  => 'sales_tax_us::settings.connection',
 *         'fields' => [
 *           ['key' => 'api_key',  'type' => 'password', 'label' => '...', 'rules' => ['required']],
 *           ['key' => 'sandbox',  'type' => 'switch',   'label' => '...', 'default' => false],
 *           ['key' => 'state',    'type' => 'select',   'label' => '...', 'options' => ['CA' => 'California']],
 *         ],
 *       ],
 *     ],
 *   ]
 */
class Schema
{
    /**
     * @param  array<int, array{title: string, fields: array<int, array<string, mixed>>}>  $sections
     */
    public function __construct(
        public readonly array $sections,
    ) {}

    /**
     * @param  array<string, mixed>  $schema
     */
    public static function fromArray(array $schema): self
    {
        if (! isset($schema['sections']) || ! is_array($schema['sections'])) {
            throw new InvalidArgumentException('Module settings schema must declare a "sections" array.');
        }

        $sections = [];

        foreach ($schema['sections'] as $sectionIndex => $section) {
            if (! is_array($section)) {
                throw new InvalidArgumentException("Section at index {$sectionIndex} must be an array.");
            }

            if (! isset($section['title']) || ! is_string($section['title']) || $section['title'] === '') {
                throw new InvalidArgumentException("Section at index {$sectionIndex} must have a non-empty string 'title'.");
            }

            if (! isset($section['fields']) || ! is_array($section['fields'])) {
                throw new InvalidArgumentException("Section '{$section['title']}' must declare a 'fields' array.");
            }

            $normalizedFields = [];

            foreach ($section['fields'] as $fieldIndex => $field) {
                $normalizedFields[] = self::normalizeField($field, $section['title'], $fieldIndex);
            }

            $sections[] = [
                'title' => $section['title'],
                'fields' => $normalizedFields,
            ];
        }

        return new self($sections);
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private static function normalizeField(array $field, string $sectionTitle, int $fieldIndex): array
    {
        if (! isset($field['key']) || ! is_string($field['key']) || $field['key'] === '') {
            throw new InvalidArgumentException("Field at index {$fieldIndex} of section '{$sectionTitle}' must have a non-empty string 'key'.");
        }

        if (! isset($field['type']) || ! is_string($field['type'])) {
            throw new InvalidArgumentException("Field '{$field['key']}' must have a string 'type'.");
        }

        $type = FieldType::tryFrom($field['type']);

        if ($type === null) {
            $allowed = implode(', ', array_map(fn (FieldType $t) => $t->value, FieldType::cases()));
            throw new InvalidArgumentException("Field '{$field['key']}' has unsupported type '{$field['type']}'. Allowed: {$allowed}.");
        }

        $normalized = [
            'key' => $field['key'],
            'type' => $type->value,
            'label' => $field['label'] ?? $field['key'],
            'rules' => $field['rules'] ?? [],
            'default' => $field['default'] ?? null,
        ];

        if ($type === FieldType::Select || $type === FieldType::MultiSelect) {
            if (! isset($field['options']) || ! is_array($field['options'])) {
                throw new InvalidArgumentException("Field '{$field['key']}' of type '{$type->value}' must declare an 'options' array.");
            }
            $normalized['options'] = $field['options'];
        }

        return $normalized;
    }

    /**
     * Return a flat list of every field in the schema.
     *
     * Used by the host app's controller to map values to keys without
     * walking the section structure twice.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fields(): array
    {
        $out = [];
        foreach ($this->sections as $section) {
            foreach ($section['fields'] as $field) {
                $out[] = $field;
            }
        }

        return $out;
    }

    /**
     * Convert to a JSON-serializable array suitable for the API response.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['sections' => $this->sections];
    }
}
