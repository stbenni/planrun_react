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
