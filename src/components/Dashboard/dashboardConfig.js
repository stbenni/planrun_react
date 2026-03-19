export const DASHBOARD_MODULE_IDS = ['today_workout', 'quick_metrics', 'next_workout', 'race_prediction', 'calendar', 'stats'];

export const DASHBOARD_MODULE_LABELS = {
  today_workout: 'Сегодняшняя тренировка',
  quick_metrics: 'Быстрые метрики',
  next_workout: 'Следующая тренировка',
  race_prediction: 'Прогноз на забег',
  calendar: 'Календарь',
  stats: 'Статистика',
};

export const STORAGE_KEY = 'planrun_dashboard_modules';
export const PAIRABLE_MODULE_IDS = new Set(['today_workout', 'next_workout', 'stats']);
