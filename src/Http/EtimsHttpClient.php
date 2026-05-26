<?php

declare(strict_types=1);

namespace Flavytech\Etims\Http;

use Flavytech\Etims\Exceptions\EtimsApiException;
use Flavytech\Etims\Exceptions\EtimsAuthException;
use Flavytech\Etims\Exceptions\EtimsConfigException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * EtimsHttpClient
 *
 * The lowest-level component of the SDK — all actual HTTP communication
 * with the KRA Gava Connect API passes through here.
 *
 * Responsibilities:
 *  1. Token management: acquire, cache, and refresh auth tokens
 *  2. Request construction: build authenticated, correctly-formatted requests
 *  3. Response normalization: parse KRA responses into arrays
 *  4. Error handling: map HTTP and KRA errors to typed SDK exceptions
 *  5. Logging: record all requests and responses for auditability
 *  6. Retry logic: implement synchronous retries with exponential backoff
 *
 * Architecture note: This class is intentionally NOT the retry-everything layer.
 * Queue-level retries (with persistence) are handled by SubmitInvoiceJob.
 * This class handles lightweight in-request retries for transient network blips.
 *
 * This class is designed to be injected via the service container and is
 * tenant-aware — the credentials passed in the constructor determine which
 * KRA account is used. This allows multi-tenant support without any statics.
 */
class EtimsHttpClient
{
    private string $baseUrl;
    private string $cacheKeyPrefix;

    public function __construct(
        private readonly array $credentials,
        private readonly array $httpConfig,
        private readonly array $cacheConfig,
        private readonly array $loggingConfig,
        private readonly string $mode = 'sandbox',
        private readonly array $endpoints = [],
    ) {
        $this->validateCredentials();
        $this->baseUrl        = $this->resolveBaseUrl();
        $this->cacheKeyPrefix = 'etims_token_' . md5($credentials['pin'] . $mode);
    }

    /**
     * Perform an authenticated GET request to the KRA API.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     * @throws EtimsApiException
     */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, [], $query);
    }

    /**
     * Perform an authenticated POST request to the KRA API.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     * @throws EtimsApiException
     */
    public function post(string $path, array $payload = []): array
    {
        return $this->request('POST', $path, $payload);
    }

    /**
     * Acquire and return a valid bearer token.
     *
     * Tokens are cached to avoid re-authenticating on every request.
     * The cache TTL is set to the token's actual expiry minus a buffer
     * (configured via etims.cache.ttl_buffer) to ensure we never use
     * a token that is about to expire.
     *
     * This method is public so the EtimsManager can call it independently
     * for healthcheck and initialization flows.
     *
     * @throws EtimsAuthException
     */
    public function authenticate(): string
    {
        $cacheKey = $this->cacheKeyPrefix;
        $store    = Cache::store($this->cacheConfig['store'] ?? null);

        // Return cached token if still valid
        if ($store->has($cacheKey)) {
            return (string) $store->get($cacheKey);
        }

        $this->logDebug('Authenticating with KRA eTIMS', ['mode' => $this->mode, 'pin' => $this->credentials['pin']]);

        try {
            $response = $this->buildHttpClient(authenticated: false)
                ->post($this->baseUrl . '/auth/token', [
                    'tpin'   => $this->credentials['pin'],
                    'brhId'  => $this->credentials['branch_id'] ?? '00',
                    'dvcSrlNo' => $this->credentials['device_serial'],
                    'secret' => $this->credentials['secret'],
                ]);

            $body = $response->json();

            if (!$response->successful() || !isset($body['data']['token'])) {
                throw new EtimsAuthException(
                    'KRA authentication failed: ' . ($body['resultMsg'] ?? 'Unknown error') .
                    ' [Code: ' . ($body['resultCd'] ?? 'N/A') . ']'
                );
            }

            $token  = $body['data']['token'];
            $expiry = (int) ($body['data']['expiresIn'] ?? 3600);
            $ttl    = max(60, $expiry - ($this->cacheConfig['ttl_buffer'] ?? 300));

            $store->put($cacheKey, $token, $ttl);

            $this->logDebug('KRA authentication successful', ['expires_in' => $expiry, 'cached_for' => $ttl]);

            return $token;

        } catch (EtimsAuthException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new EtimsAuthException(
                'Failed to connect to KRA for authentication: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }

    /**
     * Invalidate the cached token, forcing re-authentication on next request.
     *
     * Call this when you receive a 401 response or an auth-related error code.
     */
    public function invalidateToken(): void
    {
        Cache::store($this->cacheConfig['store'] ?? null)->forget($this->cacheKeyPrefix);
        $this->logDebug('KRA auth token invalidated');
    }

    // =========================================================================
    // Private Implementation
    // =========================================================================

    /**
     * Core request method. All HTTP communication flows through here.
     *
     * Flow:
     *  1. Authenticate (using cached token if available)
     *  2. Build request with auth headers
     *  3. Execute with retry loop
     *  4. Log request/response
     *  5. Parse and return response body
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     * @throws EtimsApiException
     */
    private function request(string $method, string $path, array $payload = [], array $query = []): array
    {
        $url     = $this->baseUrl . $path;
        $token   = $this->authenticate();
        $client  = $this->buildHttpClient(authenticated: true, token: $token);
        $maxTries = (int) ($this->httpConfig['retries'] ?? 3);
        $baseDelay = (int) ($this->httpConfig['retry_delay_ms'] ?? 500);

        $this->logRequest($method, $url, $payload);

        $attempt   = 0;
        $lastError = null;

        while ($attempt <= $maxTries) {
            try {
                $response = match ($method) {
                    'GET'  => $client->get($url, $query),
                    'POST' => $client->post($url, $payload),
                    default => throw new EtimsApiException("Unsupported HTTP method: {$method}"),
                };

                $this->logResponse($url, $response);

                // Handle 401 by refreshing token and retrying once
                if ($response->status() === 401 && $attempt === 0) {
                    $this->invalidateToken();
                    $token  = $this->authenticate();
                    $client = $this->buildHttpClient(authenticated: true, token: $token);
                    $attempt++;
                    continue;
                }

                return $this->parseResponse($response, $url);

            } catch (EtimsApiException $e) {
                $lastError = $e;

                if (!$e->isRetryable() || $attempt >= $maxTries) {
                    throw $e;
                }

            } catch (Throwable $e) {
                $lastError = new EtimsApiException(
                    "HTTP request to KRA failed: {$e->getMessage()}",
                    0,
                    '',
                    [],
                    0,
                    $e,
                );

                if ($attempt >= $maxTries) {
                    throw $lastError;
                }
            }

            // Exponential backoff: delay doubles on each retry
            $delayMs = $baseDelay * (2 ** $attempt);
            $this->logDebug("Retrying KRA request", [
                'attempt' => $attempt + 1,
                'max'     => $maxTries,
                'delay_ms' => $delayMs,
                'url'     => $url,
            ]);
            usleep($delayMs * 1000);
            $attempt++;
        }

        throw $lastError ?? new EtimsApiException('Max retries exceeded with no response from KRA.');
    }

    /**
     * Build a configured Guzzle/Laravel HTTP client.
     *
     * @param string|null $token
     */
    private function buildHttpClient(bool $authenticated = true, ?string $token = null): PendingRequest
    {
        $client = Http::baseUrl($this->baseUrl)
            ->connectTimeout((int) ($this->httpConfig['connect_timeout'] ?? 10))
            ->timeout((int) ($this->httpConfig['timeout'] ?? 30))
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ]);

        if ($authenticated && $token) {
            $client = $client->withToken($token);
        }

        return $client;
    }

    /**
     * Parse an HTTP response into a normalized array.
     *
     * Handles:
     *  - Non-200 HTTP status codes → EtimsApiException
     *  - KRA error result codes → EtimsApiException
     *  - Malformed JSON → EtimsApiException
     *
     * @return array<string, mixed>
     * @throws EtimsApiException
     */
    private function parseResponse(Response $response, string $url): array
    {
        $body = $response->json() ?? [];

        if (!$response->successful()) {
            throw new EtimsApiException(
                "KRA API returned HTTP {$response->status()} for {$url}: " . ($body['resultMsg'] ?? $response->body()),
                $response->status(),
                (string) ($body['resultCd'] ?? ''),
                $body,
            );
        }

        // KRA returns 200 but with error result codes — detect these too
        $resultCode = (string) ($body['resultCd'] ?? '');
        if ($resultCode !== '' && !in_array($resultCode, ['000', '0000', '00000000'], true)) {
            $isRetryableKraCode = in_array($resultCode, ['999', '998'], true); // server-side KRA errors

            throw new EtimsApiException(
                "KRA returned error result [{$resultCode}]: " . ($body['resultMsg'] ?? 'Unknown KRA error'),
                $response->status(),
                $resultCode,
                $body,
            );
        }

        return $body;
    }

    private function validateCredentials(): void
    {
        if (empty($this->credentials['pin'])) {
            throw new EtimsConfigException('ETIMS_PIN is required. Set it in your .env file.');
        }

        if (empty($this->credentials['device_serial'])) {
            throw new EtimsConfigException('ETIMS_DEVICE_SERIAL is required. Set it in your .env file.');
        }

        if (empty($this->credentials['secret'])) {
            throw new EtimsConfigException('ETIMS_SECRET is required. Set it in your .env file.');
        }
    }

    private function resolveBaseUrl(): string
    {
        return $this->endpoints[$this->mode]
            ?? throw new EtimsConfigException("No endpoint configured for mode: {$this->mode}");
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function logRequest(string $method, string $url, array $payload): void
    {
        if (!($this->loggingConfig['log_requests'] ?? true)) {
            return;
        }

        if ($this->loggingConfig['log_failed_only'] ?? false) {
            return;
        }

        $this->logDebug("KRA API Request → {$method} {$url}", ['payload' => $payload]);
    }

    private function logResponse(string $url, Response $response): void
    {
        if (!($this->loggingConfig['log_responses'] ?? true)) {
            return;
        }

        $level = $response->successful() ? 'debug' : 'warning';

        $this->log($level, "KRA API Response ← {$url}", [
            'status' => $response->status(),
            'body'   => $response->json(),
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logDebug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $channel = $this->loggingConfig['channel'] ?? null;
        $logger  = $channel ? Log::channel($channel) : Log::getFacadeRoot();
        $logger->$level('[eTIMS SDK] ' . $message, $context);
    }
}
