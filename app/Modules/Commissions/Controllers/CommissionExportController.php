<?php

namespace App\Modules\Commissions\Controllers;

use App\Modules\Commissions\Models\CommissionEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CommissionExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('commissions.view_all'), Response::HTTP_FORBIDDEN);

        $query = CommissionEntry::query()
            ->with('beneficiary')
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')))
            ->when($request->filled('user_id'), fn (Builder $query) => $query->where('beneficiary_user_id', $request->integer('user_id')))
            ->when($request->filled('from'), fn (Builder $query) => $query->whereDate('earned_at', '>=', $request->string('from')))
            ->when($request->filled('to'), fn (Builder $query) => $query->whereDate('earned_at', '<=', $request->string('to')))
            ->latest('earned_at');

        return response()->streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['UUID', 'Fecha', 'Persona', 'Email', 'Rol', 'Tipo', 'Plan historico', 'Venta', 'Monto USD', 'Estado', 'Motivo ajuste']);
            $query->chunk(200, function ($entries) use ($output): void {
                foreach ($entries as $entry) {
                    fputcsv($output, [
                        $entry->entry_uuid,
                        $entry->earned_at?->toJSON(),
                        $this->csvValue($entry->beneficiary?->name),
                        $this->csvValue($entry->beneficiary?->email),
                        $entry->beneficiary_role,
                        $entry->entry_type,
                        $this->csvValue($entry->plan_name_snapshot),
                        $entry->sale_id,
                        $entry->commission_base_amount,
                        $entry->status,
                        $this->csvValue($entry->adjustment_reason),
                    ]);
                }
            });
            fclose($output);
        }, 'comisiones-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function csvValue(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[=+\-@]/', $value) === 1 ? "'{$value}" : $value;
    }
}
