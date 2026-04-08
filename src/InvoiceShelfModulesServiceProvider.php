<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules;

use Illuminate\Support\ServiceProvider;
use Nwidart\Modules\Support\Stub;

/**
 * Service provider for the invoiceshelf/modules package.
 *
 * Wires two things into the host app:
 *
 *  1. The static Registry is always available (zero-config — just `use` the class).
 *
 *  2. Custom generator stubs that override nwidart's defaults for
 *     `php artisan module:make`. New modules are scaffolded with the
 *     InvoiceShelf Registry::registerMenu/registerSettings skeleton already
 *     in place, plus starter lang files and a composer.json that depends on
 *     this package.
 *
 *     Stub resolution is pull-through: nwidart's Stub::getPath() falls back
 *     to its own defaults if a file doesn't exist at our base path, so we
 *     only need to ship the stubs we want to override. See
 *     vendor/nwidart/laravel-modules/src/Support/Stub.php line 54.
 *
 * Auto-discovered via composer.json's `extra.laravel.providers` entry.
 */
class InvoiceShelfModulesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureStubs();
    }

    /**
     * Point nwidart's generator at our custom stubs directory.
     *
     * Called in boot() (not register) so it runs after
     * LaravelModulesServiceProvider::setupStubPath() has already set the
     * default path. Our call wins because it's later in the boot order.
     * The host app's `config/modules.php` must leave `stubs.enabled => false`
     * so nwidart's `booted()` hook doesn't overwrite us.
     */
    private function configureStubs(): void
    {
        $stubsPath = dirname(__DIR__).'/stubs';

        if (is_dir($stubsPath)) {
            Stub::setBasePath($stubsPath);
        }
    }
}
