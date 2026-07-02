<?php

namespace App\Modules\Inventory\Exceptions;

use InvalidArgumentException;

class InvalidStockQuantityException extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('Inventory quantity must be greater than zero.');
    }
}
