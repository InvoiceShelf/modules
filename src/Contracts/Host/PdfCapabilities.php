<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Contracts\Host;

/**
 * What the host's PDF rendering can do right now. Module API 1.5.0.
 */
interface PdfCapabilities
{
    /**
     * Whether invoice PDFs can carry an embedded Factur-X / ZUGFeRD XML:
     * true when they are rendered by a Gotenberg service recent enough to
     * build the PDF/A-3 container. dompdf cannot.
     */
    public function embedsFacturX(): bool;
}
