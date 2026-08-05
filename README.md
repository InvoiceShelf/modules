# InvoiceShelf Modules SDK

The MIT-licensed SDK for official [InvoiceShelf](https://invoiceshelf.com) v3 modules. It extends [`nwidart/laravel-modules`](https://github.com/nWidart/laravel-modules) with the contracts used by InvoiceShelf's module host and signed marketplace packages.

SDK **3.3** supports Module API **1.2**. First-party module packages live in their own repositories and are licensed `AGPL-3.0-only`; this SDK remains MIT.

## What a module can add

- Sidebar entries and schema-driven, per-company settings.
- Local, compiled JavaScript and CSS registered with the host.
- Typed frontend contributions through [`frontend/index.d.ts`](frontend/index.d.ts): routes, menus, HTTP access, translations, notifications, and lifecycle events. The host supplies the Vue, router, Axios, and i18n instances—modules do not bundle another framework runtime.
- Narrow host contracts under [`src/Contracts/Host`](src/Contracts/Host) for settings, authorization, and the AI assistant's read-only company-data queries. Module code must not depend on InvoiceShelf Eloquent models.
- AI drivers through [`src/Ai`](src/Ai): extend `AiDriver`, return `AiChatResponse`, and throw `AiException` for safe, localizable provider failures.

The host controls discovery, installation, activation, migrations, and provider registration. Official packages are not a general-purpose runtime Composer installer for arbitrary third-party code.

## Compatibility

Declare compatibility in `module.json`, then test it against the host versions you support.

| Concern | Current contract |
| --- | --- |
| SDK | `invoiceshelf/modules` `^3.3` |
| Module API | `^1.2.0` |
| Host application | Your explicit InvoiceShelf 3.x range |
| PHP | The range your module actually supports |

Composer caret constraints do **not** include prereleases. For example, `^3.0.0` starts at the final `3.0.0`, so it excludes `3.0.0-alpha.*`. A module intended for the current v3 preview must explicitly opt in, for example:

```json
"invoiceshelf": ">=3.0.0-alpha.2 <4.0.0"
```

Use `^3.0.0` only when you mean final 3.x releases. The generated stub is a starting point; update its PHP and InvoiceShelf constraints before releasing.

## Module manifest and lifecycle

New official modules use schema version 2. Their identity is permanent after the first marketplace release: keep `slug` and loader `name` unchanged. The manifest also declares an exact SemVer version, license, compatibility, required PHP extensions, module dependencies, local assets, and uninstall behavior.

```json
{
  "schema_version": 2,
  "name": "SalesTaxUs",
  "alias": "salestaxus",
  "slug": "sales-tax-us",
  "version": "1.2.3",
  "license": "AGPL-3.0-only",
  "providers": [
    "Modules\\SalesTaxUs\\Providers\\SalesTaxUsServiceProvider"
  ],
  "compatibility": {
    "invoiceshelf": ">=3.0.0-alpha.2 <4.0.0",
    "module_api": "^1.2.0",
    "php": "^8.4.0",
    "extensions": ["ext-json"]
  },
  "migration_policy": "reversible",
  "dependency_policy": "host-provided-only",
  "uninstall": {
    "data_cleanup": "Modules\\SalesTaxUs\\Lifecycle\\DataCleanup"
  },
  "assets": ["dist/module.js", "dist/module.css"]
}
```

Schema-v2 migrations must be reversible. Each migration has one concrete Laravel `Migration` class with non-empty `up(): void` and `down(): void` methods. The package validator rejects destructive operations in `up()` and runs no dependency resolver at installation time, so commit the local compiled assets declared in `assets`.

When an administrator chooses **Remove module data**, the host calls the module's cleanup hook while its tables still exist, runs every migration's `down()`, and removes host-owned module settings. Point `uninstall.data_cleanup` at a concrete, idempotent module-owned class:

```php
<?php

namespace Modules\SalesTaxUs\Lifecycle;

use InvoiceShelf\Modules\Contracts\DataCleanup as DataCleanupContract;

final class DataCleanup implements DataCleanupContract
{
    public function cleanup(): void
    {
        // Delete module-owned files, external resources, or shared-table rows.
    }
}
```

An intentionally empty method is valid for a module with nothing beyond reversible migrations. Throwing from `cleanup()` stops the uninstall so it can be retried safely.

## Developing a module

Install the SDK in the module project, then use Laravel Modules as usual:

```bash
composer require invoiceshelf/modules:^3.3
php artisan module:make SalesTaxUs
```

Register server-side contributions from the module service provider:

```php
use InvoiceShelf\Modules\Registry;

Registry::registerMenu('sales-tax-us', [
    'title' => 'sales_tax_us::menu.title',
    'link' => '/admin/modules/sales-tax-us/settings',
    'icon' => 'CalculatorIcon',
]);
```

Validate both the manifest and the distributable package before every release:

```bash
vendor/bin/invoiceshelf-module validate-module module.json
vendor/bin/invoiceshelf-module validate-package .
```

`validate-package` checks the manifest, providers, migrations, declared assets, and the closed host-provided dependency policy. The CLI also validates and canonicalizes generated `release-manifest.json` files.

## Releasing an official module

Every release is a deterministic signed ZIP, built in CI from an exact, unprefixed SemVer tag matching `module.json` (for example, `1.2.3`). The reusable workflow validates source and compiled assets, creates the package and signed release manifest, then registers it with the InvoiceShelf marketplace.

See [RELEASING.md](RELEASING.md) for the protected GitHub environment configuration and release workflow details. Never commit a signing key or marketplace token.

## License

This SDK is MIT-licensed; see [LICENSE.md](LICENSE.md). Official packages generated from its stubs default to `AGPL-3.0-only` and must include the source required to reproduce every shipped `dist/` asset.
