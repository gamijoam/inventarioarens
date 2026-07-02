<?php

namespace App\Modules\Inventory\Exceptions;

use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public function __construct(string $bucket = 'available')
    {
        parent::__construct("Insufficient {$bucket} stock for this inventory operation.");
    }
}
