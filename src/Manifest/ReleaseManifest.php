<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Manifest;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Immutable, signed marketplace release record. A later yank is catalog state,
 * not part of this object: the website must never need a signing key to yank a
 * compromised release.
 */
final readonly class ReleaseManifest
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        public string $slug,
        public string $moduleName,
        public string $version,
        public string $channel,
        public string $publication,
        public Compatibility $compatibility,
        public string $artifactSha256,
        public int $artifactBytes,
        public string $keyId,
        public string $sourceCommit,
        public string $releasedAt,
    ) {}

    /** @param array<string, mixed> $value */
    public static function fromArray(array $value): self
    {
        self::rejectUnknownKeys($value, [
            'schema_version', 'slug', 'module_name', 'version', 'channel', 'publication', 'compatibility',
            'artifact', 'key_id', 'source_commit', 'released_at',
        ]);

        if (($value['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException('Only release manifest schema_version=1 is supported.');
        }

        $slug = self::slug($value['slug'] ?? null);
        $moduleName = self::moduleName($value['module_name'] ?? null);
        $version = $value['version'] ?? null;
        if (! Semver::isVersion($version)) {
            throw new InvalidArgumentException('Release manifest version must be a SemVer version.');
        }

        $channel = $value['channel'] ?? null;
        if (! is_string($channel) || ! in_array($channel, ['stable', 'insider'], true)) {
            throw new InvalidArgumentException("Release channel must be either 'stable' or 'insider'.");
        }
        if ($channel === 'stable' && str_contains($version, '-')) {
            throw new InvalidArgumentException('Stable releases cannot use a prerelease SemVer version.');
        }
        if ($channel === 'insider' && ! str_contains($version, '-')) {
            throw new InvalidArgumentException('Insider releases must use a prerelease SemVer version.');
        }

        if (($value['publication'] ?? null) !== 'published') {
            throw new InvalidArgumentException("Signed release publication must be the immutable marker 'published'. Yank state belongs to the catalog envelope.");
        }

        $compatibility = is_array($value['compatibility'] ?? null)
            ? Compatibility::fromArray($value['compatibility'])
            : throw new InvalidArgumentException("Release manifest must declare a 'compatibility' object.");
        [$sha256, $bytes] = self::artifact($value['artifact'] ?? null);

        $keyId = $value['key_id'] ?? null;
        if (! is_string($keyId) || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $keyId)) {
            throw new InvalidArgumentException('Release key_id must be a stable, non-empty key identifier.');
        }

        $commit = $value['source_commit'] ?? null;
        if (! is_string($commit) || ! preg_match('/^[0-9a-f]{40}$/', $commit)) {
            throw new InvalidArgumentException('Release source_commit must be a lowercase 40-character Git SHA-1.');
        }

        $releasedAt = self::date($value['released_at'] ?? null);

        return new self($slug, $moduleName, $version, $channel, 'published', $compatibility, $sha256, $bytes, $keyId, $commit, $releasedAt);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'slug' => $this->slug,
            'module_name' => $this->moduleName,
            'version' => $this->version,
            'channel' => $this->channel,
            'publication' => $this->publication,
            'compatibility' => $this->compatibility->toArray(),
            'artifact' => ['sha256' => $this->artifactSha256, 'bytes' => $this->artifactBytes],
            'key_id' => $this->keyId,
            'source_commit' => $this->sourceCommit,
            'released_at' => $this->releasedAt,
        ];
    }

    public function canonicalJson(): string
    {
        return CanonicalJson::encode($this->toArray());
    }

    /** @return array{string, int} */
    private static function artifact(mixed $value): array
    {
        if (! is_array($value) || array_diff(array_keys($value), ['sha256', 'bytes']) !== []) {
            throw new InvalidArgumentException("Release artifact must contain only 'sha256' and 'bytes'.");
        }
        if (! isset($value['sha256']) || ! is_string($value['sha256']) || ! preg_match('/^[0-9a-f]{64}$/', $value['sha256'])) {
            throw new InvalidArgumentException('Release artifact sha256 must be lowercase hexadecimal SHA-256.');
        }
        if (! isset($value['bytes']) || ! is_int($value['bytes']) || $value['bytes'] < 1) {
            throw new InvalidArgumentException('Release artifact bytes must be a positive integer.');
        }

        return [$value['sha256'], $value['bytes']];
    }

    private static function slug(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value)) {
            throw new InvalidArgumentException('Release slug must be lowercase kebab-case.');
        }

        return $value;
    }

    private static function moduleName(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^[A-Z][A-Za-z0-9]*$/', $value)) {
            throw new InvalidArgumentException('Release module_name must be PascalCase ASCII.');
        }

        return $value;
    }

    private static function date(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^(?<date_time>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(?<fraction>\d+))?(?<timezone>Z|[+-]\d{2}:\d{2})$/', $value, $matches) !== 1) {
            throw new InvalidArgumentException('Release released_at must be an RFC 3339 timestamp with timezone.');
        }

        $timezone = $matches['timezone'] === 'Z' ? '+00:00' : $matches['timezone'];
        if ($timezone !== '+00:00') {
            $timezoneHour = (int) substr($timezone, 1, 2);
            $timezoneMinute = (int) substr($timezone, 4, 2);
            if ($timezoneHour > 23 || $timezoneMinute > 59) {
                throw new InvalidArgumentException('Release released_at must be a valid RFC 3339 timestamp.');
            }
        }

        // DateTimeImmutable accepts a maximum of microsecond precision. Preserve
        // the original fractional text for the signed manifest, but use its first
        // six digits to validate the calendar date, clock time, and offset.
        $fraction = substr(str_pad($matches['fraction'] ?? '', 6, '0'), 0, 6);
        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d\\TH:i:s.uP',
            $matches['date_time'].'.'.$fraction.$timezone,
        );
        $errors = DateTimeImmutable::getLastErrors();

        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d\\TH:i:s.uP') !== $matches['date_time'].'.'.$fraction.$timezone) {
            throw new InvalidArgumentException('Release released_at must be a valid RFC 3339 timestamp.');
        }

        return $value;
    }

    /** @param array<string, mixed> $value @param list<string> $allowed */
    private static function rejectUnknownKeys(array $value, array $allowed): void
    {
        $unknown = array_diff(array_keys($value), $allowed);
        if ($unknown !== []) {
            throw new InvalidArgumentException("Release manifest contains unsupported field '".reset($unknown)."'.");
        }
    }
}
