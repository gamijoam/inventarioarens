import { useMemo, useState } from 'react';
import { Download, Layers, Printer, Search, Users } from 'lucide-react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Skeleton } from '@/components/ui/Skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/Tabs';
import { formatMoney } from '@/lib/money';
import { usePrinterStations } from '@/features/printing/api';

import { downloadReportZPdf, openReportZPdf, printReportZThermal, useReportZ } from './reportZApi';

interface ReportZDialogProps {
  sessionId: number;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

export function ReportZDialog({ sessionId, open, onOpenChange }: ReportZDialogProps) {
  const { data, isLoading, isError } = useReportZ(open ? sessionId : null, open);
  const { data: stations = [] } = usePrinterStations({ enabled: open });
  const activeStation = stations.find((station) => station.is_active) ?? null;

  const [selectedCategory, setSelectedCategory] = useState<string>('all');
  const [categorySearch, setCategorySearch] = useState('');
  const [customerSearch, setCustomerSearch] = useState('');

  async function printThermal(): Promise<void> {
    if (!data) return;
    if (!activeStation) {
      toast.error('No hay una estación de impresión activa configurada en /printing.');
      return;
    }
    try {
      const result = await printReportZThermal(data, activeStation);
      if (result.ok === false) {
        toast.error(result.message ?? 'No se pudo imprimir el Reporte Z.');
        return;
      }
      toast.success('Reporte Z enviado a la impresora térmica.');
    } catch {
      toast.error('No se pudo contactar el agente de impresión local.');
    }
  }

  const formatBs = (value: number): string =>
    `Bs ${new Intl.NumberFormat('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value)}`;

  const formatDate = (value: string | null | undefined): string =>
    value
      ? new Intl.DateTimeFormat('es-VE', {
          dateStyle: 'short',
          timeStyle: 'short',
        }).format(new Date(value))
      : '—';

  const normalize = (str: string): string =>
    str.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

  const categories = data?.categories ?? [];
  const customers = data?.customers ?? [];

  const filteredCategories = useMemo(() => {
    return categories
      .filter((cat) => {
        if (selectedCategory !== 'all' && cat.name !== selectedCategory) {
          return false;
        }
        if (!categorySearch.trim()) return true;
        const q = normalize(categorySearch);
        const matchesCategory = normalize(cat.name).includes(q);
        const matchesProduct = (cat.products ?? []).some(
          (p) => normalize(p.name).includes(q) || (p.sku && normalize(p.sku).includes(q)),
        );
        return matchesCategory || matchesProduct;
      })
      .map((cat) => {
        if (!categorySearch.trim()) return cat;
        const q = normalize(categorySearch);
        const matchesCategory = normalize(cat.name).includes(q);
        if (matchesCategory) {
          return cat;
        }
        const matchingProducts = (cat.products ?? []).filter(
          (p) => normalize(p.name).includes(q) || (p.sku && normalize(p.sku).includes(q)),
        );
        return {
          ...cat,
          products: matchingProducts,
        };
      });
  }, [categories, selectedCategory, categorySearch]);

  const filteredCustomers = useMemo(() => {
    if (!customerSearch.trim()) return customers;
    const q = normalize(customerSearch);
    return customers.filter(
      (c) =>
        normalize(c.name).includes(q) || (c.document && normalize(c.document).includes(q)),
    );
  }, [customers, customerSearch]);

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[88vh] overflow-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Reporte Z {data?.z_number != null ? `#${data.z_number}` : ''}</DialogTitle>
          <DialogDescription>
            {data
              ? `${data.cash_register ?? 'Caja'} · ${data.cashier ?? '-'} · ${data.branch ?? '-'}`
              : 'Documento de cierre de caja.'}
          </DialogDescription>
        </DialogHeader>

        {isLoading && <Skeleton className="h-64" />}

        {isError && (
          <EmptyState
            title="No se pudo cargar el Reporte Z"
            description="Solo se emite para turnos cerrados."
          />
        )}

        {data && (
          <div className="space-y-4 text-sm">
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
              <Info label="Apertura" value={formatDate(data.opened_at)} />
              <Info label="Cierre" value={formatDate(data.closed_at)} />
              <Info label="Tickets" value={String(data.totals.orders_count)} />
              <Info label="Total USD" value={formatMoney(data.totals.paid_base_amount)} />
              <Info label="Total Bs" value={formatBs(data.totals.paid_local_amount)} />
              <Info
                label="Diferencia efectivo USD"
                value={formatMoney(data.totals.difference_cash_usd)}
              />
            </div>

            <Tabs defaultValue="resumen" className="w-full">
              <TabsList className="grid w-full grid-cols-3">
                <TabsTrigger value="resumen">Resumen & Pagos</TabsTrigger>
                <TabsTrigger value="categorias">
                  Categorías {categories.length > 0 ? `(${categories.length})` : ''}
                </TabsTrigger>
                <TabsTrigger value="clientes">
                  Clientes {customers.length > 0 ? `(${customers.length})` : ''}
                </TabsTrigger>
              </TabsList>

              <TabsContent value="resumen" className="space-y-4 pt-2">
                <div>
                  <p className="text-text-muted mb-1 text-xs font-semibold uppercase">Pagos</p>
                  <div className="space-y-1">
                    {data.payments.map((payment) => (
                      <div
                        key={`${payment.method}-${payment.currency}`}
                        className="flex justify-between gap-3 border-b border-border/40 py-1"
                      >
                        <span className="text-text-muted">
                          {payment.name} ({payment.currency}) · {payment.payments_count}
                        </span>
                        <strong>
                          {payment.currency === 'VES'
                            ? formatBs(payment.amount_local)
                            : formatMoney(payment.amount_base)}
                        </strong>
                      </div>
                    ))}
                    {data.payments.length === 0 && (
                      <p className="text-text-muted">Sin pagos registrados.</p>
                    )}
                  </div>
                </div>

                {data.counts.length > 0 && (
                  <div>
                    <p className="text-text-muted mb-1 text-xs font-semibold uppercase">Conteo</p>
                    <div className="space-y-1">
                      {data.counts.map((count, index) => (
                        <div key={index} className="flex justify-between gap-3 text-xs border-b border-border/40 py-0.5">
                          <span>
                            {count.denomination} {count.currency} x{count.quantity}
                          </span>
                          <span>
                            {count.currency === 'VES'
                              ? formatBs(count.total_amount)
                              : formatMoney(count.total_amount)}
                          </span>
                        </div>
                      ))}
                    </div>
                  </div>
                )}
              </TabsContent>

              <TabsContent value="categorias" className="space-y-3 pt-2">
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                  <div className="relative flex-1">
                    <Search className="text-text-muted absolute left-2.5 top-2.5 size-4" />
                    <Input
                      placeholder="Buscar categoría o producto..."
                      value={categorySearch}
                      onChange={(e) => setCategorySearch(e.target.value)}
                      className="pl-8 text-xs"
                    />
                  </div>
                </div>

                {categories.length > 0 && (
                  <div className="flex flex-wrap gap-1.5">
                    <button
                      type="button"
                      onClick={() => setSelectedCategory('all')}
                      className={`cursor-pointer rounded-full px-2.5 py-0.5 text-xs font-medium transition-colors ${
                        selectedCategory === 'all'
                          ? 'bg-primary text-primary-foreground'
                          : 'bg-surface border border-border text-text-secondary hover:bg-bg'
                      }`}
                    >
                      Todas ({categories.length})
                    </button>
                    {categories.map((cat) => (
                      <button
                        key={cat.name}
                        type="button"
                        onClick={() =>
                          setSelectedCategory(selectedCategory === cat.name ? 'all' : cat.name)
                        }
                        className={`cursor-pointer rounded-full px-2.5 py-0.5 text-xs font-medium transition-colors ${
                          selectedCategory === cat.name
                            ? 'bg-primary text-primary-foreground'
                            : 'bg-surface border border-border text-text-secondary hover:bg-bg'
                        }`}
                      >
                        {cat.name}
                      </button>
                    ))}
                  </div>
                )}

                <div className="space-y-3 max-h-[38vh] overflow-y-auto pr-1">
                  {filteredCategories.map((cat) => (
                    <div
                      key={cat.name}
                      className="rounded-lg border border-border bg-surface/50 p-3 space-y-2"
                    >
                      <div className="flex items-center justify-between">
                        <div className="flex items-center gap-2">
                          <Layers className="size-4 text-primary" />
                          <span className="font-semibold text-text">{cat.name}</span>
                          <Badge variant="default" className="text-[10px]">
                            {cat.items_count} un.
                          </Badge>
                        </div>
                        <div className="text-right">
                          <div className="font-bold text-text">{formatMoney(cat.amount_base)}</div>
                          <div className="text-xs text-text-muted">{formatBs(cat.amount_local)}</div>
                        </div>
                      </div>

                      {cat.products && cat.products.length > 0 && (
                        <div className="border-t border-border/60 pt-2 space-y-1">
                          {cat.products.map((prod, idx) => (
                            <div
                              key={idx}
                              className="flex items-center justify-between text-xs text-text-muted hover:text-text"
                            >
                              <div className="truncate max-w-[240px] sm:max-w-xs">
                                <span>{prod.name}</span>
                                {prod.sku && (
                                  <span className="ml-1 text-[10px] text-text-muted/80">
                                    ({prod.sku})
                                  </span>
                                )}
                              </div>
                              <div className="flex items-center gap-3">
                                <span>x{prod.quantity}</span>
                                <span className="font-medium text-text tabular-nums">
                                  {formatMoney(prod.amount_base)}
                                </span>
                              </div>
                            </div>
                          ))}
                        </div>
                      )}
                    </div>
                  ))}

                  {filteredCategories.length === 0 && (
                    <p className="text-center text-text-muted py-6 text-xs">
                      {categories.length === 0
                        ? 'No hay ventas por categoría en esta sesión.'
                        : 'No se encontraron categorías que coincidan con la búsqueda.'}
                    </p>
                  )}
                </div>
              </TabsContent>

              <TabsContent value="clientes" className="space-y-3 pt-2">
                <div className="relative">
                  <Search className="text-text-muted absolute left-2.5 top-2.5 size-4" />
                  <Input
                    placeholder="Buscar cliente por nombre o documento..."
                    value={customerSearch}
                    onChange={(e) => setCustomerSearch(e.target.value)}
                    className="pl-8 text-xs"
                  />
                </div>

                <div className="space-y-2 max-h-[38vh] overflow-y-auto pr-1">
                  {filteredCustomers.map((cust, idx) => (
                    <div
                      key={idx}
                      className="flex items-center justify-between rounded-lg border border-border bg-surface/50 p-2.5 text-xs"
                    >
                      <div className="space-y-0.5">
                        <div className="flex items-center gap-2">
                          <Users className="size-3.5 text-primary" />
                          <span className="font-medium text-text">{cust.name}</span>
                          {cust.document && (
                            <Badge variant="outline" className="text-[10px]">
                              {cust.document}
                            </Badge>
                          )}
                        </div>
                        <div className="text-text-muted text-[11px]">
                          {cust.orders_count} {cust.orders_count === 1 ? 'ticket' : 'tickets'}
                        </div>
                      </div>
                      <div className="text-right">
                        <div className="font-bold text-text tabular-nums">
                          {formatMoney(cust.amount_base)}
                        </div>
                        <div className="text-text-muted text-[11px] tabular-nums">
                          {formatBs(cust.amount_local)}
                        </div>
                      </div>
                    </div>
                  ))}

                  {filteredCustomers.length === 0 && (
                    <p className="text-center text-text-muted py-6 text-xs">
                      {customers.length === 0
                        ? 'No hay ventas registradas por cliente en esta sesión.'
                        : 'No se encontraron clientes que coincidan con la búsqueda.'}
                    </p>
                  )}
                </div>
              </TabsContent>
            </Tabs>
          </div>
        )}

        <DialogFooter>
          <Button variant="outline" disabled={!data} onClick={() => void openReportZPdf(sessionId)}>
            <Printer className="size-4" /> Imprimir
          </Button>
          <Button variant="outline" disabled={!data || !activeStation} onClick={() => void printThermal()}>
            <Printer className="size-4" /> Imprimir térmica
          </Button>
          <Button disabled={!data} onClick={() => void downloadReportZPdf(sessionId)}>
            <Download className="size-4" /> Descargar PDF
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function Info({ label, value }: { label: string; value: string }) {
  return (
    <div className="border-border rounded border px-2 py-1.5">
      <div className="text-text-muted text-[10px] uppercase">{label}</div>
      <div className="font-semibold tabular-nums">{value}</div>
    </div>
  );
}
