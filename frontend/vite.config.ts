import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { TanStackRouterVite } from '@tanstack/router-plugin/vite';
import path from 'node:path';

// Variables para Laravel Reverb (WebSocket push notifications).
// Ver docs/REVERB_WEBSOCKETS_PLAN.md. En produccion estas se inyectan
// via .env en el build; aqui usamos defaults que sirven para desarrollo
// local (Reverb escucha en localhost:8081 por defecto).
const REVERB_DEFAULTS = {
  VITE_REVERB_APP_KEY: 'inventarioarens-key',
  VITE_REVERB_HOST: 'localhost',
  VITE_REVERB_PORT: '8081',
  VITE_REVERB_SCHEME: 'http',
};

export default defineConfig({
  plugins: [
    TanStackRouterVite({
      routesDirectory: './src/routes',
      generatedRouteTree: './src/routeTree.gen.ts',
      autoCodeSplitting: true,
    }),
    react(),
    tailwindcss(),
  ],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  // Inyecta defaults de Reverb como fallback. El .env real toma
  // precedencia sobre estos defaults si el archivo existe.
  define: {
    ...Object.fromEntries(
      Object.entries(REVERB_DEFAULTS).map(([k, v]) => [
        `import.meta.env.${k}`,
        JSON.stringify(v),
      ]),
    ),
  },
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
      // Proxy WebSocket para Reverb en desarrollo. Con `ws: true` el
      // proxy hace upgrade de conexion HTTP a WebSocket.
      '/ws': {
        target: 'ws://127.0.0.1:8081',
        ws: true,
        changeOrigin: true,
      },
    },
    // Evita que el navegador cachee bundles viejos de Vite. Cuando se
    // cambian los modelos o servicios del backend, un Ctrl+R normal
    // puede mantener el JS anterior; con estos headers el navegador
    // siempre pide la version mas reciente al servidor de Vite.
    headers: {
      'Cache-Control': 'no-store, no-cache, must-revalidate, max-age=0',
      Pragma: 'no-cache',
      Expires: '0',
    },
  },
  build: {
    outDir: 'dist',
    sourcemap: true,
    target: 'es2022',
    rollupOptions: {
      output: {
        manualChunks: {
          'tanstack-vendor': [
            '@tanstack/react-query',
            '@tanstack/react-router',
            '@tanstack/react-table',
          ],
          'radix-vendor': [
            '@radix-ui/react-dialog',
            '@radix-ui/react-dropdown-menu',
            '@radix-ui/react-popover',
            '@radix-ui/react-select',
            '@radix-ui/react-tabs',
            '@radix-ui/react-tooltip',
            '@radix-ui/react-checkbox',
            '@radix-ui/react-toast',
          ],
        },
      },
    },
  },
});