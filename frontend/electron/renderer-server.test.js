import fs from 'node:fs';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

import rendererServer from './renderer-server.cjs';

const { contentTypeFor, isLoopbackAddress, safeFilePath, startRendererServer } = rendererServer;

describe('Electron renderer server', () => {
  it('resolves files inside the renderer root without allowing traversal', () => {
    expect(safeFilePath('/bundle/dist/admin', '/assets/app.js')).toBe(
      path.resolve('/bundle/dist/admin/assets/app.js'),
    );
    expect(safeFilePath('/bundle/dist/admin', '/../package.json')).toBeNull();
  });

  it('returns useful content types for packaged assets', () => {
    expect(contentTypeFor('index.html')).toBe('text/html; charset=utf-8');
    expect(contentTypeFor('assets/app.js')).toBe('text/javascript; charset=utf-8');
    expect(contentTypeFor('assets/app.css')).toBe('text/css; charset=utf-8');
  });

  it('recognizes only loopback addresses as local clients', () => {
    expect(isLoopbackAddress('127.0.0.1')).toBe(true);
    expect(isLoopbackAddress('::1')).toBe(true);
    expect(isLoopbackAddress('::ffff:127.0.0.1')).toBe(true);
    expect(isLoopbackAddress('192.168.1.20')).toBe(false);
  });

  it('listens on the requested stable port', async () => {
    const root = fs.mkdtempSync(path.join(os.tmpdir(), 'inventario-renderer-'));
    fs.writeFileSync(path.join(root, 'index.html'), '<!doctype html>');
    const renderer = await startRendererServer(root, { port: 0 });

    expect(new URL(renderer.url).hostname).toBe('127.0.0.1');
    expect(Number(new URL(renderer.url).port)).toBeGreaterThan(0);

    await new Promise((resolve) => renderer.server.close(resolve));
    fs.rmSync(root, { recursive: true, force: true });
  });

  it('exposes a loopback URL to the Electron window when binding to 0.0.0.0 (LAN mode)', async () => {
    const root = fs.mkdtempSync(path.join(os.tmpdir(), 'inventario-renderer-lan-'));
    fs.writeFileSync(path.join(root, 'index.html'), '<!doctype html>');
    const renderer = await startRendererServer(root, { host: '0.0.0.0', port: 0 });

    // El servidor se bindea a 0.0.0.0 (LAN) pero la ventana navega por loopback.
    expect(new URL(renderer.url).hostname).toBe('127.0.0.1');
    expect(renderer.server.address()).toMatchObject({ address: '0.0.0.0' });

    await new Promise((resolve) => renderer.server.close(resolve));
    fs.rmSync(root, { recursive: true, force: true });
  });

  it('proxies API requests without forwarding the browser Origin header', async () => {
    const target = await new Promise((resolve) => {
      const server = http.createServer((request, response) => {
        expect(request.headers.origin).toBeUndefined();
        response.writeHead(200, { 'Content-Type': 'application/json' });
        response.end('{"ok":true}');
      });
      server.listen(0, '127.0.0.1', () => resolve(server));
    });
    const targetPort = target.address().port;
    const root = fs.mkdtempSync(path.join(os.tmpdir(), 'inventario-renderer-proxy-'));
    fs.writeFileSync(path.join(root, 'index.html'), '<!doctype html>');
    const renderer = await startRendererServer(root, {
      port: 0,
      apiTarget: `http://127.0.0.1:${targetPort}`,
    });

    const response = await fetch(`${renderer.url}/api/health`, {
      headers: { Origin: 'http://192.168.1.20:8788' },
    });
    expect(response.status).toBe(200);
    await expect(response.json()).resolves.toEqual({ ok: true });

    await new Promise((resolve) => renderer.server.close(resolve));
    await new Promise((resolve) => target.close(resolve));
    fs.rmSync(root, { recursive: true, force: true });
  });
});
