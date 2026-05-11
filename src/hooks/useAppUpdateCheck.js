import { useCallback, useEffect, useRef, useState } from 'react';
import { isNativeCapacitor } from '../services/TokenStorageService';

const UPDATE_MANIFEST_URL = 'https://planrun.ru/version.json';

export function useAppUpdateCheck(enabled = true) {
  const [updateInfo, setUpdateInfo] = useState(null);
  const hasCheckedOnMount = useRef(false);
  const dismissedVersionCode = useRef(null);

  const checkForApkUpdate = useCallback(async () => {
    try {
      const response = await fetch(`${UPDATE_MANIFEST_URL}?t=${Date.now()}`, {
        cache: 'no-store',
      });

      if (!response.ok) return;

      const serverInfo = await response.json();
      const serverVersionCode = Number.parseInt(serverInfo?.version_code, 10);

      if (!Number.isFinite(serverVersionCode)) return;

      let currentVersionCode = 0;
      try {
        const { App } = await import('@capacitor/app');
        const appInfo = await App.getInfo();
        currentVersionCode = Number.parseInt(appInfo?.build, 10) || 0;
      } catch {
        currentVersionCode = 0;
      }

      if (serverVersionCode > currentVersionCode && dismissedVersionCode.current !== serverVersionCode) {
        setUpdateInfo({
          ...serverInfo,
          version_code: serverVersionCode,
          download_url: serverInfo.download_url || `https://planrun.ru/downloads/planrun-${serverInfo.version}.apk`,
        });
      } else {
        setUpdateInfo(null);
      }
    } catch {
      // Нет сети или сервер недоступен: приложение должно продолжать работать без шума.
    }
  }, []);

  useEffect(() => {
    if (!enabled || !isNativeCapacitor() || hasCheckedOnMount.current) return;

    hasCheckedOnMount.current = true;
    const timerId = window.setTimeout(checkForApkUpdate, 800);

    return () => window.clearTimeout(timerId);
  }, [enabled, checkForApkUpdate]);

  useEffect(() => {
    if (!enabled || !isNativeCapacitor()) return undefined;

    let listenerHandle;
    let isDisposed = false;

    import('@capacitor/app')
      .then(({ App }) => App.addListener('appStateChange', (state) => {
        if (state.isActive) {
          checkForApkUpdate();
        }
      }))
      .then((handle) => {
        if (isDisposed) {
          handle?.remove?.();
          return;
        }
        listenerHandle = handle;
      })
      .catch(() => undefined);

    return () => {
      isDisposed = true;
      listenerHandle?.remove?.();
    };
  }, [enabled, checkForApkUpdate]);

  const dismissUpdate = useCallback(() => {
    dismissedVersionCode.current = updateInfo?.version_code ?? null;
    setUpdateInfo(null);
  }, [updateInfo?.version_code]);

  return {
    updateAvailable: updateInfo !== null,
    updateInfo,
    dismissUpdate,
    checkForApkUpdate,
  };
}
