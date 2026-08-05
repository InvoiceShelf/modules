<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Manifest;

/** Small facade for CI and host integrations that do not need to know the value object names. */
final class ManifestValidator
{
    /** @param array<string, mixed> $manifest */
    public static function module(array $manifest): ModuleManifest
    {
        return ModuleManifest::fromArray($manifest);
    }

    /** @param array<string, mixed> $manifest */
    public static function release(array $manifest): ReleaseManifest
    {
        return ReleaseManifest::fromArray($manifest);
    }

    /** @param array<string, mixed> $manifest */
    public static function canonicalRelease(array $manifest): string
    {
        return self::release($manifest)->canonicalJson();
    }

    public static function package(string $directory): ModuleManifest
    {
        return PackageValidator::validate($directory);
    }
}
