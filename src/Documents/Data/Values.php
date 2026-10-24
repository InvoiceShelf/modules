<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Documents\Data;

use InvalidArgumentException;

/**
 * Reads typed values out of the arrays the document data is built from, so a
 * missing or mistyped key fails loudly instead of turning into a zero.
 *
 * @internal
 */
final class Values
{
    /** @param  array<string, mixed>  $data */
    public static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw new InvalidArgumentException("Document data needs a string '{$key}'.");
        }

        return (string) $value;
    }

    /** @param  array<string, mixed>  $data */
    public static function optionalString(array $data, string $key): ?string
    {
        return ($data[$key] ?? null) === null ? null : self::string($data, $key);
    }

    /** @param  array<string, mixed>  $data */
    public static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        if (! is_int($value) && ! (is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
            throw new InvalidArgumentException("Document data needs an integer '{$key}'.");
        }

        return (int) $value;
    }

    /** @param  array<string, mixed>  $data */
    public static function optionalInt(array $data, string $key): ?int
    {
        return ($data[$key] ?? null) === null ? null : self::int($data, $key);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    public static function list(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("Document data needs a list '{$key}'.");
        }

        foreach ($value as $entry) {
            if (! is_array($entry)) {
                throw new InvalidArgumentException("Every entry of '{$key}' must be an object.");
            }
        }

        return $value;
    }
}
