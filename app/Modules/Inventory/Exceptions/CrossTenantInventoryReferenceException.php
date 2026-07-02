<?php

namespace App\Modules\Inventory\Exceptions;

use RuntimeException;

class CrossTenantInventoryReferenceException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Inventory operation references data outside the current tenant.');
    }
}
