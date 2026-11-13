import { useState } from 'react';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { formatMoney } from '@/lib/money';
import { toast } from '@/lib/toast';
import { PERMISSIONS } from '@/permissions/constants';
import { useCan } from '@/permissions/useCan';
import {
  buildPackagingPayload,
  useCreatePackaging,
  useDeletePackaging,
  useProductPackagings,
  type PackagingFormValues,
} from '../packagingApi';

const EMPTY: PackagingFormValues = { name: '', factor: '', barcode: '', price_usd: '' };

export function ProductPackagingsTab({ productId }: { productId: number }) {
  const { data = [], isLoading } = useProductPackagings(productId);
  const create = useCreatePackaging();
  const remove = useDeletePackaging();
  const canEdit = useCan(PERMISSIONS.PRODUCTS_UPDATE);
  const [form, setForm] = useState<PackagingFormValues>(EMPTY);

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    if (!form.name.trim() || !(Number(form.factor) > 0)) {
      toast.error('Indica el nombre y cuantas unidades trae la presentacion.');
      return;
    }
    try {
      await create.mutateAsync({ productId, values: buildPackagingPayload(form) });
    } catch (error) {
      toast.error(error instanceof Error && error.message ? error.message : 'No se pudo agregar la presentacion.');
      return;
    }
    toast.success('Presentacion agregada.');
    setForm(EMPTY);
  }

  async function handleDelete(id: number) {
    try {
      await remove.mutateAsync({ id, productId });
    } catch (error) {
      toast.error(error instanceof Error && error.message ? error.message : 'No se pudo eliminar la presentacion.');
    }
  }

  return (
    <div className="space-y-4">
      <p className="text-text-muted text-sm">
        Cajas, bultos o paquetes de este producto. Se compran por presentacion y el sistema las convierte a
        unidades; al escanear su codigo en el POS se vende la presentacion completa con su precio propio.
      </p>
      {isLoading ? (
        <p className="text-text-muted text-sm">Cargando...</p>
      ) : data.length === 0 ? (
        <p className="text-text-muted text-sm">Sin presentaciones.</p>
      ) : (
        <table className="w-full text-sm">
          <thead className="text-text-muted text-left text-xs uppercase">
            <tr>
              <th className="py-2">Presentacion</th>
              <th className="py-2">Contenido</th>
              <th className="py-2">Codigo</th>
              <th className="py-2 text-right">Precio</th>
              <th className="py-2" />
            </tr>
          </thead>
          <tbody>
            {data.map((packaging) => (
              <tr key={packaging.id} className="border-border border-t">
                <td className="py-2 font-medium">{packaging.name}</td>
                <td className="py-2">{packaging.factor} unidades</td>
                <td className="py-2">{packaging.barcode ?? '-'}</td>
                <td className="py-2 text-right tabular-nums">
                  {packaging.price_usd != null ? (
                    <>
                      {formatMoney(packaging.price_usd)}
                      <span className="text-text-muted block text-xs">
                        {formatMoney(packaging.unit_price_usd ?? 0)} c/u
                      </span>
                    </>
                  ) : (
                    <span className="text-text-muted">Precio unitario</span>
                  )}
                </td>
                <td className="py-2 text-right">
                  {canEdit && (
                    <Button
                      type="button"
                      size="sm"
                      variant="ghost"
                      onClick={() => void handleDelete(packaging.id)}
                      aria-label={`Eliminar ${packaging.name}`}
                    >
                      Eliminar
                    </Button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {canEdit && (
        <form className="grid grid-cols-2 gap-3 md:grid-cols-5 md:items-end" onSubmit={(e) => void submit(e)}>
          <div>
            <Label htmlFor="packaging-name">Nombre</Label>
            <Input id="packaging-name" placeholder="Caja x24" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
          </div>
          <div>
            <Label htmlFor="packaging-factor">Unidades que trae</Label>
            <Input id="packaging-factor" type="number" min="0" step="any" value={form.factor} onChange={(e) => setForm({ ...form, factor: e.target.value })} />
          </div>
          <div>
            <Label htmlFor="packaging-barcode">Codigo de barras</Label>
            <Input id="packaging-barcode" value={form.barcode} onChange={(e) => setForm({ ...form, barcode: e.target.value })} />
          </div>
          <div>
            <Label htmlFor="packaging-price">Precio propio (USD)</Label>
            <Input id="packaging-price" type="number" min="0" step="0.01" placeholder="Opcional" value={form.price_usd} onChange={(e) => setForm({ ...form, price_usd: e.target.value })} />
          </div>
          <Button type="submit" loading={create.isPending}>
            Agregar presentacion
          </Button>
        </form>
      )}
    </div>
  );
}
