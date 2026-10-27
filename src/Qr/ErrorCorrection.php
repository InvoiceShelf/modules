<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Qr;

/**
 * How much of a QR code can be damaged and still scan, at the price of a
 * denser code. Module API 1.6.0.
 *
 * Medium is right for most printed documents, and it is what the EPC
 * (SEPA credit transfer) and Swiss QR-bill specifications require.
 */
enum ErrorCorrection: string
{
    /** About 7% of the code can be restored. */
    case Low = 'L';

    /** About 15%. */
    case Medium = 'M';

    /** About 25%. */
    case Quartile = 'Q';

    /** About 30%. */
    case High = 'H';
}
