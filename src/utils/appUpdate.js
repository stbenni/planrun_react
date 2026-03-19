const CURRENT_BUILD_ID = import.meta.env.VITE_APP_BUILD_ID || 'dev'
const UPDATE_CHECK_INTERVAL_MS = 5 * 60 * 1000
const RELOAD_MARKER_PREFIX = 'planrun-reloaded-build:'

async function fetchLatestBuildId(signal) {
  const response = await fetch(`/version.json?t=${Date.now()}`, {
    cache: 'no-store',
    headers: {
      'Cache-Control': 'no-cache',
    },
    signal,
  })

  if (!response.ok) return null

  const data = await response.json().catch(() => null)
  return typeof data?.buildId === 'string' ? data.buildId : null
}

export function startAppUpdatePolling() {
  if (typeof window === 'undefined' || !import.meta.env.PROD) {
    return () => {}
  }

  let isDisposed = false
  let activeController = null

  const checkForUpdate = async () => {
    if (isDisposed) return

    activeController?.abort()
    const controller = new AbortController()
    activeController = controller

    try {
      const latestBuildId = await fetchLatestBuildId(controller.signal)
      if (!latestBuildId || latestBuildId === CURRENT_BUILD_ID) return

      const reloadMarker = `${RELOAD_MARKER_PREFIX}${latestBuildId}`
      if (window.sessionStorage.getItem(reloadMarker) === '1') return

      window.sessionStorage.setItem(reloadMarker, '1')
      window.location.reload()
    } catch (error) {
      if (error?.name !== 'AbortError') {
        // Ошибку молча игнорируем: отсутствие сети не должно мешать работе приложения.
      }
    }
  }

  const handleVisibilityChange = () => {
    if (document.visibilityState === 'visible') {
      checkForUpdate()
    }
  }

  const handleFocus = () => {
    checkForUpdate()
  }

  const handlePageShow = () => {
    checkForUpdate()
  }

  const intervalId = window.setInterval(checkForUpdate, UPDATE_CHECK_INTERVAL_MS)

  document.addEventListener('visibilitychange', handleVisibilityChange)
  window.addEventListener('focus', handleFocus)
  window.addEventListener('pageshow', handlePageShow)

  return () => {
    isDisposed = true
    activeController?.abort()
    window.clearInterval(intervalId)
    document.removeEventListener('visibilitychange', handleVisibilityChange)
    window.removeEventListener('focus', handleFocus)
    window.removeEventListener('pageshow', handlePageShow)
  }
}
