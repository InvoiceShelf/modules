# InvoiceShelf Modules

A thin extension package on top of [`nwidart/laravel-modules`](https://github.com/nWidart/laravel-modules) that adds an InvoiceShelf-specific registry for module-contributed sidebar entries and settings schemas.

## What it provides

- **`InvoiceShelf\Modules\Registry`** — a static registry that modules call from their `ServiceProvider::boot()` to declare:
  - A sidebar entry (title, link, icon) that the host app renders in the company sidebar's "Modules" group.
  - A settings schema (sections of typed fields) that the host app renders generically as a form via `BaseSchemaForm.vue`, with values stored per-company.
- **`InvoiceShelf\Modules\Settings\Schema` / `FieldType`** — a value object + enum that lock down the supported field types (`text`, `password`, `textarea`, `switch`, `number`, `select`, `multiselect`) and validate the schema shape at registration time.

The actual module loading, file generation, migration, and provider registration are all handled by upstream `nwidart/laravel-modules` (required as a composer dependency).

## Usage from inside a module

```php
use InvoiceShelf\Modules\Registry;

class SalesTaxUsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Registry::registerMenu('sales-tax-us', [
            'title' => 'sales_tax_us::menu.title',
            'link'  => '/admin/modules/sales-tax-us/settings',
            'icon'  => 'CalculatorIcon',
        ]);

        Registry::registerSettings('sales-tax-us', [
            'sections' => [
                [
                    'title'  => 'sales_tax_us::settings.connection',
                    'fields' => [
                        ['key' => 'api_key', 'type' => 'password', 'rules' => ['required']],
                        ['key' => 'sandbox', 'type' => 'switch',   'default' => false],
                    ],
                ],
            ],
        ]);
    }
}
```

Because `nwidart/laravel-modules` only boots providers for currently-activated modules, the registry naturally only contains active modules at request time — no extra filtering needed.

## License

MIT. See [LICENSE.md](LICENSE.md).
