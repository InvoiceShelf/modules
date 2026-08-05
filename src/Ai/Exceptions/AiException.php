<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Ai\Exceptions;

use RuntimeException;
use Throwable;

/**
 * AI driver failure with a stable error key for presentation layers.
 */
class AiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorKey = 'server_error',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
