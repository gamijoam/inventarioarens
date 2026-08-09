const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');

const CONTENT_TYPES = Object.freeze({
  '.css': 'text/css; charset=utf-8',
  '.gif': 'image/gif',
  '.html': 'text/html; charset=utf-8',
  '.ico': 'image/x-icon',
  '.jpeg': 'image/jpeg',
  '.jpg': 'image/jpeg',
  '.js': 'text/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.map': 'application/json; charset=utf-8',
  '.png': 'image/png',
  '.svg': 'image/svg+xml',
  '.webp': 'image/webp',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
});

function safeFilePath(rootDirectory, requestPath) {
  let pathname;

  try {
    pathname = decodeURIComponent(requestPath.split('?')[0]);
  } catch {
    return null;
  }

  const root = path.resolve(rootDirectory);
  const candidate = path.resolve(root, `.${pathname}`);

  if (candidate !== root && !candidate.startsWith(`${root}${path.sep}`)) {
    return null;
  }

  return candidate;
}

function contentTypeFor(filePath) {
  return CONTENT_TYPES[path.extname(filePath).toLowerCase()] ?? 'application/octet-stream';
}

function isLoopbackAddress(address) {
  const normalized = String(address ?? '').replace(/^::ffff:/, '');
  return normalized === '127.0.0.1' || normalized === '::1';
}

function proxyApiRequest(request, response, apiTarget) {
  const target = new URL(request.url ?? '/', `${apiTarget}/`);
  const headers = { ...request.headers, host: target.host };
  delete headers.origin;
  delete headers.referer;

  const proxy = http.request(
    target,
    {
      method: request.method,
      headers,
    },
    (upstream) => {
      response.writeHead(upstream.statusCode ?? 502, upstream.headers);
      upstream.pipe(response);
    },
  );
  proxy.on('error', () => {
    if (!response.headersSent) response.writeHead(502);
    response.end('Local API unavailable');
  });
  request.pipe(proxy);
}

function startRendererServer(rootDirectory, options = {}) {
  const host = options.host ?? '127.0.0.1';
  // El cliente (ventana Electron) SIEMPRE debe navegar a loopback, incluso
  // cuando el servidor se bindea a 0.0.0.0 para LAN. 0.0.0.0 no es una IP
  // navegable: si la URL del renderer quedara en http://0.0.0.0:8789 la
  // ventana no carga y la app se cierra al arrancar con modo LAN activo.
  const clientHost = options.clientHost ?? loopbackUrlHost(host);
  const port = options.port ?? 0;
  const apiTarget = options.apiTarget ?? null;
  const server = http.createServer((request, response) => {
    const requestPath = request.url ?? '/';

    if (apiTarget && requestPath.startsWith('/api/')) {
      if (
        requestPath.startsWith('/api/local-support') &&
        !isLoopbackAddress(request.socket.remoteAddress)
      ) {
        response.writeHead(404);
        response.end();
        return;
      }

      proxyApiRequest(request, response, apiTarget);
      return;
    }

    let filePath = safeFilePath(rootDirectory, requestPath);

    if (!filePath || (request.method !== 'GET' && request.method !== 'HEAD')) {
      response.writeHead(404);
      response.end();
      return;
    }

    if (!fs.existsSync(filePath) || fs.statSync(filePath).isDirectory()) {
      filePath = path.join(rootDirectory, 'index.html');
    }

    if (!fs.existsSync(filePath)) {
      response.writeHead(404);
      response.end('Renderer bundle not found');
      return;
    }

    const content = fs.readFileSync(filePath);
    response.writeHead(200, {
      'Cache-Control': 'no-store',
      'Content-Length': content.byteLength,
      'Content-Type': contentTypeFor(filePath),
    });

    if (request.method === 'HEAD') {
      response.end();
      return;
    }

    response.end(content);
  });

  return new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(port, host, () => {
      const address = server.address();

      if (!address || typeof address === 'string') {
        reject(new Error('Could not determine renderer server address'));
        return;
      }

      server.removeListener('error', reject);
      resolve({
        server,
        url: `http://${clientHost}:${address.port}`,
      });
    });
  });
}

/**
 * Traduce el host de bind a un host navegable por la ventana de Electron.
 * 0.0.0.0 / :: se traducen a 127.0.0.1; cualquier otro host se conserva.
 */
function loopbackUrlHost(bindHost) {
  const normalized = String(bindHost ?? '').replace(/^::ffff:/, '');
  if (normalized === '0.0.0.0' || normalized === '::') return '127.0.0.1';
  return normalized;
}

module.exports = {
  contentTypeFor,
  isLoopbackAddress,
  loopbackUrlHost,
  safeFilePath,
  startRendererServer,
};
