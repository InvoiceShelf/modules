<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Documents\Data;

/**
 * A postal address as the host stores it. The country is its ISO 3166-1
 * alpha-2 code in upper case.
 */
final class PostalAddress
{
    public function __construct(
        public readonly ?string $street1 = null,
        public readonly ?string $street2 = null,
        public readonly ?string $city = null,
        public readonly ?string $state = null,
        public readonly ?string $zip = null,
        public readonly ?string $countryCode = null,
        public readonly ?string $phone = null,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Values::optionalString($data, 'address_street_1'),
            Values::optionalString($data, 'address_street_2'),
            Values::optionalString($data, 'city'),
            Values::optionalString($data, 'state'),
            Values::optionalString($data, 'zip'),
            Values::optionalString($data, 'country_code'),
            Values::optionalString($data, 'phone'),
        );
    }

    /** @return array{address_street_1: ?string, address_street_2: ?string, city: ?string, state: ?string, zip: ?string, country_code: ?string, phone: ?string} */
    public function toArray(): array
    {
        return [
            'address_street_1' => $this->street1,
            'address_street_2' => $this->street2,
            'city' => $this->city,
            'state' => $this->state,
            'zip' => $this->zip,
            'country_code' => $this->countryCode,
            'phone' => $this->phone,
        ];
    }
}
