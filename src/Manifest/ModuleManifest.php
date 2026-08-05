<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Manifest;

use InvalidArgumentException;

/**
 * Versioned extension of nwidart's module.json loader descriptor.
 */
final readonly class ModuleManifest
{
    public const SCHEMA_VERSION = 2;

    public const LEGACY_SCHEMA_VERSION = 1;

    /**
     * @param  list<class-string>  $providers
     * @param  array<string, string>  $moduleDependencies
     * @param  list<string>  $assets
     */
    public function __construct(
        public string $slug,
        public string $name,
        public string $alias,
        public string $description,
        public array $keywords,
        public int $priority,
        public string $version,
        public string $license,
        public array $providers,
        public array $aliases,
        public array $files,
        public array $requires,
        public Compatibility $compatibility,
        public array $moduleDependencies,
        public string $migrationPolicy,
        public string $dependencyPolicy,
        public array $assets,
        public ?Uninstall $uninstall = null,
    ) {}

    /** @param array<string, mixed> $value */
    public static function fromArray(array $value): self
    {
        self::rejectUnknownKeys($value, [
            'name', 'alias', 'description', 'keywords', 'priority', 'providers', 'aliases', 'files', 'requires',
            'schema_version', 'slug', 'version', 'license', 'compatibility', 'module_dependencies',
            'migration_policy', 'dependency_policy', 'assets', 'uninstall',
        ]);

        $schemaVersion = $value['schema_version'] ?? null;
        if (! in_array($schemaVersion, [self::LEGACY_SCHEMA_VERSION, self::SCHEMA_VERSION], true)) {
            throw new InvalidArgumentException('Only module manifest schema_version=1 or schema_version=2 is supported.');
        }

        $slug = self::string($value, 'slug');
        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new InvalidArgumentException("Module slug '{$slug}' must be lowercase kebab-case.");
        }

        $moduleName = self::string($value, 'name');
        if (! preg_match('/^[A-Z][A-Za-z0-9]*$/', $moduleName)) {
            throw new InvalidArgumentException("Module name '{$moduleName}' must be PascalCase ASCII.");
        }

        $alias = self::string($value, 'alias');
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $alias)) {
            throw new InvalidArgumentException("Module alias '{$alias}' must be lowercase ASCII with optional underscores.");
        }
        $description = $value['description'] ?? null;
        if (! is_string($description)) {
            throw new InvalidArgumentException("Module manifest field 'description' must be a string.");
        }
        $keywords = self::stringList($value['keywords'] ?? null, 'keywords');
        $priority = $value['priority'] ?? null;
        if (! is_int($priority) || $priority < 0) {
            throw new InvalidArgumentException("Module manifest field 'priority' must be a non-negative integer.");
        }

        $version = self::string($value, 'version');
        if (! Semver::isVersion($version)) {
            throw new InvalidArgumentException("Module version '{$version}' must be a SemVer version.");
        }

        $license = self::string($value, 'license');
        if ($license !== 'AGPL-3.0-only') {
            throw new InvalidArgumentException("Module license '{$license}' must be AGPL-3.0-only.");
        }

        $providers = self::providers($value['providers'] ?? null, $moduleName);
        $compatibility = is_array($value['compatibility'] ?? null)
            ? Compatibility::fromArray($value['compatibility'])
            : throw new InvalidArgumentException("Module manifest must declare a 'compatibility' object.");

        $dependencies = self::dependencies($value['module_dependencies'] ?? null, $slug);

        $migrationPolicy = $value['migration_policy'] ?? null;
        if ($schemaVersion === self::LEGACY_SCHEMA_VERSION && $migrationPolicy !== 'forward-only') {
            throw new InvalidArgumentException("Module manifest schema_version=1 requires migration_policy 'forward-only'.");
        }
        if ($schemaVersion === self::SCHEMA_VERSION && $migrationPolicy !== 'reversible') {
            throw new InvalidArgumentException("Module manifest schema_version=2 requires migration_policy 'reversible'.");
        }

        if (($value['dependency_policy'] ?? null) !== 'host-provided-only') {
            throw new InvalidArgumentException("Only dependency_policy 'host-provided-only' is supported; runtime Composer and Node dependencies are forbidden.");
        }

        return new self(
            $slug,
            $moduleName,
            $alias,
            $description,
            $keywords,
            $priority,
            $version,
            $license,
            $providers,
            self::map($value['aliases'] ?? null, 'aliases'),
            self::localFiles($value['files'] ?? null),
            self::map($value['requires'] ?? null, 'requires'),
            $compatibility,
            $dependencies,
            $migrationPolicy,
            'host-provided-only',
            self::assets($value['assets'] ?? null),
            self::uninstall($value, $moduleName, $schemaVersion),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->uninstall === null ? self::LEGACY_SCHEMA_VERSION : self::SCHEMA_VERSION,
            'slug' => $this->slug,
            'name' => $this->name,
            'alias' => $this->alias,
            'description' => $this->description,
            'keywords' => $this->keywords,
            'priority' => $this->priority,
            'version' => $this->version,
            'license' => $this->license,
            'providers' => $this->providers,
            'aliases' => $this->aliases,
            'files' => $this->files,
            'requires' => $this->requires,
            'compatibility' => $this->compatibility->toArray(),
            'module_dependencies' => $this->moduleDependencies,
            'migration_policy' => $this->migrationPolicy,
            'dependency_policy' => $this->dependencyPolicy,
            'assets' => $this->assets,
            ...($this->uninstall === null ? [] : ['uninstall' => $this->uninstall->toArray()]),
        ];
    }

    /** @param array<string, mixed> $value */
    private static function string(array $value, string $field): string
    {
        if (! isset($value[$field]) || ! is_string($value[$field]) || $value[$field] === '') {
            throw new InvalidArgumentException("Module manifest field '{$field}' must be a non-empty string.");
        }

        return $value[$field];
    }

    /** @return list<class-string> */
    private static function providers(mixed $value, string $moduleName): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            throw new InvalidArgumentException("Module manifest field 'providers' must be a non-empty list.");
        }

        $providers = [];
        $prefix = 'Modules\\'.$moduleName.'\\';
        foreach ($value as $provider) {
            if (! is_string($provider) || ! str_starts_with($provider, $prefix)
                || ! preg_match('/^Modules\\\\[A-Za-z][A-Za-z0-9]*(?:\\\\[A-Za-z][A-Za-z0-9]*)*$/', $provider)) {
                throw new InvalidArgumentException("Provider '{$provider}' must be a class in the Modules\\{$moduleName} namespace.");
            }
            if (in_array($provider, $providers, true)) {
                throw new InvalidArgumentException("Provider '{$provider}' is duplicated.");
            }
            $providers[] = $provider;
        }

        return $providers;
    }

    /** @return list<string> */
    private static function stringList(mixed $value, string $field): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("Module manifest field '{$field}' must be a list of strings.");
        }
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new InvalidArgumentException("Module manifest field '{$field}' must be a list of strings.");
            }
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value, string $field): array
    {
        if (! is_array($value) || (array_is_list($value) && $value !== [])) {
            throw new InvalidArgumentException("Module manifest field '{$field}' must be an object.");
        }

        return $value;
    }

    /** @return list<string> */
    private static function localFiles(mixed $value): array
    {
        $files = self::stringList($value, 'files');
        foreach ($files as $file) {
            if (! preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $file) || str_contains($file, '..') || str_contains($file, '//')) {
                throw new InvalidArgumentException("Module loader file '{$file}' must be a local relative path.");
            }
        }

        return $files;
    }

    /** @return array<string, string> */
    private static function dependencies(mixed $value, string $ownSlug): array
    {
        if (! is_array($value) || (array_is_list($value) && $value !== [])) {
            throw new InvalidArgumentException("Module manifest field 'module_dependencies' must be an object.");
        }

        foreach ($value as $slug => $constraint) {
            if (! is_string($slug) || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || $slug === $ownSlug) {
                throw new InvalidArgumentException("Module dependency '{$slug}' must be another lowercase kebab-case module slug.");
            }
            if (! Semver::isConstraint($constraint)) {
                throw new InvalidArgumentException("Module dependency '{$slug}' must use a supported SemVer constraint.");
            }
        }

        ksort($value, SORT_STRING);

        /** @var array<string, string> $value */
        return $value;
    }

    /** @return list<string> */
    private static function assets(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("Module manifest field 'assets' must be a list of local compiled assets.");
        }

        $assets = [];
        foreach ($value as $asset) {
            if (! is_string($asset) || ! preg_match('#^dist/[A-Za-z0-9][A-Za-z0-9._/-]*\.(?:js|css)$#', $asset)
                || str_contains($asset, '..') || str_contains($asset, '//')) {
                throw new InvalidArgumentException("Asset '{$asset}' must be a local dist/*.js or dist/*.css path; remote and source assets are forbidden.");
            }
            if (in_array($asset, $assets, true)) {
                throw new InvalidArgumentException("Asset '{$asset}' is duplicated.");
            }
            $assets[] = $asset;
        }

        return $assets;
    }

    /** @param array<string, mixed> $value */
    private static function uninstall(array $value, string $moduleName, int $schemaVersion): ?Uninstall
    {
        if ($schemaVersion === self::LEGACY_SCHEMA_VERSION) {
            if (array_key_exists('uninstall', $value)) {
                throw new InvalidArgumentException("Module manifest schema_version=1 does not support 'uninstall'.");
            }

            return null;
        }

        if (! is_array($value['uninstall'] ?? null) || array_is_list($value['uninstall'])) {
            throw new InvalidArgumentException("Module manifest schema_version=2 must declare an 'uninstall' object.");
        }

        return Uninstall::fromArray($value['uninstall'], $moduleName);
    }

    /** @param array<string, mixed> $value @param list<string> $allowed */
    private static function rejectUnknownKeys(array $value, array $allowed): void
    {
        $unknown = array_diff(array_keys($value), $allowed);
        if ($unknown !== []) {
            throw new InvalidArgumentException("Module manifest contains unsupported field '".reset($unknown)."'.");
        }
    }
}
