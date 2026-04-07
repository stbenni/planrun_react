import { existsSync, rmSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

const buildVersion = process.env.npm_package_version || '0.0.0'
const buildTimestamp = new Date().toISOString()
const buildId = `${buildVersion}-${buildTimestamp}`

function planrunVersionPlugin() {
  return {
    name: 'planrun-version-manifest',
    closeBundle() {
      writeFileSync(
        resolve(process.cwd(), 'dist/version.json'),
        JSON.stringify(
          {
            version: buildVersion,
            buildId,
            builtAt: buildTimestamp,
          },
          null,
          2,
        ),
      )
    },
  }
}

function cleanupViteArtifactsPlugin() {
  const viteArtifacts = [
    resolve(process.cwd(), 'dist/assets'),
    resolve(process.cwd(), 'dist/downloads'),
    resolve(process.cwd(), 'dist/index.html'),
    resolve(process.cwd(), 'dist/version.json'),
    resolve(process.cwd(), 'dist/planrun.apk'),
  ]

  return {
    name: 'planrun-clean-vite-artifacts',
    buildStart() {
      viteArtifacts.forEach((targetPath) => {
        if (!existsSync(targetPath)) return
        rmSync(targetPath, { recursive: true, force: true })
      })
    },
  }
}

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [react(), cleanupViteArtifactsPlugin(), planrunVersionPlugin()],
  define: {
    'import.meta.env.VITE_APP_VERSION': JSON.stringify(buildVersion),
    'import.meta.env.VITE_APP_BUILD_ID': JSON.stringify(buildId),
    'import.meta.env.VITE_APP_BUILT_AT': JSON.stringify(buildTimestamp),
  },
  build: {
    // dist/.well-known is managed outside Vite; clean only Vite-owned artifacts.
    emptyOutDir: false,
    sourcemap: process.env.GENERATE_SOURCEMAP === 'true',
  },
  server: {
    host: '0.0.0.0',
    port: 3200,
    // В dev запросы к /api проксируем на бэкенд (иначе 404 — Vite не отдаёт PHP)
    proxy: {
      '/api': {
        target: process.env.VITE_API_PROXY_TARGET || 'http://localhost',
        changeOrigin: true,
        secure: true,
      },
    },
  },
})
