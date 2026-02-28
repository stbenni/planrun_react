/**
 * Store для глобального обновления данных тренировок.
 * Вызывается после: saveResult, addTrainingDayByDate, updateTrainingDay,
 * syncWorkouts, deleteWorkout. Все экраны (Dashboard, Calendar, Stats, UserProfile)
 * подписаны и обновляют данные при изменении version.
 */

import { create } from 'zustand';

const useWorkoutRefreshStore = create((set) => ({
  version: 0,

  triggerRefresh: () => {
    set((s) => ({ version: s.version + 1 }));
  },
}));

export default useWorkoutRefreshStore;
