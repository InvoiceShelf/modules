<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Documents\Data;

/**
 * One line of a document, in the order the document lists it.
 *
 * `quantity` is a decimal string. `unit_name` is the line's own snapshot and
 * `unit_code` the UN/ECE Recommendation 20 code of the company unit by that
 * name now, null when no unit matches. `description` is plain text. `taxes`
 * is empty unless the document taxes each line. Amounts are in hundredths,
 * see {@see InvoiceDocument}.
 */
final class DocumentLine
{
    /**
     * @param  list<DocumentTax>  $taxes
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?string $description,
        public readonly string $quantity,
        public readonly int $price,
        public readonly int $discountVal,
        public readonly int $total,
        public readonly ?string $unitName = null,
        public readonly ?string $unitCode = null,
        public readonly array $taxes = [],
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Values::int($data, 'id'),
            Values::string($data, 'name'),
            Values::optionalString($data, 'description'),
            Values::string($data, 'quantity'),
            Values::int($data, 'price'),
            Values::optionalInt($data, 'discount_val') ?? 0,
            Values::int($data, 'total'),
            Values::optionalString($data, 'unit_name'),
            Values::optionalString($data, 'unit_code'),
            array_map(DocumentTax::fromArray(...), Values::list($data, 'taxes')),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'price' => $this->price,
            'discount_val' => $this->discountVal,
            'total' => $this->total,
            'unit_name' => $this->unitName,
            'unit_code' => $this->unitCode,
            'taxes' => array_map(static fn (DocumentTax $tax): array => $tax->toArray(), $this->taxes),
        ];
    }
}
