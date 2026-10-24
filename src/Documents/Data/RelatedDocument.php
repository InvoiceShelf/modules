<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Documents\Data;

/**
 * The document another one refers to, such as the invoice a credit note
 * corrects. `date` is `Y-m-d`.
 */
final class RelatedDocument
{
    public function __construct(
        public readonly int $id,
        public readonly string $number,
        public readonly ?string $date,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(Values::int($data, 'id'), Values::string($data, 'number'), Values::optionalString($data, 'date'));
    }

    /** @return array{id: int, number: string, date: ?string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'number' => $this->number, 'date' => $this->date];
    }
}
