<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Contracts\Host;

/**
 * Authorizes a user within a company without exposing host user or model types.
 */
interface ModuleAuthorization
{
    /**
     * $resource is one of the stable host-defined identifiers `customer`,
     * `invoice`, `expense`, `payment`, or `item`, or null for a dashboard-like
     * ability that has no resource.
     */
    public function allows(int $userId, int $companyId, string $ability, ?string $resource = null): bool;
}
