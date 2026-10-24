<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Tests;

use Closure;
use InvalidArgumentException;
use InvoiceShelf\Modules\Manifest\BundleManifest;
use InvoiceShelf\Modules\Manifest\LicensePolicy;
use InvoiceShelf\Modules\Manifest\PackageValidator;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Package validation for both dependency policies, on hand-built packages so
 * every rule can be broken on purpose.
 */
class BundleValidationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/invoiceshelf-bundle-validation-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);

        parent::tearDown();
    }

    public function test_tasks_and_projects_0_2_1_still_validates_unchanged(): void
    {
        $manifest = PackageValidator::validate(__DIR__.'/Fixtures/tasks-projects-0.2.1');

        $this->assertSame('tasks-projects', $manifest->slug);
        $this->assertSame('host-provided-only', $manifest->dependencyPolicy);
        $this->assertFalse($manifest->bundlesDependencies());
    }

    public function test_a_host_provided_module_may_not_carry_a_bundle(): void
    {
        $module = $this->copyTasksProjects();
        mkdir($module.'/bundle');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("dependency_policy 'host-provided-only' must not contain bundle/");

        PackageValidator::validate($module);
    }

    public function test_a_host_provided_module_still_may_not_require_third_party_packages(): void
    {
        $module = $this->copyTasksProjects();
        $composer = json_decode((string) file_get_contents($module.'/composer.json'), true);
        $composer['require']['stripe/stripe-php'] = '^17.0';
        file_put_contents($module.'/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("composer.json dependency 'stripe/stripe-php' is not host-provided.");

        PackageValidator::validate($module);
    }

    public function test_a_well_formed_bundle_validates(): void
    {
        $manifest = PackageValidator::validate($this->bundledModule());

        $this->assertTrue($manifest->bundlesDependencies());
    }

    /** @param Closure(string): void $break */
    #[DataProvider('brokenBundles')]
    public function test_a_broken_bundle_is_refused(Closure $break, string $message): void
    {
        $module = $this->bundledModule();
        $break($module);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        PackageValidator::validate($module);
    }

    /** @return iterable<string, array{Closure(string): void, string}> */
    public static function brokenBundles(): iterable
    {
        yield 'missing bundle' => [fn (string $m) => self::removeTree($m.'/bundle'), 'must contain a bundle/ directory'];
        yield 'classmap path escapes its package' => [
            fn (string $m) => self::writeClassmap($m, ['Sample\\Greeter\\Greeter' => 'sample/greeter/../../../outside.php']),
            'not a PHP file inside a bundled package',
        ];
        yield 'classmap runs code' => [
            fn (string $m) => file_put_contents($m.'/bundle/classmap.php', "<?php\nsystem('id');\nreturn [];\n"),
            'must contain only a returned array',
        ];
        yield 'reserved namespace' => [
            fn (string $m) => self::writeClassmap($m, ['Illuminate\\Support\\Str' => 'sample/greeter/src/Greeter.php']),
            'reserved for InvoiceShelf',
        ];
        yield 'composer autoloader shipped' => [
            fn (string $m) => file_put_contents($m.'/bundle/autoload.php', '<?php'),
            "Composer's own autoloader",
        ];
        yield 'undeclared package' => [
            fn (string $m) => mkdir($m.'/bundle/evil/package', 0700, true),
            'bundle/evil/package is not a package declared in bundle.json',
        ];
        yield 'declared package missing' => [
            fn (string $m) => self::editManifest($m, fn (array $b) => [...$b, 'packages' => [...$b['packages'], 'sample/ghost' => $b['packages']['sample/greeter']]]),
            "declares 'sample/ghost', but bundle/sample/ghost is missing",
        ];
        yield 'require neither bundled nor host provided' => [
            fn (string $m) => self::editComposer($m, ['sample/other' => '^1.0']),
            "composer.json requires 'sample/other', but bundle/ neither ships it",
        ];
        yield 'extension not declared' => [
            fn (string $m) => self::editModule($m, ['extensions' => []]),
            'Bundled packages need ext-mbstring',
        ];
        yield 'functions out of date' => [
            function (string $m): void {
                mkdir($m.'/bundle/sample/greeter/functions', 0700, true);
                file_put_contents($m.'/bundle/sample/greeter/functions/helpers.php', "<?php\n\nfunction sample_greet(): string\n{\n    return 'hi';\n}\n");
                self::editManifest($m, fn (array $b) => [...$b, 'files' => ['sample/greeter/functions/helpers.php']]);
            },
            'functions do not match',
        ];
        yield 'symbolic link' => [
            fn (string $m) => symlink('/etc/passwd', $m.'/bundle/sample/greeter/src/link.php'),
            'is a symbolic link',
        ];
        yield 'incompatible license' => [
            fn (string $m) => self::editManifest($m, fn (array $b) => [...$b, 'packages' => ['sample/greeter' => [...$b['packages']['sample/greeter'], 'license' => ['proprietary']]]]),
            'cannot ship',
        ];
        yield 'bundled and host provided at once' => [
            fn (string $m) => self::editManifest($m, fn (array $b) => [...$b, 'host_packages' => ['sample/greeter' => ['invoiceshelf/module-probe' => '^1.0']]]),
            'cannot be both bundled and provided by the host',
        ];
        yield 'invalid host constraint' => [
            fn (string $m) => self::editManifest($m, fn (array $b) => [...$b, 'host_packages' => ['psr/log' => ['sample/greeter' => 'not a constraint']]]),
            "invalid requirement from 'sample/greeter'",
        ];
    }

    /** @param list<string> $licenses */
    #[DataProvider('licenses')]
    public function test_license_policy_reads_composer_licenses_as_alternatives(array $licenses, bool $allowed): void
    {
        $this->assertSame($allowed, LicensePolicy::allows($licenses));
    }

    /** @return iterable<string, array{list<string>, bool}> */
    public static function licenses(): iterable
    {
        yield 'MIT' => [['MIT'], true];
        yield 'dual licensed' => [['MIT', 'Apache-2.0'], true];
        yield 'one allowed alternative' => [['proprietary', 'MIT'], true];
        yield 'LGPL short form' => [['LGPL-3.0'], true];
        yield 'SPDX or expression' => [['(MIT or GPL-3.0-or-later)'], true];
        yield 'SPDX and expression with a disallowed part' => [['MIT and proprietary'], false];
        yield 'proprietary' => [['proprietary'], false];
        yield 'OSL is not GPL compatible' => [['OSL-3.0'], false];
        yield 'none declared' => [[], false];
    }

    public function test_bundle_json_is_written_with_sorted_keys_and_round_trips(): void
    {
        $manifest = BundleManifest::fromArray([
            'schema' => 1,
            'built_against' => ['lock_sha256' => str_repeat('a', 64)],
            'packages' => [
                'zeta/z' => ['version' => '1.0.0', 'reference' => null, 'license' => ['MIT'], 'php' => '>=8.1', 'extensions' => []],
                'alpha/a' => ['version' => '2.0.0', 'reference' => 'abc', 'license' => ['MIT'], 'php' => null, 'extensions' => ['ext-gd']],
            ],
            'host_packages' => ['psr/log' => ['zeta/z' => '^1.0 || ^2.0', 'alpha/a' => '^3.0']],
            'files' => [],
            'functions' => [],
        ]);

        $json = $manifest->toJson();
        $this->assertLessThan(strpos($json, '"zeta/z"'), strpos($json, '"alpha/a"'));
        $this->assertSame(['alpha/a' => '^3.0', 'zeta/z' => '^1.0 || ^2.0'], json_decode($json, true)['host_packages']['psr/log']);
        $this->assertSame($json, BundleManifest::fromArray(json_decode($json, true))->toJson());
        $this->assertSame(['ext-gd'], $manifest->extensions());
    }

    private function copyTasksProjects(): string
    {
        $target = $this->directory.'/TasksProjects';
        self::copyTree(__DIR__.'/Fixtures/tasks-projects-0.2.1', $target);

        return $target;
    }

    /** A complete bundled module whose bundle/ ships sample/greeter and relies on the host for sample/strings. */
    private function bundledModule(): string
    {
        $module = $this->directory.'/Probe';
        mkdir($module.'/dist', 0700, true);
        mkdir($module.'/app/Lifecycle', 0700, true);
        mkdir($module.'/bundle/sample/greeter/src', 0700, true);

        file_put_contents($module.'/module.json', json_encode([
            'name' => 'Probe', 'alias' => 'probe', 'description' => '', 'keywords' => [], 'priority' => 0,
            'providers' => ['Modules\\Probe\\Providers\\ProbeServiceProvider'], 'aliases' => [], 'files' => [], 'requires' => [],
            'schema_version' => 2, 'slug' => 'probe', 'version' => '1.0.0', 'license' => 'AGPL-3.0-only',
            'compatibility' => ['invoiceshelf' => '^3.0.0', 'module_api' => '^1.4.0', 'php' => '^8.4.0', 'extensions' => ['ext-mbstring']],
            'module_dependencies' => [], 'migration_policy' => 'reversible',
            'uninstall' => ['data_cleanup' => 'Modules\\Probe\\Lifecycle\\DataCleanup'],
            'dependency_policy' => 'bundled', 'assets' => ['dist/init.js'],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($module.'/composer.json', json_encode([
            'name' => 'invoiceshelf/module-probe', 'license' => 'AGPL-3.0-only',
            'require' => ['php' => '^8.4', 'invoiceshelf/modules' => '^3.5', 'sample/greeter' => '^1.0'],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($module.'/dist/init.js', 'built javascript');
        file_put_contents($module.'/app/Lifecycle/DataCleanup.php', <<<'PHP'
<?php

namespace Modules\Probe\Lifecycle;

use InvoiceShelf\Modules\Contracts\DataCleanup as Contract;

final class DataCleanup implements Contract
{
    public function cleanup(): void {}
}
PHP);
        file_put_contents($module.'/bundle/sample/greeter/src/Greeter.php', "<?php\n\nnamespace Sample\\Greeter;\n\nfinal class Greeter {}\n");
        self::writeClassmap($module, ['Sample\\Greeter\\Greeter' => 'sample/greeter/src/Greeter.php']);
        file_put_contents($module.'/bundle/bundle.json', (new BundleManifest(
            str_repeat('b', 64),
            ['sample/greeter' => ['version' => '1.0.0', 'reference' => 'abc', 'license' => ['MIT'], 'php' => '>=8.1', 'extensions' => ['ext-mbstring']]],
            ['sample/strings' => ['sample/greeter' => '^1.0']],
        ))->toJson());

        return $module;
    }

    /** @param array<string, string> $classmap */
    private static function writeClassmap(string $module, array $classmap): void
    {
        $lines = ["<?php\n", "return [\n"];
        foreach ($classmap as $class => $path) {
            $lines[] = '    '.var_export($class, true).' => '.var_export($path, true).",\n";
        }
        file_put_contents($module.'/bundle/classmap.php', implode('', [...$lines, "];\n"]));
    }

    /** @param Closure(array<string, mixed>): array<string, mixed> $edit */
    private static function editManifest(string $module, Closure $edit): void
    {
        $bundle = json_decode((string) file_get_contents($module.'/bundle/bundle.json'), true);
        file_put_contents($module.'/bundle/bundle.json', json_encode($edit($bundle), JSON_THROW_ON_ERROR));
    }

    /** @param array<string, string> $requires */
    private static function editComposer(string $module, array $requires): void
    {
        $composer = json_decode((string) file_get_contents($module.'/composer.json'), true);
        $composer['require'] = [...$composer['require'], ...$requires];
        file_put_contents($module.'/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $compatibility */
    private static function editModule(string $module, array $compatibility): void
    {
        $manifest = json_decode((string) file_get_contents($module.'/module.json'), true);
        $manifest['compatibility'] = [...$manifest['compatibility'], ...$compatibility];
        file_put_contents($module.'/module.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    }

    private static function copyTree(string $source, string $target): void
    {
        mkdir($target, 0700, true);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            $destination = $target.'/'.substr($entry->getPathname(), strlen($source) + 1);
            $entry->isDir() ? mkdir($destination, 0700, true) : copy($entry->getPathname(), $destination);
        }
    }

    private static function removeTree(string $path): void
    {
        if (! is_dir($path) || is_link($path)) {
            is_link($path) || is_file($path) ? unlink($path) : null;

            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($path);
    }

    private function remove(string $path): void
    {
        self::removeTree($path);
    }
}
