<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Contracts\Host;

use InvoiceShelf\Modules\Documents\Data\DocumentParty;
use InvoiceShelf\Modules\Documents\Data\InvoiceDocument;

/**
 * Reads whole invoices for modules that turn them into other documents, such
 * as structured e-invoices. Module API 1.5.0.
 *
 * Every read is scoped to the company: an id from another company reads as
 * null, exactly like one that does not exist.
 */
interface InvoiceDocumentReader
{
    public function invoiceDocument(int $companyId, int $invoiceId): ?InvoiceDocument;

    /** The company as the seller of its documents, with its postal address; null when it does not exist. */
    public function seller(int $companyId): ?DocumentParty;
}
