<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Bundle;

use InvoiceShelf\Modules\Manifest\BundleManifest;

/** What one `invoiceshelf-module bundle` run produced. */
final readonly class BundleReport
{
    /** @param list<string> $warnings */
    public function __construct(
        public BundleManifest $manifest,
        public array $warnings,
        public bool $lockWritten,
    ) {}
}
