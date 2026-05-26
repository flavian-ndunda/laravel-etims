<?php

declare(strict_types=1);

namespace Flavytech\Etims\Events;

use Flavytech\Etims\DTOs\InvoiceDTO;
use Flavytech\Etims\DTOs\InvoiceResponseDTO;

/**
 * InvoiceSubmitted
 *
 * Fired when an invoice is successfully accepted by KRA eTIMS.
 *
 * Listen to this event in your application to:
 *   - Generate and send a KRA-compliant receipt to the customer
 *   - Embed the QR code on the receipt/invoice PDF
 *   - Update your POS/ERP system's submission status
 *   - Trigger downstream workflows (e.g. stock deduction after confirmed sale)
 *
 * Example listener registration in EventServiceProvider:
 *
 *   protected $listen = [
 *       \Flavytech\Etims\Events\InvoiceSubmitted::class => [
 *           \App\Listeners\GenerateKraReceipt::class,
 *           \App\Listeners\NotifyAccountingSystem::class,
 *       ],
 *   ];
 */
final class InvoiceSubmitted
{
    public function __construct(
        public readonly InvoiceDTO $invoice,
        public readonly InvoiceResponseDTO $response,
        public readonly string|int|null $tenantId = null,
    ) {}
}
