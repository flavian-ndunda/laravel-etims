<?php

declare(strict_types=1);

namespace Flavytech\Etims\Exceptions;

use RuntimeException;

/**
 * EtimsException
 *
 * Base exception for all SDK exceptions.
 *
 * Catch this to handle any SDK error. Catch the subclasses
 * for more specific error handling:
 *
 *   try {
 *       Etims::submitInvoice($invoice);
 *   } catch (EtimsAuthException $e) {
 *       // Handle authentication failure specifically
 *   } catch (EtimsValidationException $e) {
 *       // Handle invalid invoice data
 *   } catch (EtimsException $e) {
 *       // Fallback for any other SDK error
 *   }
 */
class EtimsException extends RuntimeException {}
