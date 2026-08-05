<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Contracts;

/**
 * Removes module-owned data that cannot be removed by reversible migrations.
 *
 * Implementations must be idempotent: the host may retry an uninstall after a
 * failed attempt. Throw an exception when cleanup cannot be completed.
 */
interface DataCleanup
{
    public function cleanup(): void;
}
