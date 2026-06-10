import { Preferences } from '@capacitor/preferences';
import HealthConnect from '../plugins/healthConnect';
import { isNativeCapacitor } from './TokenStorageService';

const LAST_SYNC_KEY = 'hc_last_sync_iso';
const HC_DISABLED_KEY = 'hc_disabled';
const DEFAULT_BACKFILL_DAYS = 30;

/** Отключён ли Health Connect в приложении (локальный флаг; права HC программно не отзываются). */
export async function isHealthConnectDisabled() {
  try {
    const { value } = await Preferences.get({ key: HC_DISABLED_KEY });
    return value === '1';
  } catch {
    return false;
  }
}

/** «Отключить» — помечаем локально; синк/статус перестают считать HC подключённым. */
export async function disconnectHealthConnect() {
  try {
    await Preferences.set({ key: HC_DISABLED_KEY, value: '1' });
  } catch {
    /* ignore */
  }
}

/**
 * Доступность Health Connect на устройстве.
 * @returns {Promise<{available: boolean, status: string}>}
 */
export async function isHealthConnectAvailable() {
  if (!isNativeCapacitor()) return { available: false, status: 'unsupported' };
  try {
    return await HealthConnect.isAvailable();
  } catch {
    return { available: false, status: 'unavailable' };
  }
}

/** Выданы ли уже разрешения. */
export async function hasHealthConnectPermissions() {
  if (!isNativeCapacitor()) return { granted: false, routeGranted: false };
  try {
    return await HealthConnect.hasPermissions();
  } catch {
    return { granted: false, routeGranted: false };
  }
}

/** Запросить разрешения (открывает системный экран Health Connect). */
export async function requestHealthConnectPermissions() {
  if (!isNativeCapacitor()) return { granted: false, routeGranted: false };
  return HealthConnect.requestAuthorization();
}

function getSince(backfillDays) {
  // Скользящее окно: сканируем последние N дней при каждом синке.
  // Импорт на бэкенде идемпотентен (дедуп по external_id), поэтому повтор безопасен
  // и гарантирует, что исправленные/доехавшие позже данные подтянутся.
  return new Date(Date.now() - backfillDays * 24 * 60 * 60 * 1000).toISOString();
}

/**
 * Полный цикл синхронизации: читает тренировки нативно и отправляет в бэкенд.
 * Разрешения должны быть уже выданы (вызови requestHealthConnectPermissions при подключении).
 *
 * @param {object} api ApiClient
 * @param {{ backfillDays?: number, since?: string }} [opts]
 * @returns {Promise<{ imported: number, skipped: number, total: number }>}
 */
export async function syncHealthConnect(api, opts = {}) {
  if (!isNativeCapacitor()) return { imported: 0, skipped: 0, total: 0 };

  // Перед чтением убеждаемся, что базовые права выданы; если нет — перезапрашиваем
  // (откроется экран Health Connect). При полном гранте Health Connect диалог не показывает.
  if (opts.skipPermissionCheck !== true) {
    const perm = await hasHealthConnectPermissions();
    if (!perm.granted) {
      await requestHealthConnectPermissions();
    }
  }

  const since = opts.since || getSince(opts.backfillDays ?? DEFAULT_BACKFILL_DAYS);
  const res = await HealthConnect.readWorkouts({ since });
  const workouts = res?.workouts ?? [];

  if (!workouts.length) {
    await Preferences.set({ key: LAST_SYNC_KEY, value: new Date().toISOString() });
    return { imported: 0, skipped: 0, total: 0 };
  }

  const apiRes = await api.importHealthConnectWorkouts(workouts);
  const data = apiRes?.data ?? apiRes ?? {};

  // Сдвигаем точку последнего синка только после успешной отправки
  await Preferences.set({ key: LAST_SYNC_KEY, value: new Date().toISOString() });

  return {
    imported: Number(data.imported ?? 0),
    skipped: Number(data.skipped ?? 0),
    total: workouts.length,
  };
}

/** Подключение «с нуля»: проверка доступности → запрос прав → первичный синк. */
export async function connectAndSyncHealthConnect(api, backfillDays = DEFAULT_BACKFILL_DAYS) {
  const { available } = await isHealthConnectAvailable();
  if (!available) {
    const err = new Error('Health Connect недоступен');
    err.code = 'unavailable';
    throw err;
  }
  const perm = await requestHealthConnectPermissions();
  if (!perm?.granted) {
    const err = new Error('Доступ к данным не предоставлен');
    err.code = 'denied';
    throw err;
  }
  // подключились — снимаем локальный флаг «отключено»
  await Preferences.set({ key: HC_DISABLED_KEY, value: '0' }).catch(() => {});
  return syncHealthConnect(api, { backfillDays, skipPermissionCheck: true });
}
