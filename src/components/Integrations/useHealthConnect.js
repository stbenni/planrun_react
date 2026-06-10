import { useCallback, useEffect, useState } from 'react';
import {
  isHealthConnectAvailable,
  hasHealthConnectPermissions,
  isHealthConnectDisabled,
  connectAndSyncHealthConnect,
  syncHealthConnect,
  disconnectHealthConnect,
} from '../../services/healthConnectSync';

/**
 * Состояние и действия Health Connect для экрана интеграций.
 * `connected` = доступен + права выданы + не отключён локально.
 * На web/не-Android `available` = false (карточки/кнопки скрываются).
 */
export default function useHealthConnect(api, notify) {
  const [available, setAvailable] = useState(false);
  const [status, setStatus] = useState('unknown');
  const [granted, setGranted] = useState(false);
  const [disabled, setDisabled] = useState(false);
  const [busy, setBusy] = useState(false);

  const connected = granted && !disabled;

  const refresh = useCallback(async () => {
    const res = await isHealthConnectAvailable();
    setAvailable(res.available);
    setStatus(res.status);
    if (res.available) {
      const [p, off] = await Promise.all([hasHealthConnectPermissions(), isHealthConnectDisabled()]);
      setGranted(!!p.granted);
      setDisabled(!!off);
    }
  }, []);

  useEffect(() => {
    let cancelled = false;
    (async () => { if (!cancelled) await refresh(); })();
    return () => { cancelled = true; };
  }, [refresh]);

  const connect = useCallback(async () => {
    if (busy || !api) return;
    setBusy(true);
    try {
      const res = await connectAndSyncHealthConnect(api);
      setGranted(true);
      setDisabled(false);
      notify?.('success', `Health Connect подключён. Импортировано: ${res.imported}`);
    } catch (e) {
      notify?.('error', e?.message || 'Не удалось подключить Health Connect');
    } finally {
      setBusy(false);
    }
  }, [api, busy, notify]);

  const sync = useCallback(async () => {
    if (busy || !api) return;
    setBusy(true);
    try {
      const res = await syncHealthConnect(api);
      notify?.('success', `Синхронизация Health Connect: +${res.imported}`);
    } catch (e) {
      notify?.('error', e?.message || 'Ошибка синхронизации');
    } finally {
      setBusy(false);
    }
  }, [api, busy, notify]);

  const disconnect = useCallback(async () => {
    if (busy) return;
    if (!window.confirm('Отключить Health Connect? Импорт остановится. Полностью отозвать доступ можно в настройках Health Connect.')) return;
    setBusy(true);
    try {
      await disconnectHealthConnect();
      setDisabled(true);
      notify?.('success', 'Health Connect отключён');
    } finally {
      setBusy(false);
    }
  }, [busy, notify]);

  return { available, status, connected, busy, connect, sync, disconnect, refresh };
}
