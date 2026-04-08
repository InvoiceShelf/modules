<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules;

use Illuminate\Support\ServiceProvider;

/**
 * Marker service provider for the invoiceshelf/modules package.
 *
 * The package is a thin extension on top of nwidart/laravel-modules — its only
 * runtime concerns are the static Registry (consumed by the host app's
 * BootstrapController and ModuleSettingsController) and any future package-level
 * boot logic such as publishing custom stubs.
 *
 * Auto-discovery via composer.json's extra.laravel.providers entry registers
 * this provider in any Laravel app that requires the package.
 */
class InvoiceShelfModulesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}
