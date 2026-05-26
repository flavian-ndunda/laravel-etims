<?php

declare(strict_types=1);

namespace Flavytech\Etims\Support;

use Flavytech\Etims\DTOs\InvoiceDTO;
use Flavytech\Etims\DTOs\InvoiceLineDTO;
use Flavytech\Etims\DTOs\InvoiceResponseDTO;

/**
 * ThermalReceiptBuilder
 *
 * Builds KRA-compliant fiscal receipts for thermal (POS) printers.
 *
 * Supports two output formats:
 *   1. ESC/POS binary commands — for direct printing to thermal printers
 *      connected via USB, serial, or network (e.g. Epson TM-T20, Star TSP)
 *   2. HTML — for browser-based receipt preview and PDF generation
 *
 * KRA requires fiscal receipts to include:
 *   ✅ Supplier name, PIN, and address
 *   ✅ Receipt/invoice number
 *   ✅ Date and time
 *   ✅ Line items with quantities, unit prices, and VAT
 *   ✅ Totals: taxable amount, VAT amount, total amount
 *   ✅ Payment method
 *   ✅ KRA receipt number (from eTIMS response)
 *   ✅ QR code (for verification)
 *   ✅ "FISCAL RECEIPT" label
 *
 * Usage:
 *   $receipt = ThermalReceiptBuilder::make($invoice, $response)
 *       ->businessName('Acme Supermarket')
 *       ->businessAddress('Tom Mboya St, Nairobi')
 *       ->cashierName('Jane Wanjiru')
 *       ->toHtml();
 *
 *   // For direct thermal printing (ESC/POS):
 *   $escpos = ThermalReceiptBuilder::make($invoice, $response)
 *       ->businessName('Acme Supermarket')
 *       ->toEscPos();
 *   // Send $escpos bytes to your printer socket/serial port
 */
class ThermalReceiptBuilder
{
    private string $businessName   = '';
    private string $businessAddress = '';
    private string $businessPhone  = '';
    private string $cashierName    = '';
    private string $branchName     = '';
    private int $paperWidth        = 48; // characters wide (80mm paper = 48 chars)

    public function __construct(
        private readonly InvoiceDTO $invoice,
        private readonly InvoiceResponseDTO $response,
    ) {}

    public static function make(InvoiceDTO $invoice, InvoiceResponseDTO $response): static
    {
        return new static($invoice, $response);
    }

    public function businessName(string $name): static
    {
        $this->businessName = $name;
        return $this;
    }

    public function businessAddress(string $address): static
    {
        $this->businessAddress = $address;
        return $this;
    }

    public function businessPhone(string $phone): static
    {
        $this->businessPhone = $phone;
        return $this;
    }

    public function cashierName(string $name): static
    {
        $this->cashierName = $name;
        return $this;
    }

    public function branchName(string $branch): static
    {
        $this->branchName = $branch;
        return $this;
    }

    /**
     * Set paper width in characters (default 48 for 80mm paper).
     * Use 32 for 58mm paper.
     */
    public function paperWidth(int $chars): static
    {
        $this->paperWidth = $chars;
        return $this;
    }

    // =========================================================================
    // HTML Output — for browser preview and PDF generation
    // =========================================================================

    /**
     * Generate an HTML receipt ready for browser display or wkhtmltopdf.
     *
     * The HTML is self-contained with inline CSS — no external dependencies.
     * Embed directly in a Blade view or pass to a PDF library.
     */
    public function toHtml(): string
    {
        $qrSvg    = $this->buildQrSvg();
        $items    = $this->buildHtmlItems();
        $now      = now()->format('d/m/Y H:i:s');

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>KRA Fiscal Receipt</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { font-family: 'Courier New', monospace; font-size: 12px; width: 302px; padding: 10px; }
                .center { text-align: center; }
                .right  { text-align: right; }
                .bold   { font-weight: bold; }
                .large  { font-size: 16px; }
                .small  { font-size: 10px; }
                .divider { border-top: 1px dashed #000; margin: 6px 0; }
                .row    { display: flex; justify-content: space-between; margin: 2px 0; }
                .fiscal-label { font-size: 14px; font-weight: bold; letter-spacing: 2px; border: 2px solid #000; padding: 4px 8px; display: inline-block; margin: 6px 0; }
                table   { width: 100%; border-collapse: collapse; }
                td      { padding: 2px 0; vertical-align: top; }
                td.amount { text-align: right; white-space: nowrap; }
                .qr     { text-align: center; margin: 8px 0; }
                .kra-ref { font-size: 10px; word-break: break-all; }
            </style>
        </head>
        <body>
            <div class="center bold large">{$this->businessName}</div>
            <div class="center">{$this->businessAddress}</div>
            <div class="center">{$this->businessPhone}</div>
            <div class="center">PIN: {$this->invoice->supplierPin}</div>
            {$this->branchNameHtml()}
            <div class="divider"></div>
            <div class="center"><span class="fiscal-label">FISCAL RECEIPT</span></div>
            <div class="divider"></div>
            <div class="row"><span>Receipt No:</span><span class="bold">{$this->response->receiptNumber}</span></div>
            <div class="row"><span>Invoice No:</span><span>{$this->invoice->invoiceNumber}</span></div>
            <div class="row"><span>Date/Time:</span><span>{$now}</span></div>
            <div class="row"><span>Cashier:</span><span>{$this->cashierName}</span></div>
            <div class="row"><span>Customer PIN:</span><span>{$this->invoice->buyerPin}</span></div>
            <div class="row"><span>Payment:</span><span>{$this->invoice->paymentType}</span></div>
            <div class="divider"></div>
            <table>
                <tr>
                    <td class="bold">Item</td>
                    <td class="bold amount">Qty</td>
                    <td class="bold amount">Price</td>
                    <td class="bold amount">Total</td>
                </tr>
                <tr><td colspan="4"><div class="divider"></div></td></tr>
                {$items}
            </table>
            <div class="divider"></div>
            <div class="row"><span>Taxable Amount:</span><span>KES {$this->fmt($this->invoice->taxableAmount)}</span></div>
            <div class="row"><span>VAT (16%):</span><span>KES {$this->fmt($this->invoice->vatAmount)}</span></div>
            <div class="row bold large"><span>TOTAL:</span><span>KES {$this->fmt($this->invoice->totalAmount)}</span></div>
            <div class="divider"></div>
            <div class="qr">{$qrSvg}</div>
            <div class="center small kra-ref">KRA Ref: {$this->response->internalData}</div>
            <div class="center small">Scan QR to verify this receipt at</div>
            <div class="center small">etims.kra.go.ke</div>
            <div class="divider"></div>
            <div class="center small">Thank you for your business!</div>
            <div class="center small" style="margin-top:4px;">Powered by flavytech/laravel-etims</div>
        </body>
        </html>
        HTML;
    }

    // =========================================================================
    // ESC/POS Output — for direct thermal printer communication
    // =========================================================================

    /**
     * Generate raw ESC/POS byte string for thermal printers.
     *
     * Send this string directly to your printer's socket/serial port.
     *
     * Example (network printer on 192.168.1.100:9100):
     *   $socket = fsockopen('192.168.1.100', 9100);
     *   fwrite($socket, $builder->toEscPos());
     *   fclose($socket);
     */
    public function toEscPos(): string
    {
        $esc = chr(27);
        $gs  = chr(29);

        $out = '';

        // Initialize printer
        $out .= $esc . '@';

        // Center align
        $out .= $esc . 'a' . chr(1);

        // Bold + double size for business name
        $out .= $esc . 'E' . chr(1);
        $out .= $gs . '!' . chr(17); // double width + height
        $out .= $this->businessName . "\n";
        $out .= $gs . '!' . chr(0);
        $out .= $esc . 'E' . chr(0);

        $out .= $this->businessAddress . "\n";
        $out .= 'TEL: ' . $this->businessPhone . "\n";
        $out .= 'PIN: ' . $this->invoice->supplierPin . "\n";

        if ($this->branchName) {
            $out .= $this->branchName . "\n";
        }

        $out .= $this->escDivider();

        // FISCAL RECEIPT label
        $out .= $esc . 'E' . chr(1);
        $out .= '*** FISCAL RECEIPT ***' . "\n";
        $out .= $esc . 'E' . chr(0);

        $out .= $this->escDivider();

        // Left align for details
        $out .= $esc . 'a' . chr(0);

        $out .= $this->escRow('Receipt No:', $this->response->receiptNumber ?? 'N/A');
        $out .= $this->escRow('Invoice No:', $this->invoice->invoiceNumber);
        $out .= $this->escRow('Date/Time:', now()->format('d/m/Y H:i:s'));
        $out .= $this->escRow('Cashier:', $this->cashierName ?: 'N/A');
        $out .= $this->escRow('Cust PIN:', $this->invoice->buyerPin);
        $out .= $this->escRow('Payment:', $this->invoice->paymentType);

        $out .= $this->escDivider();

        // Items header
        $out .= $esc . 'E' . chr(1);
        $out .= str_pad('ITEM', 24) . str_pad('QTY', 6, ' ', STR_PAD_LEFT) . str_pad('TOTAL', 12, ' ', STR_PAD_LEFT) . "\n";
        $out .= $esc . 'E' . chr(0);

        $out .= $this->escDivider();

        // Line items
        foreach ($this->invoice->items as $item) {
            if ($item instanceof InvoiceLineDTO) {
                $name  = substr($item->itemName, 0, 24);
                $out  .= str_pad($name, 24);
                $out  .= str_pad((string) $item->quantity, 6, ' ', STR_PAD_LEFT);
                $out  .= str_pad('KES ' . $this->fmt($item->totalAmount), 12, ' ', STR_PAD_LEFT);
                $out  .= "\n";
                // Unit price on second line
                $out  .= '  @ KES ' . $this->fmt($item->unitPrice) . ' x ' . $item->quantity . "\n";
            }
        }

        $out .= $this->escDivider();

        // Totals
        $out .= $this->escRow('Taxable Amt:', 'KES ' . $this->fmt($this->invoice->taxableAmount));
        $out .= $this->escRow('VAT (16%):', 'KES ' . $this->fmt($this->invoice->vatAmount));

        $out .= $esc . 'E' . chr(1);
        $out .= $gs . '!' . chr(1); // double height
        $out .= $this->escRow('TOTAL:', 'KES ' . $this->fmt($this->invoice->totalAmount));
        $out .= $gs . '!' . chr(0);
        $out .= $esc . 'E' . chr(0);

        $out .= $this->escDivider();

        // QR Code via ESC/POS GS ( k command (QR Code)
        if ($this->response->qrCode) {
            $qrData   = $this->response->qrCode;
            $qrLen    = strlen($qrData) + 3;
            $out .= "\n";
            $out .= $esc . 'a' . chr(1); // center

            // QR Code: Set model
            $out .= $gs . '(k' . chr(4) . chr(0) . chr(49) . chr(65) . chr(50) . chr(0);
            // QR Code: Set size (module size 6)
            $out .= $gs . '(k' . chr(3) . chr(0) . chr(49) . chr(67) . chr(6);
            // QR Code: Set error correction level H
            $out .= $gs . '(k' . chr(3) . chr(0) . chr(49) . chr(69) . chr(48);
            // QR Code: Store data
            $out .= $gs . '(k' . chr($qrLen & 0xff) . chr(($qrLen >> 8) & 0xff) . chr(49) . chr(80) . chr(48) . $qrData;
            // QR Code: Print
            $out .= $gs . '(k' . chr(3) . chr(0) . chr(49) . chr(81) . chr(48);

            $out .= "\n";
        }

        // KRA reference
        $out .= $esc . 'a' . chr(1); // center
        $out .= 'KRA Ref: ' . "\n";
        $out .= wordwrap($this->response->internalData ?? '', $this->paperWidth, "\n", true) . "\n";
        $out .= 'Scan QR at etims.kra.go.ke' . "\n";

        $out .= $this->escDivider();
        $out .= 'Thank you for your business!' . "\n\n\n";

        // Cut paper
        $out .= $gs . 'V' . chr(66) . chr(0);

        return $out;
    }

    // =========================================================================
    // Private Helpers
    // =========================================================================

    private function buildHtmlItems(): string
    {
        $html = '';
        foreach ($this->invoice->items as $item) {
            if ($item instanceof InvoiceLineDTO) {
                $html .= '<tr>';
                $html .= '<td>' . htmlspecialchars($item->itemName) . '<br><small>@ KES ' . $this->fmt($item->unitPrice) . '</small></td>';
                $html .= '<td class="amount">' . $item->quantity . '</td>';
                $html .= '<td class="amount">KES ' . $this->fmt($item->unitPrice) . '</td>';
                $html .= '<td class="amount">KES ' . $this->fmt($item->totalAmount) . '</td>';
                $html .= '</tr>';
            }
        }
        return $html;
    }

    private function buildQrSvg(): string
    {
        try {
            $generator = new QrCodeGenerator(150);
            return $generator->fromResponse($this->response)->toSvg();
        } catch (\Throwable) {
            return '<p style="font-size:9px;">QR code unavailable</p>';
        }
    }

    private function branchNameHtml(): string
    {
        return $this->branchName ? "<div class=\"center\">{$this->branchName}</div>" : '';
    }

    private function escDivider(): string
    {
        return str_repeat('-', $this->paperWidth) . "\n";
    }

    private function escRow(string $label, string $value): string
    {
        $space = $this->paperWidth - strlen($label) - strlen($value);
        return $label . str_repeat(' ', max(1, $space)) . $value . "\n";
    }

    private function fmt(float $amount): string
    {
        return number_format($amount, 2);
    }
}
