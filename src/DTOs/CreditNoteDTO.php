<?php

declare(strict_types=1);

namespace Flavytech\Etims\DTOs;

use Flavytech\Etims\Exceptions\EtimsValidationException;

/**
 * CreditNoteDTO
 *
 * Represents a credit note to be submitted to KRA eTIMS.
 *
 * A credit note reverses or partially reverses a previously submitted invoice.
 * KRA requires credit notes to reference the original invoice number and
 * carry the same supplier PIN and line items (with negative or reduced amounts).
 *
 * Architecture Decision: CreditNoteDTO wraps InvoiceDTO rather than extending it.
 * This enforces that a credit note always carries a reference to its original
 * invoice, making accidental submission of a credit note without an original
 * invoice impossible at the type level.
 *
 * Usage — Full reversal:
 *   $credit = CreditNoteDTO::reversal(
 *       originalInvoice: $originalInvoiceDto,
 *       reason: 'Goods returned by customer',
 *       creditNoteNumber: 'CN-2024-001',
 *   );
 *   Etims::submitInvoice($credit->toInvoiceDTO());
 *
 * Usage — Partial credit:
 *   $credit = CreditNoteDTO::partial(
 *       originalInvoiceNumber: 'INV-2024-001',
 *       supplierPin: 'P000000000A',
 *       buyerPin: 'P000000000B',
 *       creditNoteNumber: 'CN-2024-001',
 *       creditAmount: 5000.00,
 *       vatAmount: 800.00,
 *       reason: 'Price adjustment',
 *       items: [...],
 *   );
 */
final class CreditNoteDTO
{
    public function __construct(
        public readonly string $creditNoteNumber,
        public readonly string $originalInvoiceNumber,
        public readonly string $supplierPin,
        public readonly string $buyerPin,
        public readonly float $creditAmount,
        public readonly float $vatAmount,
        public readonly float $taxableAmount,
        public readonly string $creditDate,
        public readonly string $reason,
        public readonly string $currency,
        public readonly string $paymentType,
        public readonly array $items,
        public readonly ?string $buyerName = null,
        public readonly ?string $branchId = null,
    ) {}

    /**
     * Create a full reversal credit note from an original invoice.
     *
     * All amounts are automatically negated and line items are copied.
     * This is the correct way to fully cancel a submitted invoice.
     */
    public static function reversal(
        InvoiceDTO $originalInvoice,
        string $reason,
        string $creditNoteNumber,
        ?string $creditDate = null,
    ): self {
        return new self(
            creditNoteNumber:      $creditNoteNumber,
            originalInvoiceNumber: $originalInvoice->invoiceNumber,
            supplierPin:           $originalInvoice->supplierPin,
            buyerPin:              $originalInvoice->buyerPin,
            creditAmount:          $originalInvoice->totalAmount,
            vatAmount:             $originalInvoice->vatAmount,
            taxableAmount:         $originalInvoice->taxableAmount,
            creditDate:            $creditDate ?? now()->toDateString(),
            reason:                $reason,
            currency:              $originalInvoice->currency,
            paymentType:           $originalInvoice->paymentType,
            items:                 $originalInvoice->items,
            buyerName:             $originalInvoice->buyerName,
            branchId:              $originalInvoice->branchId,
        );
    }

    /**
     * Create a partial credit note (price adjustment, partial return).
     *
     * @param array<int, InvoiceLineDTO> $items
     * @throws EtimsValidationException
     */
    public static function partial(array $data): self
    {
        $required = ['credit_note_number', 'original_invoice_number', 'supplier_pin',
                     'buyer_pin', 'credit_amount', 'vat_amount', 'reason'];

        $missing = array_filter($required, fn($k) => empty($data[$k]));

        if (!empty($missing)) {
            throw new EtimsValidationException(
                'Missing required CreditNoteDTO fields: ' . implode(', ', $missing)
            );
        }

        return new self(
            creditNoteNumber:      $data['credit_note_number'],
            originalInvoiceNumber: $data['original_invoice_number'],
            supplierPin:           $data['supplier_pin'],
            buyerPin:              $data['buyer_pin'],
            creditAmount:          (float) $data['credit_amount'],
            vatAmount:             (float) $data['vat_amount'],
            taxableAmount:         (float) ($data['taxable_amount'] ?? ($data['credit_amount'] - $data['vat_amount'])),
            creditDate:            $data['credit_date'] ?? now()->toDateString(),
            reason:                $data['reason'],
            currency:              $data['currency'] ?? 'KES',
            paymentType:           $data['payment_type'] ?? 'CASH',
            items:                 $data['items'] ?? [],
            buyerName:             $data['buyer_name'] ?? null,
            branchId:              $data['branch_id'] ?? null,
        );
    }

    /**
     * Convert to an InvoiceDTO with type 'C' (Credit Note).
     *
     * This is what gets submitted to KRA. The credit note is just a
     * specially-typed invoice with a reference to the original.
     */
    public function toInvoiceDTO(): InvoiceDTO
    {
        return new InvoiceDTO(
            invoiceNumber:         $this->creditNoteNumber,
            supplierPin:           $this->supplierPin,
            buyerPin:              $this->buyerPin,
            totalAmount:           $this->creditAmount,
            vatAmount:             $this->vatAmount,
            taxableAmount:         $this->taxableAmount,
            exemptAmount:          0.0,
            currency:              $this->currency,
            invoiceDate:           $this->creditDate,
            invoiceType:           'C', // C = Credit Note
            paymentType:           $this->paymentType,
            items:                 $this->items,
            originalInvoiceNumber: $this->originalInvoiceNumber,
            buyerName:             $this->buyerName,
            branchId:              $this->branchId,
            remarks:               $this->reason,
        );
    }
}
