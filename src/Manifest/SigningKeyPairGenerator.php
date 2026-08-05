<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Manifest;

use InvalidArgumentException;
use RuntimeException;

/**
 * Generates a keypair for a protected CI environment without persisting it.
 * The caller is responsible for placing only the returned secret in its
 * protected GitHub environment and distributing the public key to verifiers.
 */
final class SigningKeyPairGenerator
{
    /** @return array{key_id: string, public_key_b64: string, secret_key_b64: string} */
    public static function generate(string $keyId): array
    {
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $keyId)) {
            throw new InvalidArgumentException('Key ID must be a stable, non-empty identifier using letters, numbers, dots, underscores, or hyphens.');
        }
        if (! function_exists('sodium_crypto_sign_keypair')) {
            throw new RuntimeException('The sodium extension is required to generate an Ed25519 keypair.');
        }

        $keypair = sodium_crypto_sign_keypair();

        return [
            'key_id' => $keyId,
            'public_key_b64' => base64_encode(sodium_crypto_sign_publickey($keypair)),
            'secret_key_b64' => base64_encode(sodium_crypto_sign_secretkey($keypair)),
        ];
    }
}
