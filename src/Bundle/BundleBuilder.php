<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Bundle;

use Composer\Semver\VersionParser;
use FilesystemIterator;
use InvalidArgumentException;
use InvoiceShelf\Modules\Manifest\BundleManifest;
use InvoiceShelf\Modules\Manifest\BundleValidator;
use InvoiceShelf\Modules\Manifest\LicensePolicy;
use InvoiceShelf\Modules\Manifest\ModuleManifest;
use InvoiceShelf\Modules\Manifest\PackageValidator;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Builds a bundled module's bundle/ directory against one host release.
 *
 * Composer resolves the module's third-party packages in a throwaway project
 * where every host production package is a root `replace` at its locked
 * version, so only what the host lacks is installed. The builder then writes
 * bundle/ itself (package files, a relative classmap and bundle.json) instead
 * of shipping Composer's generated autoloader, which embeds the throwaway
 * path and registers itself in front of the host's.
 *
 * bundle.lock, committed next to composer.json, pins the result. Without
 * --update the build installs exactly that lock and fails when it no longer
 * fits the host or composer.json.
 */
final class BundleBuilder
{
    private const COMPOSER_FLAGS = [
        '--no-dev', '--no-interaction', '--no-progress', '--no-scripts', '--no-plugins', '--prefer-dist',
        '--ignore-platform-req=ext-*', '--ignore-platform-req=lib-*',
    ];

    public function __construct(private readonly ComposerProcess $composer) {}

    public function build(string $moduleDirectory, string $hostLockPath, bool $update = false): BundleReport
    {
        $root = realpath($moduleDirectory);
        if ($root === false || ! is_dir($root)) {
            throw new InvalidArgumentException("Module directory '{$moduleDirectory}' cannot be read.");
        }

        $module = ModuleManifest::fromArray(self::json($root.'/module.json', 'module.json'));
        if (! $module->bundlesDependencies()) {
            throw new InvalidArgumentException("module.json must declare dependency_policy 'bundled' before its dependencies can be bundled.");
        }

        $composerJson = self::json($root.'/composer.json', 'composer.json');
        $requires = PackageValidator::thirdPartyRequires((array) ($composerJson['require'] ?? []));
        if ($requires === []) {
            throw new InvalidArgumentException("composer.json requires no third-party packages; use dependency_policy 'host-provided-only' instead.");
        }

        $host = HostLock::fromFile($hostLockPath);
        $lockPath = $root.'/bundle.lock';
        $useLock = ! $update && is_file($lockPath);
        $work = self::temporaryDirectory();

        try {
            self::writeJson($work.'/composer.json', self::project($root, $module, $composerJson, $requires, $host));

            if ($useLock) {
                $locked = self::json($lockPath, 'bundle.lock');
                self::assertLockFitsHost($locked, $host);
                copy($lockPath, $work.'/composer.lock');
                $this->composer('install', $work, 'bundle.lock no longer fits this host or composer.json; run invoiceshelf-module bundle --update');
                self::assertLockIsNeeded($locked, $requires);
            } else {
                $this->composer('update', $work, 'Composer could not resolve the bundle against this host');
                copy($work.'/composer.lock', $lockPath);
            }

            $installed = self::installedPackages($work.'/vendor');
            $warnings = self::checkPackages($installed, $module, $host);
            $classmap = self::classmap($work.'/vendor', $installed);
            $files = self::files($work.'/vendor', $installed);
            $functions = [];
            foreach ($files as $file) {
                array_push($functions, ...BundleValidator::declaredFunctions($work.'/vendor/'.$file));
            }
            sort($functions, SORT_STRING);

            $manifest = new BundleManifest(
                $host->sha256,
                self::packageEntries($installed),
                self::hostPackages($composerJson['name'] ?? 'module', $requires, $installed, $host),
                $files,
                $functions,
            );

            self::writeBundle($root, $work.'/vendor', $installed, $classmap, $manifest);
        } finally {
            self::remove($work);
        }

        BundleValidator::validate($root, $module, array_keys($requires));
        self::assertModuleLockMatches($root, $manifest);

        return new BundleReport($manifest, $warnings, ! $useLock);
    }

    /**
     * The throwaway Composer project the bundle is resolved in.
     *
     * @param  array<string, mixed>  $composerJson
     * @param  array<string, string>  $requires
     * @return array<string, mixed>
     */
    private static function project(string $root, ModuleManifest $module, array $composerJson, array $requires, HostLock $host): array
    {
        $project = [
            'name' => 'invoiceshelf/bundle-'.$module->slug,
            'description' => 'Generated by invoiceshelf-module bundle; not a real package.',
            'type' => 'project',
            'require' => $requires,
            'config' => [
                'vendor-dir' => 'vendor',
                'allow-plugins' => false,
                'classmap-authoritative' => true,
                'sort-packages' => true,
                'platform' => ['php' => self::phpFloor($module->compatibility->php)],
            ],
            'minimum-stability' => $composerJson['minimum-stability'] ?? 'stable',
            'prefer-stable' => $composerJson['prefer-stable'] ?? true,
        ];
        if ($host->replaces !== []) {
            $project['replace'] = $host->replaces;
        }
        if ($host->provides !== []) {
            $project['provide'] = $host->provides;
        }

        $repositories = self::repositories($root, $composerJson['repositories'] ?? []);
        if ($repositories !== []) {
            $project['repositories'] = $repositories;
        }

        return $project;
    }

    /**
     * The module's own repositories, minus the SDK's (the bundle never needs
     * it), with path repositories made absolute and copied rather than linked.
     *
     * @return list<mixed>
     */
    private static function repositories(string $root, mixed $repositories): array
    {
        $result = [];
        foreach (is_array($repositories) ? $repositories : [] as $key => $repository) {
            if ($repository === false && is_string($key)) {
                $result[] = [$key => false]; // e.g. "packagist.org": false

                continue;
            }
            if (! is_array($repository)) {
                continue;
            }
            $url = $repository['url'] ?? null;
            if (is_string($url) && preg_match('#github\.com[/:]InvoiceShelf/modules(?:\.git)?$#i', $url) === 1) {
                continue;
            }
            if (($repository['type'] ?? null) === 'path' && is_string($url)) {
                $repository['url'] = str_starts_with($url, '/') ? $url : $root.'/'.$url;
                $repository['options'] = [...(array) ($repository['options'] ?? []), 'symlink' => false];
            }
            $result[] = $repository;
        }

        return $result;
    }

    /** The lowest PHP the module supports, which the bundle must also run on. */
    private static function phpFloor(string $constraint): string
    {
        $lower = (new VersionParser)->parseConstraints($constraint)->getLowerBound()->getVersion();
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', $lower, $matches) !== 1 || $matches[1] === '0') {
            throw new InvalidArgumentException('compatibility.php in module.json needs a lower bound, such as ^8.4.0.');
        }

        return "{$matches[1]}.{$matches[2]}.{$matches[3]}";
    }

    private function composer(string $command, string $work, string $failure): void
    {
        [$exitCode, $output] = $this->composer->run([$command, ...self::COMPOSER_FLAGS], $work);
        if ($exitCode !== 0) {
            throw new RuntimeException("{$failure}.\n\n{$output}");
        }
    }

    /** @param array<string, mixed> $locked */
    private static function assertLockFitsHost(array $locked, HostLock $host): void
    {
        foreach ((array) ($locked['packages'] ?? []) as $package) {
            $name = (string) ($package['name'] ?? '');
            if ($host->provides($name)) {
                throw new RuntimeException("{$name} is in bundle.lock but this host already ships it; run invoiceshelf-module bundle --update.");
            }
        }
    }

    /**
     * Every locked package must still be needed by composer.json, directly or
     * through another locked package; a stale entry would ship unused code.
     *
     * @param  array<string, mixed>  $locked
     * @param  array<string, string>  $requires
     */
    private static function assertLockIsNeeded(array $locked, array $requires): void
    {
        $graph = [];
        foreach ((array) ($locked['packages'] ?? []) as $package) {
            $graph[(string) ($package['name'] ?? '')] = array_keys((array) ($package['require'] ?? []));
        }

        $needed = [];
        $queue = array_keys($requires);
        while ($queue !== []) {
            $name = array_shift($queue);
            if (isset($needed[$name]) || ! isset($graph[$name])) {
                continue;
            }
            $needed[$name] = true;
            array_push($queue, ...$graph[$name]);
        }

        $unused = array_diff(array_keys($graph), array_keys($needed));
        if ($unused !== []) {
            throw new RuntimeException('bundle.lock lists packages composer.json no longer needs ('.implode(', ', $unused).'); run invoiceshelf-module bundle --update.');
        }
    }

    /** @return array<string, array<string, mixed>> */
    private static function installedPackages(string $vendor): array
    {
        $installed = self::json($vendor.'/composer/installed.json', 'the installed package list');
        $packages = [];
        foreach ((array) ($installed['packages'] ?? $installed) as $package) {
            if (is_array($package) && is_string($package['name'] ?? null)) {
                $packages[$package['name']] = $package;
            }
        }
        ksort($packages, SORT_STRING);

        return $packages;
    }

    /**
     * Refuse what the host cannot load safely; warn about what usually breaks.
     *
     * @param  array<string, array<string, mixed>>  $installed
     * @return list<string>
     */
    private static function checkPackages(array $installed, ModuleManifest $module, HostLock $host): array
    {
        $warnings = [];
        foreach ($installed as $name => $package) {
            if (($package['type'] ?? 'library') === 'composer-plugin') {
                throw new RuntimeException("{$name} is a Composer plugin; plugins never run inside a module.");
            }
            $license = array_values(array_filter((array) ($package['license'] ?? []), 'is_string'));
            if (! LicensePolicy::allows($license)) {
                throw new RuntimeException("{$name} is licensed ".($license === [] ? 'without a declared license' : implode(' or ', $license)).', which an AGPL-3.0-only module cannot ship.');
            }
            foreach (self::extensions($package) as $extension) {
                if (! in_array($extension, $module->compatibility->extensions, true)) {
                    throw new RuntimeException("{$name} needs {$extension}; add it to compatibility.extensions in module.json.");
                }
            }
            if ($host->hasDevPackage($name)) {
                $warnings[] = "{$name} is a development dependency of the host, so a local host loads its own copy instead of the bundled one.";
            }
            if (isset($package['extra']['laravel']['providers'])) {
                $warnings[] = "{$name} declares Laravel service providers; the host does not discover them, so register what you need from the module's provider.";
            }
        }

        return $warnings;
    }

    /**
     * @param  array<string, mixed>  $package
     * @return list<string>
     */
    private static function extensions(array $package): array
    {
        $extensions = array_values(array_filter(
            array_keys((array) ($package['require'] ?? [])),
            fn (string $name): bool => str_starts_with($name, 'ext-') && ! in_array($name, BundleManifest::CORE_EXTENSIONS, true),
        ));
        sort($extensions, SORT_STRING);

        return $extensions;
    }

    /**
     * Composer's authoritative classmap, rewritten relative to bundle/ and
     * without Composer's own classes.
     *
     * @param  array<string, array<string, mixed>>  $installed
     * @return array<string, string>
     */
    private static function classmap(string $vendor, array $installed): array
    {
        $generated = require $vendor.'/composer/autoload_classmap.php';
        $prefix = realpath($vendor).'/';
        $classmap = [];
        foreach ($generated as $class => $path) {
            $path = str_replace('\\', '/', (string) $path);
            if (str_starts_with($class, 'Composer\\') || ! str_starts_with($path, $prefix)) {
                continue;
            }
            $relative = substr($path, strlen($prefix));
            $parts = explode('/', $relative);
            $package = ($parts[0] ?? '').'/'.($parts[1] ?? '');
            if (! isset($installed[$package])) {
                continue;
            }
            if (BundleValidator::isReservedClass($class)) {
                throw new RuntimeException("{$package} defines {$class}, in a namespace reserved for InvoiceShelf, its modules, Composer or Laravel.");
            }
            $classmap[$class] = $relative;
        }
        ksort($classmap, SORT_STRING);

        return $classmap;
    }

    /**
     * Composer's `files` autoload entries in the order Composer would load
     * them (dependencies first), relative to bundle/. The host loads them only
     * for enabled modules.
     *
     * @param  array<string, array<string, mixed>>  $installed
     * @return list<string>
     */
    private static function files(string $vendor, array $installed): array
    {
        $generated = is_file($vendor.'/composer/autoload_files.php') ? require $vendor.'/composer/autoload_files.php' : [];
        $prefix = realpath($vendor).'/';
        $files = [];
        foreach ($generated as $path) {
            $path = str_replace('\\', '/', (string) $path);
            if (! str_starts_with($path, $prefix)) {
                continue;
            }
            $relative = substr($path, strlen($prefix));
            $parts = explode('/', $relative);
            if (isset($installed[($parts[0] ?? '').'/'.($parts[1] ?? '')])) {
                $files[] = $relative;
            }
        }

        return $files;
    }

    /**
     * @param  array<string, array<string, mixed>>  $installed
     * @return array<string, array{version: string, reference: string|null, license: list<string>, php: string|null, extensions: list<string>}>
     */
    private static function packageEntries(array $installed): array
    {
        $entries = [];
        foreach ($installed as $name => $package) {
            $php = $package['require']['php'] ?? null;
            $entries[$name] = [
                'version' => (string) ($package['version'] ?? ''),
                'reference' => $package['dist']['reference'] ?? $package['source']['reference'] ?? null,
                'license' => array_values(array_filter((array) ($package['license'] ?? []), 'is_string')),
                'php' => is_string($php) ? $php : null,
                'extensions' => self::extensions($package),
            ];
        }

        return $entries;
    }

    /**
     * Every host package the module or a bundled package requires, with each
     * requirer's constraint kept separately.
     *
     * @param  array<string, string>  $requires
     * @param  array<string, array<string, mixed>>  $installed
     * @return array<string, array<string, string>>
     */
    private static function hostPackages(string $moduleName, array $requires, array $installed, HostLock $host): array
    {
        $requirers = [$moduleName => $requires];
        foreach ($installed as $name => $package) {
            $requirers[$name] = (array) ($package['require'] ?? []);
        }

        $hostPackages = [];
        foreach ($requirers as $requirer => $requirements) {
            foreach ($requirements as $name => $constraint) {
                if (isset($installed[$name]) || ! PackageValidator::isThirdParty((string) $name) || ! $host->provides((string) $name)) {
                    continue;
                }
                $hostPackages[$name][$requirer] = (string) $constraint;
            }
        }

        return $hostPackages;
    }

    /**
     * Replace bundle/ with the package files, the classmap and bundle.json.
     *
     * @param  array<string, array<string, mixed>>  $installed
     * @param  array<string, string>  $classmap
     */
    private static function writeBundle(string $root, string $vendor, array $installed, array $classmap, BundleManifest $manifest): void
    {
        $bundle = $root.'/'.BundleManifest::DIRECTORY;
        if (is_link($bundle)) {
            throw new RuntimeException('bundle/ is a symbolic link; remove it and build again.');
        }
        self::remove($bundle);
        mkdir($bundle, 0755, true);

        foreach (array_keys($installed) as $package) {
            self::copyTree($vendor.'/'.$package, $bundle.'/'.$package, $package);
        }

        $lines = ["<?php\n", "// Generated by invoiceshelf-module bundle. Do not edit; the host reads it as data.\n", "return [\n"];
        foreach ($classmap as $class => $path) {
            $lines[] = '    '.var_export($class, true).' => '.var_export($path, true).",\n";
        }
        $lines[] = "];\n";
        file_put_contents($bundle.'/'.BundleManifest::CLASSMAP, implode('', $lines));
        file_put_contents($bundle.'/'.BundleManifest::FILE, $manifest->toJson());
        self::normalizePermissions($bundle);
    }

    private static function copyTree(string $source, string $target, string $package): void
    {
        if (! is_dir($source)) {
            throw new RuntimeException("Composer did not install {$package}.");
        }
        mkdir($target, 0755, true);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            $relative = substr($entry->getPathname(), strlen($source) + 1);
            if ($entry->isLink()) {
                throw new RuntimeException("{$package} contains a symbolic link ({$relative}); bundles must contain plain files.");
            }
            $destination = $target.'/'.$relative;
            if ($entry->isDir()) {
                mkdir($destination, 0755, true);

                continue;
            }
            copy($entry->getPathname(), $destination);
        }
    }

    /** The same bytes and modes on every machine, whatever its umask. */
    private static function normalizePermissions(string $bundle): void
    {
        chmod($bundle, 0755);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($bundle, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            chmod($entry->getPathname(), $entry->isDir() ? 0755 : 0644);
        }
    }

    /**
     * Tests run against vendor/ and the archive ships bundle/, so the two
     * must hold the same versions.
     */
    private static function assertModuleLockMatches(string $root, BundleManifest $manifest): void
    {
        if (! is_file($root.'/composer.lock')) {
            return;
        }

        $lock = self::json($root.'/composer.lock', 'composer.lock');
        foreach ([...(array) ($lock['packages'] ?? []), ...(array) ($lock['packages-dev'] ?? [])] as $package) {
            $name = $package['name'] ?? null;
            if (is_string($name) && isset($manifest->packages[$name]) && ($package['version'] ?? null) !== $manifest->packages[$name]['version']) {
                throw new RuntimeException("composer.lock has {$name} {$package['version']} but bundle/ ships {$manifest->packages[$name]['version']}; align them (for example composer update {$name}) so tests run on what ships.");
            }
        }
    }

    /** @return array<string, mixed> */
    private static function json(string $path, string $name): array
    {
        $contents = is_file($path) ? file_get_contents($path) : false;
        if ($contents === false) {
            throw new InvalidArgumentException("Cannot read {$name} ({$path}).");
        }

        try {
            $value = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException("{$name} is not valid JSON: {$exception->getMessage()}", previous: $exception);
        }

        return is_array($value) ? $value : throw new InvalidArgumentException("{$name} must contain a JSON object.");
    }

    /** @param array<string, mixed> $value */
    private static function writeJson(string $path, array $value): void
    {
        file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }

    private static function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/invoiceshelf-bundle-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);

        return $directory;
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($path);
    }
}
