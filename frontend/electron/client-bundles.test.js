import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

const repositoryRoot = path.resolve(import.meta.dirname, '..', '..');

describe('Electron client packaging', () => {
  it.each(['admin', 'pos', 'technician'])('packages only the %s renderer bundle', (client) => {
    const configPath = path.join(repositoryRoot, 'frontend', `electron-builder.${client}.yml`);
    const config = fs.readFileSync(configPath, 'utf8');
    const rendererEntries = config
      .split('\n')
      .map((line) => line.trim())
      .filter((line) => line.startsWith('- dist/'));

    expect(rendererEntries).toEqual([`- dist/${client}/**/*`]);
  });
});
