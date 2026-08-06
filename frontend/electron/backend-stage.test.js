import { describe, expect, it } from 'vitest';

import backendStage from '../../scripts/stage-electron-backend.cjs';

describe('Electron backend staging', () => {
  it('contains the Laravel runtime directories without staging user data', () => {
    expect(backendStage.BACKEND_ENTRIES).toEqual(
      expect.arrayContaining([
        ['artisan', 'artisan'],
        ['app', 'app'],
        ['bootstrap', 'bootstrap'],
        ['config', 'config'],
        ['database', 'database'],
        ['public', 'public'],
        ['resources', 'resources'],
        ['routes', 'routes'],
        ['vendor', 'vendor'],
      ]),
    );
    expect(backendStage.BACKEND_ENTRIES).not.toContainEqual(['storage', 'storage']);
    expect(backendStage.BACKEND_ENTRIES).not.toContainEqual(['.env', '.env']);
  });

  it('uses the configured PHP runtime when preparing an installer', () => {
    expect(
      backendStage.resolvePhpRuntimeSource({
        repoRoot: '/repo',
        platform: 'win32',
        phpRuntime: '/toolchains/php',
      }),
    ).toBe('/toolchains/php');
  });

  it('pins the Linux portable PHP artifact used by both clients', () => {
    expect(
      backendStage.getPortablePhpArtifact({
        platform: 'linux',
        arch: 'x64',
      }),
    ).toEqual({
      version: '8.4.24',
      flavor: 'bulk',
      fileName: 'php-8.4.24-cli-linux-x86_64.tar.gz',
      sha256: '26424cdb8599e94565bd8e70a43be8b9b085d478cf4db41cfa0cd39017318c9f',
    });
  });

  it('pins the Windows NTS PHP artifact used by both clients', () => {
    expect(
      backendStage.getPortablePhpArtifact({
        platform: 'win32',
        arch: 'x64',
      }),
    ).toEqual({
      version: '8.4.24',
      flavor: 'nts-windows',
      fileName: 'php-8.4.24-nts-Win32-vs17-x64.zip',
      sha256: '86470a30cbbaeafb259e727dfa5cd336f2f3f0a462cd6f8e3eac00fdbded13cb',
    });
  });
});
