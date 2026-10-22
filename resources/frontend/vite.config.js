import { fileURLToPath, URL } from 'node:url'
import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import Components from 'unplugin-vue-components/vite'
import tailwindcss from '@tailwindcss/vite'
import { PrimeVueResolver } from '@primevue/auto-import-resolver'

export default defineConfig(({ mode }) => {
  const frontendRoot = fileURLToPath(new URL('.', import.meta.url))
  const backendRoot = fileURLToPath(new URL('../hris', import.meta.url))
  const frontendEnv = loadEnv(mode, frontendRoot, 'VITE_')
  const backendEnv = loadEnv(mode, backendRoot, 'APP_URL')
  const apiTarget =
    process.env.VITE_API_PROXY_TARGET ||
    frontendEnv.VITE_API_PROXY_TARGET ||
    backendEnv.APP_URL ||
    'http://127.0.0.1:8002'
  const proxy = { '/api': { target: apiTarget, changeOrigin: true, secure: true } }
  return {
    plugins: [vue(), Components({ resolvers: [PrimeVueResolver()], dts: false }), tailwindcss()],
    resolve: { alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) } },
    server: { host: '0.0.0.0', port: 5002, strictPort: true, proxy },
    preview: { host: '0.0.0.0', port: 5002, strictPort: true, proxy },
  }
})
