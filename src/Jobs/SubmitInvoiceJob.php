<?php

declare(strict_types=1);

namespace Flavytech\Etims\Jobs;

use Flavytech\Etims\Contracts\EtimsClientContract;
use Flavytech\Etims\DTOs\InvoiceDTO;
use Flavytech\Etims\DTOs\InvoiceResponseDTO;
use Flavytech\Etims\Events\InvoiceFailed;
use Flavytech\Etims\Events\InvoiceSubmitted;
use Flavytech\Etims\Exceptions\EtimsApiException;
use Flavytech\Etims\Models\EtimsInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SubmitInvoiceJob
 *
 * The queue job responsible for submitting an invoice to KRA in the background.
 *
 * This is the heart of the SDK's reliability story. It provides:
 *
 * 1. DURABLE RETRY:
 *    The job is persisted to the queue backend (Redis/DB) before execution.
 *    If the worker crashes, the job remains and will be picked up again.
 *    This is fundamentally different from in-process HTTP retries.
 *
 * 2. EXPONENTIAL BACKOFF:
 *    Each retry waits progressively longer: 10s → 30s → 60s → 120s → 300s.
 *    This gives the KRA API time to recover from outages without hammering it.
 *    Backoff values are configurable via etims.queue.backoff.
 *
 * 3. IDEMPOTENCY SAFETY:
 *    Even if the job runs multiple times due to a race condition, the
 *    idempotency key check in EtimsManager prevents double-submission.
 *
 * 4. RETRYABLE VS NON-RETRYABLE FAILURES:
 *    Network errors and 5xx responses are retried.
 *    Validation errors and 4xx responses are NOT retried (they will always fail).
 *    This prevents wasting queue capacity on inevitably-doomed jobs.
 *
 * 5. DEAD LETTER HANDLING:
 *    When maxTries is exhausted, failed() is called, which:
 *      - Marks the DB record as permanently failed
 *      - Fires the InvoiceFailed event for alerting
 *      - Writes a detailed log entry
 *
 * 6. TENANT AWARENESS:
 *    The tenant ID is carried in the job payload so the correct
 *    credentials are used inside the worker process.
 */
class SubmitInvoiceJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Maximum number of attempts before the job is considered dead.
     * Override via etims.queue.max_tries config.
     */
    public int $tries;

    /**
     * Time limit for a single job execution attempt in seconds.
     * Override via etims.queue.timeout config.
     */
    public int $timeout;

    /**
     * Exponential backoff in seconds between attempts.
     * The array defines delays per attempt: [attempt1, attempt2, ...].
     *
     * @var int[]
     */
    public array $backoff;

    /**
     * Whether to fail immediately without retrying on certain exceptions.
     *
     * We define this per-exception in the handle() method.
     */
    public bool $failOnTimeout = false;

    public function __construct(
        public readonly InvoiceDTO $invoice,
        public readonly string|int|null $tenantId = null,
    ) {
        $config = config('etims.queue', []);

        $this->tries   = (int) ($config['max_tries'] ?? 5);
        $this->timeout = (int) ($config['timeout'] ?? 60);
        $this->backoff = $config['backoff'] ?? [10, 30, 60, 120, 300];
    }

    /**
     * Execute the job.
     *
     * The job resolves a fresh EtimsClient from the container on each attempt.
     * This ensures that stale connections, expired tokens, and config changes
     * are picked up on retry, not cached from the original dispatch.
     */
    public function handle(EtimsClientContract $client): void
    {
        $invoiceNumber = $this->invoice->invoiceNumber;

        Log::info('[eTIMS SDK] Processing queued invoice submission', [
            'invoice_number' => $invoiceNumber,
            'tenant_id'      => $this->tenantId,
            'attempt'        => $this->attempts(),
            'max_tries'      => $this->tries,
        ]);

        // Update DB record to show this attempt is in progress
        $this->updateInvoiceRecord('processing', [
            'last_attempt_at' => now(),
            'attempt_count'   => $this->attempts(),
        ]);

        try {
            $response = $client->submitInvoice($this->invoice);

            if ($response->isSuccessful()) {
                // Success — update record and fire event
                $this->updateInvoiceRecord('submitted', [
                    'response'       => $response->toArray(),
                    'receipt_number' => $response->receiptNumber,
                    'qr_code'        => $response->qrCode,
                    'submitted_at'   => now(),
                ]);

                event(new InvoiceSubmitted($this->invoice, $response, $this->tenantId));

                Log::info('[eTIMS SDK] Invoice submitted successfully', [
                    'invoice_number' => $invoiceNumber,
                    'receipt_number' => $response->receiptNumber,
                ]);

            } else {
                // KRA returned a non-success result code — may be retryable
                Log::warning('[eTIMS SDK] KRA returned non-success result', [
                    'invoice_number' => $invoiceNumber,
                    'result_code'    => $response->resultCode,
                    'result_message' => $response->resultMessage,
                ]);

                $this->updateInvoiceRecord('failed', ['failure_reason' => $response->resultMessage]);
                $this->fail(new EtimsApiException(
                    "KRA rejected invoice [{$invoiceNumber}]: {$response->resultMessage}",
                    0,
                    $response->resultCode,
                    $response->rawResponse,
                ));
            }

        } catch (EtimsApiException $e) {
            Log::error('[eTIMS SDK] Invoice submission failed on attempt ' . $this->attempts(), [
                'invoice_number' => $invoiceNumber,
                'error'          => $e->getMessage(),
                'retryable'      => $e->isRetryable(),
                'http_status'    => $e->getHttpStatusCode(),
            ]);

            $this->updateInvoiceRecord('failed', ['failure_reason' => $e->getMessage()]);

            // If the error is NOT retryable (e.g. bad payload, 4xx), fail immediately
            if (!$e->isRetryable()) {
                $this->fail($e);
                return;
            }

            // For retryable errors, re-throw to let the queue handle the retry
            throw $e;
        }
    }

    /**
     * Called by Laravel when all retry attempts are exhausted.
     *
     * This is the "dead letter" handler. It fires the InvoiceFailed event
     * so the application can alert operations and take corrective action.
     */
    public function failed(Throwable $exception): void
    {
        Log::critical('[eTIMS SDK] Invoice permanently failed — all retries exhausted', [
            'invoice_number' => $this->invoice->invoiceNumber,
            'tenant_id'      => $this->tenantId,
            'exception'      => $exception->getMessage(),
            'payload'        => $this->invoice->toKraPayload(),
        ]);

        $this->updateInvoiceRecord('failed', [
            'failure_reason' => $exception->getMessage(),
            'exhausted_at'   => now(),
        ]);

        event(new InvoiceFailed($this->invoice, $exception, $this->tenantId));
    }

    /**
     * Determine when the job should be retried based on attempt number.
     *
     * Laravel calls this to schedule the next attempt.
     * Falls back to linear 60s delay if backoff array runs out.
     */
    public function retryAfter(): int
    {
        $attempt = $this->attempts() - 1; // 0-indexed attempt

        return $this->backoff[$attempt] ?? 60;
    }

    /**
     * Update the etims_invoices record for this job's invoice.
     *
     * @param array<string, mixed> $extra
     */
    private function updateInvoiceRecord(string $status, array $extra = []): void
    {
        try {
            EtimsInvoice::where('idempotency_key', $this->invoice->resolveIdempotencyKey())
                ->update(array_merge(['status' => $status], $extra));
        } catch (Throwable $e) {
            // Non-fatal — log but don't let DB errors break the submission flow
            Log::warning('[eTIMS SDK] Failed to update invoice record', [
                'invoice_number' => $this->invoice->invoiceNumber,
                'error'          => $e->getMessage(),
            ]);
        }
    }
}
