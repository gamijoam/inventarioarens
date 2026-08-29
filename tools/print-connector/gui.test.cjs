'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { test } = require('node:test');

const root = __dirname;

test('GUI connector includes the user-facing pairing and status controls', () => {
  const html = fs.readFileSync(path.join(root, 'renderer', 'index.html'), 'utf8');
  const renderer = fs.readFileSync(path.join(root, 'renderer', 'renderer.js'), 'utf8');
  const main = fs.readFileSync(path.join(root, 'main.cjs'), 'utf8');

  assert.match(html, /id="pairing-code"/);
  assert.match(html, /id="cloud-url"/);
  assert.match(html, /id="register-form"/);
  assert.match(html, /Comprobar conexión/);
  assert.match(renderer, /api\.register/);
  assert.match(renderer, /api\.checkConnection/);
  assert.doesNotMatch(renderer, /powershell|17777/i);
  assert.match(main, /createFromDataURL/);
  assert.match(main, /app\.getVersion\(\)/);
  assert.match(main, /setLoginItemSettings/);
  assert.match(main, /function quitApplication\(\)/);
});

test('Electron builder keeps the connector isolated from the other clients', () => {
  const config = fs.readFileSync(path.join(root, 'electron-builder.yml'), 'utf8');
  const packageJson = JSON.parse(fs.readFileSync(path.join(root, 'package.json'), 'utf8'));

  assert.match(config, /com\.inventarioarens\.printconnector/);
  assert.match(config, /InventarioArens-Print-Connector/);
  assert.match(config, /target:\n    - nsis\n    - portable/);
  assert.match(
    config,
    /artifactName: InventarioArens-Print-Connector-Setup-\$\{version\}\.\$\{ext\}/,
  );
  assert.match(
    config,
    /artifactName: InventarioArens-Print-Connector-Portable-\$\{version\}\.\$\{ext\}/,
  );
  assert.match(config, /!package-connector\.cjs/);
  assert.equal(packageJson.main, 'main.cjs');
  assert.equal(
    packageJson.scripts['build:gui'],
    'electron-builder --config electron-builder.yml --publish never',
  );
  assert.equal(
    packageJson.scripts['build:gui:win'],
    'electron-builder --config electron-builder.yml --win --publish never',
  );
  assert.equal(packageJson.devDependencies.electron, '43.3.0');
  assert.equal(packageJson.devDependencies['electron-builder'], '26.15.3');
});

test('release workflow publishes Electron GUI artifacts instead of the legacy task installer', () => {
  const workflow = fs.readFileSync(
    path.join(root, '../../.github/workflows/release-print-connector.yml'),
    'utf8',
  );

  assert.match(workflow, /pnpm install --frozen-lockfile/);
  assert.match(workflow, /pnpm run build:gui:win/);
  assert.doesNotMatch(workflow, /package-connector\.cjs|PrintConnector\.iss|install-task\.ps1/);
});
