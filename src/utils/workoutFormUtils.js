/**
 * Общие утилиты для форм тренировок.
 * Используются в AddTrainingModal, ResultModal, WorkoutDetailsModal и др.
 */

// --- Время: парсинг ЧЧ:ММ:СС или ММ:СС → секунды ---

export function parseTime(timeStr) {
  if (!timeStr || !String(timeStr).trim()) return null;
  const parts = String(timeStr).trim().split(':').map((p) => parseInt(p, 10));
  if (parts.some((n) => Number.isNaN(n) || n < 0)) return null;
  if (parts.length === 3) {
    const [h, m, s] = parts;
    if (m >= 60 || s >= 60) return null;
    return h * 3600 + m * 60 + s;
  }
  if (parts.length === 2) {
    const [m, s] = parts;
    if (m >= 60 || s >= 60) return null;
    return m * 60 + s;
  }
  return null;
}

export function formatTime(totalSeconds) {
  if (totalSeconds == null || totalSeconds < 0) return '';
  const h = Math.floor(totalSeconds / 3600);
  const m = Math.floor((totalSeconds % 3600) / 60);
  const s = Math.round(totalSeconds % 60);
  return `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
}

// --- Темп: парсинг MM:SS или M → минуты на км; вывод всегда MM:SS ---

export function parsePace(paceStr) {
  if (!paceStr || !String(paceStr).trim()) return null;
  const s = String(paceStr).trim();
  const parts = s.split(':');
  if (parts.length === 1) {
    const m = parseFloat(parts[0], 10);
    if (Number.isNaN(m) || m < 0) return null;
    return m;
  }
  if (parts.length !== 2) return null;
  const m = parseInt(parts[0], 10);
  const sec = parseInt(parts[1], 10);
  if (Number.isNaN(m) || Number.isNaN(sec) || m < 0 || sec < 0 || sec >= 60) return null;
  return m + sec / 60;
}

export function formatPace(minutesPerKm) {
  if (minutesPerKm == null || minutesPerKm <= 0) return '';
  const m = Math.floor(minutesPerKm);
  const s = Math.round((minutesPerKm - m) * 60);
  return `${m}:${String(s).padStart(2, '0')}`;
}

// --- Маски ввода ---

/** Маска ввода времени: только цифры → чч:мм:сс (до 6 цифр) */
export function maskTimeInput(value) {
  const digits = String(value).replace(/\D/g, '').slice(0, 6);
  if (digits.length === 0) return '';
  if (digits.length === 1) return digits;
  if (digits.length === 2) return `${digits}:`;
  if (digits.length === 3) return `${digits.slice(0, 2)}:${digits[2]}`;
  if (digits.length === 4) return `${digits.slice(0, 2)}:${digits.slice(2)}`;
  if (digits.length === 5) return `${digits.slice(0, 2)}:${digits.slice(2, 4)}:${digits[4]}`;
  return `${digits.slice(0, 2)}:${digits.slice(2, 4)}:${digits.slice(4, 6)}`;
}

/** Маска ввода темпа: только цифры → ММ:СС (до 4 цифр). "5" → "5", "53" → "5:3", "530" → "5:30" */
export function maskPaceInput(value) {
  const digits = String(value).replace(/\D/g, '').slice(0, 4);
  if (digits.length === 0) return '';
  if (digits.length <= 2) return digits.length === 1 ? digits : `${digits[0]}:${digits[1]}`;
  if (digits.length === 3) return `${digits[0]}:${digits.slice(1)}`;
  return `${digits.slice(0, 2)}:${digits.slice(2)}`;
}

// --- Константы типов тренировок ---

export const RUN_TYPES = ['easy', 'tempo', 'long', 'long-run', 'interval', 'fartlek', 'control', 'race'];
export const SIMPLE_RUN_TYPES = ['easy', 'tempo', 'long', 'control', 'race'];

export const TYPE_LABELS = {
  easy: 'Легкий бег',
  tempo: 'Темповый бег',
  long: 'Длительный бег',
  'long-run': 'Длительный бег',
  interval: 'Интервалы',
  fartlek: 'Фартлек',
  control: 'Контрольный забег',
  race: 'Соревнование',
};

export const ACTIVITY_TYPE_LABELS = {
  run: 'Бег',
  running: 'Бег',
  walking: 'Ходьба',
  hiking: 'Поход',
  cycling: 'Велосипед',
  swimming: 'Плавание',
  ofp: 'ОФП',
  sbu: 'СБУ',
  easy: 'Легкий бег',
  long: 'Длительный бег',
  'long-run': 'Длительный бег',
  tempo: 'Темповый бег',
  interval: 'Интервалы',
  fartlek: 'Фартлек',
  race: 'Соревнование',
  control: 'Контрольный забег',
  other: 'ОФП',
  rest: 'Отдых',
  free: 'Пустой день',
};

export const SOURCE_LABELS = {
  strava: 'Strava',
  huawei: 'Huawei Health',
  polar: 'Polar',
  gpx: 'GPX-файл',
};

export function getActivityTypeLabel(activityType) {
  if (!activityType) return '';
  const key = String(activityType).toLowerCase().trim();
  return ACTIVITY_TYPE_LABELS[key] || activityType;
}

/** plan type (easy, long, tempo) приоритетнее activity_type (running) */
export function getWorkoutDisplayType(workout) {
  const planType = workout?.type;
  const activityType = workout?.activity_type;
  if (planType && ACTIVITY_TYPE_LABELS[String(planType).toLowerCase().trim()]) {
    return planType;
  }
  return activityType || planType;
}

export function getSourceLabel(source) {
  if (!source) return null;
  const key = String(source).toLowerCase();
  return SOURCE_LABELS[key] || source;
}
