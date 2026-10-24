# InvoiceShelf Modules SDK

The MIT-licensed SDK for official [InvoiceShelf](https://invoiceshelf.com) v3 modules. It extends [`nwidart/laravel-modules`](https://github.com/nWidart/laravel-modules) with the contracts used by InvoiceShelf's module host and signed marketplace packages.

SDK **3.5** supports Module API **1.4**. First-party module packages live in their own repositories and are licensed `AGPL-3.0-only`; this SDK remains MIT.

## What a module can add

- Sidebar entries and schema-driven, per-company settings.
- Local, compiled JavaScript and CSS registered with the host.
- Typed frontend contributions through [`frontend/index.d.ts`](frontend/index.d.ts): full-page routes, settings pages, menus, HTTP access, translations, notifications, and lifecycle events. The host supplies the Vue, router, Axios, and i18n instances—modules do not bundle another framework runtime.
- Actions, panels, form sections and save hooks on host records (invoices, estimates, customers, payments and more), plus entries in the global New menu.
- Company abilities contributed to the host's authorization catalogue, so a module can ship its own permissions instead of reusing host ones.
- Narrow host contracts under [`src/Contracts/Host`](src/Contracts/Host) for settings, authorization, and read-only company-data queries. `CompanyDataReader` covers the AI assistant's built-in queries plus `companyMembers()` and `existingInvoiceIds()` for modules that assign work to members or stamp their entries against invoices. Module code must not depend on InvoiceShelf Eloquent models.
- AI drivers through [`src/Ai`](src/Ai): extend `AiDriver`, return `AiChatResponse`, and throw `AiException` for safe, localizable provider failures.

The host controls discovery, installation, activation, migrations, and provider registration. It never runs Composer: a module either uses only what the host already ships, or brings the third-party packages it needs pre-built in `bundle/` (see [Dependencies](#dependencies)).

## Compatibility

Declare compatibility in `module.json`, then test it against the host versions you support.

| Concern | Current contract |
| --- | --- |
| SDK | `invoiceshelf/modules` `^3.5` |
| Module API | `^1.3.0`, or `^1.4.0` for a module with bundled dependencies |
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
    "module_api": "^1.3.0",
    "php": "^8.4.0",
    "extensions": ["ext-json"]
  },
  "migration_policy": "reversible",
  "dependency_policy": "host-provided-only",
  "uninstall": {
    "data_cleanup": "Modules\\SalesTaxUs\\Lifecycle\\DataCleanup"
  },
  "assets": ["dist/init.js", "dist/style.css"]
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

## Dependencies

`dependency_policy` says where a module's PHP code comes from. Nothing is resolved on the server: the host never runs Composer, and its own `vendor/` is read-only.

| Policy | composer.json may require | Use it when |
| --- | --- | --- |
| `host-provided-only` | `php`, `ext-*`, `invoiceshelf/modules` | The module needs nothing beyond Laravel, the SDK and what the host already ships. |
| `bundled` | the above plus third-party packages | The module needs a library the host does not ship, such as a payment provider's SDK. Requires schema 2 and `module_api` `^1.4.0`. |

### How a bundle is built

A bundled module declares its packages in `composer.json` as usual and runs:

```bash
vendor/bin/invoiceshelf-module bundle . --host-lock=../../composer.lock   # the host's lock; --update to re-resolve
```

The command resolves the module's packages in a throwaway Composer project where every **production** package of that host is a root `replace` at its locked version. Composer therefore reuses what the host ships and installs only what it lacks; a package that needs a version the host does not ship is a resolver error, never a silent second copy. The command then writes:

- `bundle/<vendor>/<package>/…`: the package files, with normalized permissions;
- `bundle/classmap.php`: every class, relative to `bundle/`, which the host reads as data and loads **after** its own autoloader, so a host copy always wins;
- `bundle/bundle.json`: each package's version, reference, license, PHP range and extensions, the host packages the module relies on (constraints kept per requirer), and any Composer `files` entries with the functions they declare;
- `bundle.lock` next to `composer.json`: the pinned resolution.

Commit `bundle.lock` and review its diff like code: signing a release vouches for everything inside it. `bundle/` itself is generated: gitignore it, and keep your own tools out of it (for Pint, `"exclude": ["bundle"]` in `pint.json`). Without `--update` the command installs exactly `bundle.lock` and fails when it no longer fits the host or `composer.json`, or when your own `composer.lock` holds different versions of a bundled package (tests run on `vendor/`, the archive ships `bundle/`). The same tree is written byte for byte on every machine.

The build refuses a bundle the host cannot load safely: a license an AGPL-3.0-only module cannot convey (Composer's license list counts as alternatives), a Composer plugin, a class or function under `App\`, `Composer\`, `Database\`, `Illuminate\`, `InvoiceShelf\`, `Laravel\`, `Modules\`, `Nwidart\` or `Tests\`, an extension that `compatibility.extensions` does not list (core extensions such as `ext-json` and `ext-pcre` excepted), a symbolic link, or more than 9,000 files. It warns when a bundled package is also a development dependency of the host, because a local host then loads its own copy.

### What the host checks

On marketplace install, local `install:module`, enabling a module, and every container start (`modules:reconcile`), the host refuses or disables a bundled module when an installed host package no longer satisfies a recorded constraint, a bundled package's PHP range excludes the running PHP, a bundled package or class is also provided by the host or another module, or a bundled function already exists. Composer `files` entries are loaded only for enabled modules.

### Local development

Build the bundle against the lock of the host you run (for a sibling checkout, `--host-lock=../InvoiceShelf/composer.lock`; for a module placed in the host's `Modules/`, `--host-lock=../../composer.lock`), then `php artisan install:module <Name> <version>`. Rebuild when you change a package or update the host. A local host has its development packages installed, and those win over a bundled copy; the release workflow builds against the production package set.

## Developing a module

Install the SDK in the module project, then use Laravel Modules as usual:

```bash
composer require invoiceshelf/modules:^3.4
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

### Sidebar placement

`registerMenu` and `registerUserMenu` accept two placement keys besides `title`, `link` and `icon`:

- `group`: the sidebar group the entry joins. The default is `modules`, rendered last with the
  "Modules" label. A module may join a core group instead: `main` (Dashboard 10, Customers 20,
  Items 30), `documents` (Estimates 10, Invoices 20, Payments 30, Expenses 40) or `admin`
  (Members 20, Reports 30, Settings 40).
- `priority`: an integer; lower sorts first inside the group, default `100`. Entries with equal
  priority keep registration order, so official modules set explicit values (`10`, `20`, ...).
  Groups keep the order the host sends them in: the core groups first, then module groups in
  registration order, so a low priority never lifts a module group above the core ones.

```php
Registry::registerMenu('tasks-projects', [
    'title'    => 'tasksprojects::menu.title',
    'link'     => '/admin/modules/tasks-projects',
    'icon'     => 'ClipboardDocumentListIcon',
    'priority' => 10,
]);
```

### Abilities

`Registry::registerAbility()` adds a module's own permissions to the host's ability catalogue. Every
ability is stored namespaced as `{slug}:{ability}`, so a module can never collide with a host ability
or with another module:

```php
Registry::registerAbility('sales-tax-us', [
    'ability' => 'view-filing',
    'name' => 'View Tax Filings',
    'depends_on' => ['view-invoice', Registry::abilityId('sales-tax-us', 'view-rate')],
    'owner_only' => false,
]);
```

`ability` is plain kebab-case and `name` is the label shown in the role editor. `depends_on` lists
abilities implied by this one: host abilities in plain form (`view-invoice`), the module's own in
namespaced form via `Registry::abilityId()`. Module abilities are never model-scoped.

The host grants a module's abilities to owner roles when the module is enabled and removes them when
it is uninstalled; other roles get them through the role editor. Frontend pages must gate on the same
namespaced id through `meta.ability` (see below), and re-registering an identical entry is a no-op
while a conflicting redefinition throws.

### Full-page routes

`extensions.registerPage()` mounts a module page at `/admin/modules/{slug}/{path}`, where `{slug}` is
the `module.json` slug. Declare `meta.ability` with the namespaced ability id so the host route guard
can check it, and add `children` for sub-routes relative to the page. The `settings` path is reserved
by the host for the schema-rendered settings page.

Point the `Registry::registerMenu` link at `/admin/modules/{slug}` for a module with its own pages,
and at `/admin/modules/{slug}/settings` for a settings-only module.

`registerPage`, `Registry::registerAbility`, and the `CompanyDataReader::companyMembers()` and
`existingInvoiceIds()` queries are Module API **1.3.0** additions. Declare
`"module_api": "^1.3.0"` in `module.json` before using them.

### Extending host records

A module can add to the host's own screens without importing them. Every contribution takes a stable `id`, an optional `priority` (lower first) and `visible` predicate, and returns a teardown; the component receives a `context` prop.

| Entity | List row action | Detail action and panel | Form action, section and save hook |
| --- | --- | --- | --- |
| `invoice` | yes | yes | yes |
| `estimate` | yes | yes | yes |
| `recurring-invoice` | yes | yes | |
| `customer` | yes | yes | yes |
| `payment` | yes | yes | |
| `expense` | yes | | |
| `item` | yes | | |

```ts
extensions.registerEntityPanel('invoice', { id: 'einvoicing.status', component: EInvoiceStatusPanel })
extensions.registerEntityAction('invoice', 'detail', { id: 'einvoicing.download-xml', component: DownloadXmlButton })
extensions.registerEntityAction('customer', 'list', { id: 'crm.open-in-crm', component: OpenInCrm, visible: () => featureOn() })
extensions.registerEntityFormSection('customer', { id: 'einvoicing.peppol-id', component: PeppolIdField })

extensions.registerEntitySaveHook('customer', {
  id: 'einvoicing.save-peppol-id',
  async handle(customer, context) {
    await extensions.client.put(`/api/v1/e-invoicing/customers/${customer.id}`, pendingPeppolId())
    return false // keep the host's navigation
  },
})

extensions.registerCreateAction({ id: 'tasks.new', label: 'tasks.new_task', icon: 'ClipboardDocumentListIcon', to: '/admin/modules/tasks-projects/new', ability: 'tasks-projects:create-task' })
```

- **Contexts.** List and detail actions and panels get an `EntityContext`: `record()` returns a frozen `EntityRecord` (id, number, status, customer, totals in minor units, currency), never a host store object, `can()` checks abilities and `refresh()` reloads the host view after the module changed the record. Form actions and sections get an `EntityFormContext` with `isEdit()`, `record()` while editing and, on document forms, the live total, currency and dates.
- **Save hooks** run after the host saved the record, on create and update. Return `true` only to take over the next navigation. A hook that throws or rejects is logged and shown to the user as a warning; the record stays saved and the host navigates as usual, so a failing module never makes a user save twice.
- A slot that nobody contributes to renders nothing at all.

These slots are Module API **1.4.0** additions; declare `"module_api": "^1.4.0"` to use them.

### Thin clients

The InvoiceShelf mobile clients run the same frontend as the web app, but as a static app package
pointed at whichever server the user signed in to. A module that follows the SDK already works
there; nothing about the SDK, the manifest or the install flow changes for it. Four rules are what
make that true:

- **Call the API only through `extensions.client`.** Never a bare `fetch`, never an axios instance
  of your own. Only the host client carries the server base URL and the bearer token, so in a client
  `fetch('/api/v1/...')` resolves against the app package, where there is no server and no
  credentials to send.
- **Never hardcode `/api` or `/storage` as a root-relative URL** in a template or in code.
  Root-relative is not server-relative in a client: the root is the app package. A path handed to
  `extensions.client` is fine, because the host client resolves it against the server. For anything
  else, such as a stored file, the host exposes no asset-URL helper today, so ask for one rather
  than assembling a URL in the module.
- **Ship self-contained CSS.** The host's release bundle is built in CI with no `Modules/` directory
  present, so the host's Tailwind scan never sees module markup and cannot emit a class for it.
  Everything your markup needs must come out of your own compiled stylesheet. The host's reset and
  its theme custom properties are shared and may be relied on; a host utility class may not.
- **Register scripts and styles as local paths, never as remote http(s) URLs.** An operator cannot
  add CORS headers to an origin they do not control, so the client manifest flags such a script as
  unsupported and clients skip it. The module then keeps working on the web and is silently absent
  on mobile.

A client loads modules from the server it is connected to, at boot. It reads a public manifest
listing each enabled module's script and style as absolute, versioned URLs, adds one
`<link rel="stylesheet">` per style, then imports the scripts one at a time, in manifest order,
before `window.InvoiceShelf.start()`. The order is fixed and the imports are sequential because
modules register through `window.InvoiceShelf.booting()` and must see the same order the web
shell gives them. Each import runs under its own timeout, and a module that fails or times out is
reported and skipped rather than allowed to block boot, so a module broken in a client costs its
own features and not the whole app.

The full contract, including the client manifest, the CORS rules and the version gates on the server
side, is `thin-clients.md` in the private InvoiceShelf specs repository.

### Validating a package

Validate both the manifest and the distributable package before every release:

```bash
vendor/bin/invoiceshelf-module validate-module module.json
vendor/bin/invoiceshelf-module validate-package .
```

`validate-package` checks the manifest, providers, migrations, declared assets, and the dependency policy: for a bundled module, also `bundle/` against `bundle.json` (reading `classmap.php` as data, never running it). The CLI also validates and canonicalizes generated `release-manifest.json` files.

## Releasing an official module

Every release is a deterministic signed ZIP, built in CI from an exact, unprefixed SemVer tag matching `module.json` (for example, `1.2.3`). The reusable workflow validates source and compiled assets, creates the package and signed release manifest, then registers it with the InvoiceShelf marketplace.

See [RELEASING.md](RELEASING.md) for the protected GitHub environment configuration and release workflow details. Never commit a signing key or marketplace token.

## License

This SDK is MIT-licensed; see [LICENSE.md](LICENSE.md). Official packages generated from its stubs default to `AGPL-3.0-only` and must include the source required to reproduce every shipped `dist/` asset.
