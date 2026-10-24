<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Tests;

use InvalidArgumentException;
use InvoiceShelf\Modules\Contracts\Host\InvoiceDocumentReader;
use InvoiceShelf\Modules\Contracts\Host\PdfCapabilities;
use InvoiceShelf\Modules\Documents\Data\DocumentParty;
use InvoiceShelf\Modules\Documents\Data\DocumentTax;
use InvoiceShelf\Modules\Documents\Data\InvoiceDocument;
use InvoiceShelf\Modules\Pdf\Contracts\InvoicePdfAttachmentProvider;
use InvoiceShelf\Modules\Pdf\Data\FacturXAttachment;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;

class DocumentContractsTest extends TestCase
{
    public function test_host_contracts_expose_the_exact_document_boundary(): void
    {
        $this->assertSame(['invoiceDocument', 'seller'], $this->methods(InvoiceDocumentReader::class));
        $this->assertSame(['embedsFacturX'], $this->methods(PdfCapabilities::class));
        $this->assertSame(['appliesTo', 'attachmentFor'], $this->methods(InvoicePdfAttachmentProvider::class));

        $read = new ReflectionMethod(InvoiceDocumentReader::class, 'invoiceDocument');
        $this->assertSame(['int', 'int'], array_map(static fn ($parameter): string => (string) $parameter->getType(), $read->getParameters()));
        $this->assertSame('?'.InvoiceDocument::class, (string) $read->getReturnType());
        $this->assertSame('?'.DocumentParty::class, (string) (new ReflectionMethod(InvoiceDocumentReader::class, 'seller'))->getReturnType());
        $this->assertSame('?'.FacturXAttachment::class, (string) (new ReflectionMethod(InvoicePdfAttachmentProvider::class, 'attachmentFor'))->getReturnType());
    }

    public function test_an_invoice_document_round_trips_through_its_array_shape(): void
    {
        $data = self::creditNote();

        $document = InvoiceDocument::fromArray($data);

        $this->assertSame($data, $document->toArray());
        $this->assertTrue($document->isCreditNote());
        $this->assertSame('DE123456789', $document->seller->vatId);
        $this->assertSame('DE', $document->buyer?->address?->countryCode);
        $this->assertSame('RE-2026-0001', $document->related?->number);
        $this->assertSame('-2.00', $document->lines[0]->quantity);
        $this->assertSame('S', $document->lines[0]->taxes[0]->categoryCode);
        $this->assertSame(DocumentTax::CALCULATION_PERCENTAGE, $document->lines[0]->taxes[0]->calculationType);
    }

    public function test_optional_parts_default_when_absent(): void
    {
        $data = self::creditNote();
        unset($data['type'], $data['buyer'], $data['related'], $data['delivery_address'], $data['lines'], $data['taxes'], $data['currency_precision']);

        $document = InvoiceDocument::fromArray($data);

        $this->assertFalse($document->isCreditNote());
        $this->assertNull($document->buyer);
        $this->assertSame([], $document->lines);
        $this->assertSame(2, $document->currencyPrecision);
    }

    /** @param  array<string, mixed>  $changes */
    #[DataProvider('malformedDocuments')]
    public function test_malformed_document_data_is_rejected(array $changes, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        InvoiceDocument::fromArray(array_replace(self::creditNote(), $changes));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function malformedDocuments(): iterable
    {
        yield 'missing number' => [['number' => null], "a string 'number'"];
        yield 'fractional total' => [['total' => '12.50'], "an integer 'total'"];
        yield 'missing seller' => [['seller' => null], "a 'seller'"];
        yield 'scalar buyer' => [['buyer' => 'Muster GmbH'], "'buyer' to be an object or null"];
        yield 'lines as a map' => [['lines' => ['first' => []]], "a list 'lines'"];
        yield 'scalar line' => [['lines' => ['one']], "Every entry of 'lines'"];
    }

    public function test_a_facturx_attachment_needs_xml_and_a_known_profile(): void
    {
        $attachment = new FacturXAttachment('<rsm:CrossIndustryInvoice/>');

        $this->assertSame(FacturXAttachment::PROFILE_EN16931, $attachment->profile);
        $this->assertSame('factur-x.xml', FacturXAttachment::FILENAME);
        $this->assertSame('XRECHNUNG', (new FacturXAttachment('<x/>', 'XRECHNUNG'))->profile);

        foreach ([['  ', FacturXAttachment::PROFILE_EN16931, 'needs its XML'], ['<x/>', 'EN16931', 'must be one of']] as [$xml, $profile, $message]) {
            try {
                new FacturXAttachment($xml, $profile);
                $this->fail("Expected '{$message}'.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }
    }

    /** @return list<string> */
    private function methods(string $interface): array
    {
        return array_map(static fn (ReflectionMethod $method): string => $method->getName(), (new ReflectionClass($interface))->getMethods());
    }

    /** @return array<string, mixed> */
    private static function creditNote(): array
    {
        $address = static fn (string $street, string $zip, string $city): array => [
            'address_street_1' => $street,
            'address_street_2' => null,
            'city' => $city,
            'state' => null,
            'zip' => $zip,
            'country_code' => 'DE',
            'phone' => null,
        ];
        $tax = [
            'tax_type_id' => 3,
            'name' => 'VAT',
            'percent' => '19.00',
            'amount' => -1900,
            'calculation_type' => 'percentage',
            'fixed_amount' => null,
            'compound' => false,
            'category_code' => 'S',
            'exemption_reason' => null,
        ];

        return [
            'id' => 7,
            'company_id' => 1,
            'number' => 'CN-2026-0001',
            'type' => 'CREDIT_NOTE',
            'invoice_date' => '2026-10-24',
            'due_date' => null,
            'currency_code' => 'EUR',
            'currency_precision' => 2,
            'tax_per_item' => true,
            'discount_per_item' => false,
            'tax_included' => false,
            'sub_total' => -10000,
            'discount_val' => 0,
            'tax' => -1900,
            'total' => -11900,
            'due_amount' => 0,
            'seller' => [
                'name' => 'Acme GmbH',
                'company_name' => null,
                'vat_id' => 'DE123456789',
                'tax_id' => '12/345/67890',
                'email' => null,
                'phone' => '+49 30 1234567',
                'contact_name' => null,
                'address' => $address('Hauptstraße 1', '10115', 'Berlin'),
            ],
            'buyer' => [
                'name' => 'Erika Muster',
                'company_name' => 'Muster GmbH',
                'vat_id' => null,
                'tax_id' => null,
                'email' => 'erika@muster.test',
                'phone' => null,
                'contact_name' => 'Erika Muster',
                'address' => $address('Marktplatz 5', '80331', 'München'),
            ],
            'lines' => [[
                'id' => 11,
                'name' => 'Consulting',
                'description' => 'On site',
                'quantity' => '-2.00',
                'price' => 5000,
                'discount_val' => 0,
                'total' => -10000,
                'unit_name' => 'hour',
                'unit_code' => 'HUR',
                'taxes' => [$tax],
            ]],
            'taxes' => [],
            'reference_number' => 'RE-2026-0001',
            'notes' => 'Thank you.',
            'credit_reason' => 'Returned goods',
            'related' => ['id' => 6, 'number' => 'RE-2026-0001', 'date' => '2026-10-01'],
            'delivery_address' => null,
            'exchange_rate' => '1.000000',
            'company_currency_code' => 'EUR',
        ];
    }
}
