<?php

declare(strict_types=1);

namespace Flavytech\Etims\DTOs;

use Flavytech\Etims\Exceptions\EtimsValidationException;

/**
 * DebitNoteDTO
 *
 * Represents a debit note to be submitted to KRA eTIMS.
 *
 * A debit note increases the amount owed on a previously submitted invoice.
 * Common use cases:
 *   - Additional charges discovered after invoice submission
 *   - Price corrections upward
 *   - Additional goods/services added to an existing order
 *
 * Like CreditNoteDTO, a DebitNoteDTO always references the original invoice
 * and converts to an InvoiceDTO with type 'D' for submission to KRA.
 *
 * Usage:
 *   $debit = DebitNoteDTO::make([
 *       'debit_note_number'     => 'DN-2024-001',
 *       'original_invoice_number' => 'INV-2024-001',
 *       'supplier_pin'          => 'P000000000A',
 *       'buyer_pin'             => 'P000000000B',
 *       'debit_amount'          => 2000.00,
 *       'vat_amount'            => 320.00,
 *       'reason'                => 'Additional delivery charges',
 *       'items'                 => [...],
 *   ]);
 *   Etims::submitInvoice($debit->toInvoiceDTO());
 */
final class DebitNoteDTO
{
    public function __construct(
        public readonly string $debitNoteNumber,
        public readonly string $originalInvoiceNumber,
        public readonly string $supplierPin,
        public readonly string $buyerPin,
        public readonly float $debitAmount,
        public readonly float $vatAmount,
        public readonly float $taxableAmount,
        public readonly string $debitDate,
        public readonly string $reason,
        public readonly string $currency,
        public readonly string $paymentType,
        public readonly array $items,
        public readonly ?string $buyerName = null,
        public readonly ?string $branchId = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @throws EtimsValidationException
     */
    public static function make(array $data): self
    {
        $required = ['debit_note_number', 'original_invoice_number', 'supplier_pin',
                     'buyer_pin', 'debit_amount', 'vat_amount', 'reason'];

        $missing = array_filter($required, fn($k) => empty($data[$k]));

        if (!empty($missing)) {
            throw new EtimsValidationException(
                'Missing required DebitNoteDTO fields: ' . implode(', ', $missing)
            );
        }

        return new self(
            debitNoteNumber:       $data['debit_note_number'],
            originalInvoiceNumber: $data['original_invoice_number'],
            supplierPin:           $data['supplier_pin'],
            buyerPin:              $data['buyer_pin'],
            debitAmount:           (float) $data['debit_amount'],
            vatAmount:             (float) $data['vat_amount'],
            taxableAmount:         (float) ($data['taxable_amount'] ?? ($data['debit_amount'] - $data['vat_amount'])),
            debitDate:             $data['debit_date'] ?? now()->toDateString(),
            reason:                $data['reason'],
            currency:              $data['currency'] ?? 'KES',
            paymentType:           $data['payment_type'] ?? 'CASH',
            items:                 $data['items'] ?? [],
            buyerName:             $data['buyer_name'] ?? null,
            branchId:              $data['branch_id'] ?? null,
        );
    }

    /**
     * Convert to an InvoiceDTO with type 'D' (Debit Note) for KRA submission.
     */
    public function toInvoiceDTO(): InvoiceDTO
    {
        return new InvoiceDTO(
            invoiceNumber:         $this->debitNoteNumber,
            supplierPin:           $this->supplierPin,
            buyerPin:              $this->buyerPin,
            totalAmount:           $this->debitAmount,
            vatAmount:             $this->vatAmount,
            taxableAmount:         $this->taxableAmount,
            exemptAmount:          0.0,
            currency:              $this->currency,
            invoiceDate:           $this->debitDate,
            invoiceType:           'D', // D = Debit Note
            paymentType:           $this->paymentType,
            items:                 $this->items,
            originalInvoiceNumber: $this->originalInvoiceNumber,
            buyerName:             $this->buyerName,
            branchId:              $this->branchId,
            remarks:               $this->reason,
        );
    }
}
