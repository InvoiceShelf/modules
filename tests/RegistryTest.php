<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Tests;

use InvalidArgumentException;
use InvoiceShelf\Modules\Registry;
use InvoiceShelf\Modules\Settings\Schema;
use PHPUnit\Framework\TestCase;

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
                'title' => 'sales_tax_us::menu.title',
                'link' => '/admin/modules/sales-tax-us/settings',
                'icon' => 'CalculatorIcon',
            ],
            Registry::menuFor('sales-tax-us'),
        );
        $this->assertNull(Registry::menuFor('does-not-exist'));
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
        Registry::registerScript('analytics', '/path/to/analytics.js');
        Registry::registerStyle('theme', '/path/to/theme.css');

        $this->assertSame(['analytics' => '/path/to/analytics.js'], Registry::allScripts());
        $this->assertSame(['theme' => '/path/to/theme.css'], Registry::allStyles());
        $this->assertSame('/path/to/analytics.js', Registry::scriptFor('analytics'));
        $this->assertSame('/path/to/theme.css', Registry::styleFor('theme'));
        $this->assertNull(Registry::scriptFor('does-not-exist'));
        $this->assertNull(Registry::styleFor('does-not-exist'));
    }

    public function test_flush_also_clears_scripts_and_styles(): void
    {
        Registry::registerScript('s', '/s.js');
        Registry::registerStyle('t', '/t.css');

        Registry::flush();

        $this->assertSame([], Registry::allScripts());
        $this->assertSame([], Registry::allStyles());
    }
}
