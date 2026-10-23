import { describe, expect, it } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

import updatePolicy from './update-policy.cjs';

const { resolveUpdateChannel, shouldEnableAutoUpdater } = updatePolicy;

describe('Electron update policy', () => {
  it('uses one release channel per desktop client', () => {
    expect(resolveUpdateChannel('admin')).toBe('admin');
    expect(resolveUpdateChannel('pos')).toBe('pos');
    expect(resolveUpdateChannel('technician')).toBe('technician');
    expect(resolveUpdateChannel('balanzapro-pos')).toBe('balanzapro-pos');
    expect(resolveUpdateChannel('balanzapro-admin')).toBe('balanzapro-admin');
    expect(resolveUpdateChannel('balanzapro-technician')).toBe('balanzapro-technician');
  });

  it('falls back to the administrative channel for unknown modes', () => {
    expect(resolveUpdateChannel(undefined)).toBe('admin');
    expect(resolveUpdateChannel('unknown')).toBe('admin');
  });

  it('enables updates only for packaged desktop clients', () => {
    expect(shouldEnableAutoUpdater({ isPackaged: true, isRuntimeSupervisor: false })).toBe(true);
    expect(shouldEnableAutoUpdater({ isPackaged: false, isRuntimeSupervisor: false })).toBe(false);
    expect(shouldEnableAutoUpdater({ isPackaged: true, isRuntimeSupervisor: true })).toBe(false);
  });

  it('keeps BalanzaPro out of the single-client release workflow', () => {
    const workflowPath = path.resolve(import.meta.dirname, '../../.github/workflows/release.yml');
    const workflow = fs.readFileSync(workflowPath, 'utf8');

    expect(workflow).toContain('GITHUB_REF_NAME');
    expect(workflow).toContain("- 'v*-admin'");
    expect(workflow).toContain("- 'v*-pos'");
    expect(workflow).toContain("- 'v*-technician'");
    expect(workflow).not.toContain("- 'v*'");
    expect(workflow).not.toContain('balanzapro-pos|balanzapro-admin|balanzapro-technician');
  });

  it('builds and publishes the three BalanzaPro update channels together', () => {
    const workflowPath = path.resolve(
      import.meta.dirname,
      '../../.github/workflows/release-balanzapro.yml',
    );
    const workflow = fs.readFileSync(workflowPath, 'utf8');

    for (const client of ['balanzapro-pos', 'balanzapro-admin', 'balanzapro-technician']) {
      expect(workflow).toContain(client);
      const builderPath = path.resolve(import.meta.dirname, `../electron-builder.${client}.yml`);
      expect(fs.readFileSync(builderPath, 'utf8')).toContain(`channel: ${client}`);
    }
    expect(workflow).toContain('candidate_run_id');
    expect(workflow).toContain('acceptance_verified');
    expect(workflow).toContain('sha256sum --check SHA256SUMS');
    expect(workflow).toContain('--latest');
    expect(workflow).toContain('BalanzaPro-desktop-release.json');

    const packageJson = JSON.parse(fs.readFileSync(path.resolve(import.meta.dirname, '../package.json'), 'utf8'));
    expect(packageJson.version).toBe('0.2.66');
    expect(packageJson.devDependencies.electron).toBe('^43.3.0');
  });
});
