import React from 'react'
import ReactDOM from 'react-dom/client'
import App from './App.jsx'
import './index.css'
import { initLogger, installGlobalErrorLogger, logger } from './utils/logger'

initLogger()
installGlobalErrorLogger()
logger.log('App start')

// В нативном приложении (Capacitor) всегда показываем мобильный вид, как на мобильной версии сайта
if (typeof window !== 'undefined' && window.Capacitor) {
  const platform = window.Capacitor.getPlatform?.() || ''
  if (platform === 'android' || platform === 'ios') {
    document.documentElement.classList.add('native-app')
  }
}

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>,
)
