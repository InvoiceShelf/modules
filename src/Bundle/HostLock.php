<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Bundle;

use InvalidArgumentException;
use JsonException;

/**
 * The production packages of one InvoiceShelf release, read from its composer.lock.
 *
 * A bundle is resolved as if these were already installed: each becomes a root
 * `replace` at its exact locked version, so Composer reuses it instead of
 * bundling a copy, and refuses outright when a bundled package needs a version
 * the host does not ship. (`provide` would not do: Composer still installs a
 * real package next to a provided name.) Development packages never reach a
 * production install, so they are not replaced.
 */
final readonly class HostLock
{
    /**
     * @param  array<string, string>  $packages  production package => locked version
     * @param  array<string, string>  $replaces  package name => constraint, including what each package itself replaces
     * @param  array<string, string>  $provides  virtual package name => constraint, from the packages' own `provide`
     * @param  list<string>  $devPackages
     */
    public function __construct(
        public string $sha256,
        public array $packages,
        public array $replaces,
        public array $provides,
        public array $devPackages,
    ) {}

    public static function fromFile(string $path): self
    {
        $contents = is_file($path) ? file_get_contents($path) : false;
        if ($contents === false) {
            throw new InvalidArgumentException("Cannot read the host composer.lock at {$path}.");
        }

        try {
            $lock = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException("The host composer.lock at {$path} is not valid JSON.", previous: $exception);
        }
        if (! is_array($lock) || ! is_array($lock['packages'] ?? null)) {
            throw new InvalidArgumentException("{$path} is not a composer.lock: it has no packages list.");
        }

        $packages = [];
        $replaces = [];
        $provides = [];
        foreach ($lock['packages'] as $package) {
            $name = $package['name'] ?? null;
            $version = $package['version'] ?? null;
            if (! is_string($name) || ! is_string($version)) {
                continue;
            }
            $packages[$name] = $version;
            $replaces[$name] = $version;
            foreach ((array) ($package['replace'] ?? []) as $replaced => $constraint) {
                $replaces[$replaced] ??= $constraint === 'self.version' ? $version : (string) $constraint;
            }
            foreach ((array) ($package['provide'] ?? []) as $provided => $constraint) {
                $provides[$provided] ??= $constraint === 'self.version' ? $version : (string) $constraint;
            }
        }

        $devPackages = [];
        foreach ((array) ($lock['packages-dev'] ?? []) as $package) {
            if (is_string($package['name'] ?? null) && ! isset($packages[$package['name']])) {
                $devPackages[] = $package['name'];
            }
        }

        ksort($packages, SORT_STRING);
        ksort($replaces, SORT_STRING);
        ksort($provides, SORT_STRING);
        sort($devPackages, SORT_STRING);

        return new self(hash('sha256', $contents), $packages, $replaces, $provides, $devPackages);
    }

    /** Whether a production install of this host already has the package (installed, replaced or provided). */
    public function provides(string $name): bool
    {
        return isset($this->replaces[$name]) || isset($this->provides[$name]);
    }

    public function hasDevPackage(string $name): bool
    {
        return in_array($name, $this->devPackages, true);
    }
}
