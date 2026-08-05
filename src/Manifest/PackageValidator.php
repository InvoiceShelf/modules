<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Manifest;

use InvalidArgumentException;
use JsonException;

/** Validates the releaseable module directory, including its local build output. */
final class PackageValidator
{
    /**
     * @throws JsonException
     */
    public static function validate(string $directory): ModuleManifest
    {
        $root = realpath($directory);
        if ($root === false || ! is_dir($root)) {
            throw new InvalidArgumentException("Module package directory '{$directory}' cannot be read.");
        }

        $manifest = self::jsonFile($root.'/module.json', 'module.json');
        $module = ModuleManifest::fromArray($manifest);

        foreach ($module->assets as $asset) {
            $path = realpath($root.DIRECTORY_SEPARATOR.$asset);
            if ($path === false || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || ! is_file($path)) {
                throw new InvalidArgumentException("Declared compiled asset '{$asset}' is missing from the module package.");
            }
        }

        self::validateComposer($root.'/composer.json', $module->slug);

        if ($module->uninstall !== null) {
            CleanupValidator::validateDirectory($root, $module->uninstall->dataCleanup);
        }

        if ($module->migrationPolicy === 'reversible') {
            MigrationValidator::validateDirectory($root.'/database/migrations');
            MigrationValidator::validateDirectory($root.'/Database/Migrations');
        }

        return $module;
    }

    /** @return array<string, mixed> */
    private static function jsonFile(string $path, string $name): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new InvalidArgumentException("Module package must contain {$name}.");
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidArgumentException("{$name} must contain a JSON object.");
        }

        return $decoded;
    }

    private static function validateComposer(string $path, string $slug): void
    {
        $composer = self::jsonFile($path, 'composer.json');
        if (($composer['name'] ?? null) !== "invoiceshelf/module-{$slug}") {
            throw new InvalidArgumentException("composer.json name must be invoiceshelf/module-{$slug}.");
        }
        if (($composer['license'] ?? null) !== 'AGPL-3.0-only') {
            throw new InvalidArgumentException('composer.json license must be AGPL-3.0-only.');
        }
        if (! is_array($composer['require'] ?? null) || array_is_list($composer['require'])) {
            throw new InvalidArgumentException('composer.json must declare a require object.');
        }

        foreach ($composer['require'] as $package => $constraint) {
            if (! is_string($package) || ! is_string($constraint)
                || (! in_array($package, ['php', 'invoiceshelf/modules'], true) && ! str_starts_with($package, 'ext-'))) {
                throw new InvalidArgumentException("composer.json dependency '{$package}' is not host-provided.");
            }
            if (($package === 'php' || $package === 'invoiceshelf/modules') && ! Semver::isConstraint($constraint)
                || str_starts_with($package, 'ext-') && $constraint !== '*' && ! Semver::isConstraint($constraint)) {
                throw new InvalidArgumentException("composer.json dependency '{$package}' must use a supported SemVer constraint.");
            }
        }
    }
}
