/**
 * Store для управления тренировками
 * Использует Zustand для глобального состояния
 */

import { create } from 'zustand';
import useAuthStore from './useAuthStore';

const useWorkoutStore = create((set, get) => ({
  // Состояние
  workouts: {}, // { date: workoutData }
  allResults: [], // Все результаты тренировок
  currentDay: null, // Данные текущего дня
  loading: false,
  error: null,

  // Загрузка всех результатов
  loadAllResults: async () => {
    const { api } = useAuthStore.getState();
    if (!api) {
      set({ error: 'API client not initialized' });
      return [];
    }

    set({ loading: true, error: null });

    try {
      const results = await api.getAllResults();
      
      // Преобразуем в объект для быстрого доступа
      const workoutsMap = {};
      results.forEach(result => {
        if (result.date) {
          workoutsMap[result.date] = result;
        }
      });
      
      set({ 
        allResults: results,
        workouts: workoutsMap,
        loading: false 
      });
      
      return results;
    } catch (error) {
      set({ 
        error: error.message || 'Ошибка загрузки результатов',
        loading: false 
      });
      return [];
    }
  },

  // Загрузка данных дня
  loadDay: async (date) => {
    const { api } = useAuthStore.getState();
    if (!api) {
      set({ error: 'API client not initialized' });
      return null;
    }

    set({ loading: true, error: null });

    try {
      const dayData = await api.getDay(date);
      
      set({ 
        currentDay: dayData,
        loading: false 
      });
      
      return dayData;
    } catch (error) {
      set({ 
        error: error.message || 'Ошибка загрузки дня',
        loading: false 
      });
      return null;
    }
  },

  // Сохранение результата тренировки
  // api.saveResult ожидает один объект { date, week, day, activity_type_id?, ... }
  saveResult: async (date, result) => {
    const { api } = useAuthStore.getState();
    if (!api) {
      set({ error: 'API client not initialized' });
      return false;
    }

    set({ loading: true, error: null });

    try {
      const payload = typeof result === 'object' && result !== null ? { ...result, date } : { date };
      await api.saveResult(payload);
      
      // Обновляем локальное состояние
      const { workouts } = get();
      set({ 
        workouts: {
          ...workouts,
          [date]: { ...workouts[date], ...result, date }
        },
        loading: false 
      });
      
      // Обновляем allResults
      const { allResults } = get();
      const existingIndex = allResults.findIndex(r => r.date === date);
      if (existingIndex >= 0) {
        allResults[existingIndex] = { ...allResults[existingIndex], ...result, date };
      } else {
        allResults.push({ ...result, date });
      }
      set({ allResults: [...allResults] });
      
      return true;
    } catch (error) {
      set({ 
        error: error.message || 'Ошибка сохранения результата',
        loading: false 
      });
      return false;
    }
  },

  // Сброс результата
  resetResult: async (date) => {
    const { api } = useAuthStore.getState();
    if (!api) {
      set({ error: 'API client not initialized' });
      return false;
    }

    set({ loading: true, error: null });

    try {
      await api.reset(date);
      
      // Удаляем из локального состояния
      const { workouts, allResults } = get();
      const newWorkouts = { ...workouts };
      delete newWorkouts[date];
      
      const newAllResults = allResults.filter(r => r.date !== date);
      
      set({ 
        workouts: newWorkouts,
        allResults: newAllResults,
        loading: false 
      });
      
      return true;
    } catch (error) {
      set({ 
        error: error.message || 'Ошибка сброса результата',
        loading: false 
      });
      return false;
    }
  },

  // Получение результата по дате
  getResult: (date) => {
    const { workouts } = get();
    return workouts[date] || null;
  },

  // Проверка, есть ли результат для даты
  hasResult: (date) => {
    const { workouts } = get();
    return !!workouts[date];
  },

  // Очистка состояния
  clearWorkouts: () => {
    set({ 
      workouts: {},
      allResults: [],
      currentDay: null,
      error: null 
    });
  }
}));

export default useWorkoutStore;
