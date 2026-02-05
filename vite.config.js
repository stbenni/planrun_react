import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [react()],
  server: {
    host: '0.0.0.0',
    port: 3200,
    // В dev запросы к /api проксируем на бэкенд (иначе 404 — Vite не отдаёт PHP)
    proxy: {
      '/api': {
        target: process.env.VITE_API_PROXY_TARGET || 'https://s-vladimirov.ru',
        changeOrigin: true,
        secure: true,
      },
    },
  },
})
