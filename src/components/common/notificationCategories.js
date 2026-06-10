/**
 * Категории уведомлений для notification center.
 * Бэкенд хранит произвольный `type` в plan_notifications; фронт мапит его в категорию
 * для табов-фильтров, иконки и цвета. AI/тренерские сообщения приходят из чата (source).
 */

export const NOTIF_CATEGORY = {
  ai: { key: 'ai', label: 'AI' },
  coach: { key: 'coach', label: 'Тренер' },
  workout: { key: 'workout', label: 'Тренировки' },
  achievement: { key: 'achievement', label: 'Достижения' },
  race: { key: 'race', label: 'Старт' },
  plan: { key: 'plan', label: 'План' },
  system: { key: 'system', label: 'Система' },
};

// Порядок табов в панели (присутствующие категории показываются в этом порядке).
export const NOTIF_CATEGORY_ORDER = ['ai', 'coach', 'workout', 'achievement', 'race', 'plan', 'system'];

/**
 * Категория по типу уведомления и источнику.
 * @param {string} type - тип из plan_notifications (или '' для чата)
 * @param {'plan'|'ai'|'coach'} source
 */
export function categoryForType(type, source) {
  if (source === 'ai') return 'ai';
  if (source === 'coach') return 'coach';
  switch (type) {
    case 'chat.ai_message':
    case 'coach.proactive_post_workout_checkin_reply':
      return 'ai';
    case 'chat.admin_message':
    case 'chat.direct_message':
      return 'coach';
    case 'coach_plan_updated':
    case 'athlete_result_logged':
      return 'coach';
    case 'personal_record':
    case 'achievement':
      return 'achievement';
    case 'workout_uploaded':
    case 'strava_import':
      return 'workout';
    case 'race_countdown':
      return 'race';
    case 'plan_ready':
    case 'plan_updated':
      return 'plan';
    default:
      return 'system';
  }
}

const DEFAULT_TITLE = {
  ai: 'AI-тренер',
  coach: 'Тренер',
  workout: 'Тренировка',
  achievement: 'Новое достижение',
  race: 'До старта',
  plan: 'Обновление плана',
  system: 'Уведомление',
};

export function defaultTitleForCategory(category) {
  return DEFAULT_TITLE[category] || DEFAULT_TITLE.system;
}

const DEFAULT_ACTION = {
  ai: 'Открыть чат →',
  coach: 'Ответить →',
  workout: 'Открыть →',
  achievement: 'Посмотреть →',
  race: 'План недели →',
  plan: 'План недели →',
  system: 'Открыть →',
};

export function defaultActionForCategory(category) {
  return DEFAULT_ACTION[category] || DEFAULT_ACTION.system;
}
