export const DASHBOARD_MODULE_IDS = ['today_workout', 'quick_metrics', 'next_workout', 'coach_tip', 'race_prediction', 'training_load', 'calendar', 'stats'];

export const DASHBOARD_MODULE_LABELS = {
  today_workout: 'Сегодняшняя тренировка',
  quick_metrics: 'Быстрые метрики',
  next_workout: 'Следующая тренировка',
  coach_tip: 'Совет тренера',
  race_prediction: 'Прогноз на забег',
  training_load: 'Тренировочная нагрузка',
  calendar: 'Календарь',
  stats: 'Статистика',
};

export const STORAGE_KEY = 'planrun_dashboard_modules';
export const PAIRABLE_MODULE_IDS = new Set(['today_workout', 'next_workout', 'stats', 'training_load']);
