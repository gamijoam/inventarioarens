import { Link } from '@tanstack/react-router';
import {
  Activity,
  CloudDownload,
  HardDrive,
  MonitorCog,
  Printer,
  RefreshCw,
  ServerCrash,
  Wrench,
} from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { useState } from 'react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { AppVersionBadge } from '@/components/layout/AppVersionBadge';
import { formatDateTime } from '@/lib/format';

import {
  type LocalTenantStatus,
  useConnectLocalTenant,
  useLocalPrinterAction,
  useLocalPrinterTest,
  useLocalSupportStatus,
  useLocalTenantSync,
  useLocalRetryFailed,
  useLocalServerMode,
} from './api';

export function LocalSupportPage() {
  const status = useLocalSupportStatus();

  return (
    <main className="bg-bg text-text min-h-screen px-5 py-8 sm:px-8">
      <div className="mx-auto max-w-6xl space-y-6">
        <header className="border-border flex flex-wrap items-start justify-between gap-4 border-b pb-5">
          <div className="flex items-start gap-3">
            <div className="bg-primary text-primary-foreground flex size-11 items-center justify-center rounded">
              <MonitorCog className="size-5" />
            </div>
            <div>
              <h1 className="text-2xl font-bold">Centro tecnico local</h1>
              <p className="text-text-muted mt-1 text-sm">
                Vincula empresas, descarga datos y supervisa la sincronizacion de esta computadora.
              </p>
            </div>
          </div>
          <div className="flex items-center gap-2">
            <AppVersionBadge />
            <Button
              variant="outline"
              size="sm"
              onClick={() => void status.refetch()}
              disabled={status.isFetching}
            >
              <RefreshCw className={status.isFetching ? 'size-4 animate-spin' : 'size-4'} />{' '}
              Actualizar
            </Button>
            <Button asChild variant="outline" size="sm">
              <Link to="/login">Entrar al sistema</Link>
            </Button>
          </div>
        </header>

        {status.isLoading ? (
          <Card>
            <CardContent className="text-text-muted py-8 text-sm">
              Comprobando esta instalacion...
            </CardContent>
          </Card>
        ) : status.isError ? (
          <Card className="border-danger/40">
            <CardContent className="py-8">
              <div className="text-danger flex items-start gap-3">
                <ServerCrash className="mt-0.5 size-5 shrink-0" />
                <div>
                  <p className="font-semibold">La consola tecnica no esta disponible.</p>
                  <p className="text-text-muted mt-1 text-sm">
                    Abre esta pantalla desde el acceso “Soporte tecnico” de la instalacion local. No
                    se expone en la nube.
                  </p>
                </div>
              </div>
            </CardContent>
          </Card>
        ) : (
          <>
            <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(340px,0.8fr)]">
              <ConnectCompanyCard />
              <InstallationCard
                storagePath={status.data?.storage_path ?? ''}
                databasePath={status.data?.database_path ?? ''}
                cloudUrl={status.data?.cloud_url ?? ''}
                printer={status.data?.printer}
                lan={status.data?.lan}
              />
            </div>

            <section>
              <div className="mb-3 flex items-end justify-between gap-3">
                <div>
                  <h2 className="text-lg font-semibold">Empresas de esta computadora</h2>
                  <p className="text-text-muted text-sm">
                    Cada empresa tiene su propio token, nodo y worker. No se mezclan entre si.
                  </p>
                </div>
                <Badge variant="info">{status.data?.tenants.length ?? 0} configuradas</Badge>
              </div>
              {status.data?.tenants.length ? (
                <div className="grid gap-3 lg:grid-cols-2">
                  {status.data.tenants.map((tenant) => (
                    <TenantCard key={tenant.slug} tenant={tenant} />
                  ))}
                </div>
              ) : (
                <Card>
                  <CardContent className="text-text-muted py-10 text-center text-sm">
                    Aun no hay empresas vinculadas. Usa el codigo temporal generado desde la
                    organizacion.
                  </CardContent>
                </Card>
              )}
            </section>
          </>
        )}
      </div>
    </main>
  );
}

function ConnectCompanyCard() {
  const connect = useConnectLocalTenant();
  const [code, setCode] = useState('');
  const [nodeName, setNodeName] = useState('Equipo local');
  const [nodeCode, setNodeCode] = useState('LOCAL-01');
  const [email, setEmail] = useState('');
  const [name, setName] = useState('');
  const [password, setPassword] = useState('');

  async function submit(event: FormEvent) {
    event.preventDefault();
    try {
      const result = await connect.mutateAsync({
        code: code.trim().toUpperCase(),
        node_name: nodeName.trim(),
        node_code: nodeCode.trim().toUpperCase(),
        interval: 15,
        local_email: email.trim(),
        local_user_name: name.trim() || undefined,
        local_password: password,
      });
      const names =
        result.tenants?.map((item) => item.tenant.name) ??
        (result.tenant ? [result.tenant.name] : []);
      toast.success(
        `${names.join(', ')} fue vinculada${names.length > 1 ? 's' : ''}. La descarga inicial continuara en segundo plano.`,
      );
      setCode('');
      setPassword('');
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo vincular la empresa.');
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <CloudDownload className="text-primary size-5" /> Vincular empresa
        </CardTitle>
        <CardDescription>
          Usa un codigo temporal generado por el Owner. No necesitas SSH ni copiar tokens.
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form className="grid gap-4 sm:grid-cols-2" onSubmit={submit}>
          <Field label="Codigo de vinculacion" className="sm:col-span-2">
            <Input
              value={code}
              onChange={(event) => setCode(event.target.value.replace(/\s/g, '').toUpperCase())}
              placeholder="ARNS-..."
              minLength={40}
              maxLength={40}
              required
            />
          </Field>
          <Field label="Nombre del equipo">
            <Input
              value={nodeName}
              onChange={(event) => setNodeName(event.target.value)}
              required
            />
          </Field>
          <Field label="Codigo del equipo">
            <Input
              value={nodeCode}
              onChange={(event) =>
                setNodeCode(event.target.value.replace(/[^A-Za-z0-9_-]/g, '').toUpperCase())
              }
              required
            />
          </Field>
          <Field label="Email local autorizado">
            <Input
              type="email"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              required
            />
          </Field>
          <Field label="Nombre local">
            <Input
              value={name}
              onChange={(event) => setName(event.target.value)}
              placeholder="Opcional"
            />
          </Field>
          <Field label="Contrasena local" className="sm:col-span-2">
            <Input
              type="password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              minLength={8}
              required
            />
          </Field>
          <p className="text-text-muted text-xs sm:col-span-2">
            La clave se usa solo para crear o actualizar el acceso local de esa persona; no se envia
            a la nube.
          </p>
          <Button className="sm:col-span-2" loading={connect.isPending}>
            <CloudDownload className="size-4" /> Vincular y descargar empresa
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}

function InstallationCard({
  storagePath,
  databasePath,
  cloudUrl,
  printer,
  lan,
}: {
  storagePath: string;
  databasePath: string;
  cloudUrl: string;
  printer?: { available: boolean; message: string; url: string };
  lan?: {
    enabled: boolean;
    bind_host: string;
    api_port: number;
    renderer_ports: number[];
    restart_required: boolean;
  };
}) {
  const serverMode = useLocalServerMode();
  const printerAction = useLocalPrinterAction();
  const printerTest = useLocalPrinterTest();

  async function toggleLan(): Promise<void> {
    try {
      await serverMode.mutateAsync(!(lan?.enabled ?? false));
      toast.success('Modo LAN guardado. Reinicia los clientes Electron para aplicar el cambio.');
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo actualizar el modo LAN.');
    }
  }

  async function runPrinterAction(action: 'install' | 'start' | 'stop' | 'restart'): Promise<void> {
    try {
      const result = await printerAction.mutateAsync(action);
      toast.success(result.output || 'Accion completada.');
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo controlar el agente.');
    }
  }

  async function testPrinter(): Promise<void> {
    try {
      const result = await printerTest.mutateAsync();
      if (result.ok) {
        toast.success(result.message);
      } else {
        toast.error(result.message);
      }
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo probar el agente.');
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <HardDrive className="text-primary size-5" /> Esta instalacion
        </CardTitle>
        <CardDescription>Datos persistentes de esta computadora.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-4 text-sm">
        <Detail label="Base local" value={databasePath} />
        <Detail label="Datos y registros" value={storagePath} />
        <Detail label="Nube" value={cloudUrl} />
        <div className="border-border bg-surface rounded border p-3">
          <div className="flex items-start justify-between gap-3">
            <div>
              <p className="text-text-muted text-xs">Servidor LAN</p>
              <p className="text-sm font-medium">
                {lan?.enabled
                  ? `Activo en ${lan.bind_host}:${lan.api_port}`
                  : 'Desactivado por seguridad'}
              </p>
            </div>
            <Badge variant={lan?.enabled ? 'warning' : 'outline'}>
              {lan?.enabled ? 'Activo' : 'Local'}
            </Badge>
          </div>
          <p className="text-text-muted mt-2 text-xs">
            Permite clientes por API en la red privada. No comparte el archivo SQLite.
          </p>
          <Button
            className="mt-3"
            size="sm"
            variant="outline"
            onClick={() => void toggleLan()}
            disabled={serverMode.isPending}
          >
            {lan?.enabled ? 'Desactivar modo LAN' : 'Activar modo LAN'}
          </Button>
        </div>
        <div className="border-border rounded border p-3">
          <div className="flex items-center justify-between gap-3">
            <div className="flex items-center gap-2">
              <Printer className="text-text-muted size-4" />
              <div>
                <p className="text-text-muted text-xs">Agente de impresion</p>
                <p className="text-sm font-medium">{printer?.message ?? 'Sin comprobar'}</p>
              </div>
            </div>
            <Badge variant={printer?.available ? 'success' : 'warning'}>
              {printer?.available ? 'Conectado' : 'Detenido'}
            </Badge>
          </div>
          <p className="text-text-muted mt-2 text-xs">
            Permite imprimir tickets en la impresora termica de esta computadora (sirve en
            127.0.0.1:17777).
          </p>
          <div className="mt-3 flex flex-wrap gap-2">
            <Button
              size="sm"
              onClick={() => void runPrinterAction('install')}
              disabled={printerAction.isPending}
            >
              <Wrench className="size-3.5" /> Instalar agente
            </Button>
            <Button
              size="sm"
              variant="outline"
              onClick={() => void runPrinterAction(printer?.available ? 'restart' : 'start')}
              disabled={printerAction.isPending}
            >
              <RefreshCw className="size-3.5" />
              {printer?.available ? 'Reiniciar' : 'Iniciar'}
            </Button>
            <Button
              size="sm"
              variant="outline"
              onClick={() => void testPrinter()}
              disabled={printerTest.isPending}
            >
              <Activity className="size-3.5" /> Probar agente
            </Button>
          </div>
        </div>
        <div className="border-primary/20 bg-primary/5 text-text-muted rounded border p-3 text-xs">
          Los tokens quedan protegidos en la configuracion local y nunca se muestran en esta
          pantalla.
        </div>
      </CardContent>
    </Card>
  );
}

function TenantCard({ tenant }: { tenant: LocalTenantStatus }) {
  const sync = useLocalTenantSync();
  const retry = useLocalRetryFailed();
  const busy = sync.isPending || retry.isPending;

  async function runSync() {
    try {
      await sync.mutateAsync({ tenant: tenant.slug, cycles: 1 });
      toast.success(`Sincronizacion de ${tenant.name} completada.`);
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo sincronizar.');
    }
  }

  async function retryFailed() {
    try {
      const result = await retry.mutateAsync(tenant.slug);
      toast.success(`${result.applied} eventos aplicados. ${result.failed} siguen fallando.`);
    } catch (error) {
      toast.error(
        error instanceof Error ? error.message : 'No se pudieron reintentar los eventos.',
      );
    }
  }

  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between gap-3 space-y-0">
        <div className="min-w-0">
          <CardTitle className="truncate text-base">{tenant.name}</CardTitle>
          <CardDescription className="mt-1 font-mono">{tenant.slug}</CardDescription>
        </div>
        <Badge variant={!tenant.ready ? 'info' : tenant.worker.active ? 'success' : 'warning'}>
          {!tenant.ready
            ? 'Preparando empresa'
            : tenant.worker.active
              ? 'Worker activo'
              : 'Worker detenido'}
        </Badge>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="grid gap-3 text-sm sm:grid-cols-2">
          <Detail label="Equipo" value={tenant.node_name ?? 'Sin nombre'} />
          <Detail label="Nodo" value={tenant.node_code ?? 'Sin configurar'} />
          <Detail
            label="Ultima sincronizacion"
            value={
              tenant.last_success_at ? formatDateTime(tenant.last_success_at) : 'Aun no registrada'
            }
          />
          <Detail
            label="Intervalo"
            value={tenant.interval ? `${tenant.interval} segundos` : 'Sin configurar'}
          />
        </div>
        <div className="border-border bg-surface grid gap-2 rounded border p-3 text-xs sm:grid-cols-5">
          <Metric
            label="Outbox"
            value={tenant.sync.outbox_pending}
            tone={tenant.sync.outbox_pending ? 'warning' : 'default'}
          />
          <Metric
            label="Outbox fallidos"
            value={tenant.sync.outbox_failed}
            tone={tenant.sync.outbox_failed ? 'danger' : 'default'}
          />
          <Metric
            label="Inbox recibidos"
            value={tenant.sync.inbox_received}
            tone={tenant.sync.inbox_received ? 'info' : 'default'}
          />
          <Metric
            label="Inbox fallidos"
            value={tenant.sync.inbox_failed}
            tone={tenant.sync.inbox_failed ? 'danger' : 'default'}
          />
          <Metric label="Aplicados" value={tenant.sync.inbox_applied} tone="default" />
        </div>
        {!tenant.ready && (
          <p className="border-primary/30 bg-primary/5 text-text-muted rounded border p-2 text-xs">
            La vinculacion se guardo y esta computadora esta preparando la empresa. Actualiza esta
            pantalla en unos segundos.
          </p>
        )}
        {tenant.last_error && (
          <p className="border-warning/40 bg-warning/10 text-warning rounded border p-2 text-xs">
            {tenant.last_error}
          </p>
        )}
        {tenant.ready && (
          <div className="border-border flex flex-wrap gap-2 border-t pt-3">
            <Button size="sm" onClick={runSync} disabled={busy}>
              <RefreshCw className="size-3.5" /> Sincronizar ahora
            </Button>
            {tenant.sync.inbox_failed > 0 && (
              <Button size="sm" variant="outline" onClick={retryFailed} disabled={busy}>
                <Wrench className="size-3.5" /> Reintentar fallidos
              </Button>
            )}
            {tenant.worker.available && (
              <Badge variant="outline" className="text-muted-foreground">
                <Activity className="size-3.5" /> Sincronizacion en segundo plano activa
              </Badge>
            )}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

function Metric({
  label,
  value,
  tone,
}: {
  label: string;
  value: number;
  tone: 'default' | 'warning' | 'danger' | 'info';
}) {
  const color =
    tone === 'danger'
      ? 'text-danger'
      : tone === 'warning'
        ? 'text-warning'
        : tone === 'info'
          ? 'text-primary'
          : 'text-text-primary';
  return (
    <div>
      <p className="text-text-muted">{label}</p>
      <p className={`mt-1 text-base font-semibold ${color}`}>{value}</p>
    </div>
  );
}

function Detail({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <p className="text-text-muted text-xs">{label}</p>
      <p className="text-text-primary mt-0.5 font-medium break-all">{value}</p>
    </div>
  );
}

function Field({
  label,
  className,
  children,
}: {
  label: string;
  className?: string;
  children: ReactNode;
}) {
  return (
    <div className={`space-y-1 ${className ?? ''}`}>
      <Label>{label}</Label>
      {children}
    </div>
  );
}
