/**
 * Store для управления планами тренировок
 * Использует Zustand для глобального состояния
 */

import { create } from 'zustand';
import useAuthStore from './useAuthStore';

const usePlanStore = create((set, get) => ({
  // Состояние
  plan: null,
  loading: false,
  error: null,
  hasPlan: false,
  planStatus: null, // { has_plan: bool, error: string|null }

  // Загрузка плана
  loadPlan: async (userId = null) => {
    const { api } = useAuthStore.getState();
    if (!api) {
      set({ error: 'API client not initialized' });
      return null;
    }

    set({ loading: true, error: null });

    try {
      const planData = await api.getPlan(userId);
      
      set({ 
        plan: planData,
        hasPlan: !!planData && Array.isArray(planData.weeks_data) && planData.weeks_data.length > 0,
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

  // Сохранение плана
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

  // Проверка статуса плана
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
        hasPlan: status?.has_plan || false
      });
      
      return status;
    } catch (error) {
      set({ 
        error: error.message || 'Ошибка проверки статуса плана',
        planStatus: null
      });
      return null;
    }
  },

  // Регенерация плана
  regeneratePlan: async (withProgress = false) => {
    const { api } = useAuthStore.getState();
    if (!api) {
      set({ error: 'API client not initialized' });
      return false;
    }

    set({ loading: true, error: null });

    try {
      if (withProgress) {
        await api.regeneratePlan();
      } else {
        // Для обычной регенерации используем другой endpoint
        await api.request('regenerate_plan', {}, 'POST');
      }
      
      // Перезагружаем план после регенерации
      await get().loadPlan();
      
      set({ loading: false });
      return true;
    } catch (error) {
      set({ 
        error: error.message || 'Ошибка регенерации плана',
        loading: false 
      });
      return false;
    }
  },

  recalculating: false,

  recalculatePlan: async (reason = null) => {
    const { api } = useAuthStore.getState();
    if (!api) {
      set({ error: 'API client not initialized' });
      return false;
    }

    set({ recalculating: true, error: null });

    try {
      await api.recalculatePlan(reason);

      const poll = async (attempts = 0) => {
        if (attempts >= 40) {
          try { await api.request('reactivate_plan', {}, 'POST'); } catch {}
          await get().loadPlan();
          set({ recalculating: false, error: 'Время ожидания пересчёта истекло. План восстановлен.' });
          return false;
        }
        await new Promise(r => setTimeout(r, 5000));
        const status = await api.checkPlanStatus();
        if (status?.has_plan) {
          await get().loadPlan();
          set({ recalculating: false });
          return true;
        }
        if (status?.error) {
          try { await api.request('reactivate_plan', {}, 'POST'); } catch {}
          await get().loadPlan();
          set({ recalculating: false, error: status.error });
          return false;
        }
        return poll(attempts + 1);
      };

      return await poll();
    } catch (error) {
      set({
        error: error.message || 'Ошибка пересчёта плана',
        recalculating: false
      });
      return false;
    }
  },

  generatingNext: false,

  generateNextPlan: async (goals = null) => {
    const { api } = useAuthStore.getState();
    if (!api) {
      set({ error: 'API client not initialized' });
      return false;
    }

    set({ generatingNext: true, error: null });

    try {
      await api.generateNextPlan(goals);

      const poll = async (attempts = 0) => {
        if (attempts >= 50) {
          try { await api.request('reactivate_plan', {}, 'POST'); } catch {}
          await get().loadPlan();
          set({ generatingNext: false, error: 'Время ожидания генерации нового плана истекло. План восстановлен.' });
          return false;
        }
        await new Promise(r => setTimeout(r, 5000));
        const status = await api.checkPlanStatus();
        if (status?.has_plan) {
          await get().loadPlan();
          set({ generatingNext: false });
          return true;
        }
        if (status?.error) {
          try { await api.request('reactivate_plan', {}, 'POST'); } catch {}
          await get().loadPlan();
          set({ generatingNext: false, error: status.error });
          return false;
        }
        return poll(attempts + 1);
      };

      return await poll();
    } catch (error) {
      set({
        error: error.message || 'Ошибка генерации нового плана',
        generatingNext: false
      });
      return false;
    }
  },

  // Очистка плана
  clearPlan: () => {
    set({ 
      plan: null, 
      hasPlan: false, 
      planStatus: null,
      error: null 
    });
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
