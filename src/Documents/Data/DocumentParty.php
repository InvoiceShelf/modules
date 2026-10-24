<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Documents\Data;

/**
 * The seller (the company) or the buyer (the customer) of a document.
 *
 * `vat_id` and `tax_id` are what the host holds: a company has both; a
 * customer has only `tax_id`, an untyped field that may hold a VAT identifier
 * or a national tax number, so `vat_id` is null for a buyer.
 */
final class DocumentParty
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $companyName = null,
        public readonly ?string $vatId = null,
        public readonly ?string $taxId = null,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly ?string $contactName = null,
        public readonly ?PostalAddress $address = null,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Values::string($data, 'name'),
            Values::optionalString($data, 'company_name'),
            Values::optionalString($data, 'vat_id'),
            Values::optionalString($data, 'tax_id'),
            Values::optionalString($data, 'email'),
            Values::optionalString($data, 'phone'),
            Values::optionalString($data, 'contact_name'),
            is_array($data['address'] ?? null) ? PostalAddress::fromArray($data['address']) : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'company_name' => $this->companyName,
            'vat_id' => $this->vatId,
            'tax_id' => $this->taxId,
            'email' => $this->email,
            'phone' => $this->phone,
            'contact_name' => $this->contactName,
            'address' => $this->address?->toArray(),
        ];
    }
}
