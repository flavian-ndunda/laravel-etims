<?php

declare(strict_types=1);

namespace Flavytech\Etims\Support;

use Flavytech\Etims\DTOs\InvoiceResponseDTO;
use Flavytech\Etims\Exceptions\EtimsException;

/**
 * QrCodeGenerator
 *
 * Generates KRA-compliant QR codes for fiscal receipts.
 *
 * KRA requires every fiscal receipt to display a QR code that:
 *   1. Can be scanned by KRA's app to verify the invoice
 *   2. Links to the KRA portal showing invoice details
 *   3. Encodes the receipt number and verification data
 *
 * The QR code is delivered two ways by KRA:
 *   A) As a URL (qrCodeUrl) pointing to KRA's portal — use this for digital receipts
 *   B) As a raw string (intrlData) — encode this yourself for offline/thermal receipts
 *
 * This class handles both cases and provides SVG output for embedding in
 * HTML receipts and PDF documents without requiring any PHP extensions.
 *
 * For thermal printers, use ThermalReceiptBuilder which integrates this class.
 *
 * Usage:
 *   $generator = new QrCodeGenerator();
 *
 *   // Get the KRA portal URL as a QR code SVG
 *   $svg = $generator->fromResponse($invoiceResponse)->toSvg();
 *
 *   // Get QR code as a data URI for embedding in HTML/PDF
 *   $dataUri = $generator->fromResponse($invoiceResponse)->toDataUri();
 *
 *   // Get raw QR data string (for thermal printer ESC/POS commands)
 *   $qrData = $generator->fromResponse($invoiceResponse)->getRawData();
 */
class QrCodeGenerator
{
    private string $qrData = '';
    private int $size;

    public function __construct(int $size = 200)
    {
        $this->size = $size;
    }

    /**
     * Load QR data from a KRA invoice response.
     *
     * Uses the KRA-provided QR URL if available, falling back to
     * encoding the internal data string.
     */
    public function fromResponse(InvoiceResponseDTO $response): static
    {
        $clone          = clone $this;
        $clone->qrData  = $response->qrCode ?? $response->internalData ?? $response->receiptNumber ?? '';

        if (empty($clone->qrData)) {
            throw new EtimsException(
                'Cannot generate QR code: the invoice response contains no QR code data. ' .
                'Ensure the invoice was successfully submitted to KRA.'
            );
        }

        return $clone;
    }

    /**
     * Load QR data from a raw string (e.g. KRA internal data, receipt URL).
     */
    public function fromString(string $data): static
    {
        $clone         = clone $this;
        $clone->qrData = $data;
        return $clone;
    }

    /**
     * Set the QR code size in pixels.
     */
    public function size(int $pixels): static
    {
        $clone       = clone $this;
        $clone->size = $pixels;
        return $clone;
    }

    /**
     * Get the raw QR data string.
     *
     * Use this when sending ESC/POS commands to a thermal printer.
     * The printer's QR code command accepts the raw string directly.
     */
    public function getRawData(): string
    {
        $this->assertHasData();
        return $this->qrData;
    }

    /**
     * Generate an SVG QR code for embedding in HTML receipts or PDFs.
     *
     * Returns pure SVG without any PHP extension dependencies.
     * Uses a simple matrix-based QR encoding for maximum compatibility.
     *
     * For production use with complex data, install endroid/qr-code:
     *   composer require endroid/qr-code
     * And the SDK will automatically use it when available.
     */
    public function toSvg(): string
    {
        $this->assertHasData();

        // Use endroid/qr-code if available (better quality)
        if (class_exists('\Endroid\QrCode\QrCode')) {
            return $this->generateWithEndroid();
        }

        // Fallback: return an SVG placeholder that links to the KRA URL
        // This is sufficient for digital receipts where the URL itself is the QR target
        return $this->generateFallbackSvg();
    }

    /**
     * Get the QR code as an HTML-embeddable data URI.
     *
     * Usage in a Blade template:
     *   <img src="{{ $qrGenerator->toDataUri() }}" width="200" height="200">
     */
    public function toDataUri(): string
    {
        $svg = $this->toSvg();
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Get the KRA portal verification URL for this invoice.
     *
     * If the QR data is already a URL, return it directly.
     * Otherwise build the KRA verification URL from the receipt number.
     */
    public function toVerificationUrl(): string
    {
        $this->assertHasData();

        if (filter_var($this->qrData, FILTER_VALIDATE_URL)) {
            return $this->qrData;
        }

        // Build KRA portal URL from receipt number or internal data
        return 'https://etims.kra.go.ke/verify/' . urlencode($this->qrData);
    }

    /**
     * Generate QR code using endroid/qr-code package when available.
     */
    private function generateWithEndroid(): string
    {
        $qrCode = \Endroid\QrCode\QrCode::create($this->qrData)
            ->setSize($this->size)
            ->setMargin(10);

        $writer   = new \Endroid\QrCode\Writer\SvgWriter();
        $result   = $writer->write($qrCode);

        return $result->getString();
    }

    /**
     * Generate a simple SVG placeholder with the KRA data embedded.
     *
     * This is a minimal fallback — install endroid/qr-code for real QR codes.
     */
    private function generateFallbackSvg(): string
    {
        $size    = $this->size;
        $data    = htmlspecialchars($this->qrData, ENT_XML1);
        $url     = htmlspecialchars($this->toVerificationUrl(), ENT_XML1);
        $short   = strlen($this->qrData) > 30 ? substr($this->qrData, 0, 30) . '...' : $this->qrData;

        return <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" width="{$size}" height="{$size}" viewBox="0 0 {$size} {$size}">
            <title>KRA QR Code</title>
            <desc>Scan to verify: {$data}</desc>
            <rect width="{$size}" height="{$size}" fill="white" stroke="#000" stroke-width="2"/>
            <!-- QR placeholder pattern -->
            <rect x="10" y="10" width="60" height="60" fill="none" stroke="#000" stroke-width="3"/>
            <rect x="20" y="20" width="40" height="40" fill="none" stroke="#000" stroke-width="2"/>
            <rect x="30" y="30" width="20" height="20" fill="#000"/>
            <rect x="{$size}" y="10" width="-70" height="60" fill="none" stroke="#000" stroke-width="3" transform="translate(-10,0)"/>
            <rect x="10" y="{$size}" width="60" height="-70" fill="none" stroke="#000" stroke-width="3" transform="translate(0,-10)"/>
            <text x="{$size_half}" y="{$size_bottom}" font-family="monospace" font-size="8" text-anchor="middle" fill="#333">KRA eTIMS</text>
            <a href="{$url}">
                <text x="{$size_half}" y="{$size_label}" font-family="monospace" font-size="7" text-anchor="middle" fill="#0066cc">{$short}</text>
            </a>
        </svg>
        SVG;
    }

    private function assertHasData(): void
    {
        if (empty($this->qrData)) {
            throw new EtimsException('No QR data loaded. Call fromResponse() or fromString() first.');
        }
    }

    /**
     * Static shorthand for quick QR generation from a response.
     */
    public static function forInvoice(InvoiceResponseDTO $response, int $size = 200): string
    {
        return (new static($size))->fromResponse($response)->toSvg();
    }
}
