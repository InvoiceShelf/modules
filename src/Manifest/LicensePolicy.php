<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Manifest;

/**
 * Licenses a bundled package may carry inside an AGPL-3.0-only module.
 *
 * Composer's `license` list means the package is offered under any one of
 * them, so one allowed entry is enough. A single SPDX expression such as
 * "(MIT or GPL-3.0-or-later)" is read the same way; "and" needs every part.
 */
final class LicensePolicy
{
    /** SPDX identifiers, plus the deprecated short forms Packagist still carries, that may be conveyed under AGPL-3.0. */
    private const ALLOWED = [
        '0BSD', 'Apache-2.0', 'BSD-2-Clause', 'BSD-3-Clause', 'CC0-1.0', 'ISC', 'MIT', 'MIT-0', 'MPL-2.0',
        'Unlicense', 'Zlib',
        'LGPL-2.1', 'LGPL-2.1-only', 'LGPL-2.1-or-later', 'LGPL-2.1+',
        'LGPL-3.0', 'LGPL-3.0-only', 'LGPL-3.0-or-later', 'LGPL-3.0+',
        'GPL-3.0', 'GPL-3.0-only', 'GPL-3.0-or-later', 'GPL-3.0+',
        'AGPL-3.0', 'AGPL-3.0-only', 'AGPL-3.0-or-later',
    ];

    /** @param list<string> $licenses */
    public static function allows(array $licenses): bool
    {
        foreach ($licenses as $license) {
            if (self::expressionAllowed($license)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public static function allowed(): array
    {
        return self::ALLOWED;
    }

    private static function expressionAllowed(string $expression): bool
    {
        $expression = trim(str_replace(['(', ')'], ' ', $expression));
        if ($expression === '') {
            return false;
        }

        foreach (preg_split('/\s+or\s+/i', $expression) ?: [] as $alternative) {
            $parts = preg_split('/\s+and\s+/i', trim($alternative)) ?: [];
            if ($parts !== [] && array_filter($parts, fn (string $part): bool => ! in_array(trim($part), self::ALLOWED, true)) === []) {
                return true;
            }
        }

        return false;
    }
}
