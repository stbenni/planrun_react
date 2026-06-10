/**
 * Онбординг (специализация) — стейт формы, константы и сборка payload.
 * Поведение 1:1 с прежним RegisterScreen (specializationOnly): тот же набор полей и та же
 * нормализация перед отправкой в completeSpecialization. Визуал переехал на дизайн v3,
 * логика данных не меняется.
 */

/** Следующий понедельник в формате YYYY-MM-DD (дефолтная дата старта тренировок). */
export function getNextMonday() {
  const today = new Date();
  const day = today.getDay();
  const diff = day === 0 ? 1 : 8 - day; // вс → +1, иначе до следующего понедельника
  const nextMonday = new Date(today);
  nextMonday.setDate(today.getDate() + diff);
  return nextMonday.toISOString().split('T')[0];
}

/** Начальное состояние формы специализации (полный набор полей бэкенда). */
export function createInitialOnboardingState() {
  return {
    // Шаг «Режим» (без значения по умолчанию — выбирает пользователь)
    training_mode: '',

    // Шаг «Цель»
    goal_type: '',
    race_distance: '',
    race_date: '',
    race_target_time: '',
    weight_goal_kg: '',
    weight_goal_date: '',
    health_program: '',
    health_plan_weeks: '',
    training_start_date: getNextMonday(),

    // Шаг «Профиль»
    first_name: '',
    last_name: '',
    gender: null,
    birth_year: '',
    height_cm: '',
    weight_kg: '',
    experience_level: 'novice',
    weekly_base_km: '', // итоговое число для бэка (из чипа-диапазона или точного поля)
    weekly_base_range: '', // выбранный чип-диапазон ('' | 'none' | 'lt10' | ...)
    sessions_per_week: '',
    preferred_days: [],
    will_do_ofp: 'no', // 'yes' | 'no' — вопросы ОФП показываем только при 'yes'; тоггл выключен = 'no'
    preferred_ofp_days: [],
    ofp_preference: '',
    training_time_pref: '',
    has_treadmill: false,
    health_notes: '',

    // Расширенный профиль (race / time_improvement)
    has_race_history: '', // '' | 'yes' | 'no' — развилка: бегал ли на официальном забеге
    easy_pace_min: '', // строка MM:SS для отображения
    easy_pace_sec: '', // секунды для БД
    is_first_race_at_distance: false, // выводится автоматически из развилки + дистанции
    last_race_distance: '',
    last_race_distance_km: '',
    last_race_time: '',
    last_race_date: '',
  };
}

/**
 * Сид для онбординга при СМЕНЕ режима: пустой стейт + предвыбранный режим +
 * предзаполнение метриками, которые у пользователя уже есть (имя, цель, гонка,
 * пол/возраст/рост/вес/опыт). Недостающее (то, что регистрация не собирает)
 * пользователь дозаполнит в шагах. Сетим только непустые значения.
 */
export function seedOnboardingFromUser(user, mode) {
  const base = createInitialOnboardingState();
  if (mode) base.training_mode = mode;
  if (!user) return base;
  const set = (key, val) => { if (val != null && val !== '') base[key] = val; };
  set('first_name', user.first_name);
  set('last_name', user.last_name);
  set('goal_type', user.goal_type);
  set('race_distance', user.race_distance);
  set('race_date', user.race_date);
  set('race_target_time', user.race_target_time);
  set('gender', user.gender);
  set('birth_year', user.birth_year);
  set('height_cm', user.height_cm);
  set('weight_kg', user.weight_kg);
  set('experience_level', user.experience_level);
  set('weekly_base_km', user.weekly_base_km);
  if (Array.isArray(user.preferred_days) && user.preferred_days.length) {
    base.preferred_days = user.preferred_days;
    base.sessions_per_week = String(user.preferred_days.length);
  }
  return base;
}

/**
 * Готовит payload для completeSpecialization — та же нормализация, что в старом
 * handleSubmitSpecialization: булевы → 0/1, sessions_per_week из числа выбранных дней.
 */
export function buildSpecializationPayload(formData) {
  return {
    ...formData,
    preferred_days: formData.preferred_days,
    preferred_ofp_days: formData.preferred_ofp_days,
    has_treadmill: formData.has_treadmill ? 1 : 0,
    is_first_race_at_distance: formData.is_first_race_at_distance ? 1 : 0,
    sessions_per_week: formData.preferred_days?.length || formData.sessions_per_week || null,
  };
}

/** true для режимов, где генерируется AI-план (для текста/экрана генерации). */
export function isPlanGenerationMode(trainingMode) {
  return trainingMode === 'ai';
}

/** Дни недели: ключ для БД → подпись. */
export const DAY_LABELS = {
  mon: 'Пн', tue: 'Вт', wed: 'Ср', thu: 'Чт', fri: 'Пт', sat: 'Сб', sun: 'Вс',
};

/** Цели — порядок и тексты как в текущем RegisterScreen (иконки прикручиваются в StepGoal). */
export const GOALS = [
  { value: 'health', iconKey: 'heart', title: 'Здоровье', desc: 'Бегать для тонуса и формы' },
  { value: 'race', iconKey: 'medal', title: 'Забег', desc: 'Подготовиться к дистанции' },
  { value: 'weight_loss', iconKey: 'flame', title: 'Похудение', desc: 'Скинуть лишний вес' },
  { value: 'time_improvement', iconKey: 'time', title: 'Улучшить время', desc: 'Пробежать быстрее' },
];

/** Программы для цели «Здоровье» (полный список из текущего кода). */
export const HEALTH_PROGRAMS = [
  { value: 'start_running', iconKey: 'leaf', name: 'Начни бегать', duration: '8 недель', desc: 'С нуля до 20 минут непрерывного бега' },
  { value: 'couch_to_5k', iconKey: 'running', name: '5 км без остановки', duration: '10 недель', desc: 'Классическая программа Couch to 5K' },
  { value: 'regular_running', iconKey: 'heart', name: 'Регулярный бег', duration: '12 недель', desc: '3 раза в неделю, плавный рост объёма' },
  { value: 'custom', iconKey: 'settings', name: 'Свой план', duration: 'по выбору', desc: 'Укажу параметры сам' },
];

/** Срок «своей» программы (custom). */
export const HEALTH_PLAN_WEEKS = [
  { value: '4', label: '4 недели (пробный)' },
  { value: '8', label: '8 недель (базовый)' },
  { value: '12', label: '12 недель (полный курс)' },
  { value: '16', label: '16 недель (расширенный)' },
];

/** Уровни опыта. */
export const EXPERIENCE_LEVELS = [
  { value: 'novice', iconKey: 'leaf', title: 'Новичок', period: '< 3 мес' },
  { value: 'beginner', iconKey: 'walking', title: 'Начинающий', period: '3–6 мес' },
  { value: 'intermediate', iconKey: 'running', title: 'Средний', period: '6–12 мес' },
  { value: 'advanced', iconKey: 'zap', title: 'Продвинутый', period: '1–2 года' },
  { value: 'expert', iconKey: 'trophy', title: 'Опытный', period: '2+ года' },
];

/**
 * Диапазоны текущего недельного объёма (чипы вместо точного числа — для нерегулярно
 * бегающих новичков точное число превращается в гадание).
 * `km` — значение для бэка = СЕРЕДИНА диапазона. `exact` — у верхних диапазонов
 * показываем опциональное точное поле для продвинутых.
 */
export const WEEKLY_VOLUME_RANGES = [
  { value: 'none', label: 'Не бегаю', km: 0 },
  { value: 'lt10', label: 'до 10', km: 5 },
  { value: '10_25', label: '10–25', km: 17 },
  { value: '25_40', label: '25–40', km: 32 },
  { value: '40_60', label: '40–60', km: 50, exact: true },
  { value: 'gt60', label: '60+', km: 70, exact: true },
];

/** Целевые дистанции забега. */
export const RACE_DISTANCES = [
  { value: '5k', label: '5 км' },
  { value: '10k', label: '10 км' },
  { value: 'half', label: 'Полумарафон (21.1 км)' },
  { value: 'marathon', label: 'Марафон (42.2 км)' },
];

/** Дистанции последнего результата (расширенный профиль). */
export const LAST_RACE_DISTANCES = [
  { value: '', label: 'Не указано' },
  { value: '5k', label: '5 км' },
  { value: '10k', label: '10 км' },
  { value: 'half', label: 'Полумарафон' },
  { value: 'marathon', label: 'Марафон' },
  { value: 'other', label: 'Другая' },
];

/** Где удобно делать ОФП. */
export const OFP_PREFERENCES = [
  { value: '', label: 'Не важно' },
  { value: 'gym', label: 'В тренажерном зале (с тренажерами)' },
  { value: 'home', label: 'Дома самостоятельно' },
  { value: 'both', label: 'И в зале, и дома' },
  { value: 'group_classes', label: 'Групповые занятия' },
  { value: 'online', label: 'Онлайн-платформы' },
];

/** Предпочитаемое время тренировок. */
export const TRAINING_TIMES = [
  { value: '', label: 'Не важно' },
  { value: 'morning', label: 'Утро' },
  { value: 'day', label: 'День' },
  { value: 'evening', label: 'Вечер' },
];

/** Быстрые чипы темпа (мин:сек/км). */
export const PACE_QUICK_CHIPS = ['5:00', '6:00', '7:00', '8:00'];
