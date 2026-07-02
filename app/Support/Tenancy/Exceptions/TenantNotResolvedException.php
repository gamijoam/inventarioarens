<?php

namespace App\Support\Tenancy\Exceptions;

use RuntimeException;

class TenantNotResolvedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No current tenant has been resolved for this operation.');
    }
}
