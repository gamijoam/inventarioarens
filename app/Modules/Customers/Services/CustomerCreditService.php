<?php

namespace App\Modules\Customers\Services;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerCreditTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerCreditService
{
    public function issue(
        Customer $customer,
        User $user,
        array $data,
    ): CustomerCreditTransaction {
        return DB::transaction(function () use ($customer, $user, $data): CustomerCreditTransaction {
            $customer = Customer::query()
                ->lockForUpdate()
                ->findOrFail($customer->id);

            $operationKey = $data['operation_key'] ?? null;
            if ($operationKey) {
                $existing = CustomerCreditTransaction::query()
                    ->where('operation_key', $operationKey)
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            if ($customer->is_generic) {
                throw ValidationException::withMessages([
                    'customer_id' => 'El consumidor final no puede recibir saldo a favor. Selecciona un cliente registrado.',
                ]);
            }

            return CustomerCreditTransaction::create([
                'customer_id' => $customer->id,
                'type' => CustomerCreditTransaction::TYPE_ISSUED,
                'currency' => $data['currency'],
                'amount' => $data['amount'],
                'amount_base' => $data['amount_base'],
                'amount_local' => $data['amount_local'],
                'source_type' => $data['source_type'] ?? null,
                'source_id' => $data['source_id'] ?? null,
                'operation_key' => $operationKey,
                'created_by' => $user->id,
                'notes' => $data['notes'] ?? null,
            ]);
        });
    }

    public function availableBase(Customer $customer): float
    {
        return round((float) CustomerCreditTransaction::query()
            ->where('customer_id', $customer->id)
            ->sum('amount_base'), 4);
    }

    public function apply(Customer $customer, User $user, array $data): CustomerCreditTransaction
    {
        return DB::transaction(function () use ($customer, $user, $data): CustomerCreditTransaction {
            $customer = Customer::query()
                ->lockForUpdate()
                ->findOrFail($customer->id);
            $operationKey = $data['operation_key'] ?? null;

            if ($operationKey) {
                $existing = CustomerCreditTransaction::query()
                    ->where('operation_key', $operationKey)
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            $available = $this->availableBase($customer);
            $amountBase = round((float) $data['amount_base'], 4);

            if ($amountBase <= 0.0 || $amountBase > $available) {
                throw ValidationException::withMessages([
                    'payments' => 'El saldo a favor disponible no cubre el monto solicitado.',
                ]);
            }

            return CustomerCreditTransaction::create([
                'customer_id' => $customer->id,
                'type' => CustomerCreditTransaction::TYPE_APPLIED,
                'currency' => $data['currency'],
                'amount' => -abs((float) $data['amount']),
                'amount_base' => -$amountBase,
                'amount_local' => -abs((float) ($data['amount_local'] ?? 0)),
                'source_type' => $data['source_type'] ?? null,
                'source_id' => $data['source_id'] ?? null,
                'operation_key' => $operationKey,
                'created_by' => $user->id,
                'notes' => $data['notes'] ?? null,
            ]);
        });
    }

    public function summary(Customer $customer): array
    {
        return [
            'customer_id' => $customer->id,
            'available_base_amount' => $this->availableBase($customer),
            'transactions' => CustomerCreditTransaction::query()
                ->where('customer_id', $customer->id)
                ->latest()
                ->limit(50)
                ->get()
                ->map(fn (CustomerCreditTransaction $transaction): array => [
                    'id' => $transaction->id,
                    'type' => $transaction->type,
                    'currency' => $transaction->currency,
                    'amount' => (float) $transaction->amount,
                    'amount_base' => (float) $transaction->amount_base,
                    'amount_local' => (float) $transaction->amount_local,
                    'source_type' => $transaction->source_type,
                    'source_id' => $transaction->source_id,
                    'notes' => $transaction->notes,
                    'created_at' => $transaction->created_at?->toISOString(),
                ])->all(),
        ];
    }
}
