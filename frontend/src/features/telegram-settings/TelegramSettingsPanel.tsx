/**
 * TelegramSettingsPanel - Configuracion del bot de Telegram por empresa.
 *
 * Incluye:
 *  - Toggle activar bot + hora del resumen diario + alertas de stock bajo.
 *  - Lista blanca: tabla de usuarios vinculados con su telegram_id.
 *
 * Solo se muestra en el contexto de la empresa/grupo actual (el X-Tenant
 * del request define que empresa se configura).
 */
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Save, Plus, Trash2 } from 'lucide-react';

import { Button } from '@/components/ui/Button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';

import { useTenantSettings, useUpdateTenantSettings } from './api';
import type { TelegramSettings } from './api';

interface Row {
  id: string;
  name: string;
  telegram_id: string;
}

export function TelegramSettingsPanel() {
  const { data, isLoading } = useTenantSettings();
  const update = useUpdateTenantSettings();

  const [enabled, setEnabled] = useState(false);
  const [reportTime, setReportTime] = useState('21:00');
  const [lowStockAlerts, setLowStockAlerts] = useState(false);
  const [lowStockFrequency, setLowStockFrequency] = useState<'daily' | '4h' | '8h'>('daily');
  const [rows, setRows] = useState<Row[]>([]);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!data) return;
    const t = (data.settings?.telegram as TelegramSettings) ?? {};
    setEnabled(t.enabled ?? false);
    setReportTime(t.report_time ?? '21:00');
    setLowStockAlerts(t.low_stock_alerts ?? false);
    setLowStockFrequency(t.low_stock_frequency ?? 'daily');
    setRows(
      (t.whitelist ?? []).map((w) => ({
        id: String(w.id),
        name: w.name ?? '',
        telegram_id: w.telegram_id ?? '',
      })),
    );
  }, [data]);

  if (isLoading) return <Spinner label="Cargando configuración..." />;

  function addRow() {
    setRows((current) => [...current, { id: `new-${Date.now()}`, name: '', telegram_id: '' }]);
  }

  function updateRow(id: string, patch: Partial<Row>) {
    setRows((current) => current.map((r) => (r.id === id ? { ...r, ...patch } : r)));
  }

  function removeRow(id: string) {
    setRows((current) => current.filter((r) => r.id !== id));
  }

  async function save() {
    setSaving(true);
    try {
      const whitelist = rows
        .filter((r) => r.telegram_id.trim() !== '')
        .map((r) => ({ name: r.name.trim(), telegram_id: r.telegram_id.trim() }));
      await update.mutateAsync({
        telegram: {
          enabled,
          report_time: reportTime,
          low_stock_alerts: lowStockAlerts,
          low_stock_frequency: lowStockFrequency,
          whitelist,
        },
      });
      toast.success('Configuración de Telegram guardada.');
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo guardar.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader>
          <CardTitle>Bot de Telegram</CardTitle>
          <CardDescription>
            Configura el bot para esta empresa. El jefe (Owner) puede ver todas las empresas; el
            Admin solo la suya.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex items-center gap-3">
            <Label htmlFor="tg-enabled">Activar bot para esta empresa</Label>
            <input
              id="tg-enabled"
              type="checkbox"
              checked={enabled}
              onChange={(e) => setEnabled(e.target.checked)}
              className="size-4"
            />
          </div>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div className="space-y-1">
              <Label htmlFor="tg-time">Hora del resumen diario</Label>
              <Input
                id="tg-time"
                type="time"
                value={reportTime}
                onChange={(e) => setReportTime(e.target.value)}
              />
            </div>
            <div className="space-y-1">
              <Label htmlFor="tg-lowstock">Alertas de stock bajo</Label>
              <input
                id="tg-lowstock"
                type="checkbox"
                checked={lowStockAlerts}
                onChange={(e) => setLowStockAlerts(e.target.checked)}
                className="mt-3 size-4"
              />
            </div>
            <div className="space-y-1">
              <Label htmlFor="tg-freq">Frecuencia de alerta de stock</Label>
              <Select
                id="tg-freq"
                value={lowStockFrequency}
                onChange={(e) => setLowStockFrequency(e.target.value as 'daily' | '4h' | '8h')}
              >
                <option value="daily">Una vez al día</option>
                <option value="4h">Cada 4 horas</option>
                <option value="8h">Cada 8 horas</option>
              </Select>
            </div>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Lista blanca de usuarios</CardTitle>
          <CardDescription>
            Para vincular, cada persona escribe /start al bot y envía el Telegram ID que este le
            muestre. El ID se agrega aquí con su nombre.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          {rows.length === 0 ? (
            <p className="text-text-muted text-sm">No hay usuarios vinculados al bot.</p>
          ) : (
            <div className="space-y-2">
              {rows.map((row) => (
                <div key={row.id} className="grid grid-cols-1 gap-2 sm:grid-cols-[1fr_1fr_auto]">
                  <Input
                    placeholder="Nombre (ej: Juan Perez)"
                    value={row.name}
                    onChange={(e) => updateRow(row.id, { name: e.target.value })}
                  />
                  <Input
                    placeholder="Telegram ID (ej: 123456789)"
                    value={row.telegram_id}
                    onChange={(e) => updateRow(row.id, { telegram_id: e.target.value })}
                  />
                  <Button
                    size="icon-sm"
                    variant="ghost"
                    onClick={() => removeRow(row.id)}
                    aria-label="Quitar usuario"
                  >
                    <Trash2 className="text-danger size-4" />
                  </Button>
                </div>
              ))}
            </div>
          )}
          <Button variant="outline" leftIcon={<Plus />} onClick={addRow}>
            Agregar usuario
          </Button>
        </CardContent>
      </Card>

      <div className="flex justify-end">
        <Button onClick={save} loading={saving} leftIcon={<Save />}>
          Guardar configuración
        </Button>
      </div>
    </div>
  );
}
