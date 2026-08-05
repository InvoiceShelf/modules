<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Manifest;

use InvalidArgumentException;
use InvoiceShelf\Modules\Contracts\DataCleanup;

/** Schema-v2 uninstall contract for module-owned data outside migrations. */
final readonly class Uninstall
{
    public function __construct(
        /** @var class-string<DataCleanup> */
        public string $dataCleanup,
    ) {}

    /** @param array<string, mixed> $value */
    public static function fromArray(array $value, string $moduleName): self
    {
        $unknown = array_diff(array_keys($value), ['data_cleanup']);
        if ($unknown !== []) {
            throw new InvalidArgumentException("Module uninstall contract contains unsupported field '".reset($unknown)."'.");
        }

        $dataCleanup = $value['data_cleanup'] ?? null;
        $prefix = 'Modules\\'.$moduleName.'\\';
        if (! is_string($dataCleanup) || $dataCleanup === '' || ! str_starts_with($dataCleanup, $prefix)
            || ! preg_match('/^Modules\\\\[A-Za-z][A-Za-z0-9]*(?:\\\\[A-Za-z][A-Za-z0-9]*)*$/', $dataCleanup)) {
            throw new InvalidArgumentException("Module uninstall field 'data_cleanup' must be a class in the Modules\\{$moduleName} namespace.");
        }

        return new self($dataCleanup);
    }

    /** @return array{data_cleanup: class-string<DataCleanup>} */
    public function toArray(): array
    {
        return ['data_cleanup' => $this->dataCleanup];
    }
}
