<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Tests;

use InvalidArgumentException;
use InvoiceShelf\Modules\Ai\Contracts\AiDriver;
use InvoiceShelf\Modules\Ai\Data\AiChatResponse;
use InvoiceShelf\Modules\Registry;
use InvoiceShelf\Modules\Settings\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

class RegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Registry::flush();
    }

    protected function tearDown(): void
    {
        Registry::flush();
        parent::tearDown();
    }

    public function test_register_menu_round_trip(): void
    {
        Registry::registerMenu('sales-tax-us', [
            'title' => 'sales_tax_us::menu.title',
            'link' => '/admin/modules/sales-tax-us/settings',
            'icon' => 'CalculatorIcon',
        ]);

        $this->assertCount(1, Registry::allMenu());
        $this->assertSame(
            [
                'group' => 'modules',
                'group_label' => 'navigation.modules',
                'priority' => 100,
                'title' => 'sales_tax_us::menu.title',
                'link' => '/admin/modules/sales-tax-us/settings',
                'icon' => 'CalculatorIcon',
            ],
            Registry::menuFor('sales-tax-us'),
        );
        $this->assertNull(Registry::menuFor('does-not-exist'));
    }

    public function test_register_menu_allows_overriding_group_and_priority(): void
    {
        Registry::registerMenu('sales-tax-us', [
            'title' => 'sales_tax_us::menu.title',
            'link' => '/admin/modules/sales-tax-us/settings',
            'icon' => 'CalculatorIcon',
            'group' => 'documents',
            'group_label' => 'navigation.documents',
            'priority' => 25,
        ]);

        $menu = Registry::menuFor('sales-tax-us');
        $this->assertSame('documents', $menu['group']);
        $this->assertSame('navigation.documents', $menu['group_label']);
        $this->assertSame(25, $menu['priority']);
    }

    public function test_register_user_menu_round_trip(): void
    {
        Registry::registerUserMenu('sales-tax-us', [
            'title' => 'sales_tax_us::user_menu.title',
            'link' => '/admin/modules/sales-tax-us/support',
            'icon' => 'LifebuoyIcon',
        ]);

        $this->assertCount(1, Registry::allUserMenu());
        $this->assertSame(
            [
                'priority' => 100,
                'title' => 'sales_tax_us::user_menu.title',
                'link' => '/admin/modules/sales-tax-us/support',
                'icon' => 'LifebuoyIcon',
            ],
            Registry::allUserMenu()['sales-tax-us'],
        );
    }

    public function test_flush_clears_user_menu(): void
    {
        Registry::registerUserMenu('a', ['title' => 't', 'link' => '/l', 'icon' => 'i']);

        Registry::flush();

        $this->assertSame([], Registry::allUserMenu());
    }

    public function test_register_settings_round_trip(): void
    {
        Registry::registerSettings('sales-tax-us', [
            'sections' => [
                [
                    'title' => 'sales_tax_us::settings.connection',
                    'fields' => [
                        ['key' => 'api_key', 'type' => 'password', 'rules' => ['required']],
                        ['key' => 'sandbox', 'type' => 'switch', 'default' => false],
                    ],
                ],
            ],
        ]);

        $schema = Registry::settingsFor('sales-tax-us');
        $this->assertInstanceOf(Schema::class, $schema);
        $this->assertCount(1, $schema->sections);
        $this->assertSame('sales_tax_us::settings.connection', $schema->sections[0]['title']);
        $this->assertCount(2, $schema->sections[0]['fields']);
        $this->assertSame('api_key', $schema->sections[0]['fields'][0]['key']);
        $this->assertSame('password', $schema->sections[0]['fields'][0]['type']);
        $this->assertSame(['required'], $schema->sections[0]['fields'][0]['rules']);

        $this->assertCount(1, Registry::allSettings());
        $this->assertNull(Registry::settingsFor('does-not-exist'));
    }

    public function test_settings_schema_field_helper_flattens_sections(): void
    {
        Registry::registerSettings('m', [
            'sections' => [
                [
                    'title' => 'a', 'fields' => [
                        ['key' => 'one', 'type' => 'text'],
                        ['key' => 'two', 'type' => 'text'],
                    ],
                ],
                [
                    'title' => 'b', 'fields' => [
                        ['key' => 'three', 'type' => 'text'],
                    ],
                ],
            ],
        ]);

        $schema = Registry::settingsFor('m');
        $this->assertNotNull($schema);
        $this->assertCount(3, $schema->fields());
        $this->assertSame(['one', 'two', 'three'], array_column($schema->fields(), 'key'));
    }

    public function test_select_field_requires_options(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must declare an .options. array/');

        Registry::registerSettings('m', [
            'sections' => [
                ['title' => 's', 'fields' => [
                    ['key' => 'state', 'type' => 'select'],
                ]],
            ],
        ]);
    }

    public function test_unknown_field_type_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unsupported type/');

        Registry::registerSettings('m', [
            'sections' => [
                ['title' => 's', 'fields' => [
                    ['key' => 'foo', 'type' => 'rainbow-picker'],
                ]],
            ],
        ]);
    }

    public function test_schema_without_sections_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must declare a .sections. array/');

        Registry::registerSettings('m', ['fields' => []]);
    }

    public function test_field_without_key_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must have a non-empty string .key./');

        Registry::registerSettings('m', [
            'sections' => [
                ['title' => 's', 'fields' => [['type' => 'text']]],
            ],
        ]);
    }

    public function test_flush_clears_all_state(): void
    {
        Registry::registerMenu('a', ['title' => 't', 'link' => '/l', 'icon' => 'i']);
        Registry::registerSettings('a', ['sections' => [['title' => 's', 'fields' => []]]]);

        Registry::flush();

        $this->assertSame([], Registry::allMenu());
        $this->assertSame([], Registry::allSettings());
    }

    public function test_select_field_options_are_preserved(): void
    {
        Registry::registerSettings('m', [
            'sections' => [
                ['title' => 's', 'fields' => [
                    ['key' => 'state', 'type' => 'select', 'options' => ['CA' => 'California', 'NY' => 'New York']],
                ]],
            ],
        ]);

        $field = Registry::settingsFor('m')->fields()[0];
        $this->assertSame(['CA' => 'California', 'NY' => 'New York'], $field['options']);
    }

    public function test_field_default_label_falls_back_to_key(): void
    {
        Registry::registerSettings('m', [
            'sections' => [
                ['title' => 's', 'fields' => [['key' => 'foo', 'type' => 'text']]],
            ],
        ]);

        $field = Registry::settingsFor('m')->fields()[0];
        $this->assertSame('foo', $field['label']);
    }

    public function test_register_script_and_style_round_trip(): void
    {
        $script = realpath(__DIR__.'/Fixtures/module.js');
        $style = realpath(__DIR__.'/Fixtures/module.css');
        $this->assertIsString($script);
        $this->assertIsString($style);

        Registry::registerScript('analytics', $script);
        Registry::registerStyle('theme', $style);

        $this->assertSame(['analytics' => $script], Registry::allScripts());
        $this->assertSame(['theme' => $style], Registry::allStyles());
        $this->assertSame($script, Registry::scriptFor('analytics'));
        $this->assertSame($style, Registry::styleFor('theme'));
        $this->assertNull(Registry::scriptFor('does-not-exist'));
        $this->assertNull(Registry::styleFor('does-not-exist'));
    }

    public function test_flush_also_clears_scripts_and_styles(): void
    {
        Registry::registerScript('s', __DIR__.'/Fixtures/module.js');
        Registry::registerStyle('t', __DIR__.'/Fixtures/module.css');

        Registry::flush();

        $this->assertSame([], Registry::allScripts());
        $this->assertSame([], Registry::allStyles());
    }

    #[DataProvider('invalidRuntimeAssets')]
    public function test_remote_missing_and_wrong_extension_runtime_assets_are_rejected(string $method, string $path, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Registry::{$method}('asset', $path);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidRuntimeAssets(): iterable
    {
        yield 'remote script URL' => ['registerScript', 'https://cdn.example.test/module.js', 'existing local file'];
        yield 'missing style file' => ['registerStyle', __DIR__.'/Fixtures/missing.css', 'existing local file'];
        yield 'wrong script extension' => ['registerScript', __DIR__.'/Fixtures/module.css', 'must be a .js file'];
        yield 'wrong style extension' => ['registerStyle', __DIR__.'/Fixtures/module.js', 'must be a .css file'];
    }

    public function test_register_driver_round_trip(): void
    {
        Registry::flushDrivers();

        Registry::registerDriver('exchange_rate', 'fake_provider', [
            'class' => 'FakeDriver',
            'label' => 'fake.label',
        ]);

        $this->assertSame(
            ['class' => 'FakeDriver', 'label' => 'fake.label'],
            Registry::driverMeta('exchange_rate', 'fake_provider'),
        );
        $this->assertArrayHasKey('fake_provider', Registry::allDrivers('exchange_rate'));
    }

    public function test_register_exchange_rate_driver_is_a_typed_wrapper(): void
    {
        Registry::flushDrivers();

        Registry::registerExchangeRateDriver('fake_provider', [
            'class' => 'FakeDriver',
            'label' => 'fake.label',
        ]);

        $this->assertNotNull(Registry::driverMeta('exchange_rate', 'fake_provider'));
    }

    public function test_register_ai_driver_is_a_typed_wrapper(): void
    {
        Registry::flushDrivers();

        Registry::registerAiDriver('fake_ai_provider', [
            'class' => FakeAiDriver::class,
            'label' => 'fake.ai.label',
            'supported_roles' => ['chat', 'text_generation'],
            'suggested_models' => [['value' => 'fake-model', 'label' => 'Fake model']],
            'config_fields' => [],
        ]);

        $meta = Registry::driverMeta('ai', 'fake_ai_provider');
        $this->assertNotNull($meta);
        $this->assertSame(FakeAiDriver::class, $meta['class']);
        $this->assertSame(['chat', 'text_generation'], $meta['supported_roles']);
    }

    public function test_exchange_rate_and_ai_drivers_live_in_separate_type_buckets(): void
    {
        Registry::flushDrivers();

        Registry::registerExchangeRateDriver('shared_name', ['class' => 'RateDriver', 'label' => 'rate']);
        Registry::registerAiDriver('shared_name', $this->aiMeta());

        $this->assertSame('RateDriver', Registry::driverMeta('exchange_rate', 'shared_name')['class']);
        $this->assertSame(FakeAiDriver::class, Registry::driverMeta('ai', 'shared_name')['class']);
    }

    public function test_ai_driver_rejects_duplicate_identifiers_without_replacing_the_first_registration(): void
    {
        Registry::flushDrivers();
        Registry::registerAiDriver('fake', $this->aiMeta());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already registered');

        Registry::registerAiDriver('fake', $this->aiMeta(['label' => 'replacement']));
    }

    public function test_ai_driver_registration_is_idempotent_for_identical_metadata(): void
    {
        Registry::flushDrivers();
        Registry::registerAiDriver('fake', $this->aiMeta());
        Registry::registerAiDriver('fake', $this->aiMeta());

        $this->assertSame($this->aiMeta(), Registry::driverMeta('ai', 'fake'));
    }

    public function test_generic_driver_registration_remains_permissive_and_can_replace_a_driver(): void
    {
        Registry::flushDrivers();
        Registry::registerDriver('pdf', 'same', ['label' => 'first']);
        Registry::registerDriver('pdf', 'same', ['label' => 'second']);

        $this->assertSame(['label' => 'second'], Registry::driverMeta('pdf', 'same'));
    }

    #[DataProvider('invalidAiDriverRegistrations')]
    public function test_ai_driver_rejects_malformed_metadata(string $name, array $meta, string $message): void
    {
        Registry::flushDrivers();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Registry::registerAiDriver($name, $meta);
    }

    /** @return iterable<string, array{string, array<string, mixed>, string}> */
    public static function invalidAiDriverRegistrations(): iterable
    {
        $valid = self::validAiMeta();

        yield 'blank identifier' => ['', $valid, 'stable, non-empty identifier'];
        yield 'unstable identifier' => ['not a driver', $valid, 'stable, non-empty identifier'];
        yield 'missing class' => ['fake', array_diff_key($valid, ['class' => true]), 'concrete class extending'];
        yield 'non-driver class' => ['fake', array_replace($valid, ['class' => self::class]), 'concrete class extending'];
        yield 'abstract driver class' => ['fake', array_replace($valid, ['class' => AbstractFakeAiDriver::class]), 'concrete class extending'];
        yield 'blank label' => ['fake', array_replace($valid, ['label' => ' ']), 'label must be a non-empty string'];
        yield 'empty roles' => ['fake', array_replace($valid, ['supported_roles' => []]), 'supported_roles must be a non-empty unique list'];
        yield 'duplicate roles' => ['fake', array_replace($valid, ['supported_roles' => ['chat', 'chat']]), 'supported_roles must be a non-empty unique list'];
        yield 'unsupported role' => ['fake', array_replace($valid, ['supported_roles' => ['images']]), 'supported_roles must be a non-empty unique list'];
        yield 'non-list roles' => ['fake', array_replace($valid, ['supported_roles' => ['role' => 'chat']]), 'supported_roles must be a non-empty unique list'];
        yield 'invalid website' => ['fake', array_replace($valid, ['website' => 1]), 'website must be a string'];
        yield 'invalid default base URL' => ['fake', array_replace($valid, ['default_base_url' => []]), 'default_base_url must be a string'];
        yield 'missing suggested models' => ['fake', array_diff_key($valid, ['suggested_models' => true]), 'suggested_models must be a list'];
        yield 'non-list suggested models' => ['fake', array_replace($valid, ['suggested_models' => ['model' => ['value' => 'm', 'label' => 'M']]]), 'suggested_models must be a list'];
        yield 'model with an empty value' => ['fake', array_replace($valid, ['suggested_models' => [['value' => '', 'label' => 'M']]]), 'non-empty string value and label'];
        yield 'model with an unknown field' => ['fake', array_replace($valid, ['suggested_models' => [['value' => 'm', 'label' => 'M', 'id' => 'm']]]), 'non-empty string value and label'];
        yield 'missing config fields' => ['fake', array_diff_key($valid, ['config_fields' => true]), 'config_fields must be an array'];
        yield 'invalid config fields' => ['fake', array_replace($valid, ['config_fields' => 'not-an-array']), 'config_fields must be an array'];
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    private function aiMeta(array $changes = []): array
    {
        return array_replace(self::validAiMeta(), $changes);
    }

    /** @return array<string, mixed> */
    private static function validAiMeta(): array
    {
        return [
            'class' => FakeAiDriver::class,
            'label' => 'fake.ai.label',
            'supported_roles' => ['chat', 'text_generation'],
            'suggested_models' => [['value' => 'fake-model', 'label' => 'Fake model']],
            'config_fields' => [],
        ];
    }

    public function test_all_drivers_returns_empty_array_for_unknown_type(): void
    {
        Registry::flushDrivers();

        $this->assertSame([], Registry::allDrivers('pdf'));
    }

    public function test_driver_meta_returns_null_for_unknown_driver(): void
    {
        Registry::flushDrivers();

        $this->assertNull(Registry::driverMeta('exchange_rate', 'definitely_not_a_real_driver'));
    }

    public function test_flush_does_not_clear_driver_registrations(): void
    {
        Registry::registerExchangeRateDriver('persists', [
            'class' => 'PersistDriver',
            'label' => 'persist.label',
        ]);

        Registry::flush();

        $this->assertNotNull(Registry::driverMeta('exchange_rate', 'persists'));

        Registry::flushDrivers();
    }

    public function test_flush_drivers_clears_driver_registrations(): void
    {
        Registry::registerExchangeRateDriver('a', ['class' => 'A', 'label' => 'a']);
        Registry::registerExchangeRateDriver('b', ['class' => 'B', 'label' => 'b']);

        Registry::flushDrivers();

        $this->assertSame([], Registry::allDrivers('exchange_rate'));
    }
}

class FakeAiDriver extends AiDriver
{
    public function chatCompletion(array $messages, string $model, array $tools = [], array $options = []): AiChatResponse
    {
        return new AiChatResponse('ok');
    }

    public function textCompletion(string $prompt, string $model, array $options = []): string
    {
        return 'ok';
    }

    public function validateConnection(): array
    {
        return [];
    }
}

abstract class AbstractFakeAiDriver extends AiDriver {}
