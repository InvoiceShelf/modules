<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Tests;

class BinaryTest extends TestCase
{
    public function test_composer_proxy_binary_uses_the_consuming_projects_autoloader(): void
    {
        $directory = sys_get_temp_dir().'/invoiceshelf-modules-binary-'.bin2hex(random_bytes(8));
        $installedBin = $directory.'/vendor/invoiceshelf/modules/bin/invoiceshelf-module';
        $manifest = $directory.'/module.json';
        $proxy = $directory.'/proxy.php';

        mkdir(dirname($installedBin), 0700, true);
        copy(dirname(__DIR__).'/bin/invoiceshelf-module', $installedBin);
        file_put_contents($manifest, json_encode($this->moduleManifest(), JSON_THROW_ON_ERROR));
        file_put_contents($proxy, sprintf(
            <<<'PHP'
<?php

$GLOBALS['_composer_autoload_path'] = %s;
$argv = [__FILE__, 'validate-module', %s];

require %s;
PHP,
            var_export(dirname(__DIR__).'/vendor/autoload.php', true),
            var_export($manifest, true),
            var_export($installedBin, true),
        ));

        try {
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, $proxy],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            $this->assertIsResource($process);

            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            $this->assertSame(0, proc_close($process), $stderr ?: $stdout);
            $this->assertSame('', $stderr);
            $this->assertStringContainsString('Valid validate-module manifest', $stdout);
        } finally {
            foreach ([$proxy, $manifest, $installedBin] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            foreach ([
                $directory.'/vendor/invoiceshelf/modules/bin',
                $directory.'/vendor/invoiceshelf/modules',
                $directory.'/vendor/invoiceshelf',
                $directory.'/vendor',
                $directory,
            ] as $path) {
                if (is_dir($path)) {
                    rmdir($path);
                }
            }
        }
    }

    /** @return array<string, mixed> */
    private function moduleManifest(): array
    {
        return [
            'schema_version' => 1,
            'slug' => 'hello-world',
            'name' => 'HelloWorld',
            'alias' => 'helloworld',
            'description' => 'Reference module.',
            'keywords' => [],
            'priority' => 0,
            'version' => '1.0.0',
            'license' => 'AGPL-3.0-only',
            'providers' => ['Modules\\HelloWorld\\Providers\\HelloWorldServiceProvider'],
            'aliases' => [],
            'files' => [],
            'requires' => [],
            'compatibility' => [
                'invoiceshelf' => '^3.0.0',
                'module_api' => '^1.0.0',
                'php' => '^8.3.0',
                'extensions' => ['ext-json'],
            ],
            'module_dependencies' => [],
            'migration_policy' => 'forward-only',
            'dependency_policy' => 'host-provided-only',
            'assets' => ['dist/init.js'],
        ];
    }
}
