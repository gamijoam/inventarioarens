const fs = require('node:fs');
const path = require('node:path');

const { stageBackend, stagePhpRuntime } = require('./stage-electron-backend.cjs');

function stageLocalMotor({
  repoRoot = path.resolve(__dirname, '..'),
  stageRoot = path.join(repoRoot, 'build', 'local-motor', 'stage'),
} = {}) {
  stageBackend({ repoRoot, stageRoot });
  stagePhpRuntime({ repoRoot, stageRoot, platform: 'win32' });

  const serviceRoot = path.join(stageRoot, 'service');
  fs.mkdirSync(serviceRoot, { recursive: true });
  fs.copyFileSync(
    path.join(repoRoot, 'build', 'windows-runtime', 'winsw', 'WinSW.exe'),
    path.join(serviceRoot, 'WinSW.exe'),
  );
  fs.copyFileSync(
    path.join(repoRoot, 'scripts', 'install-local-motor.ps1'),
    path.join(serviceRoot, 'install-local-motor.ps1'),
  );
  // FrankenPHP (servidor multi-hilo) es opcional: si el binario fue preparado
  // (scripts/prepare-frankenphp.cjs) se incluye en el payload como
  // runtime/frankenphp. El instalador lo usa para el servicio backend cuando
  // esta presente; si no, cae a `php artisan serve`.
  const frankenPhpSource = path.join(repoRoot, 'build', 'windows-runtime', 'frankenphp');
  if (fs.existsSync(path.join(frankenPhpSource, 'frankenphp.exe'))) {
    const frankenPhpTarget = path.join(stageRoot, 'runtime', 'frankenphp');
    fs.rmSync(frankenPhpTarget, { recursive: true, force: true });
    fs.cpSync(frankenPhpSource, frankenPhpTarget, { recursive: true });
  }

  // Herramientas nativas en Go de alto rendimiento (si estan compiladas)
  const toolsRoot = path.join(stageRoot, 'tools');
  fs.mkdirSync(toolsRoot, { recursive: true });

  const syncDaemonBin = path.join(repoRoot, 'tools', 'sync-daemon', 'bin', 'sync-daemon.exe');
  if (fs.existsSync(syncDaemonBin)) {
    const syncDestDir = path.join(toolsRoot, 'sync-daemon');
    fs.mkdirSync(syncDestDir, { recursive: true });
    fs.copyFileSync(syncDaemonBin, path.join(syncDestDir, 'sync-daemon.exe'));
  }

  const printerAgentBin = path.join(repoRoot, 'tools', 'printer-agent', 'bin', 'printer-agent.exe');
  if (fs.existsSync(printerAgentBin)) {
    const printerDestDir = path.join(toolsRoot, 'printer-agent');
    fs.mkdirSync(printerDestDir, { recursive: true });
    fs.copyFileSync(printerAgentBin, path.join(printerDestDir, 'printer-agent.exe'));
  }

  const scaleAgentBin = path.join(repoRoot, 'tools', 'scale-agent', 'bin', 'scale-agent.exe');
  if (fs.existsSync(scaleAgentBin)) {
    const scaleDestDir = path.join(toolsRoot, 'scale-agent');
    fs.mkdirSync(scaleDestDir, { recursive: true });
    fs.copyFileSync(scaleAgentBin, path.join(scaleDestDir, 'scale-agent.exe'));
  }

  const catalogSearchBin = path.join(repoRoot, 'tools', 'catalog-search', 'bin', 'catalog-search.exe');
  if (fs.existsSync(catalogSearchBin)) {
    const searchDestDir = path.join(toolsRoot, 'catalog-search');
    fs.mkdirSync(searchDestDir, { recursive: true });
    fs.copyFileSync(catalogSearchBin, path.join(searchDestDir, 'catalog-search.exe'));
  }

  fs.writeFileSync(
    path.join(stageRoot, 'MOTOR_README.txt'),
    'Motor Local de Sistema de Inventario. Los datos persistentes no se almacenan aqui.\n',
    'utf8',
  );
  return stageRoot;
}

if (require.main === module) {
  const stageRoot = stageLocalMotor();
  process.stdout.write(`Motor Local staged at ${stageRoot}\n`);
}

module.exports = { stageLocalMotor };
