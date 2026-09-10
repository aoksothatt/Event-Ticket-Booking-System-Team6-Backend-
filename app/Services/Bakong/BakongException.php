<?php

namespace App\Services\Bakong;

use Exception;
use Throwable;

/**
 * Thrown when the Bakong payment gateway cannot complete an operation.
 */
class BakongException extends Exception
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 502,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
