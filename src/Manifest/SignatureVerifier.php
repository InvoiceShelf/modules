<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Manifest;

interface SignatureVerifier
{
    /** Verifies a detached signature over the exact canonical JSON bytes. */
    public function verify(string $canonicalJson, string $signature, string $publicKey): bool;
}
