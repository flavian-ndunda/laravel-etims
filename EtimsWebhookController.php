<?php

declare(strict_types=1);

namespace Flavytech\Etims\Http\Controllers;

use Flavytech\Etims\Exceptions\EtimsException;
use Flavytech\Etims\Http\Webhooks\WebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

/**
 * EtimsWebhookController
 *
 * Ready-to-use controller for receiving KRA eTIMS webhook notifications.
 *
 * Register this route in your application's routes/api.php:
 *
 *   use Flavytech\Etims\Http\Controllers\EtimsWebhookController;
 *
 *   Route::post('/webhooks/etims', EtimsWebhookController::class)
 *       ->name('etims.webhook')
 *       ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);
 *
 * IMPORTANT: Exclude this route from CSRF middleware (it's a server-to-server
 * call from KRA — CSRF doesn't apply). The signature verification in
 * WebhookProcessor replaces CSRF protection.
 *
 * Set your webhook endpoint URL in the KRA eTIMS portal:
 *   https://yourdomain.com/api/webhooks/etims
 *
 * Set your webhook secret in .env:
 *   ETIMS_WEBHOOK_SECRET=your-kra-webhook-secret
 */
class EtimsWebhookController extends Controller
{
    public function __construct(
        private readonly WebhookProcessor $processor,
    ) {}

    /**
     * Handle incoming KRA eTIMS webhook.
     *
     * Returns 200 immediately — KRA expects a fast response and will
     * retry on non-200 responses. All heavy processing is event-driven.
     */
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $payload = $this->processor->process($request);

            return response()->json([
                'status'     => 'received',
                'event_type' => $payload->eventType,
                'reference'  => $payload->referenceNumber,
            ], 200);

        } catch (EtimsException $e) {
            // Signature failure or config error — reject the webhook
            Log::warning('[eTIMS SDK] Webhook rejected', ['reason' => $e->getMessage()]);

            return response()->json([
                'status'  => 'rejected',
                'message' => $e->getMessage(),
            ], 401);

        } catch (\Throwable $e) {
            // Unexpected error — still return 200 to prevent KRA from retrying
            // Log the error for investigation
            Log::error('[eTIMS SDK] Webhook processing error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['status' => 'error'], 200);
        }
    }
}
