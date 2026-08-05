<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules;

use InvalidArgumentException;
use InvoiceShelf\Modules\Ai\Contracts\AiDriver;
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
     * Stored as `[slug => canonical local path]`. Assets must be existing
     * compiled files inside the installed module package; remote URLs and
     * runtime-loaded source files are never registered.
     *
     * Note: this is **not** for shipping Vue components — modules ship only
     * their pre-built local JS/CSS assets.
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
        static::$scripts[$name] = self::localAsset($path, 'js');
    }

    /**
     * Register a CSS asset to be injected into the host app's main layout.
     */
    public static function registerStyle(string $name, string $path): void
    {
        static::$styles[$name] = self::localAsset($path, 'css');
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
     * Resolve an installed, compiled asset once at registration time.
     *
     * @throws InvalidArgumentException
     */
    private static function localAsset(string $path, string $extension): string
    {
        $resolved = realpath($path);
        if ($resolved === false || ! is_file($resolved)) {
            throw new InvalidArgumentException("Module {$extension} asset '{$path}' must be an existing local file.");
        }
        if (strtolower(pathinfo($resolved, PATHINFO_EXTENSION)) !== $extension) {
            throw new InvalidArgumentException("Module asset '{$path}' must be a .{$extension} file.");
        }

        return $resolved;
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
     * Register an AI driver (chat assistant, text generation, etc).
     *
     * Convenience wrapper — modules call this from their ServiceProvider::boot():
     *
     *     Registry::registerAiDriver('my_ai_provider', [
     *         'class'            => MyAiDriver::class,
     *         'label'            => 'my_module::drivers.my_ai',
     *         'website'          => 'https://my-ai.example.com',
     *         'default_base_url' => 'https://api.my-ai.example.com/v1',
     *         'supported_roles'  => ['chat', 'text_generation'],
     *         'suggested_models' => [
     *             ['value' => 'model-a', 'label' => 'Model A'],
     *         ],
     *     ]);
     *
     * @param  array{class: class-string<AiDriver>, label: string, supported_roles: non-empty-list<'chat'|'text_generation'>, suggested_models: list<array{value: string, label: string}>, config_fields: array<mixed>, website?: string, default_base_url?: string}  $meta
     */
    public static function registerAiDriver(string $name, array $meta): void
    {
        self::validateAiDriver($name, $meta);

        if ((static::$drivers['ai'][$name] ?? null) === $meta) {
            return;
        }

        if (isset(static::$drivers['ai'][$name])) {
            throw new InvalidArgumentException("AI driver '{$name}' is already registered.");
        }

        static::registerDriver('ai', $name, $meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     *
     * @throws InvalidArgumentException
     */
    private static function validateAiDriver(string $name, array $meta): void
    {
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $name)) {
            throw new InvalidArgumentException('AI driver name must be a stable, non-empty identifier using letters, numbers, dots, underscores, or hyphens.');
        }

        $class = $meta['class'] ?? null;
        if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, AiDriver::class) || (new \ReflectionClass($class))->isAbstract()) {
            throw new InvalidArgumentException('AI driver class must be a concrete class extending '.AiDriver::class.'.');
        }

        if (! is_string($meta['label'] ?? null) || trim($meta['label']) === '') {
            throw new InvalidArgumentException('AI driver label must be a non-empty string.');
        }

        $roles = $meta['supported_roles'] ?? null;
        if (! is_array($roles) || ! array_is_list($roles) || $roles === [] || count($roles) !== count(array_unique($roles, SORT_REGULAR))) {
            throw new InvalidArgumentException("AI driver supported_roles must be a non-empty unique list containing only 'chat' and 'text_generation'.");
        }
        foreach ($roles as $role) {
            if (! is_string($role) || ! in_array($role, ['chat', 'text_generation'], true)) {
                throw new InvalidArgumentException("AI driver supported_roles must be a non-empty unique list containing only 'chat' and 'text_generation'.");
            }
        }

        foreach (['website', 'default_base_url'] as $field) {
            if (array_key_exists($field, $meta) && ! is_string($meta[$field])) {
                throw new InvalidArgumentException("AI driver {$field} must be a string when provided.");
            }
        }

        $models = $meta['suggested_models'] ?? null;
        if (! is_array($models) || ! array_is_list($models)) {
            throw new InvalidArgumentException('AI driver suggested_models must be a list of {value, label} entries.');
        }
        foreach ($models as $model) {
            if (! is_array($model) || count($model) !== 2 || ! array_key_exists('value', $model) || ! array_key_exists('label', $model)
                || ! is_string($model['value']) || trim($model['value']) === ''
                || ! is_string($model['label']) || trim($model['label']) === '') {
                throw new InvalidArgumentException('Each AI driver suggested_models entry must contain non-empty string value and label fields only.');
            }
        }

        if (! is_array($meta['config_fields'] ?? null)) {
            throw new InvalidArgumentException('AI driver config_fields must be an array.');
        }
    }

    /**
     * Get all registered drivers for a given type.
     *
     * @return array<string, array<string, mixed>> Keyed by driver name
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
