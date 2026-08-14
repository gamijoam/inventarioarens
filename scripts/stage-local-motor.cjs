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
