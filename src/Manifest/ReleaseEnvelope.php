<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Manifest;

use InvalidArgumentException;

/**
 * Transport envelope returned by the marketplace. The signature covers only
 * manifest; this class protects the duplicated artifact/key data from a
 * transport-level substitution and keeps mutable yank state outside signing.
 */
final readonly class ReleaseEnvelope
{
    public function __construct(
        public ReleaseManifest $manifest,
        public string $signature,
        public string $keyId,
        public string $downloadUrl,
        public string $expiresAt,
        public string $releaseState,
        public ?string $yankedReason,
    ) {}

    /** @param array<string, mixed> $value */
    public static function fromArray(array $value): self
    {
        self::rejectUnknownKeys($value, ['success', 'manifest', 'signature', 'key_id', 'artifact', 'release_state', 'yanked_reason']);

        if (($value['success'] ?? null) !== true) {
            throw new InvalidArgumentException('Release envelope must have success=true.');
        }
        if (! is_array($value['manifest'] ?? null)) {
            throw new InvalidArgumentException("Release envelope must contain a 'manifest' object.");
        }
        $manifest = ReleaseManifest::fromArray($value['manifest']);

        $signature = $value['signature'] ?? null;
        if (! is_string($signature) || base64_decode($signature, true) === false) {
            throw new InvalidArgumentException('Release envelope signature must be standard base64.');
        }
        $keyId = $value['key_id'] ?? null;
        if (! is_string($keyId) || $keyId !== $manifest->keyId) {
            throw new InvalidArgumentException('Release envelope key_id must match the signed manifest key_id.');
        }

        $artifact = $value['artifact'] ?? null;
        if (! is_array($artifact) || array_diff(array_keys($artifact), ['sha256', 'bytes', 'download_url', 'expires_at']) !== []) {
            throw new InvalidArgumentException('Release envelope artifact must contain sha256, bytes, download_url, and expires_at only.');
        }
        if (($artifact['sha256'] ?? null) !== $manifest->artifactSha256 || ($artifact['bytes'] ?? null) !== $manifest->artifactBytes) {
            throw new InvalidArgumentException('Release envelope artifact sha256 and bytes must match the signed manifest.');
        }
        if (! isset($artifact['download_url']) || ! is_string($artifact['download_url']) || filter_var($artifact['download_url'], FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Release envelope artifact download_url must be an absolute URL.');
        }
        $expiresAt = $artifact['expires_at'] ?? null;
        if (! is_string($expiresAt) || ! self::isRfc3339($expiresAt)) {
            throw new InvalidArgumentException('Release envelope artifact expires_at must be an RFC 3339 timestamp.');
        }

        $state = $value['release_state'] ?? 'published';
        if (! is_string($state) || ! in_array($state, ['published', 'yanked'], true)) {
            throw new InvalidArgumentException("Release envelope release_state must be 'published' or 'yanked'.");
        }
        $reason = $value['yanked_reason'] ?? null;
        if ($state === 'yanked' && (! is_string($reason) || trim($reason) === '')) {
            throw new InvalidArgumentException('A yanked release envelope must include a non-empty yanked_reason.');
        }
        if ($state === 'published' && $reason !== null) {
            throw new InvalidArgumentException('A published release envelope cannot include yanked_reason.');
        }

        return new self($manifest, $signature, $keyId, $artifact['download_url'], $expiresAt, $state, $reason);
    }

    /** @param array<string, string> $publicKeys Keyed by stable key_id. */
    public function hasValidSignature(array $publicKeys, ?SignatureVerifier $verifier = null): bool
    {
        $publicKey = $publicKeys[$this->keyId] ?? null;
        if (! is_string($publicKey)) {
            return false;
        }

        return ($verifier ?? new Ed25519SignatureVerifier)->verify(
            $this->manifest->canonicalJson(),
            $this->signature,
            $publicKey,
        );
    }

    private static function isRfc3339(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $value) === 1;
    }

    /** @param array<string, mixed> $value @param list<string> $allowed */
    private static function rejectUnknownKeys(array $value, array $allowed): void
    {
        $unknown = array_diff(array_keys($value), $allowed);
        if ($unknown !== []) {
            throw new InvalidArgumentException("Release envelope contains unsupported field '".reset($unknown)."'.");
        }
    }
}
