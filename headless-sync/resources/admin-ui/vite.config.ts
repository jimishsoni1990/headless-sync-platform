import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';

/**
 * Vite build for the HSP admin UI (DECISION W (a)).
 *
 * Library mode with STABLE, non-hashed output filenames so the PHP registrar can enqueue the
 * committed assets from deterministic paths (no manifest lookup needed):
 *   dist/hsp-onboarding.js   dist/hsp-onboarding.css
 *
 * React/ReactDOM are bundled in (self-contained; no CDN, no external host) so the page renders
 * from committed dist/ with no node toolchain on the WordPress host. The build runs in dev/CI
 * only; production deploy is a plain file copy (matches the CLAUDE.md robocopy step).
 */
export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@': resolve(__dirname, 'src'),
    },
  },
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    // Deterministic, non-hashed filenames for a stable PHP enqueue path.
    lib: {
      entry: resolve(__dirname, 'src/main.tsx'),
      name: 'HSPOnboarding',
      formats: ['iife'],
      fileName: () => 'hsp-onboarding.js',
    },
    rollupOptions: {
      output: {
        assetFileNames: (assetInfo) =>
          assetInfo.name && assetInfo.name.endsWith('.css')
            ? 'hsp-onboarding.css'
            : '[name][extname]',
        // No code-splitting — one self-contained IIFE bundle for wp-admin enqueue.
        inlineDynamicImports: true,
      },
    },
    // Keep the bundle readable-ish but small; no sourcemaps shipped.
    sourcemap: false,
    cssCodeSplit: false,
  },
});
