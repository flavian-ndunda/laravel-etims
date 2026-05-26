<?php

declare(strict_types=1);

namespace Flavytech\Etims\DTOs;

use Flavytech\Etims\Exceptions\EtimsValidationException;

/**
 * InvoiceDTO
 *
 * Represents a single invoice to be submitted to KRA eTIMS.
 *
 * Architecture Decision: We use plain PHP DTOs (not Laravel Models or Eloquent)
 * so the SDK is transport-agnostic. The host application maps its domain models
 * to these DTOs before calling the SDK. This keeps a clean boundary between
 * your business logic and KRA's API contract.
 *
 * The DTO is immutable after construction (readonly properties). This prevents
 * accidental mutation before the invoice is submitted.
 *
 * Usage:
 *   $invoice = InvoiceDTO::make([
 *       'invoice_number'  => 'INV-2024-001',
 *       'supplier_pin'    => 'P000000000A',
 *       'buyer_pin'       => 'P000000000B',
 *       'total_amount'    => 11800.00,
 *       'vat_amount'      => 1800.00,
 *       'currency'        => 'KES',
 *       'invoice_date'    => '2024-01-15',
 *       'invoice_type'    => 'S', // S=Sale, C=Credit, D=Debit
 *       'items'           => [...InvoiceLineDTO objects],
 *   ]);
 */
final class InvoiceDTO
{
    /**
     * @param InvoiceLineDTO[] $items
     */
    public function __construct(
        public readonly string $invoiceNumber,
        public readonly string $supplierPin,
        public readonly string $buyerPin,
        public readonly float $totalAmount,
        public readonly float $vatAmount,
        public readonly float $taxableAmount,
        public readonly float $exemptAmount,
        public readonly string $currency,
        public readonly string $invoiceDate,
        public readonly string $invoiceType,       // S=Sale, C=Credit Note, D=Debit Note
        public readonly string $paymentType,       // CASH, CREDIT, MPESA, BANK, etc.
        public readonly array $items,
        public readonly ?string $originalInvoiceNumber = null, // for credit/debit notes
        public readonly ?string $buyerName = null,
        public readonly ?string $branchId = null,
        public readonly ?string $remarks = null,
        public readonly ?string $idempotencyKey = null,
    ) {}

    /**
     * Named constructor for array-based creation (useful with form data or DB rows).
     *
     * @param array<string, mixed> $data
     * @throws EtimsValidationException
     */
    public static function make(array $data): self
    {
        self::validate($data);

        return new self(
            invoiceNumber:          $data['invoice_number'],
            supplierPin:            $data['supplier_pin'],
            buyerPin:               $data['buyer_pin'],
            totalAmount:            (float) $data['total_amount'],
            vatAmount:              (float) $data['vat_amount'],
            taxableAmount:          (float) ($data['taxable_amount'] ?? ($data['total_amount'] - $data['vat_amount'])),
            exemptAmount:           (float) ($data['exempt_amount'] ?? 0.0),
            currency:               $data['currency'] ?? 'KES',
            invoiceDate:            $data['invoice_date'],
            invoiceType:            $data['invoice_type'] ?? 'S',
            paymentType:            $data['payment_type'] ?? 'CASH',
            items:                  $data['items'] ?? [],
            originalInvoiceNumber:  $data['original_invoice_number'] ?? null,
            buyerName:              $data['buyer_name'] ?? null,
            branchId:               $data['branch_id'] ?? null,
            remarks:                $data['remarks'] ?? null,
            idempotencyKey:         $data['idempotency_key'] ?? null,
        );
    }

    /**
     * Generate a deterministic idempotency key for this invoice.
     *
     * The key is based on invoice_number + supplier_pin + total_amount.
     * This ensures the same invoice always produces the same key, even across
     * retries, while a genuinely different invoice gets a different key.
     */
    public function resolveIdempotencyKey(): string
    {
        return $this->idempotencyKey ?? md5(
            $this->invoiceNumber . $this->supplierPin . $this->totalAmount
        );
    }

    /**
     * Serialize to the KRA API payload format.
     *
     * This method knows the KRA field naming conventions so the rest of
     * your code can use clean PHP conventions (camelCase, descriptive names).
     *
     * @return array<string, mixed>
     */
    public function toKraPayload(): array
    {
        return [
            'invcNo'    => $this->invoiceNumber,
            'tpin'      => $this->supplierPin,
            'rcptTyCd'  => $this->invoiceType,
            'pmtTyCd'   => $this->paymentType,
            'cfmDt'     => $this->invoiceDate,
            'salesDt'   => $this->invoiceDate,
            'stockRlsDt' => $this->invoiceDate,
            'custTpin'  => $this->buyerPin,
            'custNm'    => $this->buyerName,
            'curCd'     => $this->currency,
            'totAmt'    => $this->totalAmount,
            'taxblAmt'  => $this->taxableAmount,
            'vatAmt'    => $this->vatAmount,
            'taxAmt'    => $this->vatAmount, // KRA alias
            'nontaxblAmt' => $this->exemptAmount,
            'remark'    => $this->remarks,
            'orgInvcNo' => $this->originalInvoiceNumber,
            'itemList'  => array_map(fn(InvoiceLineDTO $item) => $item->toKraPayload(), $this->items),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @throws EtimsValidationException
     */
    private static function validate(array $data): void
    {
        $required = ['invoice_number', 'supplier_pin', 'buyer_pin', 'total_amount', 'vat_amount', 'invoice_date'];
        $missing  = array_filter($required, fn($key) => empty($data[$key]));

        if (!empty($missing)) {
            throw new EtimsValidationException(
                'Missing required InvoiceDTO fields: ' . implode(', ', $missing)
            );
        }

        if (!in_array($data['invoice_type'] ?? 'S', ['S', 'C', 'D'], true)) {
            throw new EtimsValidationException('invoice_type must be S (Sale), C (Credit Note), or D (Debit Note).');
        }
    }
}
