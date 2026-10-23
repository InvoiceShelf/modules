<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Manifest;

use Composer\Semver\VersionParser;
use InvalidArgumentException;
use JsonException;
use stdClass;
use UnexpectedValueException;

/**
 * bundle/bundle.json: what a bundled module ships and what it relies on the host for.
 *
 * `packages` lists every third-party package inside bundle/, with the PHP
 * versions and extensions it needs. `host_packages` lists the host packages
 * the module and its bundled packages require, keyed by the requirer, because
 * Composer constraints from different requirers must all hold and cannot be
 * joined into one string without changing their meaning.
 *
 * `invoiceshelf-module bundle` writes this file; the SDK package validator,
 * the marketplace and the host read it.
 */
final readonly class BundleManifest
{
    public const SCHEMA = 1;

    public const DIRECTORY = 'bundle';

    public const FILE = 'bundle.json';

    public const CLASSMAP = 'classmap.php';

    private const PACKAGE_NAME = '#^[a-z0-9](?:[_.-]?[a-z0-9]+)*/[a-z0-9](?:(?:[_.]|-{1,2})?[a-z0-9]+)*$#';

    /** Extensions compiled into every PHP build; a module never has to declare them. */
    public const CORE_EXTENSIONS = [
        'ext-core', 'ext-date', 'ext-hash', 'ext-json', 'ext-pcre', 'ext-random', 'ext-reflection', 'ext-spl', 'ext-standard',
    ];

    /**
     * @param  array<string, array{version: string, reference: string|null, license: list<string>, php: string|null, extensions: list<string>}>  $packages
     * @param  array<string, array<string, string>>  $hostPackages
     * @param  list<string>  $files  Composer `files` autoload entries, relative to bundle/, in load order
     * @param  list<string>  $functions  functions those files declare unconditionally, fully qualified
     */
    public function __construct(
        public string $lockSha256,
        public array $packages,
        public array $hostPackages,
        public array $files = [],
        public array $functions = [],
    ) {}

    /** @param array<string, mixed> $value */
    public static function fromArray(array $value): self
    {
        self::rejectUnknownKeys($value, ['schema', 'built_against', 'packages', 'host_packages', 'files', 'functions'], 'bundle.json');

        if (($value['schema'] ?? null) !== self::SCHEMA) {
            throw new InvalidArgumentException('bundle.json must declare schema 1.');
        }

        $builtAgainst = $value['built_against'] ?? null;
        if (! is_array($builtAgainst) || array_is_list($builtAgainst)) {
            throw new InvalidArgumentException("bundle.json must declare a 'built_against' object.");
        }
        self::rejectUnknownKeys($builtAgainst, ['lock_sha256'], 'bundle.json built_against');
        $lockSha256 = $builtAgainst['lock_sha256'] ?? null;
        if (! is_string($lockSha256) || preg_match('/^[a-f0-9]{64}$/', $lockSha256) !== 1) {
            throw new InvalidArgumentException('bundle.json built_against.lock_sha256 must be a lowercase sha256.');
        }

        $packages = self::packages($value['packages'] ?? null);
        $hostPackages = self::hostPackages($value['host_packages'] ?? null, $packages);
        $files = self::files($value['files'] ?? [], $packages);
        $functions = self::functions($value['functions'] ?? []);

        return new self($lockSha256, $packages, $hostPackages, $files, $functions);
    }

    public static function fromFile(string $path): self
    {
        $contents = is_file($path) ? file_get_contents($path) : false;
        if ($contents === false) {
            throw new InvalidArgumentException('Bundled module must contain bundle/bundle.json; run invoiceshelf-module bundle.');
        }

        try {
            $value = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('bundle/bundle.json is not valid JSON: '.$exception->getMessage(), previous: $exception);
        }
        if (! is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('bundle/bundle.json must contain a JSON object.');
        }

        return self::fromArray($value);
    }

    public static function isPackageName(mixed $name): bool
    {
        return is_string($name) && preg_match(self::PACKAGE_NAME, $name) === 1;
    }

    public static function isConstraint(mixed $constraint): bool
    {
        if (! is_string($constraint) || trim($constraint) === '') {
            return false;
        }

        try {
            (new VersionParser)->parseConstraints($constraint);
        } catch (UnexpectedValueException) {
            return false;
        }

        return true;
    }

    /** @return list<string> */
    public function extensions(): array
    {
        $extensions = [];
        foreach ($this->packages as $package) {
            array_push($extensions, ...$package['extensions']);
        }
        $extensions = array_values(array_unique($extensions));
        sort($extensions, SORT_STRING);

        return $extensions;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $packages = $this->packages;
        ksort($packages, SORT_STRING);
        $hostPackages = [];
        foreach ($this->hostPackages as $name => $requirers) {
            ksort($requirers, SORT_STRING);
            $hostPackages[$name] = $requirers;
        }
        ksort($hostPackages, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'built_against' => ['lock_sha256' => $this->lockSha256],
            'packages' => $packages === [] ? new stdClass : $packages,
            'host_packages' => $hostPackages === [] ? new stdClass : $hostPackages,
            'files' => $this->files,
            'functions' => $this->functions,
        ];
    }

    /** Stable, reviewable JSON: the same bundle always writes the same bytes. */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    /** @return array<string, array{version: string, reference: string|null, license: list<string>, php: string|null, extensions: list<string>}> */
    private static function packages(mixed $value): array
    {
        // Empty is allowed: a module whose requirements the host already ships
        // still records them in host_packages, and the host checks them.
        if (! is_array($value) || (array_is_list($value) && $value !== [])) {
            throw new InvalidArgumentException("bundle.json 'packages' must be an object.");
        }

        $packages = [];
        foreach ($value as $name => $package) {
            if (! self::isPackageName($name)) {
                throw new InvalidArgumentException("Bundled package name '{$name}' is not a valid Composer package name.");
            }
            if (str_starts_with($name, 'invoiceshelf/')) {
                throw new InvalidArgumentException("Bundled package '{$name}' is reserved for InvoiceShelf; the host provides it.");
            }
            if (! is_array($package) || array_is_list($package)) {
                throw new InvalidArgumentException("Bundled package '{$name}' must be an object.");
            }
            self::rejectUnknownKeys($package, ['version', 'reference', 'license', 'php', 'extensions'], "bundled package '{$name}'");

            $version = $package['version'] ?? null;
            if (! is_string($version) || $version === '') {
                throw new InvalidArgumentException("Bundled package '{$name}' must declare its version.");
            }
            $reference = $package['reference'] ?? null;
            if ($reference !== null && ! is_string($reference)) {
                throw new InvalidArgumentException("Bundled package '{$name}' reference must be a string or null.");
            }
            $license = $package['license'] ?? null;
            if (! is_array($license) || ! array_is_list($license) || $license === []
                || array_filter($license, fn (mixed $item): bool => ! is_string($item)) !== []) {
                throw new InvalidArgumentException("Bundled package '{$name}' must declare its license.");
            }
            if (! LicensePolicy::allows($license)) {
                throw new InvalidArgumentException("Bundled package '{$name}' is licensed ".implode(' or ', $license).', which an AGPL-3.0-only module cannot ship.');
            }
            $php = $package['php'] ?? null;
            if ($php !== null && ! self::isConstraint($php)) {
                throw new InvalidArgumentException("Bundled package '{$name}' php requirement must be a Composer constraint or null.");
            }
            $extensions = $package['extensions'] ?? null;
            if (! is_array($extensions) || ! array_is_list($extensions)
                || array_filter($extensions, fn (mixed $item): bool => ! is_string($item) || preg_match('/^ext-[a-z0-9][a-z0-9_-]*$/', $item) !== 1) !== []) {
                throw new InvalidArgumentException("Bundled package '{$name}' extensions must be a list of ext-name entries.");
            }

            $packages[$name] = [
                'version' => $version,
                'reference' => $reference,
                'license' => $license,
                'php' => $php,
                'extensions' => $extensions,
            ];
        }

        return $packages;
    }

    /**
     * @param  array<string, mixed>  $packages
     * @return array<string, array<string, string>>
     */
    private static function hostPackages(mixed $value, array $packages): array
    {
        if (! is_array($value) || (array_is_list($value) && $value !== [])) {
            throw new InvalidArgumentException("bundle.json 'host_packages' must be an object.");
        }

        $hostPackages = [];
        foreach ($value as $name => $requirers) {
            if (! self::isPackageName($name)) {
                throw new InvalidArgumentException("Host package name '{$name}' is not a valid Composer package name.");
            }
            if (isset($packages[$name])) {
                throw new InvalidArgumentException("'{$name}' cannot be both bundled and provided by the host.");
            }
            if (! is_array($requirers) || array_is_list($requirers)) {
                throw new InvalidArgumentException("Host package '{$name}' must map each requirer to its constraint.");
            }
            foreach ($requirers as $requirer => $constraint) {
                if (! self::isPackageName($requirer) || ! self::isConstraint($constraint)) {
                    throw new InvalidArgumentException("Host package '{$name}' has an invalid requirement from '{$requirer}'.");
                }
            }
            $hostPackages[$name] = $requirers;
        }

        return $hostPackages;
    }

    /**
     * @param  array<string, mixed>  $packages
     * @return list<string>
     */
    private static function files(mixed $value, array $packages): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("bundle.json 'files' must be a list.");
        }
        foreach ($value as $file) {
            $parts = is_string($file) ? explode('/', $file) : [];
            if (count($parts) < 3 || in_array('..', $parts, true) || in_array('', $parts, true) || ! str_ends_with((string) $file, '.php')
                || ! isset($packages[$parts[0].'/'.$parts[1]])) {
                throw new InvalidArgumentException('bundle.json files must be PHP files inside bundled packages.');
            }
        }
        if (count(array_unique($value)) !== count($value)) {
            throw new InvalidArgumentException('bundle.json files must not repeat.');
        }

        return $value;
    }

    /** @return list<string> */
    private static function functions(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)
            || array_filter($value, fn (mixed $name): bool => ! is_string($name) || preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/', $name) !== 1) !== []) {
            throw new InvalidArgumentException("bundle.json 'functions' must be a list of function names.");
        }

        return $value;
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
