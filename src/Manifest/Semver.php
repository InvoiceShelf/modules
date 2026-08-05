<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Manifest;

/** Limited, intentionally portable SemVer and Composer-style range grammar. */
final class Semver
{
    private const VERSION = '(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?';

    private const CONSTRAINT_VERSION = '(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:\.(?:0|[1-9]\d*))?(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?';

    public static function isVersion(mixed $value): bool
    {
        return is_string($value) && preg_match('/^'.self::VERSION.'$/', $value) === 1;
    }

    public static function isConstraint(mixed $value): bool
    {
        if (! is_string($value) || $value === '' || str_contains($value, '||') || str_contains($value, '*')) {
            return false;
        }

        $comparator = '(?:\^|~|>=|<=|>|<|=)?';
        $part = $comparator.'v?'.self::CONSTRAINT_VERSION;

        return preg_match('/^'.$part.'(?:\s+'.$part.')*$/', $value) === 1;
    }
}
