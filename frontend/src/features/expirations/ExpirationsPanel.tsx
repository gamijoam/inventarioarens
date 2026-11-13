import { useState } from 'react';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardContent } from '@/components/ui/Card';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { formatMoney } from '@/lib/money';
import { toast } from '@/lib/toast';
import { PERMISSIONS } from '@/permissions/constants';
import { useCan } from '@/permissions/useCan';
import { useStockLots, useWriteOffStockLot } from './api';
import type { StockLot, StockLotFilters } from './schemas';

function daysLabel(lot: StockLot): string {
  if (lot.days_left < 0) return `Vencido hace ${Math.abs(lot.days_left)} d`;
  if (lot.days_left === 0) return 'Vence hoy';
  return `Vence en ${lot.days_left} d`;
}

function daysVariant(lot: StockLot): 'danger' | 'warning' | 'info' {
  if (lot.days_left < 0) return 'danger';
  if (lot.days_left <= 7) return 'warning';
  return 'info';
}

export function ExpirationsPanel() {
  const [filters, setFilters] = useState<StockLotFilters>({ status: 'expiring', days: 30 });
  const { data, isLoading } = useStockLots(filters);
  const writeOff = useWriteOffStockLot();
  const canAdjust = useCan(PERMISSIONS.INVENTORY_ADJUST);
  const rows = data?.data ?? [];

  async function handleWriteOff(lot: StockLot) {
    try {
      await writeOff.mutateAsync({ id: lot.id, reason: lot.days_left < 0 ? 'Vencido' : 'Retirado por vencer' });
    } catch (error) {
      toast.error(error instanceof Error && error.message ? error.message : 'No se pudo dar de baja el lote.');
      return;
    }
    toast.success('Lote dado de baja como merma.');
  }

  return (
    <div className="space-y-4">
      <Card>
        <CardContent className="flex flex-wrap items-end gap-3 p-3">
          <div>
            <Label htmlFor="expirations-status">Mostrar</Label>
            <Select
              id="expirations-status"
              value={filters.status}
              onChange={(e) => setFilters({ ...filters, status: e.target.value as StockLotFilters['status'] })}
            >
              <option value="expiring">Por vencer</option>
              <option value="expired">Vencidos</option>
              <option value="all">Todos los lotes</option>
            </Select>
          </div>
          {filters.status === 'expiring' && (
            <div>
              <Label htmlFor="expirations-days">En los proximos</Label>
              <Select
                id="expirations-days"
                value={String(filters.days ?? 30)}
                onChange={(e) => setFilters({ ...filters, days: Number(e.target.value) })}
              >
                <option value="7">7 dias</option>
                <option value="15">15 dias</option>
                <option value="30">30 dias</option>
                <option value="60">60 dias</option>
              </Select>
            </div>
          )}
          <div className="ml-auto text-right">
            <p className="text-text-muted text-xs">Valor en riesgo</p>
            <p className="text-lg font-semibold tabular-nums" data-testid="expirations-total">
              {formatMoney(data?.summary.value_base_amount ?? 0)}
            </p>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardContent className="p-0">
          {isLoading ? (
            <p className="text-text-muted p-4 text-sm">Cargando...</p>
          ) : rows.length === 0 ? (
            <p className="text-text-muted p-4 text-sm">No hay lotes en este filtro.</p>
          ) : (
            <table className="w-full text-sm">
              <thead className="text-text-muted text-left text-xs uppercase">
                <tr>
                  <th className="px-3 py-2">Producto</th>
                  <th className="px-3 py-2">Lote</th>
                  <th className="px-3 py-2">Almacen</th>
                  <th className="px-3 py-2">Vencimiento</th>
                  <th className="px-3 py-2 text-right">Cantidad</th>
                  <th className="px-3 py-2 text-right">Valor</th>
                  <th className="px-3 py-2" />
                </tr>
              </thead>
              <tbody>
                {rows.map((lot) => (
                  <tr key={lot.id} className="border-border border-t">
                    <td className="px-3 py-2">
                      <div className="font-medium">{lot.product?.name ?? 'Producto'}</div>
                      <div className="text-text-muted text-xs">
                        {lot.product?.sku}
                        {lot.variant?.color ? ` · ${lot.variant.color}` : ''}
                      </div>
                    </td>
                    <td className="px-3 py-2">{lot.lot_code ?? '-'}</td>
                    <td className="px-3 py-2">{lot.warehouse?.name ?? '-'}</td>
                    <td className="px-3 py-2">
                      <div>{lot.expires_on}</div>
                      <Badge variant={daysVariant(lot)} className="text-xs">
                        {daysLabel(lot)}
                      </Badge>
                    </td>
                    <td className="px-3 py-2 text-right tabular-nums">{lot.quantity}</td>
                    <td className="px-3 py-2 text-right tabular-nums">{formatMoney(lot.value_base_amount)}</td>
                    <td className="px-3 py-2 text-right">
                      {canAdjust && (
                        <Button
                          type="button"
                          size="sm"
                          variant="outline"
                          disabled={writeOff.isPending}
                          onClick={() => void handleWriteOff(lot)}
                        >
                          Dar de baja
                        </Button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
