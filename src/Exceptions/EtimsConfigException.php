<?php

declare(strict_types=1);

namespace Flavytech\Etims\Exceptions;

/**
 * EtimsConfigException
 *
 * Thrown when the SDK is misconfigured.
 *
 * Common causes:
 *  - Missing required .env variables
 *  - Attempting to use production mode while APP_ENV=testing
 *  - Invalid tenant resolver configuration
 */
class EtimsConfigException extends EtimsException {}
