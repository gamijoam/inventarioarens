import { Card, CardContent } from '@/components/ui/Card';
import { formatMoney } from '@/lib/money';
import { priceChangePercent, useProductPriceHistory } from '../priceHistoryApi';

function formatPercent(value: number): string {
  const formatted = new Intl.NumberFormat('es-VE', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(value);
  return value > 0 ? `+${formatted}%` : `${formatted}%`;
}

export function ProductPriceHistoryTab({ productId }: { productId: number }) {
  const { data, isLoading } = useProductPriceHistory(productId);
  const rows = data ?? [];

  return (
    <Card>
      <CardContent className="p-0">
        {isLoading ? (
          <p className="p-4 text-sm text-text-secondary">Cargando historial...</p>
        ) : rows.length === 0 ? (
          <p className="p-4 text-sm text-text-secondary">Este producto no tiene cambios de precio registrados.</p>
        ) : (
          <table className="w-full text-sm">
            <thead className="border-b border-border text-left text-xs text-text-secondary">
              <tr>
                <th className="px-3 py-2">Fecha</th>
                <th className="px-3 py-2">Precio</th>
                <th className="px-3 py-2 text-right">Anterior</th>
                <th className="px-3 py-2 text-right">Nuevo</th>
                <th className="px-3 py-2 text-right">Variacion</th>
                <th className="px-3 py-2">Usuario</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => {
                const before = row.before?.price ?? null;
                const after = row.after?.price ?? null;
                const change = priceChangePercent(before, after);
                return (
                  <tr key={row.id} className="border-b border-border/60">
                    <td className="px-3 py-2 text-xs">{row.created_at ? new Date(row.created_at).toLocaleString('es-VE') : '—'}</td>
                    <td className="px-3 py-2">{row.price_list_name ?? '—'}</td>
                    <td className="px-3 py-2 text-right font-mono">{before == null ? '—' : formatMoney(before)}</td>
                    <td className="px-3 py-2 text-right font-mono font-semibold">{after == null ? '—' : formatMoney(after)}</td>
                    <td className={`px-3 py-2 text-right font-mono ${change != null && change < 0 ? 'text-danger' : 'text-success'}`}>
                      {change == null ? '—' : formatPercent(change)}
                    </td>
                    <td className="px-3 py-2 text-xs">{row.created_by_name ?? 'Sistema'}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        )}
      </CardContent>
    </Card>
  );
}
