import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

const repositoryRoot = path.resolve(import.meta.dirname, '..', '..');

describe('Motor Local packaging contracts', () => {
  it('pins the stable WinSW artifact and verifies its SHA-256', async () => {
    const module = await import('../../scripts/prepare-winsw.cjs');
    const artifact = module.default?.WINSW_ARTIFACT ?? module.WINSW_ARTIFACT;

    expect(artifact.version).toBe('2.12.0');
    expect(artifact.fileName).toBe('WinSW.NET461.exe');
    expect(artifact.sha256).toMatch(/^[a-f0-9]{64}$/);
    expect(artifact.url).toBe(
      'https://github.com/winsw/winsw/releases/download/v2.12.0/WinSW.NET461.exe',
    );
  });

  it('installs versioned payloads and preserves ProgramData on uninstall', () => {
    const inno = fs.readFileSync(
      path.join(repositoryRoot, 'installer', 'windows', 'MotorLocal.iss'),
      'utf8',
    );
    const installer = fs.readFileSync(
      path.join(repositoryRoot, 'scripts', 'install-local-motor.ps1'),
      'utf8',
    );

    expect(inno).toContain('versions\\{#MotorVersion}');
    expect(inno).toContain('PrivilegesRequired=admin');
    expect(installer).toContain("service_manager = 'scm'");
    expect(installer).toContain("$SyncService = 'SistemaInventarioSync'");
    expect(installer).toContain('artisan sync:daemon-all');
    expect(installer).toContain("@('backend_wrapper', 'printer_wrapper', 'sync_wrapper')");
    expect(installer).toContain("@('backend_service', 'printer_service', 'sync_service')");
    expect(installer).toContain('inventario-before-motor-');
    expect(installer).toContain('iniciando rollback');
    expect(installer).toContain('if (-not $ValidateOnly)');
    expect(installer).toContain("Unregister-ScheduledTask -TaskName $task.TaskName");
    expect(installer).not.toContain('artisan local:repair-tasks');
    expect(installer).toContain('SQLite, tokens, storage y respaldos fueron conservados');
    expect(inno).toContain('RaiseException');
    expect(inno).toContain('ResultCode <> 0');
  });

  it('allows configuring the sync cloud URL per build variant', () => {
    const inno = fs.readFileSync(
      path.join(repositoryRoot, 'installer', 'windows', 'MotorLocal.iss'),
      'utf8',
    );
    const installer = fs.readFileSync(
      path.join(repositoryRoot, 'scripts', 'install-local-motor.ps1'),
      'utf8',
    );
    const build = fs.readFileSync(
      path.join(repositoryRoot, 'scripts', 'build-local-motor.ps1'),
      'utf8',
    );
    const workflow = fs.readFileSync(
      path.join(repositoryRoot, '.github', 'workflows', 'release-motor.yml'),
      'utf8',
    );

    expect(installer).toContain("[string]$CloudUrl = 'https://app.miinventariofacil.com/api'");
    expect(installer).toContain('$env:SYNC_CLOUD_URL = $CloudUrl');
    expect(installer).toContain('http://127.0.0.1:8791');
    expect(installer).toContain('http://127.0.0.1:8792');
    expect(inno).toContain('CloudUrl "{#CloudUrl}"');
    expect(build).toContain('/DCloudUrl=$CloudUrl');
    expect(workflow).toContain('https://app.balanzapro.com/api');
  });

  it('creates the application window before waiting for Motor health', () => {
    const main = fs.readFileSync(
      path.join(repositoryRoot, 'frontend', 'electron', 'main.cjs'),
      'utf8',
    );
    const windowCreation = main.indexOf('const window = new BrowserWindow');
    const runtimeStart = main.indexOf('void startLocalRuntime(window, url)');

    expect(windowCreation).toBeGreaterThan(-1);
    expect(runtimeStart).toBeGreaterThan(windowCreation);
    expect(main).toContain('Motor Local no disponible');
    expect(main).toContain('La aplicacion abrio, pero el Motor Local no esta respondiendo.');
  });
});
