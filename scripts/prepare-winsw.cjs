const crypto = require('node:crypto');
const fs = require('node:fs');
const https = require('node:https');
const path = require('node:path');

const WINSW_ARTIFACT = Object.freeze({
  version: '2.12.0',
  fileName: 'WinSW.NET461.exe',
  sha256: 'b5066b7bbdfba1293e5d15cda3caaea88fbeab35bd5b38c41c913d492aadfc4f',
  url: 'https://github.com/winsw/winsw/releases/download/v2.12.0/WinSW.NET461.exe',
});

function sha256(filePath) {
  return crypto.createHash('sha256').update(fs.readFileSync(filePath)).digest('hex');
}

function download(url, destination) {
  return new Promise((resolve, reject) => {
    https
      .get(url, (response) => {
        if ([301, 302, 307, 308].includes(response.statusCode)) {
          response.resume();
          download(new URL(response.headers.location, url).toString(), destination).then(
            resolve,
            reject,
          );
          return;
        }
        if (response.statusCode !== 200) {
          response.resume();
          reject(new Error(`Descarga WinSW fallo con HTTP ${response.statusCode}`));
          return;
        }
        const output = fs.createWriteStream(destination);
        response.pipe(output);
        output.on('finish', () => output.close(resolve));
        output.on('error', reject);
      })
      .on('error', reject);
  });
}

async function prepareWinSw({ repoRoot = path.resolve(__dirname, '..') } = {}) {
  const cacheRoot = path.join(repoRoot, 'build', '.cache', 'winsw');
  const cachePath = path.join(cacheRoot, WINSW_ARTIFACT.fileName);
  const targetRoot = path.join(repoRoot, 'build', 'windows-runtime', 'winsw');
  const targetPath = path.join(targetRoot, 'WinSW.exe');
  fs.mkdirSync(cacheRoot, { recursive: true });

  if (fs.existsSync(cachePath) && sha256(cachePath) !== WINSW_ARTIFACT.sha256) {
    fs.rmSync(cachePath, { force: true });
  }
  if (!fs.existsSync(cachePath)) await download(WINSW_ARTIFACT.url, cachePath);

  const checksum = sha256(cachePath);
  if (checksum !== WINSW_ARTIFACT.sha256) {
    throw new Error(`SHA-256 de WinSW no coincide: ${checksum}`);
  }

  fs.mkdirSync(targetRoot, { recursive: true });
  fs.copyFileSync(cachePath, targetPath);
  return targetPath;
}

if (require.main === module) {
  prepareWinSw()
    .then((targetPath) => process.stdout.write(`WinSW listo en ${targetPath}\n`))
    .catch((error) => {
      process.stderr.write(`${error.stack || error}\n`);
      process.exitCode = 1;
    });
}

module.exports = { WINSW_ARTIFACT, prepareWinSw, sha256 };
