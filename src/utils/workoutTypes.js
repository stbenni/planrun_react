export const WORKOUT_TYPE_LABEL = {
  easy: 'Лёгкий бег',
  recovery: 'Восстановительный бег',
  tempo: 'Темповый бег',
  long: 'Лонг',
  'long-run': 'Лонг',
  interval: 'Интервалы',
  fartlek: 'Фартлек',
  control: 'Контрольный',
  race: 'Соревнование',
  sbu: 'СБУ',
  other: 'ОФП',
  ofp: 'ОФП',
  strength: 'ОФП',
  cross: 'Кросс-тренинг',
  rest: 'Отдых',
  free: 'Свободный',
  walking: 'Ходьба',
  hiking: 'Поход',
  cycling: 'Велосипед',
  swimming: 'Плавание',
  run: 'Бег',
  running: 'Бег',
};

export const WORKOUT_TYPE_COLOR = {
  easy: 'var(--workout-easy)',
  recovery: 'var(--workout-easy)',
  tempo: 'var(--workout-tempo)',
  long: 'var(--workout-long)',
  'long-run': 'var(--workout-long)',
  interval: 'var(--workout-interval)',
  fartlek: 'var(--workout-interval)',
  control: 'var(--workout-control)',
  race: 'var(--primary-500)',
  sbu: 'var(--workout-strip-sbu)',
  other: 'var(--workout-strip-ofp)',
  ofp: 'var(--workout-strip-ofp)',
  strength: 'var(--workout-strip-ofp)',
  cross: 'var(--workout-strip-ofp)',
  walking: 'var(--workout-strip-walking)',
  hiking: 'var(--workout-strip-hiking)',
  rest: 'var(--workout-rest)',
  free: 'var(--workout-rest)',
};

export function typeLabel(type) {
  return WORKOUT_TYPE_LABEL[String(type || '').toLowerCase().trim()] || 'Тренировка';
}

export function typeColorVar(type) {
  return WORKOUT_TYPE_COLOR[String(type || '').toLowerCase().trim()] || 'var(--text-tertiary)';
}
