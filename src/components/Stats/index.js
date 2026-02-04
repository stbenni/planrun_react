/**
 * Статистические компоненты и утилиты
 * 
 * Переиспользуемые модули для отображения статистики тренировок
 * Можно использовать в любом месте приложения: Dashboard, Calendar, Profile и т.д.
 */

// Компоненты графиков
export { default as ActivityHeatmap } from './ActivityHeatmap';
export { default as DistanceChart } from './DistanceChart';
export { default as WeeklyProgressChart } from './WeeklyProgressChart';
export { default as HeartRateChart } from './HeartRateChart';
export { default as PaceChart } from './PaceChart';

// Компоненты списков
export { default as RecentWorkoutsList } from './RecentWorkoutsList';
export { default as AchievementCard } from './AchievementCard';

// Модальные окна
export { default as WorkoutDetailsModal } from './WorkoutDetailsModal';

// Утилиты
export {
  getDaysFromRange,
  formatDateStr,
  formatPace,
  processStatsData,
  processProgressData,
  processAchievementsData
} from './StatsUtils';
