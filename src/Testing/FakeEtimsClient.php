<?php

declare(strict_types=1);

namespace Flavytech\Etims\Testing;

use Flavytech\Etims\Contracts\EtimsClientContract;
use Flavytech\Etims\DTOs\BranchDTO;
use Flavytech\Etims\DTOs\BranchResponseDTO;
use Flavytech\Etims\DTOs\InvoiceDTO;
use Flavytech\Etims\DTOs\InvoiceResponseDTO;
use Flavytech\Etims\DTOs\PinValidationResponseDTO;
use Flavytech\Etims\DTOs\StockItemDTO;
use Flavytech\Etims\DTOs\StockMovementDTO;
use Flavytech\Etims\DTOs\StockResponseDTO;
use Flavytech\Etims\Exceptions\EtimsApiException;
use PHPUnit\Framework\Assert;

/**
 * FakeEtimsClient
 *
 * A testing double for EtimsClientContract that records all calls
 * and provides a fluent assertion API.
 *
 * This lets you test your application's eTIMS integration without
 * making real HTTP calls to KRA. Design goals:
 *
 *   1. Zero network calls — all operations are in-memory
 *   2. Configurable behavior — can simulate failures, pending states, etc.
 *   3. Fluent assertions — readable test expectations
 *   4. Full API coverage — every SDK method has a fake equivalent
 *
 * Usage in Pest/PHPUnit tests:
 *
 *   beforeEach(function () {
 *       Etims::fake();
 *   });
 *
 *   it('submits invoice on checkout', function () {
 *       $order = Order::factory()->create();
 *       $this->post('/checkout/' . $order->id);
 *       Etims::assertInvoiceSubmitted('INV-001');
 *   });
 *
 *   it('handles KRA downtime gracefully', function () {
 *       Etims::fake()->failWith(new EtimsApiException('KRA is down', 503));
 *       $response = $this->post('/checkout/1');
 *       $response->assertStatus(202); // App queued it for retry
 *   });
 */
class FakeEtimsClient implements EtimsClientContract
{
    /** @var InvoiceDTO[] Submitted invoices, in order */
    private array $submittedInvoices = [];

    /** @var array<string, InvoiceResponseDTO> Keyed by invoice number */
    private array $stubbedResponses = [];

    /** @var string[] Invalid PINs to simulate */
    private array $invalidPins = [];

    /** @var \Throwable|null Exception to throw on next invoice submission */
    private ?\Throwable $failException = null;

    /** @var StockItemDTO[] Stock items synced, in order */
    private array $syncedStockItems = [];

    /** @var StockMovementDTO[] Stock movements recorded, in order */
    private array $recordedMovements = [];

    /** @var \Throwable|null Exception to throw on next stock sync */
    private ?\Throwable $stockFailException = null;

    /** @var \Throwable|null Exception to throw on next stock movement */
    private ?\Throwable $movementFailException = null;

    /** @var array<string, StockResponseDTO> Stubbed stock responses keyed by item_code */
    private array $stubbedStockResponses = [];

    /** @var BranchDTO[] Branches saved, in order */
    private array $savedBranches = [];

    /**
     * Simulate a specific failure on the next submission.
     *
     * @return static For fluent configuration
     */
    public function failWith(\Throwable $exception): static
    {
        $this->failException = $exception;
        return $this;
    }

    /**
     * Simulate a specific failure on the next stock item sync.
     *
     * @return static
     */
    public function failStockSyncWith(\Throwable $exception): static
    {
        $this->stockFailException = $exception;
        return $this;
    }

    /**
     * Simulate a specific failure on the next stock movement recording.
     *
     * @return static
     */
    public function failMovementWith(\Throwable $exception): static
    {
        $this->movementFailException = $exception;
        return $this;
    }

    /**
     * Stub a specific stock response for a given item code.
     *
     * @return static
     */
    public function respondToStockSync(string $itemCode, StockResponseDTO $response): static
    {
        $this->stubbedStockResponses[$itemCode] = $response;
        return $this;
    }

    /**
     * Stub a specific response for a given invoice number.
     *
     * @return static
     */
    public function respondTo(string $invoiceNumber, InvoiceResponseDTO $response): static
    {
        $this->stubbedResponses[$invoiceNumber] = $response;
        return $this;
    }

    /**
     * Mark given PINs as invalid for validatePin() calls.
     *
     * @param string[] $pins
     * @return static
     */
    public function withInvalidPins(array $pins): static
    {
        $this->invalidPins = $pins;
        return $this;
    }

    // =========================================================================
    // EtimsClientContract Implementation
    // =========================================================================

    public function authenticate(): string
    {
        return 'fake_token_' . uniqid();
    }

    public function submitInvoice(InvoiceDTO $invoice): InvoiceResponseDTO
    {
        if ($this->failException) {
            $exception             = $this->failException;
            $this->failException   = null; // consume — only fail once
            throw $exception;
        }

        $this->submittedInvoices[] = $invoice;

        // Return a stubbed response if configured, otherwise return a success
        return $this->stubbedResponses[$invoice->invoiceNumber]
            ?? $this->defaultSuccessResponse($invoice);
    }

    public function getInvoiceStatus(string $invoiceNumber): InvoiceResponseDTO
    {
        return $this->stubbedResponses[$invoiceNumber]
            ?? $this->defaultSuccessResponse(null, $invoiceNumber);
    }

    public function validatePin(string $pin): PinValidationResponseDTO
    {
        $isValid = !in_array($pin, $this->invalidPins, true);

        return new PinValidationResponseDTO(
            isValid:       $isValid,
            pin:           $pin,
            taxpayerName:  $isValid ? 'Test Taxpayer Ltd' : null,
            taxpayerType:  $isValid ? 'Company' : null,
            resultCode:    $isValid ? '000' : '001',
            resultMessage: $isValid ? 'PIN is valid' : 'PIN not found in KRA registry',
        );
    }

    public function syncStock(StockItemDTO $stockItem): StockResponseDTO
    {
        if ($this->stockFailException) {
            $exception                = $this->stockFailException;
            $this->stockFailException = null;
            throw $exception;
        }

        $this->syncedStockItems[] = $stockItem;

        return $this->stubbedStockResponses[$stockItem->itemCode]
            ?? $this->defaultStockResponse($stockItem->itemCode);
    }

    public function recordStockMovement(StockMovementDTO $movement): StockResponseDTO
    {
        if ($this->movementFailException) {
            $exception                    = $this->movementFailException;
            $this->movementFailException  = null;
            throw $exception;
        }

        $this->recordedMovements[] = $movement;

        return $this->stubbedStockResponses[$movement->itemCode]
            ?? $this->defaultStockResponse($movement->itemCode);
    }

    // =========================================================================
    // Stock Assertion Methods
    // =========================================================================

    /**
     * Assert that a stock item with the given code was synced to KRA.
     */
    public function assertStockSynced(string $itemCode): void
    {
        $codes = array_map(fn(StockItemDTO $i) => $i->itemCode, $this->syncedStockItems);

        Assert::assertContains(
            $itemCode,
            $codes,
            "Expected stock item [{$itemCode}] to have been synced, but it was not. " .
            'Synced: [' . implode(', ', $codes) . ']'
        );
    }

    /**
     * Assert that no stock items were synced.
     */
    public function assertNoStockSynced(): void
    {
        Assert::assertEmpty(
            $this->syncedStockItems,
            'Expected no stock items to be synced, but [' . count($this->syncedStockItems) . '] were.'
        );
    }

    /**
     * Assert that exactly N stock items were synced.
     */
    public function assertStockSyncedCount(int $count): void
    {
        Assert::assertCount(
            $count,
            $this->syncedStockItems,
            "Expected [{$count}] stock items synced, but [" . count($this->syncedStockItems) . '] were.'
        );
    }

    /**
     * Assert a stock sync matching a closure condition.
     *
     * @param callable(StockItemDTO): bool $callback
     */
    public function assertStockSyncedMatching(callable $callback): void
    {
        $matching = array_filter($this->syncedStockItems, $callback);

        Assert::assertNotEmpty(
            $matching,
            'No synced stock items matched the given assertion callback.'
        );
    }

    /**
     * Assert that a stock movement for the given item code was recorded.
     */
    public function assertMovementRecorded(string $itemCode): void
    {
        $codes = array_map(fn(StockMovementDTO $m) => $m->itemCode, $this->recordedMovements);

        Assert::assertContains(
            $itemCode,
            $codes,
            "Expected a stock movement for item [{$itemCode}] to have been recorded, but it was not. " .
            'Recorded items: [' . implode(', ', $codes) . ']'
        );
    }

    /**
     * Assert that a movement of a specific type was recorded for an item.
     */
    public function assertMovementRecordedOfType(string $itemCode, string $movementType): void
    {
        $matching = array_filter(
            $this->recordedMovements,
            fn(StockMovementDTO $m) => $m->itemCode === $itemCode && $m->movementType === $movementType
        );

        Assert::assertNotEmpty(
            $matching,
            "Expected a [{$movementType}] movement for item [{$itemCode}], but none was found."
        );
    }

    /**
     * Assert no stock movements were recorded.
     */
    public function assertNoMovementsRecorded(): void
    {
        Assert::assertEmpty(
            $this->recordedMovements,
            'Expected no stock movements to be recorded, but [' . count($this->recordedMovements) . '] were.'
        );
    }

    /**
     * Assert that exactly N movements were recorded.
     */
    public function assertMovementRecordedCount(int $count): void
    {
        Assert::assertCount(
            $count,
            $this->recordedMovements,
            "Expected [{$count}] movements recorded, but [" . count($this->recordedMovements) . '] were.'
        );
    }

    /**
     * Assert a movement matching a closure condition.
     *
     * @param callable(StockMovementDTO): bool $callback
     */
    public function assertMovementRecordedMatching(callable $callback): void
    {
        $matching = array_filter($this->recordedMovements, $callback);

        Assert::assertNotEmpty(
            $matching,
            'No recorded movements matched the given assertion callback.'
        );
    }

    /**
     * Return all synced stock items for custom assertions.
     *
     * @return StockItemDTO[]
     */
    public function syncedStockItems(): array
    {
        return $this->syncedStockItems;
    }

    /**
     * Return all recorded movements for custom assertions.
     *
     * @return StockMovementDTO[]
     */
    public function recordedMovements(): array
    {
        return $this->recordedMovements;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function defaultStockResponse(string $itemCode): StockResponseDTO
    {
        return StockResponseDTO::fromKraResponse($itemCode, [
            'resultCd'  => '000',
            'resultMsg' => 'Processed Successfully',
            'data'      => [
                'itemCd' => $itemCode,
            ],
        ]);
    }

    /**
     * Assert that an invoice with the given number was submitted.
     */
    public function assertSubmitted(string $invoiceNumber): void
    {
        $numbers = array_map(fn(InvoiceDTO $i) => $i->invoiceNumber, $this->submittedInvoices);

        Assert::assertContains(
            $invoiceNumber,
            $numbers,
            "Expected invoice [{$invoiceNumber}] to have been submitted, but it was not. " .
            'Submitted: [' . implode(', ', $numbers) . ']'
        );
    }

    /**
     * Assert that no invoices were submitted.
     */
    public function assertNothingSubmitted(): void
    {
        Assert::assertEmpty(
            $this->submittedInvoices,
            'Expected no invoices to be submitted, but [' . count($this->submittedInvoices) . '] were submitted.'
        );
    }

    /**
     * Assert that exactly N invoices were submitted.
     */
    public function assertSubmittedCount(int $count): void
    {
        Assert::assertCount(
            $count,
            $this->submittedInvoices,
            "Expected [{$count}] invoices to be submitted, but [" . count($this->submittedInvoices) . '] were.'
        );
    }

    /**
     * Assert a submission matching a closure condition.
     *
     * @param callable(InvoiceDTO): bool $callback
     */
    public function assertSubmittedMatching(callable $callback): void
    {
        $matching = array_filter($this->submittedInvoices, $callback);

        Assert::assertNotEmpty(
            $matching,
            'No submitted invoices matched the given assertion callback.'
        );
    }

    /**
     * Return all submitted invoices for custom assertions.
     *
     * @return InvoiceDTO[]
     */
    public function submittedInvoices(): array
    {
        return $this->submittedInvoices;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function defaultSuccessResponse(?InvoiceDTO $invoice = null, ?string $invoiceNumber = null): InvoiceResponseDTO
    {
        return InvoiceResponseDTO::fromKraResponse([
            'resultCd'  => '000',
            'resultMsg' => 'Processed Successfully',
            'data'      => [
                'rcptNo'      => 'RCPT-' . strtoupper(uniqid()),
                'intrlData'   => 'INTERNAL-' . uniqid(),
                'qrCodeUrl'   => 'https://etims.kra.go.ke/qr/' . uniqid(),
                'sdcId'       => 'SDC-001',
                'sdcDateTime' => now()->toIso8601String(),
            ],
        ]);
    }

    public function saveBranch(BranchDTO $branch): BranchResponseDTO
    {
        $this->savedBranches[] = $branch;

        return BranchResponseDTO::fromKraResponse($branch->branchId, [
            'resultCd'  => '000',
            'resultMsg' => 'Branch saved successfully',
            'data'      => [],
        ]);
    }

    public function getBranches(): array
    {
        return array_map(fn(BranchDTO $b) => BranchResponseDTO::fromKraResponse($b->branchId, [
            'resultCd'  => '000',
            'resultMsg' => 'OK',
            'data'      => [],
        ]), $this->savedBranches);
    }

    // =========================================================================
    // Branch Assertion Methods
    // =========================================================================

    public function assertBranchSaved(string $branchId): void
    {
        $ids = array_map(fn(BranchDTO $b) => $b->branchId, $this->savedBranches);

        Assert::assertContains(
            $branchId,
            $ids,
            "Expected branch [{$branchId}] to have been saved, but it was not. " .
            'Saved: [' . implode(', ', $ids) . ']'
        );
    }

    public function assertNoBranchesSaved(): void
    {
        Assert::assertEmpty($this->savedBranches, 'Expected no branches to be saved.');
    }

    /** @return BranchDTO[] */
    public function savedBranches(): array
    {
        return $this->savedBranches;
    }
}
