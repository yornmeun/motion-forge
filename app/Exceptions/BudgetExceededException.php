<?php

namespace App\Exceptions;

use RuntimeException;

class BudgetExceededException extends RuntimeException
{
    public function __construct(string $message, public readonly float $estimateUsd = 0, public readonly ?float $remainingUsd = null)
    {
        parent::__construct($message);
    }
}
