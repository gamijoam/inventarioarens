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
import type { ProductSerial } from '@/features/inventory-center/schemas';

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

  const parseNum = (v: unknown): number => {
    if (typeof v === 'number') return Number.isFinite(v) ? v : 0;
    if (typeof v === 'string') {
      const n = parseFloat(v);
      return Number.isFinite(n) ? n : 0;
    }
    return 0;
  };

  return (
    <div className="space-y-4 pt-2">
      <Card>
        <CardHeader className="py-3 px-4">
          <CardTitle className="text-sm font-bold">Existencias actuales por almacén</CardTitle>
          <CardDescription className="text-xs">
            Distribución física en tiempo real en cada uno de tus almacenes.
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
            <table className="w-full table-dense text-xs">
              <thead className="border-b border-border bg-bg/60 text-left">
                <tr>
                  <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">
                    Almacén
                  </th>
                  <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">
                    Disponible
                  </th>
                  <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">
                    Reservado
                  </th>
                  <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">
                    Dañado
                  </th>
                </tr>
              </thead>
              <tbody>
                {stock.map((s) => {
                  const qty = parseNum(s.available);
                  const res = parseNum(s.reserved ?? 0);
                  const dmg = parseNum(s.damaged ?? 0);
                  return (
                    <tr key={s.warehouse_id} className="border-b border-border last:border-b-0">
                      <td className="px-3 py-2">
                        <div className="font-medium text-text-primary">{s.warehouse_name}</div>
                        <div className="text-[11px] text-text-muted">{s.warehouse_code}</div>
                      </td>
                      <td className="px-3 py-2 text-right tabular-nums text-text-primary font-bold">
                        {qty.toFixed(2)}
                      </td>
                      <td className="px-3 py-2 text-right tabular-nums text-text-muted">
                        {res.toFixed(2)}
                      </td>
                      <td className="px-3 py-2 text-right tabular-nums text-text-muted">
                        {dmg.toFixed(2)}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          )}
        </CardContent>
      </Card>

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
                        <td className="px-3 py-2 font-mono text-[11px] font-semibold">
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
