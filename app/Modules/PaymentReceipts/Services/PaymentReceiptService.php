<?php

namespace App\Modules\PaymentReceipts\Services;

use App\Models\User;
use App\Modules\AccountsPayable\Models\AccountsPayablePayment;
use App\Modules\AccountsReceivable\Models\AccountsReceivablePayment;
use App\Modules\PaymentReceipts\Models\PaymentReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentReceiptService
{
    public function issueForReceivablePayment(AccountsReceivablePayment $payment, User $user): PaymentReceipt
    {
        return DB::transaction(function () use ($payment, $user): PaymentReceipt {
            $payment = AccountsReceivablePayment::query()
                ->with('account.customer')
                ->lockForUpdate()
                ->findOrFail($payment->id);

            $existing = $this->existingFor($payment);

            if ($existing) {
                return $existing;
            }

            $customer = $payment->account->customer;

            return $this->createReceipt([
                'type' => PaymentReceipt::TYPE_CUSTOMER_COLLECTION,
                'source_type' => $payment::class,
                'source_id' => $payment->id,
                'accounts_receivable_payment_id' => $payment->id,
                'party_type' => 'customer',
                'party_id' => $customer?->id,
                'party_name' => $customer?->name,
                'party_document_type' => $customer?->document_type,
                'party_document_number' => $customer?->document_number,
                'payment_currency' => $payment->payment_currency,
                'amount' => $payment->amount,
                'amount_base' => $payment->amount_base,
                'amount_local' => $payment->amount_local,
                'exchange_rate_type_code' => $payment->exchange_rate_type_code,
                'exchange_rate' => $payment->exchange_rate,
                'method' => $payment->method,
                'reference' => $payment->reference,
                'notes' => $payment->notes,
                'issued_by' => $user->id,
                'issued_at' => $payment->paid_at ?? now(),
            ]);
        });
    }

    public function issueForPayablePayment(AccountsPayablePayment $payment, User $user): PaymentReceipt
    {
        return DB::transaction(function () use ($payment, $user): PaymentReceipt {
            $payment = AccountsPayablePayment::query()
                ->with('account.supplier')
                ->lockForUpdate()
                ->findOrFail($payment->id);

            $existing = $this->existingFor($payment);

            if ($existing) {
                return $existing;
            }

            $supplier = $payment->account->supplier;

            return $this->createReceipt([
                'type' => PaymentReceipt::TYPE_SUPPLIER_PAYMENT,
                'source_type' => $payment::class,
                'source_id' => $payment->id,
                'accounts_payable_payment_id' => $payment->id,
                'party_type' => 'supplier',
                'party_id' => $supplier?->id,
                'party_name' => $supplier?->name,
                'party_document_type' => $supplier?->document_type,
                'party_document_number' => $supplier?->document_number,
                'payment_currency' => $payment->payment_currency,
                'amount' => $payment->amount,
                'amount_base' => $payment->amount_base,
                'amount_local' => $payment->amount_local,
                'exchange_rate_type_code' => $payment->exchange_rate_type_code,
                'exchange_rate' => $payment->exchange_rate,
                'method' => $payment->method,
                'reference' => $payment->reference,
                'notes' => $payment->notes,
                'issued_by' => $user->id,
                'issued_at' => $payment->paid_at ?? now(),
            ]);
        });
    }

    public function void(PaymentReceipt $receipt, User $user, ?string $reason = null): PaymentReceipt
    {
        if ($receipt->status === PaymentReceipt::STATUS_VOIDED) {
            throw ValidationException::withMessages([
                'status' => 'El comprobante ya esta anulado.',
            ]);
        }

        $receipt->forceFill([
            'status' => PaymentReceipt::STATUS_VOIDED,
            'voided_by' => $user->id,
            'voided_at' => now(),
            'void_reason' => $reason,
        ])->save();

        return $receipt->refresh();
    }

    private function existingFor(object $payment): ?PaymentReceipt
    {
        return PaymentReceipt::query()
            ->where('source_type', $payment::class)
            ->where('source_id', $payment->id)
            ->first();
    }

    private function createReceipt(array $attributes): PaymentReceipt
    {
        $lastSequence = PaymentReceipt::query()
            ->orderByDesc('sequence')
            ->lockForUpdate()
            ->value('sequence');
        $sequence = ((int) $lastSequence) + 1;

        return PaymentReceipt::create($attributes + [
            'sequence' => $sequence,
            'receipt_number' => 'REC-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
            'status' => PaymentReceipt::STATUS_ISSUED,
        ]);
    }
}
