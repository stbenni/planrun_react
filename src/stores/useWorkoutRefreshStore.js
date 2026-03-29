/**
 * Store для глобального обновления данных тренировок.
 *
 * Механизмы обновления:
 * 1. triggerRefresh() — явный вызов после действий пользователя
 *    (saveResult, syncWorkouts, deleteWorkout и т.д.)
 * 2. checkForUpdates() — проверка data_version на бэкенде.
 *    Если версия изменилась (Strava webhook, Telegram и т.д.) →
 *    автоматически вызывает triggerRefresh().
 *
 * Стратегии по платформе:
 * - Браузер: polling каждые 30 сек
 * - Android/iOS (Capacitor): проверка при resume (app вернулся из background)
 *   + реакция на push-уведомления. Без polling — экономия батареи.
 *
 * Все экраны (Dashboard, Calendar, Stats, UserProfile) подписаны на version
 * и обновляют данные при его изменении.
 */

import { create } from 'zustand';
import useAuthStore from './useAuthStore';

import { isNativeCapacitor } from '../services/TokenStorageService';

const POLL_INTERVAL = 30000; // 30 секунд (только для браузера)

const useWorkoutRefreshStore = create((set, get) => ({
  version: 0,
  _lastDataVersion: null,
  _pollTimerId: null,
  _resumeCleanup: null,

  triggerRefresh: () => {
    set((s) => ({ version: s.version + 1 }));
  },

  /**
   * Проверить, изменились ли данные на бэкенде.
   * Если да — вызывает triggerRefresh().
   * Возвращает true если данные обновились.
   */
  checkForUpdates: async () => {
    const { api } = useAuthStore.getState();
    if (!api) return false;

    try {
      const data = await api.getDataVersion();
      const newVersion = data?.version;
      const prevVersion = get()._lastDataVersion;

      if (newVersion) {
        set({ _lastDataVersion: newVersion });
      }

      if (prevVersion !== null && newVersion && newVersion !== prevVersion) {
        get().triggerRefresh();
        return true;
      }

      return false;
    } catch {
      return false;
    }
  },

  /**
   * Запустить автоматическое обновление.
   * Браузер: polling. Мобилка: resume listener + push.
   */
  startAutoRefresh: () => {
    const state = get();
    if (state._pollTimerId || state._resumeCleanup) return; // уже запущен

    // Получить начальную версию (без triggerRefresh)
    const initVersion = async () => {
      const { api } = useAuthStore.getState();
      if (!api) return;
      try {
        const data = await api.getDataVersion();
        if (data?.version) {
          set({ _lastDataVersion: data.version });
        }
      } catch {
        // OK
      }
    };

    if (isNativeCapacitor()) {
      // === МОБИЛКА: проверка при resume ===
      initVersion();

      const setupResumeListener = async () => {
        try {
          const { App } = await import('@capacitor/app');
          const listener = await App.addListener('appStateChange', ({ isActive }) => {
            if (isActive) {
              // Приложение вернулось из background — проверить обновления
              get().checkForUpdates();
            }
          });
          set({ _resumeCleanup: () => listener.remove() });
        } catch {
          // Capacitor App plugin недоступен — fallback на visibilitychange
          const onVisible = () => {
            if (document.visibilityState === 'visible') {
              get().checkForUpdates();
            }
          };
          document.addEventListener('visibilitychange', onVisible);
          set({ _resumeCleanup: () => document.removeEventListener('visibilitychange', onVisible) });
        }
      };
      setupResumeListener();

      // Также слушаем push-уведомления о новых тренировках
      const setupPushListener = async () => {
        try {
          const { PushNotifications } = await import('@capacitor/push-notifications');
          const pushListener = await PushNotifications.addListener('pushNotificationReceived', (notification) => {
            const type = notification?.data?.type;
            // Если push про тренировку/синхронизацию — обновить данные
            if (type === 'workout_sync' || type === 'strava_sync' || type === 'polar_sync' || type === 'coros_sync' || type === 'new_workout') {
              get().checkForUpdates();
            }
          });
          // Сохраним cleanup для push
          const prevCleanup = get()._resumeCleanup;
          set({
            _resumeCleanup: () => {
              if (prevCleanup) prevCleanup();
              pushListener.remove();
            }
          });
        } catch {
          // Push не настроен — OK
        }
      };
      setupPushListener();

    } else {
      // === БРАУЗЕР: polling ===
      initVersion().then(() => {
        const poll = async () => {
          await get().checkForUpdates();
          const timerId = setTimeout(poll, POLL_INTERVAL);
          set({ _pollTimerId: timerId });
        };
        const timerId = setTimeout(poll, POLL_INTERVAL);
        set({ _pollTimerId: timerId });
      });
    }
  },

  /**
   * Остановить автоматическое обновление.
   */
  stopAutoRefresh: () => {
    const { _pollTimerId, _resumeCleanup } = get();
    if (_pollTimerId) {
      clearTimeout(_pollTimerId);
    }
    if (_resumeCleanup) {
      _resumeCleanup();
    }
    set({ _pollTimerId: null, _resumeCleanup: null });
  },

  // Legacy aliases
  startDataPolling: () => get().startAutoRefresh(),
  stopDataPolling: () => get().stopAutoRefresh(),
}));

export default useWorkoutRefreshStore;
