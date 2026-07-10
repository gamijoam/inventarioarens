<?php

namespace App\Modules\AdminPortal\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdminTransferActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory_transfers.admin') === true;
    }

    public function rules(): array
    {
        return [];
    }
}
