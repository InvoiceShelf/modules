<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Tests;

use InvoiceShelf\Modules\InvoiceShelfModulesServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [InvoiceShelfModulesServiceProvider::class];
    }
}
