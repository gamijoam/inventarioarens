/**
 * BulkPriceAdjustmentModal.tsx
 * Modal para simular, aplicar y revertir ajustes masivos de precios y márgenes.
 */
import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  CheckCircle2,
  History,
  Loader2,
  RotateCcw,
  Sparkles,
  TrendingUp,
} from 'lucide-react';
import { toast } from 'sonner';

import { getOne, postOne } from '@/api/client';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/Dialog';
import { Input } from '@/components/ui/Input';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/Tabs';
import { usePriceLists } from '@/features/inventory-center/api';
import { formatMoney } from '@/lib/money';

interface BulkPriceAdjustmentModalProps {
  open: boolean;
  onClose: () => void;
}

interface SimulationItem {
  product_id: number;
  name: string;
  sku: string;
  barcode: string | null;
  cost: number;
  current_price: number;
  new_price: number;
  diff_amount: number;
  diff_percent: number;
  margin_before: number;
  margin_after: number;
}

interface SimulationResult {
  items: SimulationItem[];
  total_items: number;
  average_diff_percent: number;
}

interface AdjustmentHistoryItem {
  id: number;
  name: string;
  target: string;
  adjustment_type: string;
  adjustment_value: string;
  rounding: string;
  items_count: number;
  status: 'applied' | 'reverted';
  created_at: string;
  reverted_at: string | null;
  user?: { name: string; email: string };
  reverted_by?: { name: string; email: string };
  price_list?: { name: string };
}

export function BulkPriceAdjustmentModal({ open, onClose }: BulkPriceAdjustmentModalProps) {
  const queryClient = useQueryClient();
  const { data: priceLists = [] } = usePriceLists();

  const [activeTab, setActiveTab] = useState<'adjust' | 'history'>('adjust');

  // Form state
  const [target, setTarget] = useState<'base_price' | 'price_list'>('base_price');
  const [priceListId, setPriceListId] = useState<string>('');
  const [adjustmentType, setAdjustmentType] = useState<string>('percentage_increase');
  const [adjustmentValue, setAdjustmentValue] = useState<string>('10');
  const [rounding, setRounding] = useState<string>('none');
  const [searchFilter, setSearchFilter] = useState<string>('');
  const [adjustmentName, setAdjustmentName] = useState<string>('');

  const [simulation, setSimulation] = useState<SimulationResult | null>(null);

  // 1. Simulación
  const simulateMutation = useMutation({
    mutationFn: async () => {
      const payload: Record<string, unknown> = {
        target,
        price_list_id: target === 'price_list' && priceListId ? Number(priceListId) : null,
        adjustment_type: adjustmentType,
        adjustment_value: Number(adjustmentValue),
        rounding,
        filters: searchFilter.trim() ? { search: searchFilter.trim() } : {},
      };
      const res = await postOne<typeof payload, SimulationResult>(
        '/products/bulk-prices/simulate',
        payload,
      );
      return res;
    },
    onSuccess: (data) => {
      setSimulation(data);
      toast.info(`Simulación completada: ${data.total_items} productos calculados.`);
    },
    onError: (err: any) => {
      toast.error(err?.message || 'Error al ejecutar la simulación de precios.');
    },
  });

  // 2. Aplicar ajuste
  const applyMutation = useMutation({
    mutationFn: async () => {
      const payload: Record<string, unknown> = {
        name: adjustmentName.trim() || undefined,
        target,
        price_list_id: target === 'price_list' && priceListId ? Number(priceListId) : null,
        adjustment_type: adjustmentType,
        adjustment_value: Number(adjustmentValue),
        rounding,
        filters: searchFilter.trim() ? { search: searchFilter.trim() } : {},
      };
      return postOne<typeof payload, any>('/products/bulk-prices/apply', payload);
    },
    onSuccess: (res) => {
      toast.success(res?.message || 'Ajuste masivo de precios aplicado con éxito.');
      void queryClient.invalidateQueries({ queryKey: ['products'] });
      void queryClient.invalidateQueries({ queryKey: ['bulk-prices-history'] });
      setSimulation(null);
      setActiveTab('history');
    },
    onError: (err: any) => {
      toast.error(err?.message || 'Error al aplicar el ajuste de precios.');
    },
  });

  // 3. Historial de ajustes
  const { data: historyData, isLoading: loadingHistory, refetch: refetchHistory } = useQuery({
    queryKey: ['bulk-prices-history'],
    queryFn: async () => {
      const res = await getOne<{ data: AdjustmentHistoryItem[] }>('/products/bulk-prices/history');
      return res.data;
    },
    enabled: open && activeTab === 'history',
  });

  // 4. Revertir ajuste
  const rollbackMutation = useMutation({
    mutationFn: async (adjustmentId: number) => {
      return postOne(`/products/bulk-prices/${adjustmentId}/rollback`, {});
    },
    onSuccess: () => {
      toast.success('Ajuste revertido exitosamente. Los precios anteriores fueron restaurados.');
      void queryClient.invalidateQueries({ queryKey: ['products'] });
      void refetchHistory();
    },
    onError: (err: any) => {
      toast.error(err?.message || 'Error al revertir el ajuste.');
    },
  });

  const handleSimulate = () => {
    simulateMutation.mutate();
  };

  const handleApply = () => {
    if (!simulation || simulation.items.length === 0) {
      toast.error('Primero debes simular el impacto antes de aplicar los cambios.');
      return;
    }
    applyMutation.mutate();
  };

  return (
    <Dialog open={open} onOpenChange={(isOpen) => !isOpen && onClose()}>
      <DialogContent className="max-w-4xl max-h-[90vh] flex flex-col p-6 overflow-hidden">
        <DialogHeader className="flex flex-row items-center justify-between pb-3 border-b border-border">
          <div className="flex items-center gap-2.5">
            <div className="size-10 rounded-xl bg-primary/10 text-primary flex items-center justify-center font-bold">
              <TrendingUp className="size-5" />
            </div>
            <div>
              <DialogTitle className="text-lg font-bold">
                Actualización Masiva Inteligente de Precios
              </DialogTitle>
              <p className="text-xs text-text-muted">
                Simula y aplica aumentos, descuentos o márgenes sobre costo en tiempo real con rollback seguro.
              </p>
            </div>
          </div>
        </DialogHeader>

        <Tabs value={activeTab} onValueChange={(v) => setActiveTab(v as any)} className="flex-1 flex flex-col min-h-0 mt-3">
          <TabsList className="grid w-full grid-cols-2 max-w-xs mb-3">
            <TabsTrigger value="adjust" className="gap-2 text-xs">
              <Sparkles className="size-3.5" />
              Nuevo Ajuste
            </TabsTrigger>
            <TabsTrigger value="history" className="gap-2 text-xs">
              <History className="size-3.5" />
              Historial y Reversiones
            </TabsTrigger>
          </TabsList>

          {/* TAB 1: NUEVO AJUSTE */}
          <TabsContent value="adjust" className="flex-1 flex flex-col min-h-0 overflow-y-auto space-y-4 pr-1">
            {/* Formulario de Reglas */}
            <div className="grid grid-cols-1 md:grid-cols-4 gap-3 bg-surface p-4 rounded-xl border border-border">
              <div>
                <label className="text-xs font-semibold text-text-secondary block mb-1.5">
                  Precio a Modificar
                </label>
                <select
                  value={target}
                  onChange={(e) => setTarget(e.target.value as any)}
                  className="w-full text-xs rounded-lg border border-border bg-bg px-2.5 py-2"
                >
                  <option value="base_price">Precio Base (PVP Principal)</option>
                  <option value="price_list">Lista de Precios</option>
                </select>
              </div>

              {target === 'price_list' && (
                <div>
                  <label className="text-xs font-semibold text-text-secondary block mb-1.5">
                    Seleccionar Lista
                  </label>
                  <select
                    value={priceListId}
                    onChange={(e) => setPriceListId(e.target.value)}
                    className="w-full text-xs rounded-lg border border-border bg-bg px-2.5 py-2"
                  >
                    <option value="">Seleccione una lista...</option>
                    {priceLists.map((pl) => (
                      <option key={pl.id} value={pl.id}>
                        {pl.name} ({pl.code})
                      </option>
                    ))}
                  </select>
                </div>
              )}

              <div>
                <label className="text-xs font-semibold text-text-secondary block mb-1.5">
                  Tipo de Operación
                </label>
                <select
                  value={adjustmentType}
                  onChange={(e) => setAdjustmentType(e.target.value)}
                  className="w-full text-xs rounded-lg border border-border bg-bg px-2.5 py-2"
                >
                  <option value="percentage_increase">Aumento Porcentual (+%)</option>
                  <option value="percentage_decrease">Descuento Porcentual (-%)</option>
                  <option value="fixed_amount">Monto Fijo (+/- $)</option>
                  <option value="markup_on_cost">Margen sobre Costo (% s/Costo)</option>
                </select>
              </div>

              <div>
                <label className="text-xs font-semibold text-text-secondary block mb-1.5">
                  Valor ({adjustmentType.includes('percentage') || adjustmentType === 'markup_on_cost' ? '%' : '$'})
                </label>
                <Input
                  type="number"
                  step="any"
                  value={adjustmentValue}
                  onChange={(e) => setAdjustmentValue(e.target.value)}
                  placeholder="Ej: 15"
                  className="h-9 text-xs font-mono font-bold"
                />
              </div>

              <div>
                <label className="text-xs font-semibold text-text-secondary block mb-1.5">
                  Regla de Redondeo
                </label>
                <select
                  value={rounding}
                  onChange={(e) => setRounding(e.target.value)}
                  className="w-full text-xs rounded-lg border border-border bg-bg px-2.5 py-2"
                >
                  <option value="none">Sin redondeo (2 decimales)</option>
                  <option value="integer">Entero superior (Ej: $15.00)</option>
                  <option value="cents_99">Terminar en .99 (Ej: $14.99)</option>
                  <option value="cents_50">Terminar en .50 (Ej: $14.50)</option>
                </select>
              </div>
            </div>

            {/* Filtros Opcionales */}
            <div className="flex flex-wrap items-center gap-3">
              <div className="flex-1 min-w-[240px]">
                <Input
                  value={searchFilter}
                  onChange={(e) => setSearchFilter(e.target.value)}
                  placeholder="Filtrar por nombre, SKU o código de barras (opcional)..."
                  className="h-8 text-xs"
                />
              </div>
              <Button
                variant="outline"
                size="sm"
                onClick={handleSimulate}
                loading={simulateMutation.isPending}
                className="gap-1.5 border-primary/30 text-primary hover:bg-primary/5"
              >
                <Sparkles className="size-3.5" />
                Simular Impacto
              </Button>
            </div>

            {/* Resultados de la Simulación */}
            {simulation && (
              <div className="flex-1 flex flex-col min-h-0 space-y-3">
                <div className="grid grid-cols-3 gap-3">
                  <div className="rounded-xl border border-border bg-surface p-3 text-center">
                    <span className="text-[11px] text-text-muted font-medium block">Productos Afectados</span>
                    <span className="text-xl font-bold font-mono text-text-primary">
                      {simulation.total_items}
                    </span>
                  </div>
                  <div className="rounded-xl border border-border bg-surface p-3 text-center">
                    <span className="text-[11px] text-text-muted font-medium block">Variación Promedio</span>
                    <span className="text-xl font-bold font-mono text-primary">
                      {simulation.average_diff_percent > 0 ? `+${simulation.average_diff_percent}%` : `${simulation.average_diff_percent}%`}
                    </span>
                  </div>
                  <div className="rounded-xl border border-border bg-surface p-3 text-center">
                    <span className="text-[11px] text-text-muted font-medium block">Impacto en POS</span>
                    <span className="text-xs font-semibold text-emerald-600 dark:text-emerald-400 mt-1 block">
                      Actualización Instantánea (WS)
                    </span>
                  </div>
                </div>

                <div className="flex-1 overflow-auto rounded-xl border border-border bg-surface">
                  <table className="w-full text-left text-xs border-collapse">
                    <thead className="sticky top-0 bg-bg/90 backdrop-blur-xs border-b border-border text-[11px] font-semibold text-text-muted uppercase">
                      <tr>
                        <th className="py-2.5 px-3">Producto</th>
                        <th className="py-2.5 px-3 text-right">Costo Promedio</th>
                        <th className="py-2.5 px-3 text-right">Precio Actual</th>
                        <th className="py-2.5 px-3 text-right font-bold text-text-primary">Precio Nuevo</th>
                        <th className="py-2.5 px-3 text-right">Diferencia</th>
                        <th className="py-2.5 px-3 text-right">Margen (Antes ➔ Después)</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-border">
                      {simulation.items.slice(0, 50).map((item) => (
                        <tr key={item.product_id} className="hover:bg-bg/40 transition-colors">
                          <td className="py-2.5 px-3">
                            <div className="font-semibold text-text-primary">{item.name}</div>
                            <div className="font-mono text-[10px] text-text-muted">{item.sku}</div>
                          </td>
                          <td className="py-2.5 px-3 text-right font-mono text-text-muted">
                            {formatMoney(item.cost)}
                          </td>
                          <td className="py-2.5 px-3 text-right font-mono text-text-secondary">
                            {formatMoney(item.current_price)}
                          </td>
                          <td className="py-2.5 px-3 text-right font-mono font-bold text-primary">
                            {formatMoney(item.new_price)}
                          </td>
                          <td className="py-2.5 px-3 text-right font-mono">
                            <span className={item.diff_amount >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'}>
                              {item.diff_amount >= 0 ? `+${formatMoney(item.diff_amount)}` : formatMoney(item.diff_amount)} ({item.diff_percent}%)
                            </span>
                          </td>
                          <td className="py-2.5 px-3 text-right font-mono text-[11px]">
                            <span className="text-text-muted">{item.margin_before}%</span> ➔{' '}
                            <span className="font-bold text-text-primary">{item.margin_after}%</span>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>

                <div className="flex items-center justify-between pt-2 border-t border-border">
                  <Input
                    value={adjustmentName}
                    onChange={(e) => setAdjustmentName(e.target.value)}
                    placeholder="Nombre o motivo del ajuste (ej. Aumento general de lista 10%)..."
                    className="max-w-md h-8 text-xs"
                  />
                  <div className="flex items-center gap-2">
                    <Button variant="ghost" size="sm" onClick={() => setSimulation(null)}>
                      Descartar
                    </Button>
                    <Button
                      size="sm"
                      onClick={handleApply}
                      loading={applyMutation.isPending}
                      className="gap-1.5 font-bold"
                    >
                      <CheckCircle2 className="size-4" />
                      Aplicar a {simulation.total_items} Productos
                    </Button>
                  </div>
                </div>
              </div>
            )}
          </TabsContent>

          {/* TAB 2: HISTORIAL Y ROLLBACK */}
          <TabsContent value="history" className="flex-1 flex flex-col min-h-0 overflow-y-auto">
            {loadingHistory ? (
              <div className="flex h-48 items-center justify-center gap-2 text-text-muted">
                <Loader2 className="size-5 animate-spin text-primary" />
                <span className="text-xs">Cargando historial de ajustes...</span>
              </div>
            ) : !historyData || historyData.length === 0 ? (
              <div className="flex h-48 flex-col items-center justify-center gap-2 text-text-muted">
                <History className="size-8 stroke-1 text-text-muted/60" />
                <p className="text-xs">No hay ajustes masivos registrados aún.</p>
              </div>
            ) : (
              <div className="flex-1 overflow-auto rounded-xl border border-border bg-surface">
                <table className="w-full text-left text-xs border-collapse">
                  <thead className="sticky top-0 bg-bg/90 backdrop-blur-xs border-b border-border text-[11px] font-semibold text-text-muted uppercase">
                    <tr>
                      <th className="py-2.5 px-3">Fecha y Nombre</th>
                      <th className="py-2.5 px-3">Objetivo</th>
                      <th className="py-2.5 px-3">Ajuste</th>
                      <th className="py-2.5 px-3 text-right">Productos</th>
                      <th className="py-2.5 px-3">Usuario</th>
                      <th className="py-2.5 px-3">Estado</th>
                      <th className="py-2.5 px-3 text-right">Acción</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border">
                    {historyData.map((item) => {
                      const isApplied = item.status === 'applied';
                      return (
                        <tr key={item.id} className="hover:bg-bg/40 transition-colors">
                          <td className="py-2.5 px-3">
                            <div className="font-semibold text-text-primary">{item.name}</div>
                            <div className="text-[10px] text-text-muted">
                              {new Date(item.created_at).toLocaleString()}
                            </div>
                          </td>
                          <td className="py-2.5 px-3 text-text-secondary">
                            {item.target === 'base_price' ? 'Precio Base' : (item.price_list?.name || 'Lista de precio')}
                          </td>
                          <td className="py-2.5 px-3 font-mono">
                            {item.adjustment_type.includes('percentage') ? `${item.adjustment_value}%` : `$${item.adjustment_value}`}
                          </td>
                          <td className="py-2.5 px-3 text-right font-mono font-bold">
                            {item.items_count}
                          </td>
                          <td className="py-2.5 px-3 text-text-muted">
                            {item.user?.name || 'Sistema'}
                          </td>
                          <td className="py-2.5 px-3">
                            <Badge variant={isApplied ? 'success' : 'default'} className="text-[10px]">
                              {isApplied ? 'Activo' : 'Revertido'}
                            </Badge>
                          </td>
                          <td className="py-2.5 px-3 text-right">
                            {isApplied ? (
                              <Button
                                size="sm"
                                variant="outline"
                                onClick={() => rollbackMutation.mutate(item.id)}
                                loading={rollbackMutation.isPending}
                                className="h-7 text-xs gap-1 border-rose-500/30 text-rose-600 dark:text-rose-400 hover:bg-rose-500/10"
                              >
                                <RotateCcw className="size-3" />
                                Revertir
                              </Button>
                            ) : (
                              <span className="text-[10px] text-text-muted">
                                Revertido el {item.reverted_at ? new Date(item.reverted_at).toLocaleDateString() : ''}
                              </span>
                            )}
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </TabsContent>
        </Tabs>
      </DialogContent>
    </Dialog>
  );
}
