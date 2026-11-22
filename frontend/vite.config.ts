import { fileURLToPath, URL } from 'node:url'

import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vitest/config'

// The browser only ever talks to this origin (:5173). Vite proxies the API and
// the Sanctum CSRF endpoint to Laravel, so the app stays same-origin and the
// session/CSRF cookies remain first-party (no CORS, no token in JS).
const proxyTarget = process.env.VITE_PROXY_TARGET ?? 'http://localhost:8000'

export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    port: 5173,
    proxy: {
      '/api': { target: proxyTarget, changeOrigin: true },
      '/sanctum': { target: proxyTarget, changeOrigin: true },
      // Headless WordPress. The browser calls /wp-api/index.php?rest_route=...
      // (same-origin, so no CORS in development) and Vite forwards it to
      // WordPress with the /wp-api prefix stripped. In production the app talks
      // to WordPress directly via VITE_WP_BASE_URL instead of this proxy.
      '/wp-api': {
        target: 'http://localhost:8081',
        changeOrigin: true,
        rewrite: (path) => path.replace(/^\/wp-api/, ''),
      },
    },
  },
  build: {
    // Hashed filenames let nginx serve /assets/* as immutable.
    outDir: 'dist',
    sourcemap: true,
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
    restoreMocks: true,
    css: false,
  },
})
