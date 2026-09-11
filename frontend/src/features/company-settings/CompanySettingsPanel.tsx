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
import { toast } from 'sonner';

import { Building2, Trash2, Upload } from 'lucide-react';

import { Button } from '@/components/ui/Button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Spinner } from '@/components/ui/Spinner';

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
    setShowOn({
      sale_ticket: data.show_on?.sale_ticket ?? true,
      guide: data.show_on?.guide ?? true,
      report_z: data.show_on?.report_z ?? true,
      quotation: data.show_on?.quotation ?? true,
    });
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
        show_on: showOn,
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
                <span className="text-[10px] mt-1 font-medium">Sin logo</span>
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

      <div className="flex justify-end">
        <Button onClick={save} loading={saving} data-testid="company-save">
          Guardar información de la empresa
        </Button>
      </div>
    </div>
  );
}
