import { useMemo, useState } from 'react';
import { FolderDown, Loader2, Plus, Printer, RotateCcw } from 'lucide-react';
import { toast } from 'sonner';

import { Can } from '@/components/permissions/Can';
import { PageLayout } from '@/components/layout/PageLayout';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Switch } from '@/components/ui/Switch';
import { PERMISSIONS } from '@/permissions/constants';
import { useBranchesForPos, useCashRegisters } from '@/features/pos/api';
import {
  type PrinterStationPayload,
  type PrintProfilePayload,
  useCreatePrinterStation,
  useCreatePrintProfile,
  usePrinterStations,
  usePrintProfiles,
} from './api';

const DEFAULT_PROFILE: PrintProfilePayload = {
  name: 'POS 80mm',
  paper_width_mm: 80,
  characters_per_line: 48,
  header_text: 'Sistema de Inventario',
  footer_text: 'Gracias por su compra.',
  logo_text: '',
  show_warranty_summary: true,
  cut_paper: true,
  open_cash_drawer: false,
  copies: 1,
  is_default: true,
  is_active: true,
};

export function PrintingManager() {
  const { data: profiles = [], isLoading: loadingProfiles } = usePrintProfiles();
  const { data: stations = [], isLoading: loadingStations } = usePrinterStations();
  const { data: branches = [] } = useBranchesForPos();
  const { data: cashRegisters = [] } = useCashRegisters();
  const createProfile = useCreatePrintProfile();
  const createStation = useCreatePrinterStation();
  const [profile, setProfile] = useState<PrintProfilePayload>(DEFAULT_PROFILE);
  const [station, setStation] = useState<PrinterStationPayload>({
    branch_id: null,
    cash_register_id: null,
    print_profile_id: 0,
    name: '',
    code: '',
    output_mode: 'digital',
    printer_type: 'windows_printer',
    printer_name: '',
    network_host: '',
    network_port: 9100,
    digital_directory: 'Desktop\\Tickets',
    save_html_copy: true,
    is_active: true,
  });

  const sortedProfiles = useMemo(
    () => [...profiles].sort((a, b) => Number(b.is_default) - Number(a.is_default) || a.name.localeCompare(b.name)),
    [profiles],
  );
  const sortedStations = useMemo(
    () => [...stations].sort((a, b) => a.name.localeCompare(b.name)),
    [stations],
  );

  async function submitProfile(): Promise<void> {
    if (!profile.name.trim()) {
      toast.error('El perfil necesita un nombre.');
      return;
    }

    await createProfile.mutateAsync({
      ...profile,
      characters_per_line: profile.paper_width_mm === 58 ? 32 : 48,
      logo_text: profile.logo_text?.trim() || null,
      header_text: profile.header_text?.trim() || null,
      footer_text: profile.footer_text?.trim() || null,
    });
    toast.success('Perfil de impresion creado.');
  }

  async function submitStation(): Promise<void> {
    const profileId = station.print_profile_id || sortedProfiles[0]?.id;
    if (!profileId) {
      toast.error('Crea primero un perfil 58mm u 80mm.');
      return;
    }
    if (!station.name.trim()) {
      toast.error('La estacion necesita un nombre.');
      return;
    }

    await createStation.mutateAsync({
      ...station,
      print_profile_id: profileId,
      code: station.code.trim() || station.name.trim(),
      printer_name: station.printer_name?.trim() || null,
      network_host: station.network_host?.trim() || null,
      digital_directory: station.digital_directory?.trim() || 'Desktop\\Tickets',
    });
    toast.success('Estacion de impresion creada.');
  }

  async function testAgent(): Promise<void> {
    try {
      const response = await fetch('http://127.0.0.1:17777/health');
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      toast.success('Agente local disponible.');
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Error desconocido';
      toast.error(`No se detecto el agente local. Reinicia el agente actualizado y revisa el puerto 17777. ${message}`);
    }
  }

  return (
    <PageLayout
      title="Impresion"
      description="Configura tickets POS para impresora termica 58/80mm o salida digital en escritorio."
      actions={
        <Button variant="outline" onClick={() => void testAgent()}>
          <RotateCcw className="size-4" /> Probar agente
        </Button>
      }
    >
      <div className="grid gap-4 xl:grid-cols-[420px_1fr]">
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Plus className="size-4" /> Perfil de ticket
            </CardTitle>
            <CardDescription>Ancho, textos y reglas que se imprimen en el ticket.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            <Can I={PERMISSIONS.PRINTING_MANAGE} fallback={<p className="text-sm text-text-muted">No tienes permiso para gestionar impresion.</p>}>
              <Input value={profile.name} onChange={(event) => setProfile((current) => ({ ...current, name: event.target.value }))} placeholder="Nombre del perfil" />
              <Select value={String(profile.paper_width_mm)} onChange={(event) => setProfile((current) => ({ ...current, paper_width_mm: Number(event.target.value) as 58 | 80 }))}>
                <option value="80">80mm - 48 caracteres</option>
                <option value="58">58mm - 32 caracteres</option>
              </Select>
              <Input value={profile.logo_text ?? ''} onChange={(event) => setProfile((current) => ({ ...current, logo_text: event.target.value }))} placeholder="Logo en texto o nombre comercial" />
              <Input value={profile.header_text ?? ''} onChange={(event) => setProfile((current) => ({ ...current, header_text: event.target.value }))} placeholder="Encabezado corto" />
              <Input value={profile.footer_text ?? ''} onChange={(event) => setProfile((current) => ({ ...current, footer_text: event.target.value }))} placeholder="Pie del ticket" />
              <label className="flex items-center justify-between gap-3 rounded border border-border p-3 text-sm">
                <span>Imprimir garantia resumida por item</span>
                <Switch checked={profile.show_warranty_summary ?? true} onCheckedChange={(checked) => setProfile((current) => ({ ...current, show_warranty_summary: checked }))} />
              </label>
              <label className="flex items-center justify-between gap-3 rounded border border-border p-3 text-sm">
                <span>Cortar papel al terminar</span>
                <Switch checked={profile.cut_paper ?? true} onCheckedChange={(checked) => setProfile((current) => ({ ...current, cut_paper: checked }))} />
              </label>
              <Button className="w-full" disabled={createProfile.isPending} onClick={() => void submitProfile()}>
                {createProfile.isPending && <Loader2 className="size-4 animate-spin" />}
                Crear perfil
              </Button>
            </Can>
          </CardContent>
        </Card>

        <div className="space-y-4">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Printer className="size-4" /> Estacion de impresion
              </CardTitle>
              <CardDescription>Une una caja o sucursal con una salida termica, digital o ambas.</CardDescription>
            </CardHeader>
            <CardContent className="grid gap-3 lg:grid-cols-2">
              <Can I={PERMISSIONS.PRINTING_MANAGE} fallback={<p className="text-sm text-text-muted">No tienes permiso para crear estaciones.</p>}>
                <Input value={station.name} onChange={(event) => setStation((current) => ({ ...current, name: event.target.value }))} placeholder="Nombre, ej. Mostrador principal" />
                <Input value={station.code} onChange={(event) => setStation((current) => ({ ...current, code: event.target.value }))} placeholder="Codigo interno" />
                <Select value={String(station.print_profile_id || sortedProfiles[0]?.id || '')} onChange={(event) => setStation((current) => ({ ...current, print_profile_id: Number(event.target.value) }))}>
                  <option value="">Perfil</option>
                  {sortedProfiles.map((item) => <option key={item.id} value={item.id}>{item.name} - {item.paper_width_mm}mm</option>)}
                </Select>
                <Select value={station.output_mode} onChange={(event) => setStation((current) => ({ ...current, output_mode: event.target.value as PrinterStationPayload['output_mode'] }))}>
                  <option value="digital">Digital</option>
                  <option value="thermal">Termica</option>
                  <option value="both">Termica + digital</option>
                </Select>
                <Select value={String(station.branch_id ?? '')} onChange={(event) => setStation((current) => ({ ...current, branch_id: event.target.value ? Number(event.target.value) : null }))}>
                  <option value="">Sucursal opcional</option>
                  {branches.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
                </Select>
                <Select value={String(station.cash_register_id ?? '')} onChange={(event) => setStation((current) => ({ ...current, cash_register_id: event.target.value ? Number(event.target.value) : null }))}>
                  <option value="">Caja opcional</option>
                  {cashRegisters.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
                </Select>
                <Input value={station.printer_name ?? ''} onChange={(event) => setStation((current) => ({ ...current, printer_name: event.target.value }))} placeholder="Nombre de impresora Windows" />
                <Input value={station.digital_directory ?? ''} onChange={(event) => setStation((current) => ({ ...current, digital_directory: event.target.value }))} placeholder="Carpeta digital, ej. Desktop\\Tickets" />
                <Button className="lg:col-span-2" disabled={createStation.isPending} onClick={() => void submitStation()}>
                  {createStation.isPending && <Loader2 className="size-4 animate-spin" />}
                  Crear estacion
                </Button>
              </Can>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Estaciones activas</CardTitle>
              <CardDescription>El POS usa la estacion de su caja; si no existe, genera ticket digital por defecto.</CardDescription>
            </CardHeader>
            <CardContent className="space-y-2">
              {(loadingProfiles || loadingStations) && <p className="text-sm text-text-muted">Cargando configuracion...</p>}
              {sortedStations.length === 0 && !loadingStations && <p className="text-sm text-text-muted">Aun no hay estaciones configuradas.</p>}
              {sortedStations.map((item) => (
                <div key={item.id} className="flex items-center justify-between rounded border border-border p-3">
                  <div>
                    <p className="font-semibold">{item.name}</p>
                    <p className="text-sm text-text-muted">{item.branch_name ?? 'Todas las sucursales'} - {item.cash_register_name ?? 'Cualquier caja'}</p>
                    <p className="text-xs text-text-muted">{item.output_mode} - {item.profile?.paper_width_mm ?? '-'}mm</p>
                  </div>
                  <div className="flex items-center gap-2">
                    {item.output_mode !== 'thermal' && <FolderDown className="size-4 text-text-muted" />}
                    <Badge variant={item.is_active ? 'success' : 'default'}>{item.is_active ? 'Activa' : 'Inactiva'}</Badge>
                  </div>
                </div>
              ))}
            </CardContent>
          </Card>
        </div>
      </div>
    </PageLayout>
  );
}
