import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { existsSync, writeFileSync, unlinkSync } from 'fs'
import { resolve } from 'path'

const projectRoot = resolve(__dirname, '..')
const outDir = process.env.COMET_ADMIN_OUT_DIR
  ? resolve(process.env.COMET_ADMIN_OUT_DIR)
  : resolve(projectRoot, 'dist/admin')
const hotFile = resolve(projectRoot, 'cms/.vite-hot')

export default defineConfig(({ command }) => ({
  // The dev server is mounted at /admin/. Production builds use relative asset
  // URLs so the admin works from any install prefix (e.g. /cms/admin/); the
  // PHP shell provides that prefix to the app at runtime.
  base: command === 'build' ? './' : '/admin/',
  plugins: [
    vue(),
    // Write/clean "hot" file so PHP knows when the dev server is running.
    {
      name: 'comet-hot-file',
      configureServer(server) {
        server.httpServer?.once('listening', () => {
          const addr = server.httpServer?.address()
          const port = typeof addr === 'object' && addr ? addr.port : 5173
          writeFileSync(hotFile, `http://localhost:${port}`)
        })
        const cleanup = () => {
          if (existsSync(hotFile)) unlinkSync(hotFile)
        }
        process.on('exit', cleanup)
        process.on('SIGINT', () => { cleanup(); process.exit() })
        process.on('SIGTERM', () => { cleanup(); process.exit() })
      },
    },
  ],
  root: __dirname,
  // Prepare the lazy-loaded editor together so opening it does not trigger a
  // dependency re-optimization that invalidates in-flight module requests.
  optimizeDeps: {
    include: ['codemirror', '@codemirror/lang-css', '@codemirror/state', '@codemirror/view', '@codemirror/commands'],
  },
  test: {
    setupFiles: ['./src/test/setup.js'],
  },
  build: {
    outDir,
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: resolve(__dirname, 'src/main.js'),
    },
  },
  server: {
    // Preserve the browser's host: preview CSP sources must match the origin
    // used by srcdoc iframes and their relative block module URLs.
    proxy: {
      '/admin/api': { target: 'http://localhost:8000', changeOrigin: false },
      '/admin/site-preview': { target: 'http://localhost:8000', changeOrigin: false },
      '/site-preview': { target: 'http://localhost:8000', changeOrigin: false },
      '/api': { target: 'http://localhost:8000', changeOrigin: false },
      '/media': { target: 'http://localhost:8000', changeOrigin: false },
      // Site assets the builder canvas loads, such as language flags.
      '/_site': { target: 'http://localhost:8000', changeOrigin: false },
    },
    cors: true,
  },
}))
