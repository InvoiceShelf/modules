<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Manifest;

use JsonException;

/**
 * Canonical JSON used as the byte representation for marketplace signatures.
 *
 * Object keys are sorted lexicographically at every depth. JSON lists retain
 * their declared order; signatures must therefore be made over this output,
 * never over a prettified manifest file.
 */
final class CanonicalJson
{
    /**
     * @param  array<string, mixed>|list<mixed>  $value
     *
     * @throws JsonException
     */
    public static function encode(array $value): string
    {
        return json_encode(
            self::sort($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $value
     * @return array<string, mixed>|list<mixed>
     */
    private static function sort(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sort($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
