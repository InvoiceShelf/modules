<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Contracts\Host;

/**
 * Host-owned settings boundary for module configuration.
 */
interface SettingsStore
{
    public function getGlobal(string $key, mixed $default = null): mixed;

    public function putGlobal(string $key, mixed $value): void;

    public function deleteGlobal(string $key): void;

    public function getCompany(int $companyId, string $key, mixed $default = null): mixed;

    public function putCompany(int $companyId, string $key, mixed $value): void;

    public function deleteCompany(int $companyId, string $key): void;

    /** Delete one module-owned setting key for every company. */
    public function deleteCompanyForAll(string $key): void;
}
