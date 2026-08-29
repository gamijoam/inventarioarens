import { Download, Printer } from 'lucide-react';
import { toast } from 'sonner';

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
import { Skeleton } from '@/components/ui/Skeleton';
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

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[85vh] overflow-auto">
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
            <div className="grid grid-cols-2 gap-2">
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

            <div>
              <p className="text-text-muted mb-1 text-xs font-semibold uppercase">Pagos</p>
              <div className="space-y-1">
                {data.payments.map((payment) => (
                  <div key={`${payment.method}-${payment.currency}`} className="flex justify-between gap-3">
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
                    <div key={index} className="flex justify-between gap-3 text-xs">
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
