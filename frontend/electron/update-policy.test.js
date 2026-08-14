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

  it('publishes a client inferred from a channel-suffixed tag instead of defaulting to technician', () => {
    const workflowPath = path.resolve(import.meta.dirname, '../../.github/workflows/release.yml');
    const workflow = fs.readFileSync(workflowPath, 'utf8');

    expect(workflow).toContain('GITHUB_REF_NAME');
    expect(workflow).toContain('CLIENT="${TAG_NAME##*-}"');
    expect(workflow).toContain('admin|pos|technician');
  });
});
