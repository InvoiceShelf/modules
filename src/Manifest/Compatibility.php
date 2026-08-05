<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Manifest;

use InvalidArgumentException;

/**
 * Compatibility gates evaluated by the host before a module is installed.
 */
final readonly class Compatibility
{
    /**
     * @param  list<string>  $extensions
     */
    public function __construct(
        public string $invoiceshelf,
        public string $moduleApi,
        public string $php,
        public array $extensions,
    ) {}

    /**
     * @param  array<string, mixed>  $value
     */
    public static function fromArray(array $value): self
    {
        self::rejectUnknownKeys($value, ['invoiceshelf', 'module_api', 'php', 'extensions'], 'compatibility');

        foreach (['invoiceshelf', 'module_api', 'php'] as $field) {
            if (! isset($value[$field]) || ! is_string($value[$field]) || ! Semver::isConstraint($value[$field])) {
                throw new InvalidArgumentException("Compatibility field '{$field}' must be a supported SemVer constraint.");
            }
        }

        if (! isset($value['extensions']) || ! is_array($value['extensions']) || ! array_is_list($value['extensions'])) {
            throw new InvalidArgumentException("Compatibility field 'extensions' must be a list of PHP extensions.");
        }

        $extensions = [];
        foreach ($value['extensions'] as $extension) {
            if (! is_string($extension) || ! preg_match('/^ext-[a-z0-9][a-z0-9_-]*$/', $extension)) {
                throw new InvalidArgumentException("Unsupported PHP extension '{$extension}'. Extensions must use the ext-name form.");
            }

            if (in_array($extension, $extensions, true)) {
                throw new InvalidArgumentException("Compatibility extension '{$extension}' is duplicated.");
            }

            $extensions[] = $extension;
        }

        return new self($value['invoiceshelf'], $value['module_api'], $value['php'], $extensions);
    }

    /** @return array{invoiceshelf: string, module_api: string, php: string, extensions: list<string>} */
    public function toArray(): array
    {
        return [
            'invoiceshelf' => $this->invoiceshelf,
            'module_api' => $this->moduleApi,
            'php' => $this->php,
            'extensions' => $this->extensions,
        ];
    }

    /** @param array<string, mixed> $value @param list<string> $allowed */
    private static function rejectUnknownKeys(array $value, array $allowed, string $context): void
    {
        $unknown = array_diff(array_keys($value), $allowed);
        if ($unknown !== []) {
            throw new InvalidArgumentException("{$context} contains unsupported field '".reset($unknown)."'.");
        }
    }
}
