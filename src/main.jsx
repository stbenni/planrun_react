import React from 'react'
import ReactDOM from 'react-dom/client'
import App from './App.jsx'
import AppErrorBoundary from './components/common/AppErrorBoundary'
import './index.css'
import { Capacitor } from '@capacitor/core'
import { initLogger, installGlobalErrorLogger, logger } from './utils/logger'
import WebPushService from './services/WebPushService'
import { isNativeCapacitor } from './services/TokenStorageService'

initLogger()
installGlobalErrorLogger()
if (process.env.NODE_ENV !== 'production') {
  logger.log('App start')
}

// В нативном приложении (Capacitor) всегда показываем мобильный вид, как на мобильной версии сайта
if (isNativeCapacitor()) {
  document.documentElement.classList.add('native-app')
  try {
    const platform = Capacitor?.getPlatform?.() || ''
    const userAgent = navigator.userAgent || ''
    const androidMatch = userAgent.match(/Android\s+(\d+)/i)
    const androidMajor = Number.parseInt(androidMatch?.[1] || '', 10)
    if (platform === 'android') {
      document.documentElement.classList.add('native-android')
      if (Number.isFinite(androidMajor) && androidMajor >= 13) {
        document.documentElement.classList.add('native-android-modern')
      }
    }
  } catch (error) {
    void error
  }
}

if (WebPushService.isSupported()) {
  WebPushService.registerServiceWorker().catch(() => {})
}

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <AppErrorBoundary>
      <App />
    </AppErrorBoundary>
  </React.StrictMode>,
)
