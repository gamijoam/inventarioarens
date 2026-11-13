import { useState } from 'react';
import { Printer, X } from 'lucide-react';

import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/Button';
import { Card, CardContent } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Switch } from '@/components/ui/Switch';
import { usePriceLists } from '@/features/inventory-center/api';
import { ProductAutocomplete, type ProductAutocompleteOption } from '@/features/purchases/components/ProductAutocomplete';
import { useProductLabels } from './api';
import { LabelSheet } from './LabelSheet';
import type { LabelFormat, LabelsSelection } from './schemas';

type Source = 'products' | 'changed' | 'bulk';

const PRINT_CSS = `@media print {
  body * { visibility: hidden !important; }
  .label-sheet, .label-sheet * { visibility: visible !important; }
  .label-sheet { position: absolute; left: 0; top: 0; width: 100%; }
  @page { margin: 6mm; }
}`;

function weekAgo(): string {
  const date = new Date();
  date.setDate(date.getDate() - 7);
  return date.toISOString().slice(0, 10);
}

interface LabelsPanelProps {
  initialBulkAdjustmentId?: number;
}

export function LabelsPanel({ initialBulkAdjustmentId }: LabelsPanelProps) {
  const [source, setSource] = useState<Source>(initialBulkAdjustmentId ? 'bulk' : 'products');
  const [products, setProducts] = useState<ProductAutocompleteOption[]>([]);
  const [changedSince, setChangedSince] = useState(weekAgo);
  const [priceListId, setPriceListId] = useState<number | null>(null);
  const [format, setFormat] = useState<LabelFormat>('shelf');
  const [copies, setCopies] = useState(1);
  const [showVes, setShowVes] = useState(true);
  const [showPackagings, setShowPackagings] = useState(true);
  const [selection, setSelection] = useState<LabelsSelection | null>(
    initialBulkAdjustmentId ? { bulkAdjustmentId: initialBulkAdjustmentId, priceListId: null } : null,
  );
  const { data: priceLists } = usePriceLists();
  const { data, isFetching } = useProductLabels(selection);
  const labels = data?.data.labels ?? [];
  const skipped = data?.data.skipped ?? [];

  const canGenerate =
    (source === 'products' && products.length > 0) || (source === 'changed' && changedSince !== '') || (source === 'bulk' && Boolean(initialBulkAdjustmentId));

  function generate() {
    if (source === 'products') setSelection({ productIds: products.map((p) => p.id), priceListId });
    if (source === 'changed') setSelection({ changedSince, priceListId });
    if (source === 'bulk' && initialBulkAdjustmentId) setSelection({ bulkAdjustmentId: initialBulkAdjustmentId, priceListId });
  }

  function addProduct(product?: ProductAutocompleteOption) {
    if (!product) return;
    setProducts((current) => (current.some((p) => p.id === product.id) ? current : [...current, product]));
  }

  return (
    <div className="space-y-4">
      <style>{PRINT_CSS}</style>
      <Card>
        <CardContent className="space-y-3 p-3">
          <div className="flex flex-wrap items-end gap-3">
            <div>
              <Label htmlFor="labels-source">Que productos</Label>
              <Select id="labels-source" value={source} onChange={(e) => setSource(e.target.value as Source)}>
                <option value="products">Elegir productos</option>
                <option value="changed">Cambiaron de precio</option>
                {initialBulkAdjustmentId ? <option value="bulk">Ajuste masivo #{initialBulkAdjustmentId}</option> : null}
              </Select>
            </div>
            {source === 'changed' && (
              <div>
                <Label htmlFor="labels-since">Cambiaron desde</Label>
                <Input id="labels-since" type="date" value={changedSince} onChange={(e) => setChangedSince(e.target.value)} />
              </div>
            )}
            <div>
              <Label htmlFor="labels-price-list">Lista de precio</Label>
              <Select
                id="labels-price-list"
                value={priceListId ?? ''}
                onChange={(e) => setPriceListId(e.target.value ? Number(e.target.value) : null)}
              >
                <option value="">Precio base</option>
                {(priceLists ?? []).map((list) => (
                  <option key={list.id} value={list.id}>
                    {list.name}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label htmlFor="labels-format">Formato</Label>
              <Select id="labels-format" value={format} onChange={(e) => setFormat(e.target.value as LabelFormat)}>
                <option value="shelf">Etiqueta 50 x 30 mm</option>
                <option value="a4">Hoja carta / A4 (3 columnas)</option>
              </Select>
            </div>
            <div className="w-24">
              <Label htmlFor="labels-copies">Copias</Label>
              <Input
                id="labels-copies"
                type="number"
                min={1}
                max={50}
                value={copies}
                onChange={(e) => setCopies(Math.min(50, Math.max(1, Number(e.target.value) || 1)))}
              />
            </div>
            <label className="flex items-center gap-2 text-sm">
              <Switch checked={showVes} onCheckedChange={setShowVes} aria-label="Mostrar precio en Bs" />
              Precio en Bs
            </label>
            <label className="flex items-center gap-2 text-sm">
              <Switch checked={showPackagings} onCheckedChange={setShowPackagings} aria-label="Incluir presentaciones" />
              Presentaciones
            </label>
            <Button onClick={generate} disabled={!canGenerate} loading={isFetching}>
              Generar
            </Button>
            <Button variant="outline" onClick={() => window.print()} disabled={labels.length === 0}>
              <Printer className="size-4" />
              Imprimir
            </Button>
          </div>

          {source === 'products' && (
            <div className="space-y-2">
              <div className="max-w-md">
                <ProductAutocomplete value={null} onChange={(_id, product) => addProduct(product)} placeholder="Buscar producto por nombre, SKU o codigo" />
              </div>
              <div className="flex flex-wrap gap-2">
                {products.map((product) => (
                  <span key={product.id} className="inline-flex items-center gap-1 rounded border border-border px-2 py-0.5 text-xs">
                    {product.name}
                    <button
                      type="button"
                      aria-label={`Quitar ${product.name}`}
                      onClick={() => setProducts((current) => current.filter((p) => p.id !== product.id))}
                    >
                      <X className="size-3" />
                    </button>
                  </span>
                ))}
              </div>
            </div>
          )}
        </CardContent>
      </Card>

      {skipped.length > 0 && (
        <Alert variant="warning">
          {skipped.length} producto(s) sin etiqueta:{' '}
          {skipped.map((item) => `${item.name} (${item.reason ?? 'sin precio'})`).join(', ')}
        </Alert>
      )}

      {labels.length > 0 ? (
        <div className="overflow-auto rounded border border-border bg-white p-3">
          <LabelSheet labels={labels} copies={copies} format={format} showVes={showVes} showPackagings={showPackagings} />
        </div>
      ) : (
        selection && !isFetching && <p className="text-sm text-text-secondary">No hay productos para etiquetar con esa seleccion.</p>
      )}
    </div>
  );
}
