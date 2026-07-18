import { useEffect, useMemo, useState } from 'react';
import { Eye, FolderDown, Loader2, Plus, Printer, RotateCcw, Save } from 'lucide-react';
import { toast } from 'sonner';

import { Can } from '@/components/permissions/Can';
import { PageLayout } from '@/components/layout/PageLayout';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Switch } from '@/components/ui/Switch';
import { Textarea } from '@/components/ui/Textarea';
import { PERMISSIONS } from '@/permissions/constants';
import { useBranchesForPos, useCashRegisters } from '@/features/pos/api';
import {
  exampleTicketPayload,
  sendTestTicketToLocalAgent,
  type PrinterStation,
  type PrinterStationPayload,
  type PrintProfile,
  type PrintProfilePayload,
  useCreatePrinterStation,
  useCreatePrintProfile,
  usePrinterStations,
  usePrintProfiles,
  useUpdatePrinterStation,
  useUpdatePrintProfile,
} from './api';

const DEFAULT_PROFILE: PrintProfilePayload = {
  name: 'POS 80mm',
  paper_width_mm: 80,
  characters_per_line: 48,
  header_text: 'Sistema de Inventario',
  footer_text: 'Gracias por su compra.',
  warranty_policy_text: '',
  legal_text: 'Documento no fiscal',
  logo_text: '',
  show_tenant_slug: true,
  show_sale_number: true,
  show_paid_at: true,
  show_cashier: true,
  show_cash_register: true,
  show_branch: true,
  show_customer: true,
  show_item_sku: true,
  show_item_discount: true,
  show_item_serials: true,
  show_warranty_summary: true,
  show_total_local: true,
  show_payment_rate: true,
  show_payment_reference: true,
  show_receivable_balance: true,
  show_non_fiscal_text: true,
  cut_paper: true,
  open_cash_drawer: false,
  copies: 1,
  is_default: true,
  is_active: true,
};

const PROFILE_TOGGLES: Array<{ section: string; key: keyof PrintProfilePayload; label: string }> = [
  { section: 'Datos del ticket', key: 'show_tenant_slug', label: 'Slug de empresa' },
  { section: 'Datos del ticket', key: 'show_sale_number', label: 'Numero interno de venta' },
  { section: 'Datos del ticket', key: 'show_paid_at', label: 'Fecha/hora' },
  { section: 'Datos del ticket', key: 'show_cashier', label: 'Cajero' },
  { section: 'Datos del ticket', key: 'show_cash_register', label: 'Caja' },
  { section: 'Datos del ticket', key: 'show_branch', label: 'Sucursal' },
  { section: 'Datos del ticket', key: 'show_customer', label: 'Cliente' },
  { section: 'Items', key: 'show_item_sku', label: 'SKU/codigo' },
  { section: 'Items', key: 'show_item_discount', label: 'Descuentos por item' },
  { section: 'Items', key: 'show_item_serials', label: 'IMEI/seriales' },
  { section: 'Garantia', key: 'show_warranty_summary', label: 'Garantia resumida por item' },
  { section: 'Pagos', key: 'show_total_local', label: 'Total VES' },
  { section: 'Pagos', key: 'show_payment_rate', label: 'Tasa usada en pagos' },
  { section: 'Pagos', key: 'show_payment_reference', label: 'Referencias de pago' },
  { section: 'Pagos', key: 'show_receivable_balance', label: 'Saldo CxC si queda pendiente' },
  { section: 'Pie', key: 'show_non_fiscal_text', label: 'Texto documento no fiscal/legal' },
  { section: 'Formato', key: 'cut_paper', label: 'Cortar papel al terminar' },
  { section: 'Formato', key: 'open_cash_drawer', label: 'Abrir gaveta al imprimir' },
];

export function PrintingManager() {
  const { data: profiles = [], isLoading: loadingProfiles } = usePrintProfiles();
  const { data: stations = [], isLoading: loadingStations } = usePrinterStations();
  const { data: branches = [] } = useBranchesForPos();
  const { data: cashRegisters = [] } = useCashRegisters();
  const createProfile = useCreatePrintProfile();
  const updateProfile = useUpdatePrintProfile();
  const createStation = useCreatePrinterStation();
  const updateStation = useUpdatePrinterStation();
  const [selectedProfileId, setSelectedProfileId] = useState<number | 'new'>('new');
  const [profile, setProfile] = useState<PrintProfilePayload>(DEFAULT_PROFILE);
  const [showPreview, setShowPreview] = useState(true);
  const [editingStationId, setEditingStationId] = useState<number | null>(null);
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
  const selectedProfile = sortedProfiles.find((item) => item.id === selectedProfileId) ?? null;

  useEffect(() => {
    if (!selectedProfile) return;
    setProfile(profileToPayload(selectedProfile));
  }, [selectedProfile]);

  async function submitProfile(): Promise<void> {
    if (!profile.name.trim()) {
      toast.error('El perfil necesita un nombre.');
      return;
    }

    const payload = cleanProfilePayload(profile);
    if (selectedProfile) {
      await updateProfile.mutateAsync({ id: selectedProfile.id, payload });
      toast.success('Perfil de impresion actualizado.');
      return;
    }

    const created = await createProfile.mutateAsync(payload);
    setSelectedProfileId(created.id);
    toast.success('Perfil de impresion creado.');
  }

  async function submitStation(): Promise<void> {
    const profileId = station.print_profile_id || selectedProfile?.id || sortedProfiles[0]?.id;
    if (!profileId) {
      toast.error('Crea primero un perfil 58mm u 80mm.');
      return;
    }
    if (!station.name.trim()) {
      toast.error('La estacion necesita un nombre.');
      return;
    }

    const payload = {
      ...station,
      print_profile_id: profileId,
      code: station.code.trim() || station.name.trim(),
      printer_name: station.printer_name?.trim() || null,
      network_host: station.network_host?.trim() || null,
      digital_directory: station.digital_directory?.trim() || 'Desktop\\Tickets',
    };

    if (editingStationId) {
      await updateStation.mutateAsync({ id: editingStationId, payload });
      toast.success('Estacion de impresion actualizada.');
      resetStationForm();
      return;
    }

    await createStation.mutateAsync(payload);
    toast.success('Estacion de impresion creada.');
    resetStationForm();
  }

  function editStation(item: PrinterStation): void {
    setEditingStationId(item.id);
    setStation({
      branch_id: item.branch_id ?? null,
      cash_register_id: item.cash_register_id ?? null,
      print_profile_id: item.print_profile_id,
      name: item.name,
      code: item.code,
      output_mode: item.output_mode,
      printer_type: item.printer_type,
      printer_name: item.printer_name ?? '',
      network_host: item.network_host ?? '',
      network_port: item.network_port ?? 9100,
      digital_directory: item.digital_directory ?? 'Desktop\\Tickets',
      save_html_copy: item.save_html_copy,
      is_active: item.is_active,
    });
  }

  function resetStationForm(): void {
    setEditingStationId(null);
    setStation({
      branch_id: null,
      cash_register_id: null,
      print_profile_id: selectedProfile?.id || sortedProfiles[0]?.id || 0,
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

  async function testStation(stationToTest: PrinterStation, output: 'thermal' | 'digital'): Promise<void> {
    try {
      const selected = stationToTest.profile ?? selectedProfile ?? sortedProfiles[0] ?? profile;
      const result = await sendTestTicketToLocalAgent(output, stationToTest, selected);
      toast.success(result.pdf_path || result.html_path ? `Prueba generada: ${result.pdf_path ?? result.html_path}` : 'Prueba enviada al agente.');
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo probar la estacion.');
    }
  }

  return (
    <PageLayout
      title="Impresion"
      description="Configura tickets POS para impresora termica 58/80mm o salida digital en escritorio."
      actions={
        <div className="flex gap-2">
          <Button variant="outline" onClick={() => setShowPreview((value) => !value)}>
            <Eye className="size-4" /> Vista previa
          </Button>
          <Button variant="outline" onClick={() => void testAgent()}>
            <RotateCcw className="size-4" /> Probar agente
          </Button>
        </div>
      }
    >
      <div className="grid gap-4 2xl:grid-cols-[minmax(460px,640px)_1fr]">
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Plus className="size-4" /> Perfil de ticket
            </CardTitle>
            <CardDescription>Activa solo los datos que quieres imprimir. Los cambios aplican a tickets nuevos.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <Can I={PERMISSIONS.PRINTING_MANAGE} fallback={<p className="text-sm text-text-muted">No tienes permiso para gestionar impresion.</p>}>
              <Select value={String(selectedProfileId)} onChange={(event) => setSelectedProfileId(event.target.value === 'new' ? 'new' : Number(event.target.value))}>
                <option value="new">Nuevo perfil</option>
                {sortedProfiles.map((item) => <option key={item.id} value={item.id}>{item.name} - {item.paper_width_mm}mm</option>)}
              </Select>
              <Section title="Formato">
                <Input value={profile.name} onChange={(event) => setProfile((current) => ({ ...current, name: event.target.value }))} placeholder="Nombre del perfil" />
                <Select value={String(profile.paper_width_mm)} onChange={(event) => setProfile((current) => ({ ...current, paper_width_mm: Number(event.target.value) as 58 | 80, characters_per_line: Number(event.target.value) === 58 ? 32 : 48 }))}>
                  <option value="80">80mm - 48 caracteres</option>
                  <option value="58">58mm - 32 caracteres</option>
                </Select>
                {toggleRows('Formato', profile, setProfile)}
              </Section>
              <Section title="Encabezado">
                <Input value={profile.logo_text ?? ''} onChange={(event) => setProfile((current) => ({ ...current, logo_text: event.target.value }))} placeholder="Logo en texto o nombre comercial" />
                <Textarea value={profile.header_text ?? ''} onChange={(event) => setProfile((current) => ({ ...current, header_text: event.target.value }))} placeholder="Encabezado corto" />
              </Section>
              <Section title="Datos del ticket">
                {toggleRows('Datos del ticket', profile, setProfile)}
              </Section>
              <Section title="Items">
                {toggleRows('Items', profile, setProfile)}
              </Section>
              <Section title="Pagos">
                {toggleRows('Pagos', profile, setProfile)}
              </Section>
              <Section title="Garantia">
                {toggleRows('Garantia', profile, setProfile)}
                <Textarea value={profile.warranty_policy_text ?? ''} onChange={(event) => setProfile((current) => ({ ...current, warranty_policy_text: event.target.value }))} placeholder="Politica corta de garantia general" />
              </Section>
              <Section title="Pie">
                {toggleRows('Pie', profile, setProfile)}
                <Textarea value={profile.footer_text ?? ''} onChange={(event) => setProfile((current) => ({ ...current, footer_text: event.target.value }))} placeholder="Pie del ticket" />
                <Input value={profile.legal_text ?? ''} onChange={(event) => setProfile((current) => ({ ...current, legal_text: event.target.value }))} placeholder="Texto legal/no fiscal" />
              </Section>
              <Button className="w-full" disabled={createProfile.isPending || updateProfile.isPending} onClick={() => void submitProfile()}>
                {(createProfile.isPending || updateProfile.isPending) ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />}
                {selectedProfile ? 'Guardar perfil' : 'Crear perfil'}
              </Button>
            </Can>
          </CardContent>
        </Card>

        <div className="space-y-4">
          {showPreview && (
            <Card>
              <CardHeader>
                <CardTitle>Vista previa</CardTitle>
                <CardDescription>Modelo aproximado del ticket antes de guardar o probar con el agente.</CardDescription>
              </CardHeader>
              <CardContent>
                <TicketPreview profile={profile} />
              </CardContent>
            </Card>
          )}

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
                <Select value={String(station.print_profile_id || selectedProfile?.id || sortedProfiles[0]?.id || '')} onChange={(event) => setStation((current) => ({ ...current, print_profile_id: Number(event.target.value) }))}>
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
                <Input value={station.printer_name ?? ''} onChange={(event) => setStation((current) => ({ ...current, printer_name: event.target.value }))} placeholder="Nombre exacto de impresora Windows" />
                <Input value={station.digital_directory ?? ''} onChange={(event) => setStation((current) => ({ ...current, digital_directory: event.target.value }))} placeholder="Carpeta digital, ej. Desktop\\Tickets" />
                <div className="flex gap-2 lg:col-span-2">
                  <Button className="flex-1" disabled={createStation.isPending || updateStation.isPending} onClick={() => void submitStation()}>
                    {(createStation.isPending || updateStation.isPending) && <Loader2 className="size-4 animate-spin" />}
                    {editingStationId ? 'Guardar estacion' : 'Crear estacion'}
                  </Button>
                  {editingStationId && <Button variant="outline" onClick={resetStationForm}>Cancelar edicion</Button>}
                </div>
              </Can>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Estaciones activas</CardTitle>
              <CardDescription>El POS usa la estacion de su caja. Prueba digital o termica antes de vender.</CardDescription>
            </CardHeader>
            <CardContent className="space-y-2">
              {(loadingProfiles || loadingStations) && <p className="text-sm text-text-muted">Cargando configuracion...</p>}
              {sortedStations.length === 0 && !loadingStations && <p className="text-sm text-text-muted">Aun no hay estaciones configuradas.</p>}
              {sortedStations.map((item) => (
                <div key={item.id} className="rounded border border-border p-3">
                  <div className="flex items-center justify-between gap-3">
                    <div>
                      <p className="font-semibold">{item.name}</p>
                      <p className="text-sm text-text-muted">Aplica a: {item.branch_name ?? 'Todas las sucursales'} / {item.cash_register_name ?? 'Cualquier caja'}</p>
                      <p className="text-xs text-text-muted">{item.output_mode} - {item.profile?.name ?? 'Sin perfil'} - {item.profile?.paper_width_mm ?? '-'}mm</p>
                    </div>
                    <div className="flex items-center gap-2">
                      {item.output_mode !== 'thermal' && <FolderDown className="size-4 text-text-muted" />}
                      <Badge variant={item.is_active ? 'success' : 'default'}>{item.is_active ? 'Activa' : 'Inactiva'}</Badge>
                    </div>
                  </div>
                  <div className="mt-3 flex flex-wrap gap-2">
                    <Can I={PERMISSIONS.PRINTING_MANAGE}>
                      <Button size="sm" variant="outline" onClick={() => editStation(item)}>Editar</Button>
                    </Can>
                    {item.output_mode !== 'thermal' && <Button size="sm" variant="outline" onClick={() => void testStation(item, 'digital')}>Probar digital</Button>}
                    {item.output_mode !== 'digital' && <Button size="sm" variant="outline" onClick={() => void testStation(item, 'thermal')}>Probar termica</Button>}
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

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="space-y-2 rounded border border-border p-3">
      <h3 className="text-sm font-semibold">{title}</h3>
      {children}
    </div>
  );
}

function toggleRows(
  section: string,
  profile: PrintProfilePayload,
  setProfile: React.Dispatch<React.SetStateAction<PrintProfilePayload>>,
) {
  return PROFILE_TOGGLES.filter((item) => item.section === section).map((item) => (
    <label key={String(item.key)} className="flex items-center justify-between gap-3 rounded border border-border/70 p-2 text-sm">
      <span>{item.label}</span>
      <Switch
        checked={Boolean(profile[item.key] ?? true)}
        onCheckedChange={(checked) => setProfile((current) => ({ ...current, [item.key]: checked }))}
      />
    </label>
  ));
}

function TicketPreview({ profile }: { profile: PrintProfilePayload }) {
  const ticket = exampleTicketPayload(profile);
  const p = ticket.profile as PrintProfilePayload;
  const width = p.paper_width_mm === 58 ? 'w-64' : 'w-80';
  return (
    <div className={`mx-auto rounded bg-white p-4 font-mono text-xs text-black shadow ${width}`}>
      <div className="text-center">
        <p className="font-bold">{p.logo_text || ticket.tenant.name}</p>
        {p.header_text && <p className="whitespace-pre-line">{p.header_text}</p>}
        {p.show_tenant_slug && <p className="text-[10px] text-gray-500">{ticket.tenant.slug}</p>}
      </div>
      <Dash />
      <p>Ticket POS #{ticket.pos_order.id}{p.show_sale_number ? ` - Venta #${ticket.pos_order.sale_id}` : ''}</p>
      {p.show_paid_at && <p>Fecha: {ticket.pos_order.paid_at}</p>}
      {p.show_cashier && <p>Cajero: {ticket.pos_order.cashier_name}</p>}
      {p.show_cash_register && <p>Caja: {ticket.pos_order.cash_register_name}</p>}
      {p.show_branch && <p>Sucursal: {ticket.pos_order.branch_name}</p>}
      {p.show_customer && <p>Cliente: {ticket.pos_order.customer_name}</p>}
      <Dash />
      {ticket.items.map((item) => (
        <div key={item.product_name} className="mb-2">
          <p className="font-bold">{item.product_name}</p>
          {p.show_item_sku && <p className="text-gray-500">{item.sku}</p>}
          <p className="flex justify-between"><span>{item.quantity} x ${item.unit_price}</span><span>${item.total}</span></p>
          {p.show_item_discount && item.discount > 0 && <p>Desc: ${item.discount}</p>}
          {p.show_item_serials && item.serials.map((serial) => <p key={serial.serial_number}>IMEI/Serial: {serial.serial_number}</p>)}
          {p.show_warranty_summary && <p>Garantia: {item.warranty.name} - vence {item.warranty.expires_at}</p>}
        </div>
      ))}
      <Dash />
      <p className="flex justify-between font-bold"><span>Total USD</span><span>${ticket.totals.total_base_amount}</span></p>
      {p.show_total_local && <p className="flex justify-between"><span>Total VES</span><span>Bs {ticket.totals.total_local_amount}</span></p>}
      <p className="flex justify-between"><span>Pagado USD</span><span>${ticket.totals.paid_base_amount}</span></p>
      <Dash />
      <p className="font-bold">Pagos</p>
      {ticket.payments.map((payment) => (
        <div key={payment.method}>
          <p>{payment.method} {payment.currency} ${payment.amount}</p>
          {p.show_payment_rate && <p className="text-gray-500">{payment.exchange_rate_type_code} @ {payment.exchange_rate}</p>}
          {p.show_payment_reference && <p>Ref: {payment.reference}</p>}
        </div>
      ))}
      <Dash />
      {p.warranty_policy_text && <><p className="whitespace-pre-line">{p.warranty_policy_text}</p><Dash /></>}
      {p.footer_text && <p className="whitespace-pre-line text-center">{p.footer_text}</p>}
      {p.show_non_fiscal_text && <p className="text-center text-[10px] text-gray-500">{p.legal_text || 'Documento no fiscal'}</p>}
    </div>
  );
}

function Dash() {
  return <div className="my-2 border-t border-dashed border-black" />;
}

function profileToPayload(profile: PrintProfile): PrintProfilePayload {
  return { ...DEFAULT_PROFILE, ...profile };
}

function cleanProfilePayload(profile: PrintProfilePayload): PrintProfilePayload {
  return {
    ...profile,
    characters_per_line: profile.paper_width_mm === 58 ? 32 : 48,
    logo_text: profile.logo_text?.trim() || null,
    header_text: profile.header_text?.trim() || null,
    footer_text: profile.footer_text?.trim() || null,
    warranty_policy_text: profile.warranty_policy_text?.trim() || null,
    legal_text: profile.legal_text?.trim() || 'Documento no fiscal',
  };
}
