const ACTIVITY_TYPE_LABELS = {
  run: 'Бег', running: 'Бег', walking: 'Ходьба', hiking: 'Поход',
  cycling: 'Велосипед', swimming: 'Плавание', ofp: 'ОФП', sbu: 'СБУ',
  easy: 'Лёгкий бег', long: 'Длительный бег', 'long-run': 'Длительный бег',
  tempo: 'Темповый бег', interval: 'Интервалы', fartlek: 'Фартлек',
  race: 'Соревнование', control: 'Контрольный забег', other: 'ОФП',
  rest: 'Отдых', free: 'Пустой день',
};

export function getActivityTypeLabel(workout) {
  if (!workout) return 'Тренировка';
  const planType = workout.type;
  const key = planType ? String(planType).toLowerCase().trim() : '';
  if (key && ACTIVITY_TYPE_LABELS[key]) return ACTIVITY_TYPE_LABELS[key];
  const activity = workout.activity_type ? String(workout.activity_type).toLowerCase().trim() : '';
  return ACTIVITY_TYPE_LABELS[activity] || workout.activity_type || planType || 'Тренировка';
}

export function getRoutePoints(timeline) {
  if (!Array.isArray(timeline)) return [];
  return timeline
    .map((p) => ({ lat: Number(p?.latitude), lng: Number(p?.longitude) }))
    .filter((p) => Number.isFinite(p.lat) && Number.isFinite(p.lng)
      && p.lat >= -90 && p.lat <= 90 && p.lng >= -180 && p.lng <= 180
      && !(Math.abs(p.lat) < 0.0001 && Math.abs(p.lng) < 0.0001));
}

function fmtDuration(workout) {
  let total = null;
  if (workout.duration_seconds != null && workout.duration_seconds > 0) total = Math.round(Number(workout.duration_seconds));
  else if (workout.duration_minutes != null && workout.duration_minutes > 0) total = Math.round(Number(workout.duration_minutes) * 60);
  if (total == null) return null;
  const h = Math.floor(total / 3600);
  const m = Math.floor((total % 3600) / 60);
  const s = total % 60;
  return h > 0
    ? `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`
    : `${m}:${String(s).padStart(2, '0')}`;
}

function num(v) { return v != null && Number.isFinite(Number(v)) ? Number(v) : null; }

function fmtDateRu(workout, date) {
  const base = workout?.start_time ? new Date(workout.start_time) : (date ? new Date(`${date}T12:00:00`) : null);
  if (!base || Number.isNaN(base.getTime())) return '';
  return base.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' });
}

/**
 * Модель для генератора шеринга: набор доступных метрик + маршрут.
 * metrics — массив { key, label, value, unit }; только реально доступные.
 */
export function buildShareModel({ date, workout, timeline }) {
  if (!workout) return null;
  const metrics = [];
  const push = (key, label, value, unit) => { if (value != null && value !== '') metrics.push({ key, label, value, unit: unit || '' }); };

  const dist = num(workout.distance_km ?? workout.distance);
  if (dist != null && dist > 0) push('distance', 'Дистанция', dist.toFixed(2).replace('.', ','), 'км');
  push('time', 'Время', fmtDuration(workout), '');
  if (workout.avg_pace || workout.pace) push('pace', 'Темп', String(workout.avg_pace ?? workout.pace), '/км');
  const hr = num(workout.avg_heart_rate);
  if (hr) push('hr', 'Пульс', String(Math.round(hr)), 'уд/м');
  const maxhr = num(workout.max_heart_rate);
  if (maxhr) push('maxhr', 'Макс. пульс', String(Math.round(maxhr)), 'уд/м');
  const cal = num(workout.calories);
  if (cal) push('calories', 'Калории', String(Math.round(cal)), 'ккал');
  const elev = num(workout.elevation_gain);
  if (elev) push('elevation', 'Набор', String(Math.round(elev)), 'м');
  const cad = num(workout.cadence);
  if (cad) push('cadence', 'Каденс', String(Math.round(cad)), 'шаг/м');

  return {
    typeLabel: getActivityTypeLabel(workout),
    dateStr: fmtDateRu(workout, date),
    metrics,
    routePoints: getRoutePoints(timeline),
  };
}

export default buildShareModel;
