<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Tests;

use InvalidArgumentException;
use InvoiceShelf\Modules\Bundle\BundleBuilder;
use InvoiceShelf\Modules\Bundle\BundleReport;
use InvoiceShelf\Modules\Bundle\ComposerProcess;
use InvoiceShelf\Modules\Manifest\PackageValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * Builds real bundles with the Composer binary against the offline sample
 * packages in tests/Fixtures/bundle/packages and a hand-written host lock.
 */
class BundleBuilderTest extends TestCase
{
    private string $directory;

    private string $module;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/invoiceshelf-bundle-test-'.bin2hex(random_bytes(8));
        $this->module = $this->directory.'/Module';
        mkdir($this->module, 0700, true);

        [$exitCode] = ComposerProcess::locate()->run(['--version'], $this->directory);
        if ($exitCode !== 0) {
            $this->markTestSkipped('The composer binary is not available.');
        }
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);

        parent::tearDown();
    }

    public function test_only_packages_the_host_lacks_are_bundled(): void
    {
        $this->module(['sample/greeter' => '^1.0']);

        $report = $this->build($this->hostLock(['sample/strings' => '1.0.0']));

        $this->assertSame(['sample/greeter'], array_keys($report->manifest->packages));
        $this->assertSame(['sample/strings' => ['sample/greeter' => '^1.0']], $report->manifest->hostPackages);
        $this->assertSame(['ext-mbstring'], $report->manifest->packages['sample/greeter']['extensions']);
        $this->assertTrue($report->lockWritten);
        $this->assertFileExists($this->module.'/bundle.lock');
        $this->assertDirectoryDoesNotExist($this->module.'/bundle/sample/strings');
        $this->assertDirectoryDoesNotExist($this->module.'/bundle/composer');
        $this->assertFileDoesNotExist($this->module.'/bundle/autoload.php');

        $classmap = require $this->module.'/bundle/classmap.php';
        $this->assertSame(['Sample\\Greeter\\Greeter' => 'sample/greeter/src/Greeter.php'], $classmap);
    }

    public function test_a_rebuild_from_bundle_lock_writes_the_same_bytes(): void
    {
        $this->module(['sample/greeter' => '^1.0']);
        $host = $this->hostLock([]);

        $first = $this->build($host);
        $tree = $this->tree($this->module.'/bundle');
        $second = $this->build($host);

        $this->assertTrue($first->lockWritten);
        $this->assertFalse($second->lockWritten);
        $this->assertSame(['sample/greeter', 'sample/strings'], array_keys($second->manifest->packages));
        $this->assertSame($tree, $this->tree($this->module.'/bundle'));
    }

    public function test_a_host_that_ships_an_incompatible_version_is_a_resolver_error(): void
    {
        $this->module(['sample/greeter' => '^1.0']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('could not resolve the bundle against this host');

        $this->build($this->hostLock(['sample/strings' => '2.0.0']));
    }

    public function test_bundle_lock_is_refused_once_the_host_ships_a_bundled_package(): void
    {
        $this->module(['sample/greeter' => '^1.0']);
        $this->build($this->hostLock([]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sample/strings is in bundle.lock but this host already ships it');

        $this->build($this->hostLock(['sample/strings' => '1.0.0']));
    }

    public function test_bundle_lock_is_refused_when_composer_json_no_longer_needs_a_package(): void
    {
        $this->module(['sample/greeter' => '^1.0', 'sample/extra' => '^1.0']);
        $host = $this->hostLock([]);
        $this->build($host);
        $this->module(['sample/greeter' => '^1.0']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no longer needs (sample/extra)');

        $this->build($host);
    }

    public function test_files_autoload_is_recorded_with_the_functions_it_declares_unconditionally(): void
    {
        $this->module(['sample/helpers' => '^1.0']);

        $report = $this->build($this->hostLock([]));

        $this->assertSame(['sample/helpers/src/functions.php'], $report->manifest->files);
        $this->assertSame(['Sample\\Helpers\\shout'], $report->manifest->functions);
        $this->assertSame(['proprietary', 'MIT'], $report->manifest->packages['sample/helpers']['license']);
    }

    /** @param array<string, string> $requires */
    #[DataProvider('refusedBundles')]
    public function test_packages_the_host_cannot_load_safely_are_refused(array $requires, array $extensions, string $message): void
    {
        $this->module($requires, $extensions);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        $this->build($this->hostLock([]));
    }

    /** @return iterable<string, array{array<string, string>, list<string>, string}> */
    public static function refusedBundles(): iterable
    {
        yield 'incompatible license' => [['sample/proprietary' => '^1.0'], [], 'which an AGPL-3.0-only module cannot ship'];
        yield 'reserved namespace' => [['sample/sneaky' => '^1.0'], [], 'in a namespace reserved for InvoiceShelf'];
        yield 'composer plugin' => [['sample/plugin' => '^1.0'], [], 'is a Composer plugin'];
        yield 'undeclared extension' => [['sample/greeter' => '^1.0'], [], 'needs ext-mbstring; add it to compatibility.extensions'];
    }

    public function test_the_module_composer_lock_must_hold_the_versions_that_ship(): void
    {
        $this->module(['sample/greeter' => '^1.0']);
        file_put_contents($this->module.'/composer.lock', json_encode([
            'packages' => [['name' => 'sample/greeter', 'version' => '0.9.0']],
            'packages-dev' => [],
        ], JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('composer.lock has sample/greeter 0.9.0 but bundle/ ships 1.0.0');

        $this->build($this->hostLock([]));
    }

    public function test_only_a_bundled_module_with_third_party_packages_can_be_bundled(): void
    {
        $this->module(['sample/greeter' => '^1.0'], ['ext-mbstring'], 'host-provided-only');

        try {
            $this->build($this->hostLock([]));
            $this->fail('A host-provided-only module should not be bundled.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString("must declare dependency_policy 'bundled'", $exception->getMessage());
        }

        $this->module([]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires no third-party packages');

        $this->build($this->hostLock([]));
    }

    public function test_a_built_module_passes_package_validation(): void
    {
        $this->module(['sample/greeter' => '^1.0']);
        $this->build($this->hostLock(['sample/strings' => '1.0.0']));
        mkdir($this->module.'/dist');
        file_put_contents($this->module.'/dist/init.js', 'built javascript');
        mkdir($this->module.'/app/Lifecycle', 0700, true);
        file_put_contents($this->module.'/app/Lifecycle/DataCleanup.php', <<<'PHP'
<?php

namespace Modules\Probe\Lifecycle;

use InvoiceShelf\Modules\Contracts\DataCleanup as Contract;

final class DataCleanup implements Contract
{
    public function cleanup(): void {}
}
PHP);

        $this->assertTrue(PackageValidator::validate($this->module)->bundlesDependencies());
    }

    private function build(string $hostLock): BundleReport
    {
        return (new BundleBuilder(ComposerProcess::locate()))->build($this->module, $hostLock);
    }

    /**
     * @param  array<string, string>  $requires
     * @param  list<string>  $extensions
     */
    private function module(array $requires, array $extensions = ['ext-mbstring'], string $policy = 'bundled'): void
    {
        file_put_contents($this->module.'/module.json', json_encode([
            'name' => 'Probe', 'alias' => 'probe', 'description' => '', 'keywords' => [], 'priority' => 0,
            'providers' => ['Modules\\Probe\\Providers\\ProbeServiceProvider'], 'aliases' => [], 'files' => [], 'requires' => [],
            'schema_version' => 2, 'slug' => 'probe', 'version' => '1.0.0', 'license' => 'AGPL-3.0-only',
            'compatibility' => ['invoiceshelf' => '^3.0.0', 'module_api' => '^1.4.0', 'php' => '^8.4.0', 'extensions' => $extensions],
            'module_dependencies' => [], 'migration_policy' => 'reversible',
            'uninstall' => ['data_cleanup' => 'Modules\\Probe\\Lifecycle\\DataCleanup'],
            'dependency_policy' => $policy, 'assets' => ['dist/init.js'],
        ], JSON_THROW_ON_ERROR));

        $packages = __DIR__.'/Fixtures/bundle/packages';
        $repositories = array_map(fn (string $path): array => ['type' => 'path', 'url' => $path], glob($packages.'/*', GLOB_ONLYDIR) ?: []);
        file_put_contents($this->module.'/composer.json', json_encode([
            'name' => 'invoiceshelf/module-probe',
            'license' => 'AGPL-3.0-only',
            'require' => ['php' => '^8.4', 'invoiceshelf/modules' => '^3.5', ...$requires],
            'repositories' => [...$repositories, ['packagist.org' => false]],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, string> $packages */
    private function hostLock(array $packages): string
    {
        $path = $this->directory.'/host-'.md5(serialize($packages)).'.lock';
        file_put_contents($path, json_encode([
            'packages' => array_map(fn (string $name, string $version): array => ['name' => $name, 'version' => $version], array_keys($packages), $packages),
            'packages-dev' => [],
        ], JSON_THROW_ON_ERROR));

        return $path;
    }

    /** @return array<string, string> relative path => sha256 and mode */
    private function tree(string $directory): array
    {
        $tree = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $tree[substr($file->getPathname(), strlen($directory) + 1)] = hash_file('sha256', $file->getPathname()).' '.decoct(fileperms($file->getPathname()) & 0777);
        }
        ksort($tree);

        return $tree;
    }

    private function remove(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($path);
    }
}
