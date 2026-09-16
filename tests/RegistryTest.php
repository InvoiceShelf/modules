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

    public function test_register_ability_round_trip(): void
    {
        Registry::registerAbility('tasks-projects', ['ability' => 'view-project', 'name' => 'View Projects']);
        Registry::registerAbility('tasks-projects', ['ability' => 'manage-project', 'name' => 'Manage Projects', 'owner_only' => true]);
        Registry::registerAbility('stock-control', ['ability' => 'adjust-stock', 'name' => 'Adjust Stock', 'depends_on' => ['view-item']]);

        $this->assertSame(
            [
                'ability' => 'tasks-projects:view-project',
                'name' => 'View Projects',
                'model' => null,
                'depends_on' => [],
                'owner_only' => false,
            ],
            Registry::abilitiesFor('tasks-projects')[0],
        );
        $this->assertSame(
            ['ability', 'name', 'model', 'depends_on', 'owner_only'],
            array_keys(Registry::abilitiesFor('tasks-projects')[0]),
        );
        $this->assertSame(
            ['tasks-projects:view-project', 'tasks-projects:manage-project'],
            array_column(Registry::abilitiesFor('tasks-projects'), 'ability'),
        );
        $this->assertTrue(Registry::abilitiesFor('tasks-projects')[1]['owner_only']);
        $this->assertSame(['view-item'], Registry::abilitiesFor('stock-control')[0]['depends_on']);
        $this->assertSame(
            ['tasks-projects:view-project', 'tasks-projects:manage-project', 'stock-control:adjust-stock'],
            array_column(Registry::allAbilities(), 'ability'),
        );
    }

    public function test_ability_id_namespaces_an_ability_with_its_module_slug(): void
    {
        $this->assertSame('tasks-projects:view-project', Registry::abilityId('tasks-projects', 'view-project'));
    }

    public function test_ability_registration_is_idempotent_for_an_identical_entry(): void
    {
        Registry::registerAbility('tasks-projects', ['ability' => 'view-project', 'name' => 'View Projects']);
        Registry::registerAbility('tasks-projects', ['ability' => 'view-project', 'name' => 'View Projects']);

        $this->assertCount(1, Registry::abilitiesFor('tasks-projects'));
    }

    public function test_ability_rejects_a_conflicting_duplicate_without_replacing_the_first_registration(): void
    {
        Registry::registerAbility('tasks-projects', ['ability' => 'view-project', 'name' => 'View Projects']);

        try {
            Registry::registerAbility('tasks-projects', ['ability' => 'view-project', 'name' => 'Browse Projects']);
            $this->fail('Expected a conflicting ability registration to throw.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString("tasks-projects:view-project' is already registered", $exception->getMessage());
            $this->assertStringContainsString("module 'tasks-projects'", $exception->getMessage());
        }

        $this->assertSame('View Projects', Registry::abilitiesFor('tasks-projects')[0]['name']);
    }

    public function test_ability_depends_on_accepts_plain_host_and_namespaced_module_ids(): void
    {
        Registry::registerAbility('tasks-projects', [
            'ability' => 'view-project',
            'name' => 'View Projects',
            'depends_on' => ['view-customer', Registry::abilityId('tasks-projects', 'view-task')],
        ]);

        $this->assertSame(
            ['view-customer', 'tasks-projects:view-task'],
            Registry::abilitiesFor('tasks-projects')[0]['depends_on'],
        );
    }

    #[DataProvider('invalidAbilityRegistrations')]
    public function test_register_ability_rejects_malformed_entries(string $slug, array $entry, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Registry::registerAbility($slug, $entry);
    }

    /** @return iterable<string, array{string, array<string, mixed>, string}> */
    public static function invalidAbilityRegistrations(): iterable
    {
        $valid = ['ability' => 'view-project', 'name' => 'View Projects'];

        yield 'invalid slug' => ['Tasks_Projects', $valid, "Module slug 'Tasks_Projects' must be lower-case kebab-case"];
        yield 'missing ability' => ['tasks-projects', ['name' => 'View Projects'], "must declare a non-empty string 'ability' key"];
        yield 'namespaced ability' => ['tasks-projects', array_replace($valid, ['ability' => 'tasks-projects:view-project']), 'must not contain a colon'];
        yield 'uppercase ability' => ['tasks-projects', array_replace($valid, ['ability' => 'viewProject']), "'viewProject' must be lower-case kebab-case"];
        yield 'blank name' => ['tasks-projects', array_replace($valid, ['name' => ' ']), 'name must be a non-empty string'];
        yield 'model-scoped ability' => ['tasks-projects', array_replace($valid, ['model' => 'App\Models\Project']), 'model must be absent or null'];
        yield 'unknown key' => ['tasks-projects', array_replace($valid, ['group' => 'projects']), "unsupported key 'group'"];
        yield 'non-list depends_on' => ['tasks-projects', array_replace($valid, ['depends_on' => ['project' => 'view-project']]), 'depends_on must be a list of ability ids'];
        yield 'invalid depends_on entry' => ['tasks-projects', array_replace($valid, ['depends_on' => ['View_Project']]), 'depends_on entries must be plain host ability ids'];
        yield 'non-bool owner_only' => ['tasks-projects', array_replace($valid, ['owner_only' => 'yes']), 'owner_only must be a boolean'];
    }

    public function test_abilities_for_an_unknown_slug_is_empty(): void
    {
        $this->assertSame([], Registry::abilitiesFor('unknown'));
        $this->assertSame([], Registry::allAbilities());
    }

    public function test_flush_clears_abilities(): void
    {
        Registry::registerAbility('tasks-projects', ['ability' => 'view-project', 'name' => 'View Projects']);

        Registry::flush();

        $this->assertSame([], Registry::allAbilities());
        $this->assertSame([], Registry::abilitiesFor('tasks-projects'));
    }

    public function test_menu_placement_keys_are_kept_and_validated(): void
    {
        Registry::registerMenu('placed', ['title' => 'Placed', 'link' => '/admin/modules/placed', 'icon' => 'FolderIcon', 'group' => 'documents', 'priority' => 25]);

        self::assertSame('documents', Registry::menuFor('placed')['group']);
        self::assertSame(25, Registry::menuFor('placed')['priority']);
    }

    public function test_menu_priority_must_be_an_integer(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Menu entry 'bad' priority must be an integer.");

        Registry::registerMenu('bad', ['title' => 'Bad', 'link' => '/x', 'icon' => 'FolderIcon', 'priority' => '10']);
    }

    public function test_user_menu_group_must_be_a_non_empty_string(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Menu entry 'bad' group must be a non-empty string.");

        Registry::registerUserMenu('bad', ['title' => 'Bad', 'link' => '/x', 'icon' => 'FolderIcon', 'group' => '']);
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
