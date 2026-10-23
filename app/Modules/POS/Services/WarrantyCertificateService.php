<?php

namespace App\Modules\POS\Services;

use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\POS\Models\PosOrder;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\CompanySettings;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\View;

class WarrantyCertificateService
{
    /**
     * Construye el payload estructurado con todos los datos del certificado.
     */
    public function buildCertificateData(PosOrder $order): array
    {
        $order->loadMissing([
            'customer',
            'seller',
            'cashier',
            'cashRegisterSession.branch',
            'sale.customer',
            'sale.items.product',
            'sale.items.variant',
            'sale.items.warehouse',
            'payments.paymentMethod',
        ]);

        $tenant = Tenant::query()->withoutGlobalScopes()->whereKey($order->tenant_id)->first()
            ?? app(TenantManager::class)->current();

        $company = $tenant ? CompanySettings::getForTenant($tenant) : CompanySettings::defaults();
        $warrantyConfig = $company['warranty_certificate'] ?? CompanySettings::DEFAULTS['warranty_certificate'];

        $customer = $order->customer ?? $order->sale?->customer;
        $customerName = trim((string) ($customer?->name ?? $order->customer_name ?? 'Consumidor Final'));
        if ($customerName === '') {
            $customerName = 'Consumidor Final';
        }

        $docType = $customer?->document_type ? trim((string) $customer->document_type) : '';
        $docNum = $customer?->document_number ? trim((string) $customer->document_number) : '';
        $customerDoc = $docNum !== '' ? ($docType !== '' ? "{$docType}-{$docNum}" : $docNum) : 'N/A';

        $customerPhone = $customer?->phone ? trim((string) $customer->phone) : 'N/A';
        $customerEmail = $customer?->email ? trim((string) $customer->email) : 'N/A';

        $paidAt = $order->paid_at ?? $order->opened_at ?? now();
        $issueDateFormatted = Carbon::parse($paidAt)->format('d/m/Y H:i');

        // Total pagado formateado
        $totalBase = number_format((float) ($order->total_base_amount ?? 0), 2, '.', ',');
        $totalDisplay = "\${$totalBase}";
        if ((float) ($order->total_local_amount ?? 0) > 0) {
            $totalLocal = number_format((float) $order->total_local_amount, 2, '.', ',');
            $totalDisplay .= " (Bs. {$totalLocal})";
        }

        // Resolucion de seriales/IMEIs de los items
        $saleItems = $order->sale?->items ?? collect();
        $allUnitIds = $saleItems->flatMap(fn ($item): array => $item->product_unit_ids ?? [])
            ->filter()
            ->unique()
            ->values()
            ->all();

        $unitsById = [];
        if ($allUnitIds !== []) {
            $unitsById = ProductUnit::query()
                ->whereIn('id', $allUnitIds)
                ->get()
                ->keyBy('id');
        }

        $warrantiedEquipments = [];
        $totalWarrantiedCount = 0;

        foreach ($saleItems as $item) {
            $durationDays = $item->warranty_duration_days ?? 30;
            $policyName = $item->warranty_policy_name ?: "Garantía de {$durationDays} días";
            $conditions = $item->warranty_conditions ?: 'Cubre fallas técnicas internas por defectos de fabricación en condiciones normales de uso.';

            $itemExpiresAt = $item->warranty_expires_at
                ? Carbon::parse($item->warranty_expires_at)
                : Carbon::parse($paidAt)->addDays((int) $durationDays);
            $expiresFormatted = $itemExpiresAt->format('d/m/Y');

            $productName = $item->product?->name ?? 'Producto';
            if ($item->variant) {
                $varParts = array_filter([$item->variant->color, $item->variant->sku_variant]);
                if ($varParts !== []) {
                    $productName .= ' ('.implode(' - ', $varParts).')';
                }
            }

            // Unidades con serial / IMEI
            $itemUnitIds = $item->product_unit_ids ?? [];
            $serialList = [];
            foreach ($itemUnitIds as $uid) {
                if (isset($unitsById[$uid])) {
                    $u = $unitsById[$uid];
                    $serialList[] = (string) $u->serial_number;
                }
            }

            // Si tiene seriales, desglosamos por unidad para que cada teléfono tenga su propio IMEI visible
            if (count($serialList) > 0) {
                foreach ($serialList as $idx => $serialNum) {
                    $totalWarrantiedCount++;
                    $warrantiedEquipments[] = [
                        'product_name' => $productName,
                        'quantity' => 1,
                        'imei_serial' => $serialNum,
                        'policy_name' => $policyName,
                        'duration_text' => "{$durationDays} DÍAS",
                        'conditions' => $conditions,
                        'expires_at' => $expiresFormatted,
                    ];
                }
            } else {
                // Producto con garantia pero sin seriales individuales
                $qty = (int) ceil((float) ($item->quantity ?? 1));
                $totalWarrantiedCount += $qty;
                $warrantiedEquipments[] = [
                    'product_name' => $productName,
                    'quantity' => $qty,
                    'imei_serial' => 'N/A',
                    'policy_name' => $policyName,
                    'duration_text' => "{$durationDays} DÍAS",
                    'conditions' => $conditions,
                    'expires_at' => $expiresFormatted,
                ];
            }
        }

        // Parseo de lineas de terminos
        $parseLines = function (?string $raw): array {
            if (! $raw) {
                return [];
            }
            $lines = preg_split('/\r\n|\r|\n/', $raw);

            return array_values(array_filter(array_map(function ($l) {
                $cleaned = trim((string) preg_replace('/^([•*\-+0-9.)\]]\s*)+/u', '', trim($l)));

                return $cleaned;
            }, $lines), fn ($l) => $l !== ''));
        };

        $covers = $parseLines($warrantyConfig['covers'] ?? null);
        $notCovers = $parseLines($warrantyConfig['not_covers'] ?? null);
        $claimSteps = $parseLines($warrantyConfig['claim_steps'] ?? null);
        $importantNotes = $parseLines($warrantyConfig['important_notes'] ?? null);

        // Razon social y direccion
        $razonSocial = trim((string) ($company['razon_social'] ?? $tenant?->name ?? 'NEGOCIO DE EJEMPLO'));
        $rif = trim((string) ($company['rif'] ?? ''));
        $phone = trim((string) ($company['telefono'] ?? ''));
        $addressParts = array_filter([
            $company['domicilio_fiscal'] ?? null,
            $company['ciudad'] ?? null,
            $company['estado'] ?? null,
        ]);
        $address = implode(', ', $addressParts);

        $headerSubtitle = array_filter([
            $rif !== '' ? "RIF: {$rif}" : null,
            $phone !== '' ? "Tel: {$phone}" : null,
            $address !== '' ? $address : null,
        ]);

        return [
            'tenant' => [
                'name' => $razonSocial,
                'rif' => $rif,
                'phone' => $phone,
                'address' => $address,
                'subtitle' => implode(' · ', $headerSubtitle),
            ],
            'warranty' => [
                'title' => $warrantyConfig['title'] ?? 'CERTIFICADO DE GARANTÍA OFICIAL',
                'number' => '#'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT),
                'order_id' => $order->id,
                'issued_at' => $issueDateFormatted,
                'is_enabled' => (bool) ($warrantyConfig['enabled'] ?? true),
            ],
            'sale' => [
                'invoice_number' => '#'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT),
                'date' => $issueDateFormatted,
                'total' => $totalDisplay,
            ],
            'customer' => [
                'name' => $customerName,
                'document' => $customerDoc,
                'phone' => $customerPhone,
                'email' => $customerEmail,
            ],
            'equipments' => $warrantiedEquipments,
            'has_equipments' => count($warrantiedEquipments) > 0,
            'terms' => [
                'covers' => $covers,
                'not_covers' => $notCovers,
                'claim_steps' => $claimSteps,
                'important_notes' => $importantNotes,
            ],
        ];
    }

    /**
     * Renderiza el HTML del certificado optimizado para pantalla y diálogo de impresión estándar.
     */
    public function renderHtml(PosOrder $order): string
    {
        $data = $this->buildCertificateData($order);

        return View::make('printing.warranty-certificate', ['cert' => $data])->render();
    }

    /**
     * Genera el binario PDF en formato Carta / A4 listo para descargar o transmitir.
     */
    public function renderPdf(PosOrder $order): string
    {
        $html = $this->renderHtml($order);

        $dompdf = app('dompdf.wrapper');
        $dompdf->loadHTML($html);
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
