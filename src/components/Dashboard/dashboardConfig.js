export const DASHBOARD_MODULE_IDS = ['goal_countdown', 'today_workout', 'next_workout', 'trend_compare', 'personal_records', 'race_prediction', 'training_load', 'calendar', 'stats'];

export const DASHBOARD_MODULE_LABELS = {
  goal_countdown: 'Обратный отсчёт',
  today_workout: 'Сегодняшняя тренировка',
  next_workout: 'Следующая тренировка',
  trend_compare: 'Тренд месяца',
  personal_records: 'Личные рекорды',
  race_prediction: 'Прогноз на забег',
  training_load: 'Тренировочная нагрузка',
  calendar: 'Календарь',
  stats: 'Статистика',
};

export const STORAGE_KEY = 'planrun_dashboard_modules';
export const PAIRABLE_MODULE_IDS = new Set(['today_workout', 'next_workout', 'stats', 'training_load', 'trend_compare']);
