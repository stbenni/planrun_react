export const PROACTIVE_TYPE_LABELS = {
  post_workout_analysis: 'Анализ тренировки',
  daily_briefing: 'План на сегодня',
  weekly_digest: 'Итоги недели',
  pause: 'Пауза в тренировках',
  overload: 'Риск перегрузки',
  overload_warning: 'Рост нагрузки',
  race_approaching: 'Забег скоро',
  low_compliance: 'Выполнение плана',
  distance_record: 'Рекорд дистанции',
  vdot_improvement: 'Рост формы',
  volume_record: 'Рекорд объёма',
  consistency_streak: 'Серия тренировок',
  goal_achievable: 'Цель достижима',
};

export function getProactiveTypeLabel(type, fallback = 'Совет тренера') {
  return PROACTIVE_TYPE_LABELS[type] || fallback;
}
