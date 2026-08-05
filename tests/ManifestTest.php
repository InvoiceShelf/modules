<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Tests;

use InvalidArgumentException;
use InvoiceShelf\Modules\Manifest\CanonicalJson;
use InvoiceShelf\Modules\Manifest\CleanupValidator;
use InvoiceShelf\Modules\Manifest\MigrationValidator;
use InvoiceShelf\Modules\Manifest\ModuleManifest;
use InvoiceShelf\Modules\Manifest\PackageValidator;
use InvoiceShelf\Modules\Manifest\ReleaseEnvelope;
use InvoiceShelf\Modules\Manifest\ReleaseManifest;
use InvoiceShelf\Modules\Manifest\SigningKeyPairGenerator;
use PHPUnit\Framework\Attributes\DataProvider;

class ManifestTest extends TestCase
{
    public function test_official_module_composer_stub_uses_the_reserved_package_and_laravel_13_test_stack(): void
    {
        $contents = file_get_contents(dirname(__DIR__).'/stubs/composer.stub');
        $this->assertIsString($contents);

        $stub = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('invoiceshelf/module-$KEBAB_NAME$', $stub['name']);
        $this->assertSame('AGPL-3.0-only', $stub['license']);
        $this->assertSame('^8.3', $stub['require']['php']);
        $this->assertSame('*', $stub['require']['ext-json']);
        $this->assertSame('^3.2', $stub['require']['invoiceshelf/modules']);
        $this->assertSame('^11.0', $stub['require-dev']['orchestra/testbench']);
        $this->assertSame('^12.0', $stub['require-dev']['phpunit/phpunit']);
        $this->assertSame('vendor/bin/pint --test', $stub['scripts']['lint']);
    }

    public function test_service_provider_enables_the_kebab_name_composer_replacement(): void
    {
        $replacements = $this->app['config']->get('modules.stubs.replacements.composer');

        $this->assertIsArray($replacements);
        $this->assertContains('KEBAB_NAME', $replacements);
    }

    public function test_schema_v2_stubs_declare_a_compatible_cleanup_provider(): void
    {
        $json = file_get_contents(dirname(__DIR__).'/stubs/json.stub');
        $provider = file_get_contents(dirname(__DIR__).'/stubs/scaffold/provider.stub');

        $this->assertIsString($json);
        $this->assertIsString($provider);

        $stub = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(2, $stub['schema_version']);
        $this->assertSame('^1.1.0', $stub['compatibility']['module_api']);
        $this->assertSame('reversible', $stub['migration_policy']);
        $this->assertSame('$MODULE_NAMESPACE$\\$STUDLY_NAME$\\Providers\\$STUDLY_NAME$ServiceProvider', $stub['uninstall']['data_cleanup']);
        $this->assertStringContainsString('implements DataCleanup', $provider);
        $this->assertStringContainsString('function cleanup(): void', $provider);
    }

    public function test_provider_stub_uses_the_public_platform_ai_driver_contract(): void
    {
        $provider = file_get_contents(dirname(__DIR__).'/stubs/scaffold/provider.stub');

        $this->assertIsString($provider);
        $this->assertStringContainsString('App\\Platform\\Ai\\Contracts\\AiDriver', $provider);
        $this->assertStringNotContainsString('App\\Support\\Ai\\AiDriver', $provider);
    }

    public function test_valid_module_manifest_is_normalized(): void
    {
        $manifest = ModuleManifest::fromArray($this->moduleManifest());

        $this->assertSame('sales-tax-us', $manifest->slug);
        $this->assertSame('SalesTaxUs', $manifest->name);
        $this->assertSame(['ext-mbstring', 'ext-json'], $manifest->compatibility->extensions);
        $this->assertSame(['accounting-core' => '^1.0.0'], $manifest->moduleDependencies);
        $this->assertSame(['dist/module.js', 'dist/module.css'], $manifest->assets);
    }

    public function test_compatibility_accepts_the_standard_short_composer_range_form(): void
    {
        $manifest = $this->moduleManifest();
        $manifest['compatibility']['invoiceshelf'] = '^3.0';
        $manifest['compatibility']['module_api'] = '^1.0';
        $manifest['compatibility']['php'] = '>=8.3 <9.0';

        $this->assertSame('^3.0', ModuleManifest::fromArray($manifest)->compatibility->invoiceshelf);
    }

    public function test_schema_v2_module_manifest_requires_reversible_migrations_and_a_data_cleanup_class(): void
    {
        $manifest = ModuleManifest::fromArray($this->reversibleModuleManifest());

        $this->assertSame('reversible', $manifest->migrationPolicy);
        $this->assertSame('Modules\\SalesTaxUs\\Providers\\SalesTaxUsServiceProvider', $manifest->uninstall?->dataCleanup);
        $this->assertSame($this->reversibleModuleManifest(), $manifest->toArray());
    }

    #[DataProvider('invalidModuleManifests')]
    public function test_invalid_module_contracts_are_rejected(string $message, array $changes): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        ModuleManifest::fromArray(array_replace_recursive($this->moduleManifest(), $changes));
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function invalidModuleManifests(): iterable
    {
        yield 'unsupported schema' => ['schema_version=1 or schema_version=2', ['schema_version' => 3]];
        yield 'bad slug' => ['lowercase kebab-case', ['slug' => 'Sales Tax']];
        yield 'bad module name' => ['PascalCase', ['name' => 'sales_tax_us']];
        yield 'bad version' => ['SemVer', ['version' => 'v1.0']];
        yield 'non-official license' => ['AGPL-3.0-only', ['license' => 'MIT']];
        yield 'provider outside module namespace' => ['Modules\\SalesTaxUs namespace', ['providers' => ['App\\Provider']]];
        yield 'bad host range' => ['supported SemVer constraint', ['compatibility' => ['invoiceshelf' => '*']]];
        yield 'bad extension' => ['ext-name', ['compatibility' => ['extensions' => ['json']]]];
        yield 'bad module dependency' => ['another lowercase kebab-case', ['module_dependencies' => ['sales-tax-us' => '^1.0.0']]];
        yield 'bad dependency range' => ['supported SemVer constraint', ['module_dependencies' => ['other-module' => 'dev-main']]];
        yield 'legacy rollback migration policy' => ['schema_version=1 requires migration_policy', ['migration_policy' => 'reversible']];
        yield 'runtime dependency policy' => ['host-provided-only', ['dependency_policy' => 'composer-install']];
        yield 'remote asset' => ['local dist', ['assets' => ['https://cdn.example.test/module.js']]];
        yield 'source asset' => ['local dist', ['assets' => ['resources/module.ts']]];
        yield 'unknown key' => ['unsupported field', ['composer_dependencies' => []]];
    }

    #[DataProvider('invalidSchemaV2ModuleManifests')]
    public function test_invalid_schema_v2_module_contracts_are_rejected(string $message, array $changes): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        ModuleManifest::fromArray(array_replace_recursive($this->reversibleModuleManifest(), $changes));
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function invalidSchemaV2ModuleManifests(): iterable
    {
        yield 'forward only v2 policy' => ['schema_version=2 requires migration_policy', ['migration_policy' => 'forward-only']];
        yield 'missing uninstall contract' => ["must declare an 'uninstall' object", ['uninstall' => null]];
        yield 'missing data cleanup class' => ['data_cleanup', ['uninstall' => ['data_cleanup' => '']]];
        yield 'data cleanup outside module namespace' => ['Modules\\SalesTaxUs namespace', ['uninstall' => ['data_cleanup' => 'App\\Cleanup']]];
        yield 'unknown uninstall key' => ['uninstall contract contains unsupported field', ['uninstall' => ['data_cleanup' => 'Modules\\SalesTaxUs\\Cleanup', 'command' => 'rm -rf']]];
    }

    public function test_canonical_json_sorts_objects_and_preserves_lists_exactly(): void
    {
        $first = ['z' => ['b' => 1, 'a' => 2], 'a' => ['second', ['z' => 1, 'a' => 2]], 'zero' => 1.0];
        $second = ['zero' => 1.0, 'a' => ['second', ['a' => 2, 'z' => 1]], 'z' => ['a' => 2, 'b' => 1]];

        $expected = '{"a":["second",{"a":2,"z":1}],"z":{"a":2,"b":1},"zero":1.0}';
        $this->assertSame($expected, CanonicalJson::encode($first));
        $this->assertSame($expected, CanonicalJson::encode($second));
    }

    public function test_keypair_generator_returns_standard_base64_ed25519_material_without_writing_files(): void
    {
        $keypair = SigningKeyPairGenerator::generate('official-2026-01');

        $this->assertSame('official-2026-01', $keypair['key_id']);
        $this->assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen(base64_decode($keypair['public_key_b64'], true)));
        $this->assertSame(SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, strlen(base64_decode($keypair['secret_key_b64'], true)));
        $this->assertTrue(sodium_crypto_sign_verify_detached(
            sodium_crypto_sign_detached('canonical bytes', base64_decode($keypair['secret_key_b64'], true)),
            'canonical bytes',
            base64_decode($keypair['public_key_b64'], true),
        ));
    }

    public function test_package_validator_requires_built_assets_host_provided_composer_dependencies_and_reversible_migrations(): void
    {
        $directory = sys_get_temp_dir().'/invoiceshelf-modules-'.bin2hex(random_bytes(8));
        mkdir($directory.'/dist', 0700, true);

        try {
            mkdir($directory.'/database/migrations', 0700, true);
            mkdir($directory.'/app/Providers', 0700, true);
            file_put_contents($directory.'/module.json', json_encode($this->reversibleModuleManifest(), JSON_THROW_ON_ERROR));
            file_put_contents($directory.'/composer.json', json_encode([
                'name' => 'invoiceshelf/module-sales-tax-us',
                'license' => 'AGPL-3.0-only',
                'require' => ['php' => '^8.3', 'invoiceshelf/modules' => '^3.0', 'ext-json' => '*'],
            ], JSON_THROW_ON_ERROR));
            file_put_contents($directory.'/dist/module.js', 'built javascript');
            file_put_contents($directory.'/dist/module.css', 'built css');
            file_put_contents($directory.'/database/migrations/2026_01_01_000000_create_rates_table.php', $this->validMigration());
            file_put_contents($directory.'/app/Providers/SalesTaxUsServiceProvider.php', $this->validCleanupProvider());

            $this->assertSame('sales-tax-us', PackageValidator::validate($directory)->slug);

            file_put_contents($directory.'/composer.json', json_encode([
                'name' => 'invoiceshelf/module-sales-tax-us',
                'license' => 'AGPL-3.0-only',
                'require' => ['php' => 'not-a-constraint'],
            ], JSON_THROW_ON_ERROR));
            try {
                PackageValidator::validate($directory);
                $this->fail('An unsupported Composer constraint should be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('supported SemVer constraint', $exception->getMessage());
            }

            file_put_contents($directory.'/composer.json', json_encode([
                'name' => 'invoiceshelf/module-sales-tax-us',
                'license' => 'AGPL-3.0-only',
                'require' => ['php' => '^8.3', 'invoiceshelf/modules' => '^3.0', 'ext-json' => '*'],
            ], JSON_THROW_ON_ERROR));

            unlink($directory.'/dist/module.css');
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('missing');
            PackageValidator::validate($directory);
        } finally {
            foreach (['module.json', 'composer.json', 'dist/module.js', 'dist/module.css', 'database/migrations/2026_01_01_000000_create_rates_table.php', 'app/Providers/SalesTaxUsServiceProvider.php'] as $file) {
                if (is_file($directory.'/'.$file)) {
                    unlink($directory.'/'.$file);
                }
            }
            if (is_dir($directory.'/dist')) {
                rmdir($directory.'/dist');
            }
            if (is_dir($directory.'/database/migrations')) {
                rmdir($directory.'/database/migrations');
            }
            if (is_dir($directory.'/database')) {
                rmdir($directory.'/database');
            }
            if (is_dir($directory.'/app/Providers')) {
                rmdir($directory.'/app/Providers');
            }
            if (is_dir($directory.'/app')) {
                rmdir($directory.'/app');
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    #[DataProvider('invalidMigrations')]
    public function test_reversible_migrations_must_have_public_non_empty_up_and_down_methods(string $source, string $message): void
    {
        $path = tempnam(sys_get_temp_dir(), 'invoiceshelf-module-migration-');
        $this->assertNotFalse($path);
        file_put_contents($path, $source);

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage($message);
            MigrationValidator::validateFile($path);
        } finally {
            unlink($path);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidMigrations(): iterable
    {
        yield 'missing down' => [
            <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
return new class extends Migration { public function up(): void { $value = 1; } };
PHP,
            'down(): void',
        ];
        yield 'protected up' => [
            <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
return new class extends Migration { protected function up(): void { $value = 1; } public function down(): void { $value = 1; } };
PHP,
            'up(): void',
        ];
        yield 'empty down' => [
            <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
return new class extends Migration { public function up(): void { $value = 1; } public function down(): void {} };
PHP,
            'down(): void',
        ];
        yield 'drop table in up' => [
            <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
return new class extends Migration { public function up(): void { Schema::dropIfExists('rates'); } public function down(): void { Schema::create('rates', fn () => null); } };
PHP,
            'Destructive calls are allowed only in down()',
        ];
        yield 'update data in up' => [
            <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
return new class extends Migration { public function up(): void { DB::table('rates')->update(['active' => false]); } public function down(): void { $value = 1; } };
PHP,
            'Destructive calls are allowed only in down()',
        ];
        yield 'rename in up' => [
            <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
return new class extends Migration { public function up(): void { Schema::rename('old', 'new'); } public function down(): void { $value = 1; } };
PHP,
            'Destructive calls are allowed only in down()',
        ];
        yield 'raw function in up' => [
            <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
return new class extends Migration { public function up(): void { raw('delete from rates'); } public function down(): void { $value = 1; } };
PHP,
            'Destructive calls are allowed only in down()',
        ];
        yield 'unprepared in up' => [
            <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
return new class extends Migration { public function up(): void { DB::unprepared('delete from rates'); } public function down(): void { $value = 1; } };
PHP,
            'Destructive calls are allowed only in down()',
        ];
        yield 'statement in up' => [
            <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
return new class extends Migration { public function up(): void { DB::statement('delete from rates'); } public function down(): void { $value = 1; } };
PHP,
            'Destructive calls are allowed only in down()',
        ];
        yield 'affecting statement in up' => [
            <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
return new class extends Migration { public function up(): void { DB::affectingStatement('delete from rates'); } public function down(): void { $value = 1; } };
PHP,
            'Destructive calls are allowed only in down()',
        ];
        yield 'static up' => [
            <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
return new class extends Migration { public static function up(): void { $value = 1; } public function down(): void { $value = 1; } };
PHP,
            'up(): void',
        ];
        yield 'up with argument' => [
            <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
return new class extends Migration { public function up(string $table): void { $value = 1; } public function down(): void { $value = 1; } };
PHP,
            'up(): void',
        ];
        yield 'non-void down' => [
            <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
return new class extends Migration { public function up(): void { $value = 1; } public function down(): bool { return true; } };
PHP,
            'down(): void',
        ];
        yield 'multiple migration classes' => [
            <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
return new class extends Migration { public function up(): void { $value = 1; } public function down(): void { $value = 1; } };
class AnotherMigration extends Migration { public function up(): void { $value = 1; } public function down(): void { $value = 1; } }
PHP,
            'exactly one concrete Laravel migration class',
        ];
        yield 'not a Laravel migration' => [
            <<<'PHP'
<?php
return new class { public function up(): void { $value = 1; } public function down(): void { $value = 1; } };
PHP,
            'must extend Illuminate\\Database\\Migrations\\Migration',
        ];
        yield 'abstract migration' => [
            <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
abstract class SalesTaxMigration extends Migration { public function up(): void { $value = 1; } public function down(): void { $value = 1; } }
PHP,
            'and be concrete',
        ];
    }

    public function test_reversible_migrations_allow_destructive_calls_in_down(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'invoiceshelf-module-migration-');
        $this->assertNotFalse($path);
        file_put_contents($path, $this->validMigration());

        try {
            MigrationValidator::validateFile($path);
            $this->addToAssertionCount(1);
        } finally {
            unlink($path);
        }
    }

    #[DataProvider('invalidCleanupProviders')]
    public function test_cleanup_class_must_be_concrete_and_implement_the_public_contract(string $source, string $message): void
    {
        $directory = sys_get_temp_dir().'/invoiceshelf-modules-cleanup-'.bin2hex(random_bytes(8));
        mkdir($directory.'/app/Providers', 0700, true);
        file_put_contents($directory.'/app/Providers/SalesTaxUsServiceProvider.php', $source);

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage($message);
            CleanupValidator::validateDirectory($directory, 'Modules\\SalesTaxUs\\Providers\\SalesTaxUsServiceProvider');
        } finally {
            unlink($directory.'/app/Providers/SalesTaxUsServiceProvider.php');
            rmdir($directory.'/app/Providers');
            rmdir($directory.'/app');
            rmdir($directory);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidCleanupProviders(): iterable
    {
        yield 'missing interface' => [
            <<<'PHP'
<?php
namespace Modules\SalesTaxUs\Providers;
final class SalesTaxUsServiceProvider { public function cleanup(): void {} }
PHP,
            'must implement',
        ];
        yield 'abstract class' => [
            <<<'PHP'
<?php
namespace Modules\SalesTaxUs\Providers;
use InvoiceShelf\Modules\Contracts\DataCleanup;
abstract class SalesTaxUsServiceProvider implements DataCleanup { public function cleanup(): void {} }
PHP,
            'must not be abstract',
        ];
        yield 'abstract cleanup method' => [
            <<<'PHP'
<?php
namespace Modules\SalesTaxUs\Providers;
use InvoiceShelf\Modules\Contracts\DataCleanup;
abstract class SalesTaxUsServiceProvider implements DataCleanup { abstract public function cleanup(): void; }
PHP,
            'concrete public zero-argument cleanup(): void',
        ];
        yield 'static cleanup method' => [
            <<<'PHP'
<?php
namespace Modules\SalesTaxUs\Providers;
use InvoiceShelf\Modules\Contracts\DataCleanup;
final class SalesTaxUsServiceProvider implements DataCleanup { public static function cleanup(): void {} }
PHP,
            'concrete public zero-argument cleanup(): void',
        ];
        yield 'cleanup with argument' => [
            <<<'PHP'
<?php
namespace Modules\SalesTaxUs\Providers;
use InvoiceShelf\Modules\Contracts\DataCleanup;
final class SalesTaxUsServiceProvider implements DataCleanup { public function cleanup(string $scope): void {} }
PHP,
            'concrete public zero-argument cleanup(): void',
        ];
    }

    public function test_valid_stable_release_has_deterministic_canonical_form(): void
    {
        $release = ReleaseManifest::fromArray($this->releaseManifest());

        $this->assertSame('stable', $release->channel);
        $this->assertSame('published', $release->publication);
        $this->assertSame('2026-08-05T11:30:00Z', $release->releasedAt);
        $this->assertStringContainsString('"artifact":{"bytes":1234,"sha256":"'.str_repeat('a', 64).'"}', $release->canonicalJson());
        $this->assertSame($release->canonicalJson(), ReleaseManifest::fromArray(array_reverse($this->releaseManifest()))->canonicalJson());
    }

    public function test_valid_insider_release_requires_a_prerelease_version(): void
    {
        $manifest = $this->releaseManifest();
        $manifest['channel'] = 'insider';
        $manifest['version'] = '1.3.0-insider.1';

        $release = ReleaseManifest::fromArray($manifest);

        $this->assertSame('insider', $release->channel);
        $this->assertSame('1.3.0-insider.1', $release->version);
    }

    #[DataProvider('validReleaseTimestamps')]
    public function test_release_timestamp_accepts_z_offset_and_fractional_rfc3339_values(string $timestamp): void
    {
        $manifest = $this->releaseManifest();
        $manifest['released_at'] = $timestamp;

        $release = ReleaseManifest::fromArray($manifest);

        $this->assertSame($timestamp, $release->releasedAt);
        $this->assertStringContainsString('"released_at":"'.$timestamp.'"', $release->canonicalJson());
    }

    /** @return iterable<string, array{string}> */
    public static function validReleaseTimestamps(): iterable
    {
        yield 'UTC Z timezone' => ['2026-08-05T11:30:00Z'];
        yield 'numeric timezone offset' => ['2026-08-05T13:00:00+01:30'];
        yield 'fractional seconds' => ['2026-08-05T11:30:00.123456789Z'];
    }

    #[DataProvider('invalidReleaseTimestamps')]
    public function test_release_timestamp_rejects_impossible_or_non_timezoned_values(string $timestamp, string $message): void
    {
        $manifest = $this->releaseManifest();
        $manifest['released_at'] = $timestamp;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        ReleaseManifest::fromArray($manifest);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidReleaseTimestamps(): iterable
    {
        yield 'impossible calendar date' => ['2026-02-31T11:30:00Z', 'valid RFC 3339'];
        yield 'invalid clock time' => ['2026-08-05T24:00:00Z', 'valid RFC 3339'];
        yield 'normalized timezone offset' => ['2026-08-05T11:30:00+23:60', 'valid RFC 3339'];
        yield 'no timezone' => ['2026-08-05T11:30:00', 'with timezone'];
    }

    #[DataProvider('invalidReleaseManifests')]
    public function test_invalid_release_contracts_are_rejected(string $message, array $changes): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        ReleaseManifest::fromArray(array_replace_recursive($this->releaseManifest(), $changes));
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function invalidReleaseManifests(): iterable
    {
        yield 'bad channel' => ["either 'stable' or 'insider'", ['channel' => 'beta']];
        yield 'stable prerelease' => ['Stable releases', ['version' => '1.2.3-rc.1']];
        yield 'insider regular version' => ['Insider releases', ['channel' => 'insider']];
        yield 'mutable yanked signed state' => ['immutable marker', ['publication' => 'yanked']];
        yield 'uppercase checksum' => ['lowercase hexadecimal', ['artifact' => ['sha256' => strtoupper(str_repeat('a', 64))]]];
        yield 'zero bytes' => ['positive integer', ['artifact' => ['bytes' => 0]]];
        yield 'bad key id' => ['key_id', ['key_id' => 'bad key']];
        yield 'bad commit' => ['40-character', ['source_commit' => 'abcdef']];
        yield 'bad time' => ['RFC 3339', ['released_at' => 'yesterday']];
        yield 'unknown state field' => ['unsupported field', ['state' => 'published']];
    }

    public function test_envelope_requires_matching_integrity_metadata_and_verifies_ed25519_signature(): void
    {
        $release = ReleaseManifest::fromArray($this->releaseManifest());
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = base64_encode(sodium_crypto_sign_publickey($keypair));
        $signature = base64_encode(sodium_crypto_sign_detached($release->canonicalJson(), sodium_crypto_sign_secretkey($keypair)));
        $envelope = [
            'success' => true,
            'manifest' => $this->releaseManifest(),
            'signature' => $signature,
            'key_id' => 'official-2026-01',
            'artifact' => [
                'sha256' => str_repeat('a', 64),
                'bytes' => 1234,
                'download_url' => 'https://modules.invoiceshelf.com/download/sales-tax-us.zip',
                'expires_at' => '2026-08-05T12:00:00Z',
            ],
        ];

        $parsed = ReleaseEnvelope::fromArray($envelope);
        $this->assertTrue($parsed->hasValidSignature(['official-2026-01' => $publicKey]));

        $envelope['artifact']['bytes'] = 9;
        $this->expectExceptionMessage('must match');
        ReleaseEnvelope::fromArray($envelope);
    }

    public function test_yanked_catalog_state_requires_reason_and_does_not_change_signed_manifest(): void
    {
        $envelope = [
            'success' => true,
            'manifest' => $this->releaseManifest(),
            'signature' => base64_encode(str_repeat('x', SODIUM_CRYPTO_SIGN_BYTES)),
            'key_id' => 'official-2026-01',
            'artifact' => [
                'sha256' => str_repeat('a', 64),
                'bytes' => 1234,
                'download_url' => 'https://modules.invoiceshelf.com/download/sales-tax-us.zip',
                'expires_at' => '2026-08-05T12:00:00Z',
            ],
            'release_state' => 'yanked',
            'yanked_reason' => 'Security issue',
        ];

        $parsed = ReleaseEnvelope::fromArray($envelope);
        $this->assertSame('yanked', $parsed->releaseState);
        $this->assertSame('published', $parsed->manifest->publication);
    }

    public function test_invalid_yank_catalog_state_is_rejected(): void
    {
        $envelope = [
            'success' => true,
            'manifest' => $this->releaseManifest(),
            'signature' => base64_encode(str_repeat('x', SODIUM_CRYPTO_SIGN_BYTES)),
            'key_id' => 'official-2026-01',
            'artifact' => [
                'sha256' => str_repeat('a', 64),
                'bytes' => 1234,
                'download_url' => 'https://modules.invoiceshelf.com/download/sales-tax-us.zip',
                'expires_at' => '2026-08-05T12:00:00Z',
            ],
            'release_state' => 'yanked',
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('yanked_reason');

        ReleaseEnvelope::fromArray($envelope);
    }

    /** @return array<string, mixed> */
    private function moduleManifest(): array
    {
        return [
            'schema_version' => 1,
            'slug' => 'sales-tax-us',
            'name' => 'SalesTaxUs',
            'alias' => 'salestaxus',
            'description' => '',
            'keywords' => [],
            'priority' => 0,
            'version' => '1.2.3',
            'license' => 'AGPL-3.0-only',
            'providers' => ['Modules\\SalesTaxUs\\Providers\\SalesTaxUsServiceProvider'],
            'aliases' => [],
            'files' => [],
            'requires' => [],
            'compatibility' => [
                'invoiceshelf' => '^3.0.0',
                'module_api' => '^1.0.0',
                'php' => '^8.3.0',
                'extensions' => ['ext-mbstring', 'ext-json'],
            ],
            'module_dependencies' => ['accounting-core' => '^1.0.0'],
            'migration_policy' => 'forward-only',
            'dependency_policy' => 'host-provided-only',
            'assets' => ['dist/module.js', 'dist/module.css'],
        ];
    }

    /** @return array<string, mixed> */
    private function reversibleModuleManifest(): array
    {
        return [
            ...$this->moduleManifest(),
            'schema_version' => 2,
            'migration_policy' => 'reversible',
            'uninstall' => [
                'data_cleanup' => 'Modules\\SalesTaxUs\\Providers\\SalesTaxUsServiceProvider',
            ],
        ];
    }

    private function validMigration(): string
    {
        return <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('rates', function (): void {});
    }

    public function down(): void
    {
        Schema::dropIfExists('rates');
    }
};
PHP;
    }

    private function validCleanupProvider(): string
    {
        return <<<'PHP'
<?php

namespace Modules\SalesTaxUs\Providers;

use InvoiceShelf\Modules\Contracts\DataCleanup;

final class SalesTaxUsServiceProvider implements DataCleanup
{
    public function cleanup(): void {}
}
PHP;
    }

    /** @return array<string, mixed> */
    private function releaseManifest(): array
    {
        return [
            'schema_version' => 1,
            'slug' => 'sales-tax-us',
            'module_name' => 'SalesTaxUs',
            'version' => '1.2.3',
            'channel' => 'stable',
            'publication' => 'published',
            'compatibility' => [
                'invoiceshelf' => '^3.0.0',
                'module_api' => '^1.0.0',
                'php' => '^8.3.0',
                'extensions' => ['ext-json'],
            ],
            'artifact' => ['sha256' => str_repeat('a', 64), 'bytes' => 1234],
            'key_id' => 'official-2026-01',
            'source_commit' => str_repeat('b', 40),
            'released_at' => '2026-08-05T11:30:00Z',
        ];
    }
}
