# Releasing an official InvoiceShelf module

This document is for maintainers of official module repositories. It describes the reusable [module release workflow](.github/workflows/module-release.yml) shipped by the SDK.

## Before tagging

1. Set `module.json`'s `version` to the intended exact SemVer version.
2. Build and commit every declared `dist/` asset.
3. Run the module's lint, tests, frontend build, and package validation.
4. Merge the release change, then create an **unprefixed** tag that exactly matches `module.json`, for example `1.2.3` rather than `v1.2.3`.

The reusable workflow rejects a tag that does not exactly match the manifest version. Stable releases require final SemVer; insider releases require a prerelease SemVer.

## Use the reusable workflow

An official module normally calls the tagged SDK workflow from its own tag workflow:

```yaml
jobs:
  release:
    uses: InvoiceShelf/modules/.github/workflows/module-release.yml@3.3.0
    with:
      channel: stable
    secrets: inherit
```

The workflow installs dependencies, runs Pint and PHPUnit, builds frontend assets when present, validates the package, creates a timestamp-normalized ZIP, generates and validates `release-manifest.json`, signs its canonical JSON, and sends it to marketplace ingest.

## Protected `module-release` environment

Configure this protected GitHub environment in every official module repository:

| Setting | Type | Purpose |
| --- | --- | --- |
| `MODULE_SIGNING_SECRET_KEY_B64` | Secret | Standard-base64 raw Ed25519 secret key. |
| `MODULE_MARKETPLACE_INGEST_TOKEN` | Secret | Bearer token for the module's marketplace ingest request. |
| `MODULE_SIGNING_KEY_ID` | Variable | Identifier of the matching public signing key. |
| `MODULE_MARKETPLACE_INGEST_URL` | Variable | HTTPS ingest base URL ending in `/api/marketplace/v1/modules`. |

The workflow appends `/{slug}/releases` to the ingest URL. It disables persisted checkout credentials and uses the protected token only for that request.

Generate an Ed25519 keypair with:

```bash
vendor/bin/invoiceshelf-module generate-keypair official-2026-01
```

Put `secret_key_b64` only in the environment secret. Configure `public_key_b64` under the same `key_id` in the InvoiceShelf and marketplace public-key configuration, then discard the command output. Do not commit either key, the ingest token, or an organization-specific endpoint.

## Signed release manifests

CI creates an immutable schema-v1 `release-manifest.json` containing the module identity/version, channel, compatibility, artifact SHA-256 and size, signing key ID, source commit, and release time. It signs canonical JSON with an Ed25519 detached signature.

The marketplace can mark a published release as yanked without re-signing it; package identity and artifact integrity remain signed. Use the SDK CLI to inspect a manifest locally:

```bash
vendor/bin/invoiceshelf-module validate-release release-manifest.json
vendor/bin/invoiceshelf-module canonicalize-release release-manifest.json > release-manifest.canonical.json
```
