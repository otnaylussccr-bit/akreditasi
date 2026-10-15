<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class OfficeDocumentPreviewException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
