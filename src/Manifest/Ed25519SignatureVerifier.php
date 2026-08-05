<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Manifest;

/** Standard-base64 Ed25519 detached signature verifier; it never owns a private key. */
final class Ed25519SignatureVerifier implements SignatureVerifier
{
    public function verify(string $canonicalJson, string $signature, string $publicKey): bool
    {
        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }

        $signatureBytes = base64_decode($signature, true);
        $publicKeyBytes = base64_decode($publicKey, true);

        if ($signatureBytes === false || $publicKeyBytes === false
            || strlen($signatureBytes) !== SODIUM_CRYPTO_SIGN_BYTES
            || strlen($publicKeyBytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signatureBytes, $canonicalJson, $publicKeyBytes);
    }
}
