<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Tests;

use InvalidArgumentException;
use InvoiceShelf\Modules\Manifest\CanonicalJson;
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
        $this->assertSame('^3.1', $stub['require']['invoiceshelf/modules']);
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
        yield 'unsupported schema' => ['schema_version=1', ['schema_version' => 2]];
        yield 'bad slug' => ['lowercase kebab-case', ['slug' => 'Sales Tax']];
        yield 'bad module name' => ['PascalCase', ['name' => 'sales_tax_us']];
        yield 'bad version' => ['SemVer', ['version' => 'v1.0']];
        yield 'non-official license' => ['AGPL-3.0-only', ['license' => 'MIT']];
        yield 'provider outside module namespace' => ['Modules\\SalesTaxUs namespace', ['providers' => ['App\\Provider']]];
        yield 'bad host range' => ['supported SemVer constraint', ['compatibility' => ['invoiceshelf' => '*']]];
        yield 'bad extension' => ['ext-name', ['compatibility' => ['extensions' => ['json']]]];
        yield 'bad module dependency' => ['another lowercase kebab-case', ['module_dependencies' => ['sales-tax-us' => '^1.0.0']]];
        yield 'bad dependency range' => ['supported SemVer constraint', ['module_dependencies' => ['other-module' => 'dev-main']]];
        yield 'rollback migration policy' => ['forward-only', ['migration_policy' => 'reversible']];
        yield 'runtime dependency policy' => ['host-provided-only', ['dependency_policy' => 'composer-install']];
        yield 'remote asset' => ['local dist', ['assets' => ['https://cdn.example.test/module.js']]];
        yield 'source asset' => ['local dist', ['assets' => ['resources/module.ts']]];
        yield 'unknown key' => ['unsupported field', ['composer_dependencies' => []]];
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

    public function test_package_validator_requires_built_assets_and_host_provided_composer_dependencies(): void
    {
        $directory = sys_get_temp_dir().'/invoiceshelf-modules-'.bin2hex(random_bytes(8));
        mkdir($directory.'/dist', 0700, true);

        try {
            file_put_contents($directory.'/module.json', json_encode($this->moduleManifest(), JSON_THROW_ON_ERROR));
            file_put_contents($directory.'/composer.json', json_encode([
                'name' => 'invoiceshelf/module-sales-tax-us',
                'license' => 'AGPL-3.0-only',
                'require' => ['php' => '^8.3', 'invoiceshelf/modules' => '^3.0', 'ext-json' => '*'],
            ], JSON_THROW_ON_ERROR));
            file_put_contents($directory.'/dist/module.js', 'built javascript');
            file_put_contents($directory.'/dist/module.css', 'built css');

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
            foreach (['module.json', 'composer.json', 'dist/module.js', 'dist/module.css'] as $file) {
                if (is_file($directory.'/'.$file)) {
                    unlink($directory.'/'.$file);
                }
            }
            if (is_dir($directory.'/dist')) {
                rmdir($directory.'/dist');
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
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
