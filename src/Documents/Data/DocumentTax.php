<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Documents\Data;

/**
 * One tax row of a document or of a line, as stored.
 *
 * `name` and `percent` are the row's own snapshot; `category_code` (an EN 16931
 * tax category such as `S`, `Z` or `E`) and `exemption_reason` come from the
 * tax type as it is now. `percent` is a decimal string, null for a fixed tax,
 * whose amount per line is `fixed_amount`. Amounts are in hundredths, see
 * {@see InvoiceDocument}.
 */
final class DocumentTax
{
    public const CALCULATION_PERCENTAGE = 'percentage';

    public const CALCULATION_FIXED = 'fixed';

    public function __construct(
        public readonly ?int $taxTypeId,
        public readonly string $name,
        public readonly ?string $percent,
        public readonly int $amount,
        public readonly string $calculationType = self::CALCULATION_PERCENTAGE,
        public readonly ?int $fixedAmount = null,
        public readonly bool $compound = false,
        public readonly ?string $categoryCode = null,
        public readonly ?string $exemptionReason = null,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Values::optionalInt($data, 'tax_type_id'),
            Values::string($data, 'name'),
            Values::optionalString($data, 'percent'),
            Values::int($data, 'amount'),
            Values::optionalString($data, 'calculation_type') ?? self::CALCULATION_PERCENTAGE,
            Values::optionalInt($data, 'fixed_amount'),
            (bool) ($data['compound'] ?? false),
            Values::optionalString($data, 'category_code'),
            Values::optionalString($data, 'exemption_reason'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tax_type_id' => $this->taxTypeId,
            'name' => $this->name,
            'percent' => $this->percent,
            'amount' => $this->amount,
            'calculation_type' => $this->calculationType,
            'fixed_amount' => $this->fixedAmount,
            'compound' => $this->compound,
            'category_code' => $this->categoryCode,
            'exemption_reason' => $this->exemptionReason,
        ];
    }
}
