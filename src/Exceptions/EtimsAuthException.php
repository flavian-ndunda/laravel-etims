<?php

declare(strict_types=1);

namespace Flavytech\Etims\Exceptions;

/**
 * EtimsAuthException
 *
 * Thrown when authentication with the KRA API fails.
 *
 * Common causes:
 *  - Invalid PIN or secret
 *  - Expired credentials
 *  - Device not initialized / deregistered
 *  - Wrong mode (using sandbox credentials against production endpoint)
 */
class EtimsAuthException extends EtimsException {}
