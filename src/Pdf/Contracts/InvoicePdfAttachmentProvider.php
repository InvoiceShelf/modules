<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Pdf\Contracts;

use InvoiceShelf\Modules\Contracts\Host\PdfCapabilities;
use InvoiceShelf\Modules\Pdf\Data\FacturXAttachment;
use InvoiceShelf\Modules\Registry;

/**
 * Supplies the structured XML the host embeds in an invoice's PDF, which
 * turns it into a hybrid e-invoice. Register the class with
 * {@see Registry::registerInvoicePdfAttachmentProvider()}.
 * Module API 1.5.0.
 *
 * The host asks on every invoice PDF it renders (download, public link,
 * customer portal, mail attachment, archive), only while the module is
 * enabled and only when {@see PdfCapabilities::embedsFacturX()}
 * is true. The first provider that returns an attachment wins. An exception
 * is reported and the invoice gets its ordinary PDF: a provider can never
 * stop an invoice from being delivered.
 */
interface InvoicePdfAttachmentProvider
{
    /**
     * Whether this provider may attach anything to the company's invoices at
     * all, such as a setting being on. Keep it cheap: no document work. The
     * host also uses it to decide whether an archived PDF is still current.
     */
    public function appliesTo(int $companyId): bool;

    /** The XML to embed in this invoice's PDF, or null for the ordinary PDF. */
    public function attachmentFor(int $companyId, int $invoiceId): ?FacturXAttachment;
}
