<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules;

use InvoiceShelf\Modules\Settings\Schema;

/**
 * Registry of module-contributed sidebar entries and settings schemas.
 *
 * Modules call these from their ServiceProvider::boot(). Because nwidart only
 * boots providers for currently-activated modules, the registry naturally
 * contains only active modules at request time — no extra filtering needed
 * by readers.
 */
class Registry
{
    /**
     * Sidebar items keyed by module slug.
     *
     * Each entry has the shape: ['title' => string, 'link' => string, 'icon' => string].
     *
     * @var array<string, array{title: string, link: string, icon: string}>
     */
    public static array $menu = [];

    /**
     * Settings schemas keyed by module slug.
     *
     * Values are normalized Schema instances. Modules pass plain arrays to
     * registerSettings(); the array goes through Schema::fromArray() which
     * validates the structure and rejects unknown field types.
     *
     * @var array<string, Schema>
     */
    public static array $settings = [];

    /**
     * JS/CSS assets a module wants to inject into the host app's main layout.
     *
     * Stored as `[slug => path]`. Path may be a local file path served by the
     * host app's ScriptController/StyleController, or a fully-qualified URL
     * (in which case the host renders a direct <script> tag).
     *
     * Note: this is **not** for shipping Vue components — modules don't ship
     * SFCs. This is for plain JS/CSS injection (analytics tags, third-party
     * widgets, custom themes), which is a much smaller surface than runtime
     * Vue compilation.
     *
     * @var array<string, string>
     */
    public static array $scripts = [];

    /**
     * @var array<string, string>
     */
    public static array $styles = [];

    /**
     * User dropdown menu items keyed by module slug.
     *
     * @var array<string, array{title: string, link: string, icon: string, priority?: int}>
     */
    public static array $userMenu = [];

    /**
     * Driver registrations keyed by type (e.g. 'exchange_rate', 'pdf'), then by driver name.
     *
     * Each entry contains at minimum:
     *   - 'class'  (class-string) — the driver implementation class
     *   - 'label'  (string)       — i18n key or plain-text display name
     *
     * Optional keys:
     *   - 'website'       (string) — provider website URL
     *   - 'config_fields' (array)  — schema for driver-specific configuration fields
     *   - 'resolver'      (Closure) — factory callable (used instead of 'class' when present)
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    public static array $drivers = [];

    /**
     * Register a sidebar entry for a module.
     *
     * @param  array{title: string, link: string, icon: string}  $item
     */
    public static function registerMenu(string $slug, array $item): void
    {
        static::$menu[$slug] = array_merge([
            'group' => 'modules',
            'group_label' => 'navigation.modules',
            'priority' => 100,
        ], $item);
    }

    /**
     * Register a settings schema for a module.
     *
     * Accepts a plain array following the schema shape:
     *   ['sections' => [['title' => '...', 'fields' => [...]]]]
     *
     * The array is validated and normalized into a Schema instance at
     * registration time so renderers downstream can rely on a stable shape.
     *
     * @param  array<string, mixed>  $schema
     */
    public static function registerSettings(string $slug, array $schema): void
    {
        static::$settings[$slug] = Schema::fromArray($schema);
    }

    /**
     * @return array<string, array{title: string, link: string, icon: string}>
     */
    public static function allMenu(): array
    {
        return static::$menu;
    }

    /**
     * @return array{title: string, link: string, icon: string}|null
     */
    public static function menuFor(string $slug): ?array
    {
        return static::$menu[$slug] ?? null;
    }

    /**
     * Register a user dropdown menu entry for a module.
     *
     * Items appear in the user avatar dropdown in the header,
     * between "Account Settings" and "Logout".
     *
     * @param  array{title: string, link: string, icon: string, priority?: int}  $item
     */
    public static function registerUserMenu(string $slug, array $item): void
    {
        static::$userMenu[$slug] = array_merge([
            'priority' => 100,
        ], $item);
    }

    /**
     * @return array<string, array{title: string, link: string, icon: string, priority?: int}>
     */
    public static function allUserMenu(): array
    {
        return static::$userMenu;
    }

    /**
     * @return array<string, Schema>
     */
    public static function allSettings(): array
    {
        return static::$settings;
    }

    public static function settingsFor(string $slug): ?Schema
    {
        return static::$settings[$slug] ?? null;
    }

    /**
     * Register a JS asset to be injected into the host app's main layout.
     */
    public static function registerScript(string $name, string $path): void
    {
        static::$scripts[$name] = $path;
    }

    /**
     * Register a CSS asset to be injected into the host app's main layout.
     */
    public static function registerStyle(string $name, string $path): void
    {
        static::$styles[$name] = $path;
    }

    /**
     * @return array<string, string>
     */
    public static function allScripts(): array
    {
        return static::$scripts;
    }

    /**
     * @return array<string, string>
     */
    public static function allStyles(): array
    {
        return static::$styles;
    }

    public static function scriptFor(string $name): ?string
    {
        return static::$scripts[$name] ?? null;
    }

    public static function styleFor(string $name): ?string
    {
        return static::$styles[$name] ?? null;
    }

    /**
     * Register a driver for a given type.
     *
     * @param  string  $type  Driver category (e.g. 'exchange_rate', 'pdf')
     * @param  string  $name  Unique driver identifier
     * @param  array<string, mixed>  $meta  Driver metadata (class, label, website, config_fields, etc.)
     */
    public static function registerDriver(string $type, string $name, array $meta): void
    {
        static::$drivers[$type][$name] = $meta;
    }

    /**
     * Register an exchange rate driver.
     *
     * Convenience wrapper — modules call this from their ServiceProvider::boot():
     *
     *     Registry::registerExchangeRateDriver('my_provider', [
     *         'class'   => MyExchangeRateDriver::class,
     *         'label'   => 'my_module::drivers.my_provider',
     *         'website' => 'https://my-provider.com',
     *     ]);
     *
     * @param  array<string, mixed>  $meta
     */
    public static function registerExchangeRateDriver(string $name, array $meta): void
    {
        static::registerDriver('exchange_rate', $name, $meta);
    }

    /**
     * Get all registered drivers for a given type.
     *
     * @return array<string, array<string, mixed>>  Keyed by driver name
     */
    public static function allDrivers(string $type): array
    {
        return static::$drivers[$type] ?? [];
    }

    /**
     * Get metadata for a single driver.
     *
     * @return array<string, mixed>|null
     */
    public static function driverMeta(string $type, string $name): ?array
    {
        return static::$drivers[$type][$name] ?? null;
    }

    /**
     * Test-only: clear module-contributed state.
     *
     * Tests that mutate the registry should call this in tearDown() to prevent
     * cross-test contamination, since the registry is process-global.
     *
     * Drivers are deliberately *not* cleared here: built-in drivers are registered
     * once at app boot by host-app service providers, not per-module, and should
     * persist for the entire test process. Tests that need to assert driver
     * registration in isolation can use flushDrivers() explicitly.
     */
    public static function flush(): void
    {
        static::$menu = [];
        static::$userMenu = [];
        static::$settings = [];
        static::$scripts = [];
        static::$styles = [];
    }

    /**
     * Test-only: clear driver registrations.
     *
     * Use this when you want to assert that a specific test re-populates the
     * driver registry. Most tests should not need this.
     */
    public static function flushDrivers(): void
    {
        static::$drivers = [];
    }
}
