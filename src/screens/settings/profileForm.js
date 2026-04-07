import { createInitialNotificationSettings } from './notificationSettings';

export function normalizeValue(value) {
  if (value === null || value === undefined || value === '' || value === 'null') {
    return null;
  }
  return value;
}

export function createInitialFormData() {
  return {
    username: '',
    email: '',
    gender: '',
    birth_year: '',
    height_cm: '',
    weight_kg: '',
    max_hr: '',
    rest_hr: '',
    timezone: 'Europe/Moscow',
    goal_type: 'health',
    race_distance: '',
    race_date: '',
    race_target_time: '',
    target_marathon_date: '',
    target_marathon_time: '',
    weight_goal_kg: '',
    weight_goal_date: '',
    experience_level: 'novice',
    weekly_base_km: '',
    sessions_per_week: '',
    preferred_days: [],
    preferred_ofp_days: [],
    has_treadmill: false,
    training_time_pref: '',
    ofp_preference: '',
    training_mode: 'ai',
    coach_style: 'motivational',
    training_start_date: '',
    health_notes: '',
    health_program: '',
    health_plan_weeks: '',
    easy_pace_min: '',
    easy_pace_sec: '',
    is_first_race_at_distance: false,
    last_race_distance: '',
    last_race_distance_km: '',
    last_race_time: '',
    last_race_date: '',
    avatar_path: '',
    privacy_level: 'public',
    privacy_show_email: true,
    privacy_show_trainer: true,
    privacy_show_calendar: true,
    privacy_show_metrics: true,
    privacy_show_workouts: true,
    telegram_id: '',
    push_workouts_enabled: 1,
    push_chat_enabled: 1,
    push_workout_hour: 20,
    push_workout_minute: 0,
    notification_settings: createInitialNotificationSettings(),
  };
}

function parsePreferredDays(value, key = null) {
  if (!value) return [];
  if (typeof value === 'string') {
    try {
      const parsed = JSON.parse(value);
      if (Array.isArray(parsed)) return parsed;
      if (key && Array.isArray(parsed?.[key])) return parsed[key];
    } catch {
      return [];
    }
    return [];
  }
  if (Array.isArray(value)) return value;
  if (key && Array.isArray(value?.[key])) return value[key];
  return [];
}

function formatTime(time) {
  if (!time) return '';
  const str = String(time).trim();

  if (/^\d{2}:\d{2}:\d{2}$/.test(str)) {
    const [hoursPart, minutesPart, secondsPart] = str.split(':');
    const hours = parseInt(hoursPart, 10);
    const minutes = parseInt(minutesPart, 10);
    const seconds = parseInt(secondsPart, 10);

    if ([hours, minutes, seconds].some((value) => Number.isNaN(value))) {
      return '';
    }

    if (minutes > 59 || seconds > 59) {
      return '';
    }

    if (hours <= 23) {
      return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
    }

    // MySQL TIME может хранить длительность как 49:27:00.
    // Для time-input это невалидно, поэтому трактуем такие значения как duration
    // и переводим в укладывающийся формат HH:mm:ss.
    const totalSeconds = (hours * 60) + minutes + Math.floor(seconds / 60);
    const normalizedHours = Math.floor(totalSeconds / 3600);
    const normalizedMinutes = Math.floor((totalSeconds % 3600) / 60);
    const normalizedSeconds = totalSeconds % 60;

    return `${String(normalizedHours).padStart(2, '0')}:${String(normalizedMinutes).padStart(2, '0')}:${String(normalizedSeconds).padStart(2, '0')}`;
  }

  if (/^\d{1,2}:\d{2}:\d{2}$/.test(str)) {
    const [hours, minutes, seconds] = str.split(':');
    return `${hours.padStart(2, '0')}:${minutes}:${seconds}`;
  }

  if (/^\d{1,3}:\d{2}$/.test(str)) {
    const [majorPart, minorPart] = str.split(':');
    const major = parseInt(majorPart, 10);
    const minor = parseInt(minorPart, 10);
    if (Number.isNaN(major) || Number.isNaN(minor)) return '';

    // Для длительности вида 49:27 или 75:00 переводим в HH:mm:ss,
    // а короткие целевые времена вроде 3:30 / 1:45 оставляем как HH:mm.
    if (major <= 6) {
      return `${String(major).padStart(2, '0')}:${String(minor).padStart(2, '0')}`;
    }

    const totalSeconds = (major * 60) + minor;
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;

    return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
  }

  return '';
}

function formatDate(date) {
  if (!date) return '';
  const str = String(date);
  return /^\d{4}-\d{2}-\d{2}$/.test(str) ? str : '';
}

function normalizeRaceDistance(dist) {
  if (!dist) return '';
  const value = String(dist).toLowerCase().trim();
  if (value === 'marathon' || value === '5k' || value === '10k' || value === 'half') return value;
  if (value.includes('марафон') || value.includes('42.2') || value.includes('42')) return 'marathon';
  if (value.includes('полумарафон') || value.includes('21.1') || value.includes('21')) return 'half';
  if (value.includes('10') && !value.includes('5') && !value.includes('42')) return '10k';
  if (value.includes('5') && !value.includes('10') && !value.includes('42')) return '5k';
  return '';
}

function normalizeExperienceLevel(level) {
  const value = String(level || 'beginner');
  if (value === 'beginner') return 'beginner';
  if (value === 'intermediate') return 'intermediate';
  if (value === 'advanced') return 'advanced';
  if (['novice', 'expert'].includes(value)) return value;
  return 'novice';
}

function formatEasyPaceMinutes(easyPaceSec) {
  if (easyPaceSec === null || easyPaceSec === undefined || easyPaceSec === '') return '';
  const seconds = parseInt(easyPaceSec, 10);
  if (Number.isNaN(seconds)) return '';
  const minutes = Math.floor(seconds / 60);
  const remainder = seconds % 60;
  return `${minutes}:${String(remainder).padStart(2, '0')}`;
}

export function mapProfileToFormData(userData = {}) {
  return {
    username: String(userData.username || ''),
    email: String(userData.email || ''),
    gender: String(userData.gender || ''),
    birth_year: userData.birth_year ? String(userData.birth_year) : '',
    height_cm: userData.height_cm ? String(userData.height_cm) : '',
    weight_kg: userData.weight_kg ? String(userData.weight_kg) : '',
    max_hr: userData.max_hr ? String(userData.max_hr) : '',
    rest_hr: userData.rest_hr ? String(userData.rest_hr) : '',
    timezone: String(userData.timezone || 'Europe/Moscow'),
    goal_type: String(userData.goal_type || 'health'),
    race_distance: normalizeRaceDistance(userData.race_distance),
    race_date: formatDate(userData.race_date),
    race_target_time: formatTime(userData.race_target_time),
    target_marathon_date: formatDate(userData.target_marathon_date),
    target_marathon_time: formatTime(userData.target_marathon_time),
    weight_goal_kg: userData.weight_goal_kg ? String(userData.weight_goal_kg) : '',
    weight_goal_date: formatDate(userData.weight_goal_date),
    experience_level: normalizeExperienceLevel(userData.experience_level),
    weekly_base_km: userData.weekly_base_km ? String(userData.weekly_base_km) : '',
    sessions_per_week: userData.sessions_per_week ? String(userData.sessions_per_week) : '',
    preferred_days: parsePreferredDays(userData.preferred_days, 'run'),
    preferred_ofp_days: parsePreferredDays(userData.preferred_ofp_days, 'ofp'),
    has_treadmill: Boolean(userData.has_treadmill === 1 || userData.has_treadmill === true),
    training_time_pref: String(userData.training_time_pref || ''),
    ofp_preference: String(userData.ofp_preference || ''),
    training_mode: String(userData.training_mode || 'ai'),
    coach_style: String(userData.coach_style || 'motivational'),
    training_start_date: formatDate(userData.training_start_date),
    health_notes: String(userData.health_notes || ''),
    health_program: String(userData.health_program || ''),
    health_plan_weeks: userData.health_plan_weeks ? String(userData.health_plan_weeks) : '',
    easy_pace_sec: userData.easy_pace_sec !== null && userData.easy_pace_sec !== undefined && userData.easy_pace_sec !== '' ? String(userData.easy_pace_sec) : '',
    easy_pace_min: formatEasyPaceMinutes(userData.easy_pace_sec),
    is_first_race_at_distance: Boolean(userData.is_first_race_at_distance === 1 || userData.is_first_race_at_distance === true),
    last_race_distance: String(userData.last_race_distance || ''),
    last_race_distance_km: userData.last_race_distance_km ? String(userData.last_race_distance_km) : '',
    last_race_time: formatTime(userData.last_race_time),
    last_race_date: formatDate(userData.last_race_date),
    avatar_path: String(userData.avatar_path || ''),
    privacy_level: String(userData.privacy_level || 'public'),
    privacy_show_email: Boolean(userData.privacy_show_email !== 0 && userData.privacy_show_email !== '0'),
    privacy_show_trainer: Boolean(userData.privacy_show_trainer !== 0 && userData.privacy_show_trainer !== '0'),
    privacy_show_calendar: Boolean(userData.privacy_show_calendar !== 0 && userData.privacy_show_calendar !== '0'),
    privacy_show_metrics: Boolean(userData.privacy_show_metrics !== 0 && userData.privacy_show_metrics !== '0'),
    privacy_show_workouts: Boolean(userData.privacy_show_workouts !== 0 && userData.privacy_show_workouts !== '0'),
    username_slug: String(userData.username_slug || userData.username || ''),
    public_token: String(userData.public_token || ''),
    telegram_id: userData.telegram_id ? String(userData.telegram_id) : '',
    push_workouts_enabled: userData.push_workouts_enabled !== 0 && userData.push_workouts_enabled !== '0' ? 1 : 0,
    push_chat_enabled: userData.push_chat_enabled !== 0 && userData.push_chat_enabled !== '0' ? 1 : 0,
    push_workout_hour: Math.min(23, Math.max(0, parseInt(userData.push_workout_hour, 10) || 20)),
    push_workout_minute: Math.min(59, Math.max(0, parseInt(userData.push_workout_minute, 10) || 0)),
    notification_settings: createInitialNotificationSettings(String(userData.timezone || 'Europe/Moscow')),
  };
}

export const daysOfWeek = [
  { value: 'mon', label: 'Пн' },
  { value: 'tue', label: 'Вт' },
  { value: 'wed', label: 'Ср' },
  { value: 'thu', label: 'Чт' },
  { value: 'fri', label: 'Пт' },
  { value: 'sat', label: 'Сб' },
  { value: 'sun', label: 'Вс' },
];
