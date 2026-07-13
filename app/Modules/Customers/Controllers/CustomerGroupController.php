<?php

namespace App\Modules\Customers\Controllers;

use App\Modules\Customers\Models\CustomerGroup;
use App\Modules\Customers\Resources\CustomerGroupResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class CustomerGroupController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $groups = CustomerGroup::query()
            ->orderBy('name')
            ->get();

        return CustomerGroupResource::collection($groups);
    }

    public function show(Request $request, CustomerGroup $customerGroup): CustomerGroupResource
    {
        return CustomerGroupResource::make($customerGroup);
    }
}
