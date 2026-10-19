/**
 * ProductStockDetailPanel: Muestra la distribución de existencias por almacén
 * (disponible, reservado, dañado) y la lista de seriales/IMEIs físicos si el
 * producto es serializado.
 */
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { useProductStockByWarehouse, useProductSerials } from '@/features/inventory-center/api';
import { useCrossBranchStock } from '@/features/pos/api';
import type { ProductSerial } from '@/features/inventory-center/schemas';
import { Boxes, Warehouse } from 'lucide-react';
import { cn } from '@/lib/cn';

interface ProductStockDetailPanelProps {
  productId: number;
  trackingType?: string | null;
}

export function ProductStockDetailPanel({
  productId,
  trackingType = 'by_quantity',
}: ProductStockDetailPanelProps) {
  const { data: stock = [], isLoading: loadingStock } = useProductStockByWarehouse(productId);
  const { data: serials = [], isLoading: loadingSerials } = useProductSerials(
    trackingType === 'serialized' ? productId : 0,
  );
  const { data: crossBranchData } = useCrossBranchStock(productId);

  const parseNum = (v: unknown): number => {
    if (typeof v === 'number') return Number.isFinite(v) ? v : 0;
    if (typeof v === 'string') {
      const n = parseFloat(v);
      return Number.isFinite(n) ? n : 0;
    }
    return 0;
  };

  const hasVariants = stock.some((s) => Boolean(s.variant_color || s.product_variant_id || s.variant_sku));

  return (
    <div className="space-y-4 pt-2">
      <Card>
        <CardHeader className="py-3 px-4">
          <CardTitle className="text-sm font-bold flex items-center gap-1.5">
            <Warehouse className="size-4 text-primary" />
            Existencias actuales por almacén
          </CardTitle>
          <CardDescription className="text-xs">
            Distribución física en tiempo real en cada uno de tus almacenes y variantes.
          </CardDescription>
        </CardHeader>
        <CardContent className="p-0">
          {loadingStock ? (
            <div className="p-4 flex justify-center">
              <Spinner label="Consultando almacenes..." />
            </div>
          ) : stock.length === 0 ? (
            <div className="p-4">
              <EmptyState
                title="Sin existencias registradas"
                description="Este producto aún no cuenta con movimientos de entrada en ningún almacén."
              />
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full table-dense text-xs">
                <thead className="border-b border-border bg-bg/60 text-left">
                  <tr>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">
                      Almacén / Sucursal
                    </th>
                    {hasVariants && (
                      <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">
                        Variante / Color
                      </th>
                    )}
                    <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">
                      Disponible
                    </th>
                    <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">
                      Reservado
                    </th>
                    <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">
                      Dañado
                    </th>
                    <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-primary">
                      Físico Total
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {stock.map((s, idx) => {
                    const qty = parseNum(s.available);
                    const res = parseNum(s.reserved ?? 0);
                    const dmg = parseNum(s.damaged ?? 0);
                    const phys = qty + res + dmg;
                    const rowKey = s.id ?? `${s.warehouse_id}-${s.product_variant_id ?? 'base'}-${idx}`;

                    return (
                      <tr key={rowKey} className="border-b border-border last:border-b-0 hover:bg-muted/30">
                        <td className="px-3 py-2">
                          <div className="font-medium text-text-primary">{s.warehouse_name}</div>
                          <div className="text-[11px] text-text-muted">
                            {s.branch_name ? `${s.branch_name} ` : ''}
                            {s.warehouse_code ? `(${s.warehouse_code})` : ''}
                          </div>
                        </td>
                        {hasVariants && (
                          <td className="px-3 py-2">
                            {s.variant_color || s.variant_sku ? (
                              <div className="flex items-center gap-1.5">
                                {s.variant_color_hex && (
                                  <span
                                    className="size-3 rounded-full border border-border shrink-0 shadow-sm"
                                    style={{ backgroundColor: s.variant_color_hex }}
                                  />
                                )}
                                <span className="font-medium text-text-primary">
                                  {s.variant_color || 'Estándar'}
                                </span>
                                {s.variant_sku && (
                                  <span className="font-mono text-[10px] px-1 py-0.2 rounded bg-muted text-text-muted border border-border">
                                    {s.variant_sku}
                                  </span>
                                )}
                              </div>
                            ) : (
                              <span className="text-text-muted italic">Estándar / Base</span>
                            )}
                          </td>
                        )}
                        <td className="px-3 py-2 text-right tabular-nums">
                          <span
                            className={cn(
                              'px-1.5 py-0.5 rounded font-bold',
                              qty > 0
                                ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                                : 'text-text-muted',
                            )}
                          >
                            {qty.toFixed(2)}
                          </span>
                        </td>
                        <td className="px-3 py-2 text-right tabular-nums text-amber-600 dark:text-amber-400">
                          {res > 0 ? res.toFixed(2) : '—'}
                        </td>
                        <td className="px-3 py-2 text-right tabular-nums text-rose-600 dark:text-rose-400">
                          {dmg > 0 ? dmg.toFixed(2) : '—'}
                        </td>
                        <td className="px-3 py-2 text-right tabular-nums font-bold text-text-primary">
                          {phys.toFixed(2)}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
                <tfoot className="border-t border-border bg-muted/40 font-bold">
                  <tr>
                    <td colSpan={hasVariants ? 2 : 1} className="px-3 py-2 text-text-secondary">
                      Totales consolidados:
                    </td>
                    <td className="px-3 py-2 text-right tabular-nums text-emerald-600 dark:text-emerald-400">
                      {stock.reduce((acc, s) => acc + parseNum(s.available), 0).toFixed(2)}
                    </td>
                    <td className="px-3 py-2 text-right tabular-nums text-amber-600 dark:text-amber-400">
                      {stock.reduce((acc, s) => acc + parseNum(s.reserved ?? 0), 0).toFixed(2)}
                    </td>
                    <td className="px-3 py-2 text-right tabular-nums text-rose-600 dark:text-rose-400">
                      {stock.reduce((acc, s) => acc + parseNum(s.damaged ?? 0), 0).toFixed(2)}
                    </td>
                    <td className="px-3 py-2 text-right tabular-nums text-text-primary">
                      {stock
                        .reduce(
                          (acc, s) =>
                            acc +
                            parseNum(s.available) +
                            parseNum(s.reserved ?? 0) +
                            parseNum(s.damaged ?? 0),
                          0,
                        )
                        .toFixed(2)}
                    </td>
                  </tr>
                </tfoot>
              </table>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Stock en otras sucursales / empresas hermanas */}
      {crossBranchData?.branches && crossBranchData.branches.length > 0 && (
        <Card>
          <CardHeader className="py-3 px-4">
            <div className="flex items-center justify-between">
              <div>
                <CardTitle className="text-sm font-bold flex items-center gap-1.5">
                  <Boxes className="size-4 text-primary" />
                  Existencias en otras sucursales / empresas ({crossBranchData.branches.length})
                </CardTitle>
                <CardDescription className="text-xs">
                  Disponibilidad en tiempo real en empresas hermanas del grupo.
                </CardDescription>
              </div>
            </div>
          </CardHeader>
          <CardContent className="p-3 space-y-2">
            {crossBranchData.branches
              .slice()
              .sort((a, b) => (b.total_available || 0) - (a.total_available || 0))
              .map((b) => (
                <div
                  key={b.tenant_id}
                  className={cn(
                    'rounded-lg border p-3 text-xs space-y-2 transition-colors',
                    b.total_available > 0
                      ? 'border-emerald-500/30 bg-emerald-500/5'
                      : 'border-border bg-surface',
                  )}
                >
                  <div className="flex items-center justify-between">
                    <span className="font-semibold text-text-primary text-sm">{b.tenant_name}</span>
                    <Badge variant={b.total_available > 0 ? 'success' : 'outline'} className="font-mono text-xs">
                      {b.total_available} disp.
                    </Badge>
                  </div>
                  {b.warehouses && b.warehouses.length > 0 ? (
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 pt-1">
                      {b.warehouses.map((w, wIdx) => (
                        <div
                          key={`${w.warehouse_id}-${w.product_variant_id ?? wIdx}`}
                          className="flex items-center justify-between p-2 rounded bg-bg/60 border border-border/60 text-xs"
                        >
                          <div className="min-w-0 pr-2">
                            <div className="font-medium text-text-primary truncate">{w.warehouse_name}</div>
                            {w.variant_color && (
                              <div className="text-[11px] text-text-muted flex items-center gap-1 mt-0.5">
                                <span>
                                  Variante/Color:{' '}
                                  <strong className="text-text-primary">{w.variant_color}</strong>
                                </span>
                                {w.variant_sku && (
                                  <span className="font-mono text-[10px] text-text-muted">
                                    ({w.variant_sku})
                                  </span>
                                )}
                              </div>
                            )}
                          </div>
                          <span className="font-mono font-bold text-emerald-600 dark:text-emerald-400 shrink-0">
                            {w.quantity_available} disp.
                          </span>
                        </div>
                      ))}
                    </div>
                  ) : (
                    <div className="text-xs text-text-muted italic">Sin almacenes con existencias</div>
                  )}
                </div>
              ))}
          </CardContent>
        </Card>
      )}

      {trackingType === 'serialized' && (
        <Card>
          <CardHeader className="py-3 px-4">
            <div className="flex items-center justify-between">
              <div>
                <CardTitle className="text-sm font-bold">Seriales / IMEI físicos</CardTitle>
                <CardDescription className="text-xs">
                  {serials.length} unidades físicas registradas en el sistema.
                </CardDescription>
              </div>
            </div>
          </CardHeader>
          <CardContent className="p-0">
            {loadingSerials ? (
              <div className="p-4 flex justify-center">
                <Spinner label="Cargando seriales..." />
              </div>
            ) : serials.length === 0 ? (
              <div className="p-4">
                <EmptyState
                  title="Sin seriales registrados"
                  description="Este producto serializado aún no tiene IMEI o seriales asignados."
                />
              </div>
            ) : (
              <div className="max-h-60 overflow-y-auto">
                <table className="w-full table-dense text-xs">
                  <thead className="border-b border-border bg-bg/60 text-left sticky top-0">
                    <tr>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">
                        Serial / IMEI
                      </th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">
                        Tipo
                      </th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">
                        Estado
                      </th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">
                        Almacén
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {serials.map((s: ProductSerial) => (
                      <tr key={s.id} className="border-b border-border last:border-b-0">
                        <td className="px-3 py-2 font-mono text-xs font-semibold">
                          {s.serial_number}
                        </td>
                        <td className="px-3 py-2 text-text-muted">{s.serial_type}</td>
                        <td className="px-3 py-2">
                          <Badge
                            variant={
                              s.status === 'available'
                                ? 'success'
                                : s.status === 'sold'
                                  ? 'default'
                                  : s.status === 'damaged'
                                    ? 'danger'
                                    : 'warning'
                            }
                          >
                            {s.status === 'available' ? 'Disponible' : s.status}
                          </Badge>
                        </td>
                        <td className="px-3 py-2 text-text-muted">{s.warehouse_name ?? '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </CardContent>
        </Card>
      )}
    </div>
  );
}
