import { defineConfig } from 'vite';

// The prototype is deliberately a plain static three.js page: no backend, no data.
// `host: '0.0.0.0'` + `allowedHosts: true` make it work behind the sandbox/preview proxy.
export default defineConfig({
  base: './',
  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: false,
    allowedHosts: true,
    cors: true,
  },
  preview: {
    host: '0.0.0.0',
    port: 4173,
    strictPort: false,
    allowedHosts: true,
    cors: true,
  },
  build: {
    target: 'es2020',
    assetsInlineLimit: 0,
  },
});
