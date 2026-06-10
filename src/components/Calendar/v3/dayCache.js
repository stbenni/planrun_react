/**
 * Кэш данных дня (getDay) для DaySheet — чтобы повторное открытие дня было мгновенным,
 * без мигания «Загрузка дня…». Version-aware: при росте глобального version
 * (useWorkoutRefreshStore — после добавления/правки/удаления/синка) кэш сбрасывается,
 * поэтому устаревшие данные не залипают.
 */
let cacheVersion = 0;
const cache = new Map();

export const dayCacheKey = (date, viewContext) => `${date}|${viewContext ?? ''}`;

/** Синхронизировать версию кэша с глобальной; при изменении — очистить. */
export function syncDayCacheVersion(version) {
  if (version !== cacheVersion) {
    cacheVersion = version;
    cache.clear();
  }
}

export const getCachedDay = (key) => cache.get(key) ?? null;
export const setCachedDay = (key, data) => { cache.set(key, data); };

const normalizeDay = (raw) => ({
  planDays: raw.planDays ?? raw.plan_days ?? [],
  dayExercises: raw.dayExercises ?? raw.day_exercises ?? [],
  workouts: Array.isArray(raw.workouts) ? raw.workouts : [],
});

/**
 * Префетч деталей дней в кэш (фоном). Уже закэшированные пропускаем.
 * Один batch-запрос get_days; фолбэк — по одному (get_day), если batch недоступен.
 */
export async function prefetchDays(api, dates, viewContext, version) {
  if (!api?.getDay || !Array.isArray(dates) || dates.length === 0) return;
  syncDayCacheVersion(version);
  const missing = dates.filter((d) => d && !getCachedDay(dayCacheKey(d, viewContext)));
  if (missing.length === 0) return;

  // batch (1 запрос)
  if (typeof api.getDays === 'function') {
    try {
      const res = await api.getDays(missing, viewContext);
      const map = res?.data?.days ?? res?.days ?? null;
      if (map && typeof map === 'object') {
        for (const [date, raw] of Object.entries(map)) {
          if (raw && !raw.error) setCachedDay(dayCacheKey(date, viewContext), normalizeDay(raw));
        }
        return;
      }
    } catch { /* фолбэк ниже */ }
  }

  // фолбэк: по одному
  await Promise.all(missing.map(async (date) => {
    const key = dayCacheKey(date, viewContext);
    if (getCachedDay(key)) return;
    try {
      const res = await api.getDay(date, viewContext || undefined);
      const raw = res?.data != null ? res.data : res;
      if (raw && !raw.error) setCachedDay(key, normalizeDay(raw));
    } catch { /* silent */ }
  }));
}
