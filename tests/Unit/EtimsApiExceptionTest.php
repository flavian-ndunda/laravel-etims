<?php

declare(strict_types=1);

use Flavytech\Etims\Exceptions\EtimsApiException;

it('marks 5xx errors as retryable', function () {
    $exception = new EtimsApiException('Server error', 500);
    expect($exception->isRetryable())->toBeTrue();

    $exception = new EtimsApiException('Bad gateway', 502);
    expect($exception->isRetryable())->toBeTrue();

    $exception = new EtimsApiException('Service unavailable', 503);
    expect($exception->isRetryable())->toBeTrue();
});

it('marks 429 Too Many Requests as retryable', function () {
    $exception = new EtimsApiException('Rate limited', 429);
    expect($exception->isRetryable())->toBeTrue();
});

it('marks network errors (status 0) as retryable', function () {
    $exception = new EtimsApiException('Connection refused', 0);
    expect($exception->isRetryable())->toBeTrue();
});

it('marks 4xx client errors as NOT retryable', function () {
    $exception = new EtimsApiException('Bad request', 400);
    expect($exception->isRetryable())->toBeFalse();

    $exception = new EtimsApiException('Unauthorized', 401);
    expect($exception->isRetryable())->toBeFalse();

    $exception = new EtimsApiException('Not found', 404);
    expect($exception->isRetryable())->toBeFalse();

    $exception = new EtimsApiException('Unprocessable entity', 422);
    expect($exception->isRetryable())->toBeFalse();
});

it('stores HTTP status code and KRA result code', function () {
    $exception = new EtimsApiException('Error', 422, 'E001', ['resultMsg' => 'Error']);

    expect($exception->getHttpStatusCode())->toBe(422)
        ->and($exception->getKraResultCode())->toBe('E001')
        ->and($exception->getResponseBody())->toBe(['resultMsg' => 'Error']);
});
