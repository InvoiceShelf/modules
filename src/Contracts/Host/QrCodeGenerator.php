<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Contracts\Host;

use InvalidArgumentException;
use InvoiceShelf\Modules\Qr\ErrorCorrection;

/**
 * QR codes drawn by the host, so a module does not bundle a QR library of its
 * own. Module API 1.6.0.
 *
 * Content is UTF-8 text: a URL, or a payment payload such as an EPC or Swiss
 * QR-bill one. Plain ASCII is encoded byte for byte; anything else is encoded
 * as UTF-8 with an ECI marker saying so, which is what those payment
 * specifications expect, rather than being converted to ISO-8859-1.
 *
 * The output is SVG, which needs no image extension on the server, stays
 * sharp at any print size, and is drawn by both of the host's PDF drivers.
 */
interface QrCodeGenerator
{
    /**
     * An SVG document of the code.
     *
     * @param  int  $size  the edge of the square image in pixels, quiet zone included
     * @param  int  $margin  the quiet zone around the code, in modules; 4 is what the QR standard asks for
     *
     * @throws InvalidArgumentException when the content is empty or too long for a QR code at that level
     */
    public function svg(
        string $content,
        int $size = 200,
        ErrorCorrection $errorCorrection = ErrorCorrection::Medium,
        int $margin = 4,
    ): string;

    /**
     * The same SVG as a `data:image/svg+xml;base64,` URI, ready for the `src`
     * of an `<img>` in a PDF template: the renderer never fetches anything.
     *
     * @param  int  $size  the edge of the square image in pixels, quiet zone included
     * @param  int  $margin  the quiet zone around the code, in modules; 4 is what the QR standard asks for
     *
     * @throws InvalidArgumentException when the content is empty or too long for a QR code at that level
     */
    public function svgDataUri(
        string $content,
        int $size = 200,
        ErrorCorrection $errorCorrection = ErrorCorrection::Medium,
        int $margin = 4,
    ): string;
}
