<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Documents\Data;

use InvoiceShelf\Modules\Contracts\Host\InvoiceDocumentReader;

/**
 * An invoice or credit note as a module reads it: everything a structured
 * document such as an EN 16931 e-invoice needs, as plain values.
 *
 * Read through {@see InvoiceDocumentReader}.
 * Money is an integer in hundredths of the currency unit, exactly as the host
 * stores it, for every currency whatever its precision (`currency_precision`
 * is how many of those digits the currency shows). Amounts keep their stored
 * sign: a credit note's prices, totals and taxes are negative. Nothing is
 * recalculated. Dates are `Y-m-d`; `notes` is plain text with the host's
 * placeholders already filled in. `tax_per_item` means each line carries its
 * own `taxes`; otherwise the document-level `taxes` apply to every line.
 * `exchange_rate` (a decimal string) converts to `company_currency_code`.
 */
final class InvoiceDocument
{
    public const TYPE_INVOICE = 'INVOICE';

    public const TYPE_CREDIT_NOTE = 'CREDIT_NOTE';

    /**
     * @param  list<DocumentLine>  $lines
     * @param  list<DocumentTax>  $taxes
     */
    public function __construct(
        public readonly int $id,
        public readonly int $companyId,
        public readonly string $number,
        public readonly string $type,
        public readonly string $invoiceDate,
        public readonly ?string $dueDate,
        public readonly string $currencyCode,
        public readonly int $currencyPrecision,
        public readonly bool $taxPerItem,
        public readonly bool $discountPerItem,
        public readonly bool $taxIncluded,
        public readonly int $subTotal,
        public readonly int $discountVal,
        public readonly int $tax,
        public readonly int $total,
        public readonly int $dueAmount,
        public readonly DocumentParty $seller,
        public readonly ?DocumentParty $buyer,
        public readonly array $lines = [],
        public readonly array $taxes = [],
        public readonly ?string $referenceNumber = null,
        public readonly ?string $notes = null,
        public readonly ?string $creditReason = null,
        public readonly ?RelatedDocument $related = null,
        public readonly ?PostalAddress $deliveryAddress = null,
        public readonly ?string $exchangeRate = null,
        public readonly ?string $companyCurrencyCode = null,
    ) {}

    public function isCreditNote(): bool
    {
        return $this->type === self::TYPE_CREDIT_NOTE;
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        foreach (['seller', 'buyer', 'related', 'delivery_address'] as $key) {
            if (($data[$key] ?? null) !== null && ! is_array($data[$key])) {
                throw new \InvalidArgumentException("Document data needs '{$key}' to be an object or null.");
            }
        }

        if (! is_array($data['seller'] ?? null)) {
            throw new \InvalidArgumentException("Document data needs a 'seller'.");
        }

        return new self(
            id: Values::int($data, 'id'),
            companyId: Values::int($data, 'company_id'),
            number: Values::string($data, 'number'),
            type: Values::optionalString($data, 'type') ?? self::TYPE_INVOICE,
            invoiceDate: Values::string($data, 'invoice_date'),
            dueDate: Values::optionalString($data, 'due_date'),
            currencyCode: Values::string($data, 'currency_code'),
            currencyPrecision: Values::optionalInt($data, 'currency_precision') ?? 2,
            taxPerItem: (bool) ($data['tax_per_item'] ?? false),
            discountPerItem: (bool) ($data['discount_per_item'] ?? false),
            taxIncluded: (bool) ($data['tax_included'] ?? false),
            subTotal: Values::int($data, 'sub_total'),
            discountVal: Values::optionalInt($data, 'discount_val') ?? 0,
            tax: Values::int($data, 'tax'),
            total: Values::int($data, 'total'),
            dueAmount: Values::int($data, 'due_amount'),
            seller: DocumentParty::fromArray($data['seller']),
            buyer: isset($data['buyer']) ? DocumentParty::fromArray($data['buyer']) : null,
            lines: array_map(DocumentLine::fromArray(...), Values::list($data, 'lines')),
            taxes: array_map(DocumentTax::fromArray(...), Values::list($data, 'taxes')),
            referenceNumber: Values::optionalString($data, 'reference_number'),
            notes: Values::optionalString($data, 'notes'),
            creditReason: Values::optionalString($data, 'credit_reason'),
            related: isset($data['related']) ? RelatedDocument::fromArray($data['related']) : null,
            deliveryAddress: isset($data['delivery_address']) ? PostalAddress::fromArray($data['delivery_address']) : null,
            exchangeRate: Values::optionalString($data, 'exchange_rate'),
            companyCurrencyCode: Values::optionalString($data, 'company_currency_code'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->companyId,
            'number' => $this->number,
            'type' => $this->type,
            'invoice_date' => $this->invoiceDate,
            'due_date' => $this->dueDate,
            'currency_code' => $this->currencyCode,
            'currency_precision' => $this->currencyPrecision,
            'tax_per_item' => $this->taxPerItem,
            'discount_per_item' => $this->discountPerItem,
            'tax_included' => $this->taxIncluded,
            'sub_total' => $this->subTotal,
            'discount_val' => $this->discountVal,
            'tax' => $this->tax,
            'total' => $this->total,
            'due_amount' => $this->dueAmount,
            'seller' => $this->seller->toArray(),
            'buyer' => $this->buyer?->toArray(),
            'lines' => array_map(static fn (DocumentLine $line): array => $line->toArray(), $this->lines),
            'taxes' => array_map(static fn (DocumentTax $tax): array => $tax->toArray(), $this->taxes),
            'reference_number' => $this->referenceNumber,
            'notes' => $this->notes,
            'credit_reason' => $this->creditReason,
            'related' => $this->related?->toArray(),
            'delivery_address' => $this->deliveryAddress?->toArray(),
            'exchange_rate' => $this->exchangeRate,
            'company_currency_code' => $this->companyCurrencyCode,
        ];
    }
}
