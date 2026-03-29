/**
 * Store для управления планами тренировок
 * Единый источник правды о состоянии генерации/пересчёта плана.
 * Компоненты читают isGenerating, generationLabel и т.д. — не дублируют логику.
 */

import { create } from 'zustand';
import useAuthStore from './useAuthStore';
import useWorkoutRefreshStore from './useWorkoutRefreshStore';

const usePlanStore = create((set, get) => ({
  // === Состояние ===
  plan: null,
  loading: false,
  error: null,
  hasPlan: false,
  planStatus: null, // { has_plan, generating, job_type, error, ... }

  // === Флаги генерации ===
  recalculating: false,
  generatingNext: false,

  // Единый computed-like геттер: генерируется ли план прямо сейчас
  // Компоненты используют: usePlanStore(s => s.isGenerating) — НЕ нужно считать самому
  isGenerating: false,

  // Текст для баннера
  generationLabel: '',

  // --- Внутренний хелпер: пересчитать isGenerating и label ---
  _updateGeneratingState: () => {
    const { recalculating, generatingNext, planStatus } = get();
    const fromAction = recalculating || generatingNext;
    const fromStatus = planStatus?.generating === true;
    const isGenerating = fromAction || fromStatus;

    let generationLabel = '';
    if (isGenerating) {
      if (generatingNext || planStatus?.job_type === 'next_plan') {
        generationLabel = 'Генерация нового плана...';
      } else if (recalculating || planStatus?.job_type === 'recalculate') {
        generationLabel = 'Пересчёт плана...';
      } else {
        generationLabel = 'Генерация плана...';
      }
    }

    set({ isGenerating, generationLabel });
  },

  // --- Поллинг после F5 (когда нет action-поллинга) ---
  _pollTimerId: null,

  startStatusPolling: () => {
    const state = get();
    // Не запускаем если уже есть action-поллинг
    if (state.recalculating || state.generatingNext) return;
    // Не запускаем если уже поллим
    if (state._pollTimerId) return;

    const poll = async () => {
      const { api } = useAuthStore.getState();
      if (!api) return;

      const status = await api.checkPlanStatus().catch(() => null);
      if (!status) return;

      set({
        planStatus: status,
        hasPlan: status.has_plan || status.has_old_plan || false,
      });
      get()._updateGeneratingState();

      if (status.has_plan) {
        // План готов — загрузить и остановить поллинг
        set({ _pollTimerId: null });
        await get().loadPlan();
        useWorkoutRefreshStore.getState().triggerRefresh();
        return;
      }

      if (status.error) {
        set({ _pollTimerId: null, error: status.error });
        return;
      }

      // Ещё генерируется — продолжить поллинг
      if (status.generating) {
        const timerId = setTimeout(poll, 5000);
        set({ _pollTimerId: timerId });
      } else {
        set({ _pollTimerId: null });
      }
    };

    const timerId = setTimeout(poll, 3000);
    set({ _pollTimerId: timerId });
  },

  stopStatusPolling: () => {
    const { _pollTimerId } = get();
    if (_pollTimerId) {
      clearTimeout(_pollTimerId);
      set({ _pollTimerId: null });
    }
  },

  // === Инициализация: проверить статус при старте (после F5) ===
  _initPromise: null,

  initPlanStatus: async () => {
    // Дедупликация: не вызывать повторно если уже запущен или завершён
    if (get()._initPromise) return get()._initPromise;

    const { api } = useAuthStore.getState();
    if (!api) return;

    const promise = (async () => {
      try {
        const status = await api.checkPlanStatus();
        set({
          planStatus: status,
          hasPlan: status?.has_plan || status?.has_old_plan || false,
        });
        get()._updateGeneratingState();

        // Если генерация идёт и нет action-поллинга — запустить статус-поллинг
        if (status?.generating && !get().recalculating && !get().generatingNext) {
          get().startStatusPolling();
        }
        return status;
      } catch (error) {
        // Не критично — статус обновится при загрузке dashboard/calendar
        return null;
      }
    })();

    set({ _initPromise: promise });
    return promise;
  },

  applyQueuedPlanState: (queueResult = null) => {
    set({
      loading: false,
      recalculating: false,
      generatingNext: false,
      hasPlan: false,
      plan: null,
      planStatus: {
        has_plan: false,
        generating: true,
        queued: true,
        job_id: queueResult?.job_id ?? null,
        job_type: queueResult?.job_type ?? null,
      }
    });
    get()._updateGeneratingState();
  },

  // === Загрузка плана ===
  loadPlan: async (userId = null) => {
    const { api } = useAuthStore.getState();
    if (!api) {
      set({ error: 'API client not initialized' });
      return null;
    }

    set({ loading: true, error: null });

    try {
      const raw = await api.getPlan(userId);
      const planData = raw?.data ?? raw;
      const hasPlan = !!planData && Array.isArray(planData.weeks_data) && planData.weeks_data.length > 0;

      set({
        plan: planData,
        hasPlan,
        loading: false
      });

      return planData;
    } catch (error) {
      set({
        error: error.message || 'Ошибка загрузки плана',
        loading: false
      });
      return null;
    }
  },

  // === Сохранение плана ===
  savePlan: async (planData) => {
    const { api } = useAuthStore.getState();
    if (!api) {
      set({ error: 'API client not initialized' });
      return false;
    }

    set({ loading: true, error: null });

    try {
      await api.savePlan(planData);

      set({
        plan: planData,
        hasPlan: true,
        loading: false
      });

      return true;
    } catch (error) {
      set({
        error: error.message || 'Ошибка сохранения плана',
        loading: false
      });
      return false;
    }
  },

  // === Проверка статуса плана ===
  checkPlanStatus: async (userId = null) => {
    const { api } = useAuthStore.getState();
    if (!api) {
      set({ error: 'API client not initialized' });
      return null;
    }

    try {
      const status = await api.checkPlanStatus(userId);

      set({
        planStatus: status,
        hasPlan: status?.has_plan || status?.has_old_plan || false
      });
      get()._updateGeneratingState();

      return status;
    } catch (error) {
      set({
        error: error.message || 'Ошибка проверки статуса плана',
        planStatus: null
      });
      get()._updateGeneratingState();
      return null;
    }
  },

  // === Регенерация плана ===
  regeneratePlan: async (withProgress = false) => {
    const { api } = useAuthStore.getState();
    if (!api) {
      set({ error: 'API client not initialized' });
      return false;
    }

    set({ loading: true, error: null, planStatus: { has_plan: false, generating: true } });
    get()._updateGeneratingState();

    try {
      let result;
      if (withProgress) {
        result = await api.regeneratePlan();
      } else {
        result = await api.request('regenerate_plan', {}, 'POST');
      }
      get().applyQueuedPlanState(result);
      useWorkoutRefreshStore.getState().triggerRefresh();
      return true;
    } catch (error) {
      set({
        error: error.message || 'Ошибка регенерации плана',
        loading: false,
        planStatus: get().planStatus?.generating ? { has_plan: false } : get().planStatus
      });
      get()._updateGeneratingState();
      return false;
    }
  },

  // === Пересчёт плана ===
  recalculatePlan: async (reason = null) => {
    const { api } = useAuthStore.getState();
    if (!api) {
      set({ error: 'API client not initialized' });
      return false;
    }

    set({ recalculating: true, error: null, planStatus: { has_plan: false, generating: true, job_type: 'recalculate' } });
    get()._updateGeneratingState();

    try {
      await api.recalculatePlan(reason);

      const poll = async (attempts = 0) => {
        if (attempts >= 40) {
          try { await api.request('reactivate_plan', {}, 'POST'); } catch {}
          await get().loadPlan();
          set({ recalculating: false, planStatus: { has_plan: true }, error: 'Время ожидания пересчёта истекло. План восстановлен.' });
          get()._updateGeneratingState();
          return false;
        }
        await new Promise(r => setTimeout(r, 5000));
        const status = await api.checkPlanStatus();
        if (status?.has_plan) {
          await get().loadPlan();
          set({ recalculating: false, planStatus: { has_plan: true } });
          get()._updateGeneratingState();
          useWorkoutRefreshStore.getState().triggerRefresh();
          return true;
        }
        if (status?.error) {
          try { await api.request('reactivate_plan', {}, 'POST'); } catch {}
          await get().loadPlan();
          set({ recalculating: false, planStatus: { has_plan: true, error: status.error }, error: status.error });
          get()._updateGeneratingState();
          return false;
        }
        return poll(attempts + 1);
      };

      return await poll();
    } catch (error) {
      set({
        error: error.message || 'Ошибка пересчёта плана',
        recalculating: false,
        planStatus: get().planStatus?.generating ? { has_plan: false } : get().planStatus
      });
      get()._updateGeneratingState();
      return false;
    }
  },

  // === Генерация следующего плана ===
  generateNextPlan: async (goals = null) => {
    const { api } = useAuthStore.getState();
    if (!api) {
      set({ error: 'API client not initialized' });
      return false;
    }

    set({ generatingNext: true, error: null, planStatus: { has_plan: false, generating: true, job_type: 'next_plan' } });
    get()._updateGeneratingState();

    try {
      await api.generateNextPlan(goals);

      const poll = async (attempts = 0) => {
        if (attempts >= 50) {
          try { await api.request('reactivate_plan', {}, 'POST'); } catch {}
          await get().loadPlan();
          set({ generatingNext: false, planStatus: { has_plan: true }, error: 'Время ожидания генерации нового плана истекло. План восстановлен.' });
          get()._updateGeneratingState();
          return false;
        }
        await new Promise(r => setTimeout(r, 5000));
        const status = await api.checkPlanStatus();
        if (status?.has_plan) {
          await get().loadPlan();
          set({ generatingNext: false, planStatus: { has_plan: true } });
          get()._updateGeneratingState();
          useWorkoutRefreshStore.getState().triggerRefresh();
          return true;
        }
        if (status?.error) {
          try { await api.request('reactivate_plan', {}, 'POST'); } catch {}
          await get().loadPlan();
          set({ generatingNext: false, planStatus: { has_plan: true, error: status.error }, error: status.error });
          get()._updateGeneratingState();
          return false;
        }
        return poll(attempts + 1);
      };

      return await poll();
    } catch (error) {
      set({
        error: error.message || 'Ошибка генерации нового плана',
        generatingNext: false,
        planStatus: get().planStatus?.generating ? { has_plan: false } : get().planStatus
      });
      get()._updateGeneratingState();
      return false;
    }
  },

  // === Очистка плана ===
  clearPlan: () => {
    set({
      plan: null,
      hasPlan: false,
      planStatus: { has_plan: false },
      error: null,
      isGenerating: false,
      generationLabel: '',
    });
  },

  /** Установить статус «проверено, плана нет» */
  setPlanStatusChecked: (hasPlan = false) => {
    set({
      plan: null,
      hasPlan,
      planStatus: { has_plan: hasPlan },
      error: null,
      loading: false
    });
    get()._updateGeneratingState();
  },

  // Установка плана (для оптимистичных обновлений)
  setPlan: (planData) => {
    set({
      plan: planData,
      hasPlan: !!planData && Array.isArray(planData.weeks_data) && planData.weeks_data.length > 0
    });
  }
}));

export default usePlanStore;
