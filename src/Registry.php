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
     * Register a sidebar entry for a module.
     *
     * @param  array{title: string, link: string, icon: string}  $item
     */
    public static function registerMenu(string $slug, array $item): void
    {
        static::$menu[$slug] = $item;
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
     * Test-only: clear all registered state.
     *
     * Tests that mutate the registry should call this in tearDown() to prevent
     * cross-test contamination, since the registry is process-global.
     */
    public static function flush(): void
    {
        static::$menu = [];
        static::$settings = [];
        static::$scripts = [];
        static::$styles = [];
    }
}
