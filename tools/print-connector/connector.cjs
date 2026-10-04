'use strict';

const crypto = require('node:crypto');
const fsSync = require('node:fs');
const fs = require('node:fs/promises');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const { execFile } = require('node:child_process');
const { promisify } = require('node:util');

const execFileAsync = promisify(execFile);

const DEFAULT_POLL_INTERVAL_MS = 15_000;
const DEFAULT_DATA_DIR = path.join(
  process.env.ProgramData || path.join(os.homedir(), 'InventarioArens'),
  'PrintConnector',
);

function apiUrl(baseUrl, endpoint) {
  return `${String(baseUrl).replace(/\/$/, '')}${endpoint}`;
}

function makeError(message, status = 0, body = null) {
  const error = new Error(message);
  error.status = status;
  error.body = body;
  return error;
}

async function readResponse(response) {
  const text = await response.text();
  let body = null;
  try {
    body = text ? JSON.parse(text) : null;
  } catch {
    body = text;
  }
  if (!response.ok) {
    throw makeError(
      body?.message || `Cloud API respondio ${response.status}`,
      response.status,
      body,
    );
  }
  return body;
}

function buildPlainTicket(ticket) {
  const profile = ticket?.profile || {};
  const max = Number(profile.paper_width_mm) === 58 ? 32 : 48;
  const lines = [];
  const push = (value = '') => lines.push(String(value).slice(0, max));
  // Etiqueta a la izquierda y monto alineado a la derecha (usa todo el ancho).
  const pushPair = (left, right) => {
    const l = String(left ?? '');
    const r = String(right ?? '');
    const space = Math.max(1, max - l.length - r.length);
    lines.push((l + ' '.repeat(space) + r).slice(0, max));
  };
  const tenant = ticket?.tenant || {};
  const order = ticket?.pos_order || {};

  push(profile.logo_text || tenant.name || 'Sistema de Inventario');
  if (profile.header_text) push(profile.header_text);
  if (profile.show_tenant_slug !== false && tenant.slug) push(tenant.slug);
  push(`Ticket POS #${order.id || '?'}`);
  if (profile.show_sale_number !== false && order.sale_id) push(`Venta #${order.sale_id}`);
  if (profile.show_paid_at !== false && order.paid_at) push(`Fecha: ${order.paid_at}`);
  if (profile.show_cashier !== false && order.cashier_name) push(`Cajero: ${order.cashier_name}`);
  if (profile.show_cash_register !== false && order.cash_register_name)
    push(`Caja: ${order.cash_register_name}`);
  if (profile.show_branch !== false && order.branch_name) push(`Sucursal: ${order.branch_name}`);
  if (profile.show_customer !== false)
    push(`Cliente: ${order.customer_name || 'Consumidor Final'}`);

  push('-'.repeat(max));
  for (const item of ticket?.items || []) {
    push(item.product_name || 'Producto');
    if (profile.show_item_sku !== false && item.sku) push(item.sku);
    if (profile.show_item_price_list && item.price_list_name)
      push(`Lista: ${item.price_list_name}`);
    if (profile.show_item_price !== false) {
      pushPair(
        `${item.quantity || 0} x $${Number(item.unit_price || 0).toFixed(2)}`,
        `$${Number(item.total || 0).toFixed(2)}`,
      );
    }
    if (profile.show_item_serials !== false) {
      for (const serial of item.serials || []) push(`IMEI/Serial: ${serial.serial_number || ''}`);
    }
  }
  push('-'.repeat(max));
  const totals = ticket?.totals || {};
  pushPair('Total USD:', `$${Number(totals.total_base_amount || 0).toFixed(2)}`);
  if (profile.show_total_local !== false)
    pushPair('Total VES:', `Bs ${Number(totals.total_local_amount || 0).toFixed(2)}`);
  pushPair('Pagado USD:', `$${Number(totals.paid_base_amount || 0).toFixed(2)}`);
  push('-'.repeat(max));
  for (const payment of ticket?.payments || []) {
    pushPair(`${payment.method || 'Pago'} ${payment.currency || ''}`, `${payment.amount || 0}`);
    if (profile.show_payment_reference !== false && payment.reference)
      push(`Ref: ${payment.reference}`);
  }
  if (profile.footer_text) push(profile.footer_text);
  if (profile.show_non_fiscal_text !== false) push(profile.legal_text || 'Documento no fiscal');
  return `${lines.join('\n')}\n\n`;
}

function buildEscPos(text, { cutPaper = false, openCashDrawer = false, feedLines = 4 } = {}) {
  let output = '';
  if (openCashDrawer) output += '\x1b\x70\x00\x19\xfa';
  output += text;
  // Avanza el papel antes del corte para no cortar informacion pendiente.
  if (cutPaper && feedLines > 0) output += '\n'.repeat(feedLines);
  if (cutPaper) output += '\x1d\x56\x00';
  return Buffer.from(output, 'ascii');
}

function sendTcp(host, port, buffer, timeoutMs = 5000) {
  return new Promise((resolve, reject) => {
    const socket = net.createConnection({ host, port }, () => socket.end(buffer));
    const timer = setTimeout(
      () => socket.destroy(makeError(`Timeout conectando a ${host}:${port}`)),
      timeoutMs,
    );
    socket.once('error', (error) => {
      clearTimeout(timer);
      reject(error);
    });
    socket.once('close', () => {
      clearTimeout(timer);
      resolve();
    });
  });
}

/**
 * Imprime un buffer ESC/POS directo a la impresora por nombre, sin pasar por el
 * render del driver de Windows (que reimprimia el texto minusculo). En Windows
 * usa el spooler en modo RAW (winspool.drv); en Linux usa `lp -o raw`.
 */
const RAW_PRINT_SCRIPT = `param([string]$PrinterName, [string]$FilePath)
$ErrorActionPreference = 'Stop'
$code = @'
using System;
using System.IO;
using System.Runtime.InteropServices;
public class InventarioRawPrinter {
  [StructLayout(LayoutKind.Sequential, CharSet=CharSet.Unicode)]
  public struct DOCINFOA {
    [MarshalAs(UnmanagedType.LPWStr)] public string pDocName;
    [MarshalAs(UnmanagedType.LPWStr)] public string pOutputFile;
    [MarshalAs(UnmanagedType.LPWStr)] public string pDataType;
  }
  [DllImport("winspool.Drv", EntryPoint="OpenPrinterW", SetLastError=true, CharSet=CharSet.Unicode)]
  public static extern bool OpenPrinter(string src, out IntPtr hPrinter, IntPtr pd);
  [DllImport("winspool.Drv", EntryPoint="ClosePrinter", SetLastError=true)]
  public static extern bool ClosePrinter(IntPtr hPrinter);
  [DllImport("winspool.Drv", EntryPoint="StartDocPrinterW", SetLastError=true, CharSet=CharSet.Unicode)]
  public static extern bool StartDocPrinter(IntPtr hPrinter, int level, ref DOCINFOA di);
  [DllImport("winspool.Drv", EntryPoint="EndDocPrinter", SetLastError=true)]
  public static extern bool EndDocPrinter(IntPtr hPrinter);
  [DllImport("winspool.Drv", EntryPoint="StartPagePrinter", SetLastError=true)]
  public static extern bool StartPagePrinter(IntPtr hPrinter);
  [DllImport("winspool.Drv", EntryPoint="EndPagePrinter", SetLastError=true)]
  public static extern bool EndPagePrinter(IntPtr hPrinter);
  [DllImport("winspool.Drv", EntryPoint="WritePrinter", SetLastError=true)]
  public static extern bool WritePrinter(IntPtr hPrinter, IntPtr pBytes, int dwCount, out int dwWritten);
  public static bool SendFile(string printer, string path) {
    byte[] bytes = File.ReadAllBytes(path);
    IntPtr hPrinter;
    DOCINFOA di = new DOCINFOA();
    di.pDocName = "Inventario Arens Ticket";
    di.pDataType = "RAW";
    if (!OpenPrinter(printer, out hPrinter, IntPtr.Zero)) return false;
    bool ok = StartDocPrinter(hPrinter, 1, ref di);
    if (ok) ok = StartPagePrinter(hPrinter);
    IntPtr p = Marshal.AllocCoTaskMem(bytes.Length);
    Marshal.Copy(bytes, 0, p, bytes.Length);
    int written;
    if (ok) ok = WritePrinter(hPrinter, p, bytes.Length, out written);
    Marshal.FreeCoTaskMem(p);
    if (ok) { EndPagePrinter(hPrinter); EndDocPrinter(hPrinter); }
    ClosePrinter(hPrinter);
    return ok;
  }
}
'@
Add-Type -TypeDefinition $code -Language CSharp | Out-Null
if (-not [InventarioRawPrinter]::SendFile($PrinterName, $FilePath)) {
  throw "No se pudo enviar RAW a la impresora '$PrinterName'."
}
`;

async function printRaw(buffer, printerName, tempDir = os.tmpdir(), exec = execFileAsync) {
  if (!printerName) throw makeError('La estacion no tiene printer_name configurado.');
  const data = Buffer.isBuffer(buffer) ? buffer : Buffer.from(String(buffer), 'ascii');
  const filePath = path.join(tempDir, `inventario-ticket-${crypto.randomUUID()}.bin`);
  await fs.writeFile(filePath, data);
  try {
    if (process.platform === 'win32') {
      const scriptPath = path.join(tempDir, `inventario-raw-${crypto.randomUUID()}.ps1`);
      await fs.writeFile(scriptPath, RAW_PRINT_SCRIPT, 'utf8');
      try {
        await exec('powershell.exe', [
          '-NoProfile',
          '-NonInteractive',
          '-ExecutionPolicy',
          'Bypass',
          '-File',
          scriptPath,
          '-PrinterName',
          String(printerName),
          '-FilePath',
          filePath,
        ]);
      } finally {
        await fs.rm(scriptPath, { force: true });
      }
    } else {
      await exec('lp', ['-d', printerName, '-o', 'raw', filePath]);
    }
  } finally {
    await fs.rm(filePath, { force: true });
  }
}

class PrintConnector {
  constructor({
    config,
    fetchImpl = globalThis.fetch,
    printImpl,
    saveConfig,
    sleep,
    onError,
    exec = execFileAsync,
  } = {}) {
    this.config = { ...config };
    this.fetchImpl = fetchImpl;
    this.printImpl = printImpl || ((job) => this.printJob(job, exec));
    this.saveConfig = saveConfig || (async (nextConfig) => saveConfigFile(nextConfig));
    this.sleep = sleep || ((ms) => new Promise((resolve) => setTimeout(resolve, ms)));
    this.onError = onError || (() => {});
    this.lastHeartbeatAt = 0;
  }

  authHeaders() {
    if (!this.config.token) throw makeError('El conector no esta vinculado a una empresa.');
    return {
      Authorization: `Bearer ${this.config.token}`,
      Accept: 'application/json',
    };
  }

  async request(method, endpoint, body, signal) {
    const headers = { ...this.authHeaders() };
    const options = { method, headers };
    if (signal) options.signal = signal;
    if (body !== undefined) {
      headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(body);
    }
    const response = await this.fetchImpl(apiUrl(this.config.cloudApiUrl, endpoint), options);
    return readResponse(response);
  }

  async register({ code, name, installationId, version, metadata } = {}) {
    const response = await this.fetchImpl(
      apiUrl(this.config.cloudApiUrl, '/printing/connectors/register'),
      {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          code,
          name,
          installation_id: installationId,
          version,
          metadata,
        }),
      },
    );
    const body = await readResponse(response);
    const result = body.data;
    this.config = {
      ...this.config,
      token: result.token,
      tokenExpiresAt: result.token_expires_at,
      connector: result.connector,
      installationId,
      name,
      version,
    };
    await this.saveConfig(this.config);
    return result.connector;
  }

  async heartbeat(signal) {
    const body = await this.request('GET', '/printing/connector/heartbeat', undefined, signal);
    this.lastHeartbeatAt = Date.now();
    return body.data?.connector;
  }

  async pollOnce(signal) {
    const body = await this.request(
      'GET',
      `/printing/connector/jobs?limit=${this.config.batchSize || 20}`,
      undefined,
      signal,
    );
    const jobs = body.data || [];
    const results = [];
    for (const job of jobs) results.push(await this.processJob(job, signal));
    if (Date.now() - this.lastHeartbeatAt >= (this.config.heartbeatIntervalMs || 60_000))
      await this.heartbeat(signal);
    return results;
  }

  async processJob(summary, signal) {
    let claim;
    try {
      const body = await this.request(
        'POST',
        `/printing/connector/jobs/${encodeURIComponent(summary.job_uuid)}/claim`,
        undefined,
        signal,
      );
      claim = body.data;
    } catch (error) {
      if (error.status === 404 || error.status === 409)
        return {
          jobUuid: summary.job_uuid,
          status: 'skipped',
          reason: error.message,
        };
      throw error;
    }

    try {
      await this.printImpl(claim.job);
      await this.request(
        'POST',
        `/printing/connector/jobs/${encodeURIComponent(summary.job_uuid)}/ack`,
        {
          claim_token: claim.claim_token,
          status: 'printed',
        },
        signal,
      );
      return { jobUuid: summary.job_uuid, status: 'printed' };
    } catch (error) {
      await this.request(
        'POST',
        `/printing/connector/jobs/${encodeURIComponent(summary.job_uuid)}/ack`,
        {
          claim_token: claim.claim_token,
          status: 'failed',
          message: error.message,
        },
        signal,
      );
      return {
        jobUuid: summary.job_uuid,
        status: 'failed',
        message: error.message,
      };
    }
  }

  async printJob(job, exec = execFileAsync) {
    const station = job.station || {};
    const profile = job.profile || job.payload_snapshot?.profile || {};
    const text = buildPlainTicket(job.payload_snapshot || {});
    if (station.printer_type === 'network') {
      if (!station.network_host)
        throw makeError('La estacion de red no tiene network_host configurado.');
      await sendTcp(
        station.network_host,
        Number(station.network_port || 9100),
        buildEscPos(text, {
          cutPaper: profile.cut_paper,
          openCashDrawer: profile.open_cash_drawer,
        }),
        5000,
      );
      return;
    }
    // Impresora por driver/nombre: se manda ESC/POS crudo (spooler RAW) para
    // que la impresora lo renderice a tamano nativo y no el driver de Windows.
    await printRaw(
      buildEscPos(text, {
        cutPaper: profile.cut_paper,
        openCashDrawer: profile.open_cash_drawer,
      }),
      station.printer_name,
      this.config.tempDir,
      exec,
    );
  }

  async run({ signal } = {}) {
    while (!signal?.aborted) {
      try {
        await this.pollOnce(signal);
      } catch (error) {
        this.onError(error);
      }
      if (signal?.aborted) break;
      await this.sleep(this.config.pollIntervalMs || DEFAULT_POLL_INTERVAL_MS);
    }
  }
}

async function loadConfig(filePath = path.join(DEFAULT_DATA_DIR, 'config.json')) {
  try {
    return JSON.parse(await fs.readFile(filePath, 'utf8'));
  } catch (error) {
    if (error.code === 'ENOENT') return { cloudApiUrl: '', dataDir: path.dirname(filePath) };
    throw error;
  }
}

function resolveCloudApiUrl(config, cliValue, env = process.env) {
  const value = cliValue || env.PRINT_CONNECTOR_CLOUD_API_URL || config.cloudApiUrl;
  if (!value) {
    throw makeError(
      'Indica la URL de la nube como tercer argumento o PRINT_CONNECTOR_CLOUD_API_URL.',
    );
  }
  return String(value).replace(/\/$/, '');
}

function resolveConnectorVersion(config, env = process.env) {
  if (env.PRINT_CONNECTOR_VERSION) return env.PRINT_CONNECTOR_VERSION;
  if (config.version) return config.version;
  try {
    return fsSync.readFileSync(path.join(__dirname, 'VERSION.txt'), 'utf8').trim() || '0.1.0';
  } catch {
    return '0.1.0';
  }
}

async function saveConfigFile(
  config,
  filePath = path.join(config.dataDir || DEFAULT_DATA_DIR, 'config.json'),
) {
  await fs.mkdir(path.dirname(filePath), { recursive: true });
  await fs.writeFile(filePath, `${JSON.stringify(config, null, 2)}\n`, {
    encoding: 'utf8',
    mode: 0o600,
  });
}

async function main(argv = process.argv.slice(2)) {
  const command = argv[0] || 'run';
  const configPath =
    process.env.PRINT_CONNECTOR_CONFIG || path.join(DEFAULT_DATA_DIR, 'config.json');
  const config = await loadConfig(configPath);
  const connector = new PrintConnector({
    config,
    saveConfig: (nextConfig) => saveConfigFile(nextConfig, configPath),
  });

  if (command === 'status') {
    process.stdout.write(
      `${JSON.stringify({ ...config, token: config.token ? '[configured]' : null }, null, 2)}\n`,
    );
    return;
  }
  if (command === 'register') {
    const code = argv[1] || process.env.PRINT_CONNECTOR_PAIRING_CODE;
    if (!code) throw makeError('Indica el codigo como argumento o PRINT_CONNECTOR_PAIRING_CODE.');
    const installationId = config.installationId || crypto.randomUUID();
    const name = argv[2] || process.env.COMPUTERNAME || os.hostname();
    connector.config.cloudApiUrl = resolveCloudApiUrl(config, argv[3]);
    await connector.register({
      code,
      installationId,
      name,
      version: resolveConnectorVersion(config),
    });
    process.stdout.write(`Conector vinculado: ${name}\n`);
    return;
  }
  await connector.run();
}

if (require.main === module) {
  main().catch((error) => {
    process.stderr.write(`${error.message}\n`);
    process.exitCode = 1;
  });
}

module.exports = {
  DEFAULT_DATA_DIR,
  PrintConnector,
  buildEscPos,
  buildPlainTicket,
  loadConfig,
  makeError,
  printRaw,
  resolveCloudApiUrl,
  resolveConnectorVersion,
  saveConfigFile,
};
