<?php

namespace App\Exceptions;

use DomainException;

class CheckInException extends DomainException
{
    public function __construct(
        string $message,
        private readonly string $status = 'invalid',
    ) {
        parent::__construct($message);
    }

    public function status(): string
    {
        return $this->status;
    }
}
