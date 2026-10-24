<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Pdf\Data;

use InvalidArgumentException;

/**
 * A Factur-X / ZUGFeRD XML to embed in an invoice PDF, with the profile it
 * conforms to. The host embeds it as `factur-x.xml` in a PDF/A-3b file and
 * writes the profile into the file's metadata.
 */
final class FacturXAttachment
{
    public const FILENAME = 'factur-x.xml';

    public const PROFILE_EN16931 = 'EN 16931';

    /** The conformance levels the Factur-X metadata can name. */
    public const PROFILES = ['MINIMUM', 'BASIC WL', 'BASIC', self::PROFILE_EN16931, 'EXTENDED', 'XRECHNUNG'];

    public function __construct(
        public readonly string $xml,
        public readonly string $profile = self::PROFILE_EN16931,
    ) {
        if (trim($xml) === '') {
            throw new InvalidArgumentException('A Factur-X attachment needs its XML.');
        }

        if (! in_array($profile, self::PROFILES, true)) {
            throw new InvalidArgumentException("Factur-X profile '{$profile}' must be one of: ".implode(', ', self::PROFILES).'.');
        }
    }
}
