# InvoiceShelf Modules

The MIT-licensed SDK for InvoiceShelf v3 modules. It extends [`nwidart/laravel-modules`](https://github.com/nWidart/laravel-modules) with registry helpers and the versioned, signed marketplace contract used by the secure module installer.

The SDK remains MIT. Official first-party modules are separate repositories and packages, and must be licensed `AGPL-3.0-only`; the generator's `composer.json` stub defaults to that license.

## What it provides

- **`InvoiceShelf\Modules\Registry`** — a static registry that modules call from their `ServiceProvider::boot()` to declare:
  - A sidebar entry (title, link, icon) that the host app renders in the company sidebar's "Modules" group.
  - A settings schema (sections of typed fields) that the host app renders generically as a form via `BaseSchemaForm.vue`, with values stored per-company.
- **`InvoiceShelf\Modules\Settings\Schema` / `FieldType`** — a value object + enum that lock down the supported field types (`text`, `password`, `textarea`, `switch`, `number`, `select`, `multiselect`) and validate the schema shape at registration time.

The actual module loading, file generation, migration, and provider registration are all handled by upstream `nwidart/laravel-modules` (required as a composer dependency).

## Official module package contract

An official module lives in its own repository and Composer package — never in this SDK repository or in the InvoiceShelf application repository. First-party packages use the reserved `invoiceshelf/module-<slug>` convention (the generator's Composer stub uses it automatically); this marketplace contract is for official modules, not a runtime package-install mechanism for arbitrary third-party code. `php artisan module:make` creates the extended `module.json` automatically through this SDK's [`stubs/json.stub`](stubs/json.stub).

`module.json` is schema version 1. Its identity (`slug`, loader `name`) is immutable after the first marketplace release. It preserves nwidart loader keys (`name`, `alias`, `description`, `keywords`, `priority`, `providers`, `aliases`, `files`, and `requires`) and adds an exact SemVer `version`, SPDX `license`, compatibility constraints, required `ext-*` PHP extensions, module-to-module SemVer dependencies, and local compiled `dist/*.js`/`dist/*.css` assets. Unknown fields beyond those defined loader and SDK keys are rejected.

```json
{
  "schema_version": 1,
  "slug": "sales-tax-us",
  "name": "SalesTaxUs",
  "alias": "salestaxus",
  "description": "",
  "keywords": [],
  "priority": 0,
  "version": "1.2.3",
  "license": "AGPL-3.0-only",
  "providers": ["Modules\\SalesTaxUs\\Providers\\SalesTaxUsServiceProvider"],
  "aliases": {},
  "files": [],
  "requires": {},
  "compatibility": {
    "invoiceshelf": "^3.0.0",
    "module_api": "^1.0.0",
    "php": "^8.3.0",
    "extensions": ["ext-json"]
  },
  "module_dependencies": {"accounting-core": "^1.0.0"},
  "migration_policy": "forward-only",
  "dependency_policy": "host-provided-only",
  "assets": ["dist/module.js", "dist/module.css"]
}
```

The policies are deliberately closed: migrations are forward-only and installation never runs Composer, npm, pnpm, or another dependency resolver. Runtime dependencies must be provided by InvoiceShelf (`host-provided-only`). Generated packages use Orchestra Testbench 11 (Laravel 13), PHPUnit 12, and Pint only as `require-dev` CI tooling; build tooling is allowed in CI only, and compiled output is shipped inside the ZIP. Remote URLs, source assets, path traversal, arbitrary PHP providers, unsupported constraints, and unknown manifest fields are rejected.

At runtime, `Registry::registerScript()` and `Registry::registerStyle()` accept only existing local `.js` and `.css` files (for example `module_path($name, 'dist/module.js')`). The registry stores the canonical real path and rejects URLs, missing files, and incorrect extensions; modules cannot inject remote scripts or styles.

## Signed releases

Each release has a second, immutable schema-v1 `release-manifest.json`, generated in CI. It contains the module identity/version, `channel` (`stable` or `insider`), immutable `publication: "published"`, compatibility, artifact lowercase SHA-256 and byte size, `key_id`, lowercase 40-character source commit, and RFC 3339 release time. Stable releases require a final SemVer; insider releases require a prerelease SemVer.

The signature is a standard-base64 Ed25519 detached signature over the canonical JSON bytes of this manifest only. Canonical JSON recursively sorts object keys lexicographically, preserves list order, uses `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR`, and contains no whitespace or newline. The SDK verifies signatures using public keys only; it never accepts or stores private signing keys.

Marketplace responses have this envelope:

```json
{
  "success": true,
  "manifest": { "...": "signed release manifest" },
  "signature": "base64 detached Ed25519 signature",
  "key_id": "official-2026-01",
  "artifact": {
    "sha256": "lowercase hex SHA-256",
    "bytes": 1234,
    "download_url": "https://...",
    "expires_at": "2026-08-05T12:00:00Z"
  }
}
```

The envelope `key_id`, artifact SHA-256, and bytes must equal their signed manifest counterparts. Current catalog state is intentionally outside the signature: an optional `release_state` (`published` or `yanked`) and required `yanked_reason` for yanked releases let the marketplace yank a release without a private key or a re-sign.

Use the bundled executable in development and CI:

```bash
vendor/bin/invoiceshelf-module validate-module module.json
vendor/bin/invoiceshelf-module validate-package .
vendor/bin/invoiceshelf-module validate-release release-manifest.json
vendor/bin/invoiceshelf-module canonicalize-release release-manifest.json > release-manifest.canonical.json
vendor/bin/invoiceshelf-module generate-keypair official-2026-01
```

## Release workflow

Copy or call [`.github/workflows/module-release.yml`](.github/workflows/module-release.yml) from each official module repository. When a caller invokes it from a tag, the workflow fails before secrets are used unless `GITHUB_REF_NAME` exactly equals `module.json`'s version (for example, `1.2.3`, not `v1.2.3`). Manual dispatch and branch-based `workflow_call` usage remain available. It installs dependencies, runs Pint in test mode and PHPUnit, builds frontend assets if present, validates the complete package (including built local assets and Composer host-only dependencies), makes a timestamp-normalized deterministic ZIP rooted at the module `name`, calculates SHA-256/bytes, creates and validates the release manifest, canonicalizes through the SDK CLI, then signs those exact bytes with a protected environment secret before uploading to the configured website ingest endpoint.

Configure the protected `module-release` environment with:

- `MODULE_SIGNING_SECRET_KEY_B64` secret: standard base64 raw Ed25519 secret key.
- `MODULE_MARKETPLACE_INGEST_TOKEN` secret: ingest bearer token.
- `MODULE_SIGNING_KEY_ID` variable: matching public-key identifier.
- `MODULE_MARKETPLACE_INGEST_URL` variable: website ingest base URL ending in `/api/marketplace/v1/modules` (the workflow appends `/{slug}/releases`).

No private key, token, endpoint, or organization-specific value is hardcoded in the template. The checkout disables persisted GitHub credentials; the protected, repository-scoped ingest token is used only as a masked bearer value for the single release registration request. The workflow sends the manifest and detached signature as scalar multipart fields (not upload parts), as required by the website ingest endpoint. A caller can use the workflow with `secrets: inherit`; the source repository retains the protected environment and its secrets.

`generate-keypair` prints a standard-base64 Ed25519 keypair and does not write files. Put `secret_key_b64` only in the protected `MODULE_SIGNING_SECRET_KEY_B64` GitHub environment secret, put `public_key_b64` under its `key_id` in the website and InvoiceShelf public-key configuration, then discard the command output. Never commit either key.

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

This SDK is MIT. See [LICENSE.md](LICENSE.md). Official module packages generated from its stubs are `AGPL-3.0-only` by default and must include their corresponding license text and complete source needed to produce every shipped `dist/` asset.
