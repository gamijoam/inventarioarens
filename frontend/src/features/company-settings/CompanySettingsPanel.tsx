/**
 * CompanySettingsPanel - Informacion legal/fiscal de la empresa.
 *
 * Guarda en tenant_settings.settings.company: razon social, RIF, domicilio
 * fiscal, ciudad/estado, telefono, correo, web y regimen. Ademas permite
 * elegir en que documentos se refleja (ticket de venta, guias, reporte Z).
 *
 * Requiere permiso settings.manage (Owner/Administrador).
 */
import { useEffect, useState } from 'react';
import { toast } from '@/lib/toast';

import { Building2, ShieldCheck, Trash2, Upload } from 'lucide-react';

import { Button } from '@/components/ui/Button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';

import { OfflineSetupDownloads } from './OfflineSetupDownloads';

import {
  useCompanySettings,
  useUpdateCompanySettings,
  useUploadCompanyLogo,
  useDeleteCompanyLogo,
  type CompanySettings,
} from './api';

interface ShowOnState {
  sale_ticket: boolean;
  guide: boolean;
  report_z: boolean;
  quotation: boolean;
}

const SHOW_ON_LABELS: Array<{ key: keyof ShowOnState; label: string; hint: string }> = [
  { key: 'sale_ticket', label: 'Ticket de venta', hint: 'Aparece en el ticket POS al imprimir' },
  { key: 'guide', label: 'Guías de traslado', hint: 'Aparece en la guía de traslado (PDF)' },
  { key: 'report_z', label: 'Reporte Z', hint: 'Aparece en el reporte Z de cierre de turno' },
  { key: 'quotation', label: 'Cotizaciones', hint: 'Aparece en el PDF de cotización' },
];

export function CompanySettingsPanel() {
  const { data, isLoading } = useCompanySettings();
  const update = useUpdateCompanySettings();
  const uploadLogo = useUploadCompanyLogo();
  const deleteLogo = useDeleteCompanyLogo();

  const [razonSocial, setRazonSocial] = useState('');
  const [rif, setRif] = useState('');
  const [domicilio, setDomicilio] = useState('');
  const [ciudad, setCiudad] = useState('');
  const [estado, setEstado] = useState('');
  const [telefono, setTelefono] = useState('');
  const [correo, setCorreo] = useState('');
  const [website, setWebsite] = useState('');
  const [regimen, setRegimen] = useState('');
  const [logoUrl, setLogoUrl] = useState<string | null>(null);
  const [uploadingLogo, setUploadingLogo] = useState(false);
  const [deletingLogo, setDeletingLogo] = useState(false);
  const [showOn, setShowOn] = useState<ShowOnState>({
    sale_ticket: true,
    guide: true,
    report_z: true,
    quotation: true,
  });
  const [saving, setSaving] = useState(false);
  const [allowNegativeStock, setAllowNegativeStock] = useState(false);
  const [allowRateEdit, setAllowRateEdit] = useState(false);
  const [allowCatalogView, setAllowCatalogView] = useState(true);
  const [allowCatalogStockEdit, setAllowCatalogStockEdit] = useState(false);
  const [defaultPosViewMode, setDefaultPosViewMode] = useState<'classic' | 'catalog' | 'ticket'>('classic');

  const [warrantyCertEnabled, setWarrantyCertEnabled] = useState(true);
  const [warrantyCertTitle, setWarrantyCertTitle] = useState('CERTIFICADO DE GARANTÍA OFICIAL');
  const [warrantyCertCovers, setWarrantyCertCovers] = useState('');
  const [warrantyCertNotCovers, setWarrantyCertNotCovers] = useState('');
  const [warrantyCertClaimSteps, setWarrantyCertClaimSteps] = useState('');
  const [warrantyCertImportantNotes, setWarrantyCertImportantNotes] = useState('');

  const isMiInventarioFacil =
    typeof window !== 'undefined' &&
    (window.location.hostname.includes('miinventariofacil.com') ||
      window.location.hostname === 'localhost' ||
      window.location.hostname === '127.0.0.1');

  useEffect(() => {
    if (!data) return;
    setRazonSocial(data.razon_social ?? '');
    setRif(data.rif ?? '');
    setDomicilio(data.domicilio_fiscal ?? '');
    setCiudad(data.ciudad ?? '');
    setEstado(data.estado ?? '');
    setTelefono(data.telefono ?? '');
    setCorreo(data.correo ?? '');
    setWebsite(data.website ?? '');
    setRegimen(data.regimen ?? '');
    setLogoUrl(data.logo_url ?? null);
    setAllowNegativeStock(data.allow_negative_stock ?? false);
    setAllowRateEdit(data.pos_allow_rate_edit ?? false);
    setAllowCatalogView(data.pos_allow_catalog_view ?? true);
    setAllowCatalogStockEdit(data.catalog_allow_stock_edit ?? data.pos_catalog_stock_edit ?? false);
    setDefaultPosViewMode((data.pos_default_view_mode as 'classic' | 'catalog' | 'ticket') ?? 'classic');
    setShowOn({
      sale_ticket: data.show_on?.sale_ticket ?? true,
      guide: data.show_on?.guide ?? true,
      report_z: data.show_on?.report_z ?? true,
      quotation: data.show_on?.quotation ?? true,
    });
    setWarrantyCertEnabled(data.warranty_certificate?.enabled ?? true);
    setWarrantyCertTitle(data.warranty_certificate?.title ?? 'CERTIFICADO DE GARANTÍA OFICIAL');
    setWarrantyCertCovers(
      data.warranty_certificate?.covers ??
        '• Defectos de fabricación\n• Fallas técnicas internas\n• Componentes defectuosos\n• Mal funcionamiento del equipo en condiciones normales',
    );
    setWarrantyCertNotCovers(
      data.warranty_certificate?.not_covers ??
        '• Mal uso o negligencia\n• Caídas, golpes o impactos\n• Contacto con líquidos o humedad\n• Modificaciones no autorizadas o sellos violados',
    );
    setWarrantyCertClaimSteps(
      data.warranty_certificate?.claim_steps ??
        '1. Presente este certificado original\n2. Junto al comprobante de pago\n3. El equipo debe estar completo con empaque y accesorios\n4. Acuda a nuestra sede en horario de atención',
    );
    setWarrantyCertImportantNotes(
      data.warranty_certificate?.important_notes ??
        '• Conserve este documento en buen estado\n• Sin certificado la garantía no aplica\n• Garantía personal e intransferible\n• Válida solo en el local de compra',
    );
  }, [data]);

  if (isLoading) return <Spinner label="Cargando información de la empresa..." />;

  async function handleFileChange(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    if (!file) return;

    if (!file.type.startsWith('image/')) {
      toast.error('Solo se permiten archivos de imagen (PNG, JPG, WebP, SVG).');
      return;
    }

    if (file.size > 2 * 1024 * 1024) {
      toast.error('La imagen no debe superar los 2MB.');
      return;
    }

    setUploadingLogo(true);
    try {
      const res = await uploadLogo.mutateAsync(file);
      setLogoUrl(res.logo_url);
      toast.success('Logo actualizado correctamente.');
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al subir el logo.');
    } finally {
      setUploadingLogo(false);
      e.target.value = '';
    }
  }

  async function handleDeleteLogo() {
    setDeletingLogo(true);
    try {
      await deleteLogo.mutateAsync();
      setLogoUrl(null);
      toast.success('Logo eliminado correctamente.');
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al eliminar el logo.');
    } finally {
      setDeletingLogo(false);
    }
  }

  async function save() {
    setSaving(true);
    try {
      const payload: CompanySettings = {
        razon_social: razonSocial.trim() || null,
        rif: rif.trim() || null,
        domicilio_fiscal: domicilio.trim() || null,
        ciudad: ciudad.trim() || null,
        estado: estado.trim() || null,
        telefono: telefono.trim() || null,
        correo: correo.trim() || null,
        website: website.trim() || null,
        regimen: regimen.trim() || null,
        logo_url: logoUrl,
        allow_negative_stock: allowNegativeStock,
        pos_allow_rate_edit: allowRateEdit,
        pos_allow_catalog_view: allowCatalogView,
        pos_catalog_stock_edit: allowCatalogStockEdit,
        catalog_allow_stock_edit: allowCatalogStockEdit,
        pos_default_view_mode: defaultPosViewMode,
        show_on: showOn,
        warranty_certificate: {
          enabled: warrantyCertEnabled,
          title: warrantyCertTitle.trim() || 'CERTIFICADO DE GARANTÍA OFICIAL',
          covers: warrantyCertCovers.trim() || null,
          not_covers: warrantyCertNotCovers.trim() || null,
          claim_steps: warrantyCertClaimSteps.trim() || null,
          important_notes: warrantyCertImportantNotes.trim() || null,
        },
      };
      await update.mutateAsync(payload);
      toast.success('Información de la empresa guardada.');
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo guardar.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="space-y-4">
      {/* Logo de la empresa */}
      <Card>
        <CardHeader>
          <CardTitle>Logo de la empresa</CardTitle>
          <CardDescription>
            Identidad visual que se mostrará en el inicio de sesión, en la pestaña del navegador y en la cabecera del sistema.
          </CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col sm:flex-row items-start sm:items-center gap-6">
          <div className="relative flex size-24 shrink-0 items-center justify-center rounded-2xl border-2 border-dashed border-slate-200 bg-slate-50 p-2 shadow-xs dark:border-slate-800 dark:bg-slate-900 overflow-hidden">
            {logoUrl ? (
              <img
                src={logoUrl}
                alt="Logo de la empresa"
                className="size-full object-contain"
                data-testid="company-logo-preview"
              />
            ) : (
              <div className="flex flex-col items-center justify-center text-slate-400">
                <Building2 className="size-8" />
                <span className="text-xs mt-1 font-medium">Sin logo</span>
              </div>
            )}
          </div>

          <div className="space-y-2 flex-1">
            <div className="flex flex-wrap items-center gap-3">
              <label className="cursor-pointer">
                <input
                  type="file"
                  accept="image/png,image/jpeg,image/jpg,image/webp,image/svg+xml"
                  onChange={handleFileChange}
                  disabled={uploadingLogo || deletingLogo}
                  className="sr-only"
                  data-testid="company-logo-input"
                />
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  asChild
                  loading={uploadingLogo}
                  className="pointer-events-none"
                >
                  <span>
                    <Upload className="size-4 mr-2" />
                    {logoUrl ? 'Cambiar logo' : 'Subir logo'}
                  </span>
                </Button>
              </label>

              {logoUrl && (
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={handleDeleteLogo}
                  loading={deletingLogo}
                  disabled={uploadingLogo}
                  className="text-red-600 hover:text-red-700 hover:bg-red-50 border-red-200"
                  data-testid="company-logo-delete"
                >
                  <Trash2 className="size-4 mr-2" />
                  Eliminar logo
                </Button>
              )}
            </div>
            <p className="text-xs text-text-muted">
              Formatos recomendados: PNG o SVG transparente. Tamaño máximo permitido: 2MB.
            </p>
          </div>
        </CardContent>
      </Card>
      <Card>
        <CardHeader>
          <CardTitle>Información de la empresa</CardTitle>
          <CardDescription>
            Razón social, RIF, domicilio fiscal y datos de contacto. Esta información se puede
            reflejar en los documentos impresos (tickets, guías y reportes Z).
          </CardDescription>
        </CardHeader>
        <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div className="space-y-1">
            <Label htmlFor="company-razon-social">Razón social / nombre fiscal</Label>
            <Input
              id="company-razon-social"
              value={razonSocial}
              onChange={(e) => setRazonSocial(e.target.value)}
              placeholder="Nombre de la empresa"
              maxLength={255}
            />
          </div>
          <div className="space-y-1">
            <Label htmlFor="company-rif">RIF</Label>
            <Input
              id="company-rif"
              value={rif}
              onChange={(e) => setRif(e.target.value)}
              placeholder="J-12345678-9"
              maxLength={30}
            />
          </div>
          <div className="space-y-1 sm:col-span-2">
            <Label htmlFor="company-domicilio">Domicilio fiscal</Label>
            <Input
              id="company-domicilio"
              value={domicilio}
              onChange={(e) => setDomicilio(e.target.value)}
              placeholder="Av. Principal, Local 5"
              maxLength={500}
            />
          </div>
          <div className="space-y-1">
            <Label htmlFor="company-ciudad">Ciudad</Label>
            <Input
              id="company-ciudad"
              value={ciudad}
              onChange={(e) => setCiudad(e.target.value)}
              maxLength={120}
            />
          </div>
          <div className="space-y-1">
            <Label htmlFor="company-estado">Estado</Label>
            <Input
              id="company-estado"
              value={estado}
              onChange={(e) => setEstado(e.target.value)}
              maxLength={120}
            />
          </div>
          <div className="space-y-1">
            <Label htmlFor="company-telefono">Teléfono</Label>
            <Input
              id="company-telefono"
              value={telefono}
              onChange={(e) => setTelefono(e.target.value)}
              placeholder="+58 212 555 0101"
              maxLength={40}
            />
          </div>
          <div className="space-y-1">
            <Label htmlFor="company-correo">Correo</Label>
            <Input
              id="company-correo"
              type="email"
              value={correo}
              onChange={(e) => setCorreo(e.target.value)}
              maxLength={150}
            />
          </div>
          <div className="space-y-1">
            <Label htmlFor="company-website">Sitio web</Label>
            <Input
              id="company-website"
              value={website}
              onChange={(e) => setWebsite(e.target.value)}
              maxLength={150}
            />
          </div>
          <div className="space-y-1">
            <Label htmlFor="company-regimen">Régimen (opcional)</Label>
            <Input
              id="company-regimen"
              value={regimen}
              onChange={(e) => setRegimen(e.target.value)}
              placeholder="Contribuyente formal"
              maxLength={80}
            />
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>¿Dónde se refleja?</CardTitle>
          <CardDescription>
            Activa o desactiva la aparición de los datos de la empresa en cada documento.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          {SHOW_ON_LABELS.map((option) => (
            <div
              key={option.key}
              className="flex items-center justify-between gap-3 rounded border border-border bg-bg/30 p-3"
            >
              <div>
                <div className="text-sm font-medium">{option.label}</div>
                <div className="text-text-muted text-xs">{option.hint}</div>
              </div>
              <input
                type="checkbox"
                checked={showOn[option.key]}
                onChange={(e) =>
                  setShowOn((current) => ({ ...current, [option.key]: e.target.checked }))
                }
                className="size-4"
                data-testid={`company-show-${option.key}`}
              />
            </div>
          ))}
        </CardContent>
      </Card>

      <Card data-testid="company-pos-operation">
        <CardHeader>
          <CardTitle>Operación del POS</CardTitle>
          <CardDescription>
            Opciones de facturación del punto de venta.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          <div className="flex items-center justify-between gap-3 rounded border border-border bg-bg/30 p-3">
            <div>
              <div className="text-sm font-medium">Permitir facturar sin stock</div>
              <div className="text-text-muted text-xs">
                Si se activa, el POS permite vender aunque no haya existencias y el stock queda en
                negativo.
              </div>
            </div>
            <input
              type="checkbox"
              checked={allowNegativeStock}
              onChange={(e) => setAllowNegativeStock(e.target.checked)}
              className="size-4"
              data-testid="company-allow-negative-stock"
            />
          </div>

          <div className="flex items-center justify-between gap-3 rounded border border-border bg-bg/30 p-3">
            <div>
              <div className="text-sm font-medium">Permitir cambiar la tasa desde el POS</div>
              <div className="text-text-muted text-xs">
                Si se activa, en el POS podés tocar la tasa de la barra superior para abrir un modal
                y cargar una nueva tasa.
              </div>
            </div>
            <input
              type="checkbox"
              checked={allowRateEdit}
              onChange={(e) => setAllowRateEdit(e.target.checked)}
              className="size-4"
              data-testid="company-pos-allow-rate-edit"
            />
          </div>

          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 rounded border border-border bg-bg/30 p-3">
            <div>
              <div className="text-sm font-medium">Vista activa predeterminada del POS</div>
              <div className="text-text-muted text-xs">
                Seleccione el modo de pantalla principal con el que iniciará el punto de venta en todos los dispositivos de esta empresa.
              </div>
            </div>
            <select
              value={defaultPosViewMode}
              onChange={(e) =>
                setDefaultPosViewMode(e.target.value as 'classic' | 'catalog' | 'ticket')
              }
              className="bg-surface text-text-primary border border-border rounded-lg px-3 py-1.5 text-xs font-semibold focus:outline-none focus:ring-2 focus:ring-primary/40 shrink-0 cursor-pointer"
              data-testid="company-pos-default-view-mode"
            >
              <option value="classic">🖥️ Vista Clásica (Facturación Rápida / 1Sistema)</option>
              <option value="catalog">🛍️ Vista Catálogo (Cuadrícula con Fotos)</option>
              <option value="ticket">🧾 Vista Ticket (Código de barras tradicional)</option>
            </select>
          </div>

          <div className="flex items-center justify-between gap-3 rounded border border-border bg-bg/30 p-3">
            <div>
              <div className="text-sm font-medium">Habilitar vista catálogo en el POS</div>
              <div className="text-text-muted text-xs">
                Si se desactiva, el POS solo muestra la vista ticket (sin el selector
                Catálogo/Ticket).
              </div>
            </div>
            <input
              type="checkbox"
              checked={allowCatalogView}
              onChange={(e) => setAllowCatalogView(e.target.checked)}
              className="size-4"
              data-testid="company-pos-allow-catalog-view"
            />
          </div>

          <div className="flex items-center justify-between gap-3 rounded border border-border bg-bg/30 p-3">
            <div>
              <div className="text-sm font-medium">Edición rápida de stock en catálogo y producto rápido</div>
              <div className="text-text-muted text-xs">
                Si se activa, permite ingresar o modificar directamente el stock de los productos
                desde la vista catálogo y en la creación rápida de producto. El valor ingresado
                será el stock exacto que tendrá el producto (admite números positivos, cero o negativos).
              </div>
            </div>
            <input
              type="checkbox"
              checked={allowCatalogStockEdit}
              onChange={(e) => setAllowCatalogStockEdit(e.target.checked)}
              className="size-4"
              data-testid="company-catalog-stock-edit"
            />
          </div>
        </CardContent>
      </Card>

      {/* Certificado de Garantía (Impresora Estándar / Carta) - Exclusivo app.miinventariofacil */}
      {isMiInventarioFacil && (
        <Card data-testid="company-warranty-certificate-card">
          <CardHeader>
            <div className="flex items-center gap-2">
              <ShieldCheck className="size-5 text-primary" />
              <CardTitle>Certificado de Garantía Oficial (Impresora Estándar)</CardTitle>
            </div>
            <CardDescription>
              Configuración del documento en formato Carta / A4 para impresoras de oficina (HP, Epson, Canon).
              Al cobrar una venta con teléfonos o equipos amparados por garantía, se llenará automáticamente
              con los datos de la factura, el cliente y los números de IMEI / serial.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex items-center justify-between gap-3 rounded-lg border border-border bg-bg/40 p-3">
              <div>
                <div className="text-sm font-medium">Habilitar emisión de Certificado de Garantía</div>
                <div className="text-text-muted text-xs">
                  Si se activa, el POS mostrará el botón para imprimir el certificado oficial en impresoras estándar tras confirmar la venta.
                </div>
              </div>
              <input
                type="checkbox"
                checked={warrantyCertEnabled}
                onChange={(e) => setWarrantyCertEnabled(e.target.checked)}
                className="size-4"
                data-testid="company-warranty-cert-enabled"
              />
            </div>

            {warrantyCertEnabled && (
              <div className="space-y-4 pt-2">
                <div>
                  <Label htmlFor="warranty-title">Título del Certificado</Label>
                  <Input
                    id="warranty-title"
                    value={warrantyCertTitle}
                    onChange={(e) => setWarrantyCertTitle(e.target.value)}
                    placeholder="CERTIFICADO DE GARANTÍA OFICIAL"
                    className="mt-1"
                  />
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <Label htmlFor="warranty-covers" className="text-success font-semibold flex items-center gap-1.5">
                      <span>✓</span> Qué sí cubre
                    </Label>
                    <Textarea
                      id="warranty-covers"
                      value={warrantyCertCovers}
                      onChange={(e) => setWarrantyCertCovers(e.target.value)}
                      rows={4}
                      placeholder="• Defectos de fabricación&#10;• Fallas técnicas internas..."
                      className="mt-1 font-mono text-xs"
                    />
                    <p className="text-[11px] text-text-muted mt-1">Una condición o viñeta por línea.</p>
                  </div>

                  <div>
                    <Label htmlFor="warranty-not-covers" className="text-destructive font-semibold flex items-center gap-1.5">
                      <span>✕</span> Qué no cubre
                    </Label>
                    <Textarea
                      id="warranty-not-covers"
                      value={warrantyCertNotCovers}
                      onChange={(e) => setWarrantyCertNotCovers(e.target.value)}
                      rows={4}
                      placeholder="• Caídas o golpes&#10;• Contacto con líquidos..."
                      className="mt-1 font-mono text-xs"
                    />
                    <p className="text-[11px] text-text-muted mt-1">Una exclusión o viñeta por línea.</p>
                  </div>

                  <div>
                    <Label htmlFor="warranty-claim" className="text-primary font-semibold flex items-center gap-1.5">
                      <span>ℹ</span> Pasos para reclamar
                    </Label>
                    <Textarea
                      id="warranty-claim"
                      value={warrantyCertClaimSteps}
                      onChange={(e) => setWarrantyCertClaimSteps(e.target.value)}
                      rows={4}
                      placeholder="1. Presente este certificado original&#10;2. Junto al comprobante de pago..."
                      className="mt-1 font-mono text-xs"
                    />
                    <p className="text-[11px] text-text-muted mt-1">Un paso por línea.</p>
                  </div>

                  <div>
                    <Label htmlFor="warranty-notes" className="text-amber-500 font-semibold flex items-center gap-1.5">
                      <span>⚠</span> Importante / Advertencias
                    </Label>
                    <Textarea
                      id="warranty-notes"
                      value={warrantyCertImportantNotes}
                      onChange={(e) => setWarrantyCertImportantNotes(e.target.value)}
                      rows={4}
                      placeholder="• Conserve este documento en buen estado&#10;• Sin certificado no aplica..."
                      className="mt-1 font-mono text-xs"
                    />
                    <p className="text-[11px] text-text-muted mt-1">Una nota por línea.</p>
                  </div>
                </div>
              </div>
            )}
          </CardContent>
        </Card>
      )}

      <OfflineSetupDownloads />

      <div className="flex justify-end">
        <Button onClick={save} loading={saving} data-testid="company-save">
          Guardar información de la empresa
        </Button>
      </div>
    </div>
  );
}
