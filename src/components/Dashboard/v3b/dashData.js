/**
 * Слой производных данных дашборда v3B. Логика перенесена 1:1 из секций v3
 * (WeekSectionV3 / TodayHeroV3 / GoalSectionV3 / FormSectionV3 / PRSectionV3 / StatsSectionV3),
 * визуальные статусы переведены на токены --pr-*.
 */

export const DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
export const DOW_SHORT = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
const MONTHS_GEN = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];

export const TYPE_LABELS = {
  rest: 'Отдых', tempo: 'Темповая', interval: 'Интервалы', long: 'Длительная',
  race: 'Гонка', other: 'ОФП', free: 'Свободно', easy: 'Лёгкая', sbu: 'СБУ',
  fartlek: 'Фартлек', control: 'Контрольная', walking: 'Ходьба', recovery: 'Восстановительная',
};

/** Название типа для заголовка «Темповый · 10 км». */
export const TYPE_TITLE = {
  easy: 'Лёгкий', tempo: 'Темповый', interval: 'Интервалы', long: 'Длительный',
  race: 'Гонка', fartlek: 'Фартлек', control: 'Контрольный', walking: 'Ходьба',
  free: 'Свободный', recovery: 'Восстановительный',
};

export const MODE_LABEL = { ai: 'AI-тренер', coach: 'Тренер', self: 'Сам' };
export const MODE_GLYPH = { ai: 'AI', coach: 'СК', self: '✎' };

export const DIST_LABELS = {
  '5k': '5 км', '10k': '10 км', half: 'Полумарафон', half_marathon: 'Полумарафон',
  marathon: 'Марафон', ultra: 'Ультра', '21.1k': 'Полумарафон', '42.2k': 'Марафон',
};
export const USER_DIST_TO_KEY = {
  '5k': '5k', '10k': '10k', half: 'half', half_marathon: 'half',
  marathon: 'marathon', '21.1k': 'half', '42.2k': 'marathon',
};

export function isoDay(d) {
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
}

export function greeting() {
  const h = new Date().getHours();
  if (h < 5) return 'Доброй ночи';
  if (h < 12) return 'Доброе утро';
  if (h < 18) return 'Добрый день';
  return 'Добрый вечер';
}

export function formatHeaderDate() {
  const d = new Date();
  const dows = ['Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота'];
  return `${dows[d.getDay()]}, ${d.getDate()} ${MONTHS_GEN[d.getMonth()]}`;
}

export function formatRaceDate(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  return `${d.getDate()} ${MONTHS_GEN[d.getMonth()]}`;
}

export function daysToRace(iso) {
  if (!iso) return null;
  const t = Date.parse(iso);
  if (Number.isNaN(t)) return null;
  return Math.max(0, Math.ceil((t - Date.now()) / 86400000));
}

// ---------- неделя ----------

function allPlanWeeks(plan) {
  const phases = Array.isArray(plan?.phases) ? plan.phases : null;
  return phases
    ? phases.flatMap((p) => (Array.isArray(p?.weeks_data) ? p.weeks_data : []))
    : (Array.isArray(plan?.weeks_data) ? plan.weeks_data : []);
}

export function extractKm(text) {
  if (!text) return 0;
  for (const line of String(text).split(/\r?\n/)) {
    const m = line.match(/(?:^|\s)(\d+(?:[.,]\d+)?)\s*км(?=$|[\s·,.])/i);
    if (m) {
      const v = parseFloat(m[1].replace(',', '.'));
      if (Number.isFinite(v) && v > 0) return v;
    }
  }
  return 0;
}

/**
 * workoutsByDate[date] — это сводка-ОБЪЕКТ за день из get_all_workouts_summary:
 * { count, distance (км), duration (минуты), duration_seconds, pace, hr, activity_type }.
 * Нормализуем в массив на случай и старого (массивного) формата.
 */
function dayItems(value) {
  if (!value) return [];
  return Array.isArray(value) ? value : [value];
}

function itemKm(it) {
  return Number(it?.distance ?? it?.distance_km) || 0;
}

function itemMinutes(it) {
  if (!it) return 0;
  if (it.duration_seconds) return (Number(it.duration_seconds) || 0) / 60;
  return Number(it.duration ?? it.duration_minutes) || 0;
}

function isDayDone(dateIso, workoutsByDate, progressDataMap) {
  if (progressDataMap && progressDataMap[dateIso]) return true;
  const items = dayItems(workoutsByDate?.[dateIso]);
  return items.some((it) => (it.count ?? 1) > 0 || itemKm(it) > 0 || itemMinutes(it) > 0);
}

/**
 * Модель текущей недели для точек: 7 дней со статусами
 * done · today · missed · plan · rest (язык статусов v3B).
 */
export function buildWeekModel(plan, workoutsByDate, progressDataMap) {
  const weeks = allPlanWeeks(plan);
  if (weeks.length === 0) return null;
  const todayIso = isoDay(new Date());
  const currentWeek = weeks.find((w) => {
    if (!w?.start_date) return false;
    const start = new Date(w.start_date);
    const end = new Date(start);
    end.setDate(start.getDate() + 6);
    return todayIso >= isoDay(start) && todayIso <= isoDay(end);
  }) || weeks[0];

  const start = new Date(currentWeek.start_date);
  const days = [];
  let doneKm = 0;
  for (let i = 0; i < 7; i++) {
    const d = new Date(start);
    d.setDate(start.getDate() + i);
    const iso = isoDay(d);
    const items = currentWeek.days?.[DAY_KEYS[i]];
    const arr = Array.isArray(items) ? items : (items ? [items] : []);
    const primary = arr.find((it) => it && it.type !== 'rest' && it.type !== 'free') || arr[0] || null;
    const type = primary?.type || 'rest';
    const isRest = type === 'rest' || type === 'free';
    const km = extractKm(primary?.text || primary?.description || '');
    const done = isDayDone(iso, workoutsByDate, progressDataMap);
    if (done) {
      for (const it of dayItems(workoutsByDate?.[iso])) doneKm += itemKm(it);
    }
    let state;
    if (done) state = 'done';
    else if (iso === todayIso) state = isRest ? 'rest' : 'today';
    else if (isRest) state = 'rest';
    else if (iso < todayIso) state = 'missed';
    else state = 'plan';
    days.push({ date: iso, dow: DOW_SHORT[i], km, state, type });
  }

  return {
    days,
    weekNumber: currentWeek.number || currentWeek.week_number || null,
    planKm: Math.round(parseFloat(String(currentWeek.total_volume || '').replace(/[^\d.]/g, '')) || 0),
    doneKm: Math.round(doneKm),
  };
}

/** Серия выполненных плановых тренировок подряд (стрик). Отдых не рвёт серию. */
export function computeStreak(plan, workoutsByDate, progressDataMap) {
  const weeks = allPlanWeeks(plan);
  if (weeks.length === 0) return 0;
  const todayIso = isoDay(new Date());
  const planned = [];
  for (const w of weeks) {
    if (!w?.start_date) continue;
    const start = new Date(w.start_date);
    for (let i = 0; i < 7; i++) {
      const d = new Date(start);
      d.setDate(start.getDate() + i);
      const iso = isoDay(d);
      if (iso > todayIso) continue;
      const items = w.days?.[DAY_KEYS[i]];
      const arr = Array.isArray(items) ? items : (items ? [items] : []);
      if (arr.some((it) => it && it.type && it.type !== 'rest' && it.type !== 'free')) planned.push(iso);
    }
  }
  planned.sort().reverse();
  let streak = 0;
  for (const iso of planned) {
    const done = isDayDone(iso, workoutsByDate, progressDataMap);
    if (done) streak += 1;
    else if (iso === todayIso) continue; // сегодня ещё не вечер — не рвём серию
    else break;
  }
  return streak;
}

// ---------- метрики / спарклайны ----------

function kmOfItems(value) {
  let km = 0;
  for (const it of dayItems(value)) km += itemKm(it);
  return km;
}

/** Недельные суммы км за N недель (включая текущую), старые → новые. */
export function weeklyKmSeries(workoutsByDate, weeks = 8) {
  if (!workoutsByDate) return null;
  const out = new Array(weeks).fill(0);
  const now = Date.now();
  for (const [date, items] of Object.entries(workoutsByDate)) {
    const t = Date.parse(date);
    if (Number.isNaN(t)) continue;
    const weeksAgo = Math.floor((now - t) / (7 * 86400000));
    if (weeksAgo >= 0 && weeksAgo < weeks) out[weeks - 1 - weeksAgo] += kmOfItems(items);
  }
  const nonZero = out.filter((v) => v > 0).length;
  return nonZero >= 2 ? out.map((v) => Math.round(v)) : null;
}

/** Средний темп (сек/км) по неделям за N недель — для спарклайна. */
export function weeklyPaceSeries(workoutsByDate, weeks = 8) {
  if (!workoutsByDate) return null;
  const km = new Array(weeks).fill(0);
  const min = new Array(weeks).fill(0);
  const now = Date.now();
  for (const [date, items] of Object.entries(workoutsByDate)) {
    const t = Date.parse(date);
    if (Number.isNaN(t)) continue;
    const w = Math.floor((now - t) / (7 * 86400000));
    if (w < 0 || w >= weeks) continue;
    for (const it of dayItems(items)) {
      const d = itemKm(it);
      const m = itemMinutes(it);
      if (d > 0 && m > 0) { km[weeks - 1 - w] += d; min[weeks - 1 - w] += m; }
    }
  }
  const pts = [];
  for (let i = 0; i < weeks; i++) {
    if (km[i] > 0 && min[i] > 0) pts.push(Math.round((min[i] / km[i]) * 60));
  }
  return pts.length >= 2 ? pts : null;
}

/** Статистика за 30 дней: км, минуты, средний темп. */
export function stats30d(workoutsByDate) {
  if (!workoutsByDate) return { km: 0, minutes: 0, pace: null };
  let km = 0;
  let minutes = 0;
  const now = Date.now();
  for (const [date, items] of Object.entries(workoutsByDate)) {
    const t = Date.parse(date);
    if (Number.isNaN(t) || now - t > 30 * 86400000 || t > now) continue;
    for (const it of dayItems(items)) {
      km += itemKm(it);
      minutes += itemMinutes(it);
    }
  }
  let pace = null;
  if (km > 0 && minutes > 0) {
    const sec = Math.round((minutes / km) * 60);
    pace = `${Math.floor(sec / 60)}:${String(sec % 60).padStart(2, '0')}`;
  }
  return { km: Math.round(km), minutes: Math.round(minutes), pace };
}

// ---------- форма (TSB) ----------

export function tsbStatus(tsb) {
  if (tsb >= 5) return { label: 'Свежий', color: 'var(--pr-good)' };
  if (tsb >= -10) return { label: 'Норма', color: 'var(--pr-ink)' };
  if (tsb >= -20) return { label: 'Усталость', color: 'var(--pr-accent)' };
  return { label: 'Перегруз', color: 'var(--pr-bad)' };
}

/** Готовность 0–100 из TSB: линейная проекция диапазона −40…+20 → 8…97. */
export function readinessFromTsb(tsb) {
  if (tsb == null || Number.isNaN(tsb)) return null;
  return Math.max(8, Math.min(97, Math.round(68 + 1.45 * tsb)));
}

export function tsbAdvice(tsb) {
  if (tsb >= 5) return 'Восстановление полное — сегодня можно ключевую работу на пороге.';
  if (tsb >= -10) return 'Форма в норме. Работай по плану — нагрузка сбалансирована.';
  if (tsb >= -20) return 'Накоплена усталость — держи лёгкие зоны, не форсируй темп.';
  return 'Перегруз. Приоритет — восстановление, сон и лёгкая активность.';
}

export function acwrLabel(status) {
  if (status === 'optimal') return { text: 'оптимально', color: 'var(--pr-good)' };
  if (status === 'detrained') return { text: 'мало', color: 'var(--pr-accent)' };
  if (status === 'caution') return { text: 'риск', color: 'var(--pr-accent)' };
  if (status === 'risk') return { text: 'перегруз', color: 'var(--pr-bad)' };
  return { text: '', color: 'var(--pr-sub)' };
}

// ---------- цель ----------

export function parseHhMmSs(t) {
  if (!t) return null;
  const parts = String(t).split(':').map((p) => parseInt(p, 10) || 0);
  if (parts.length === 3) return parts[0] * 3600 + parts[1] * 60 + parts[2];
  if (parts.length === 2) return parts[0] * 60 + parts[1];
  return null;
}

function formatDeltaSec(sec) {
  const abs = Math.abs(sec);
  if (abs < 60) return `${abs} сек`;
  const m = Math.floor(abs / 60);
  const s = abs % 60;
  return s === 0 ? `${m} мин` : `${m}:${String(s).padStart(2, '0')}`;
}

export function computeDelta(targetTime, predictedTime) {
  const targetSec = parseHhMmSs(targetTime);
  const predSec = parseHhMmSs(predictedTime);
  if (targetSec == null || predSec == null) return null;
  const diff = predSec - targetSec;
  if (diff < 0) return { faster: true, text: `−${formatDeltaSec(diff)}` };
  if (diff > 0) return { slower: true, text: `+${formatDeltaSec(diff)}` };
  return { text: 'точно цель' };
}

export function weeksProgress(plan) {
  const weeks = allPlanWeeks(plan);
  const total = weeks.length;
  if (total === 0) return { weeksDone: 0, weeksTotal: 0 };
  const todayIso = isoDay(new Date());
  let done = 0;
  for (const w of weeks) {
    if (!w?.start_date) continue;
    const end = new Date(w.start_date);
    end.setDate(end.getDate() + 6);
    if (isoDay(end) < todayIso) done += 1;
  }
  return { weeksDone: done, weeksTotal: total };
}

export function derivePhase(weeksDone, weeksTotal) {
  if (!weeksTotal || weeksTotal <= 0) return null;
  const pct = weeksDone / weeksTotal;
  if (pct < 0.25) return 'базовая';
  if (pct < 0.5) return 'развивающая';
  if (pct < 0.8) return 'пиковая';
  if (pct < 1) return 'подводка';
  return 'старт';
}

// ---------- описание тренировки ----------

/** Парсинг описания дня плана: км/темп/время/интервалы/строка-название. */
export function parseDescription(text) {
  if (!text) return { km: null, pace: null, dur: null, title: null, intervals: null };
  const lines = String(text).split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
  let km = null;
  let pace = null;
  let dur = null;
  let titleLine = null;
  let intervals = null;
  for (const line of lines) {
    if (km == null) {
      const m = line.match(/(\d+(?:[.,]\d+)?)\s*км(?=$|[\s·,.])/i);
      if (m) km = parseFloat(m[1].replace(',', '.'));
    }
    if (pace == null) {
      const m = line.match(/(\d{1,2}:\d{2})\s*(?:мин\/км|\/км)/i);
      if (m) pace = m[1];
    }
    if (dur == null) {
      const m = line.match(/(\d{1,2}):(\d{2}):(\d{2})/);
      if (m) dur = parseInt(m[1], 10) * 60 + parseInt(m[2], 10);
    }
    if (intervals == null) {
      const m = line.match(/(\d+)\s*[×x]\s*(\d+(?:[.,]\d+)?)\s*(к?м)/i);
      if (m) {
        const reps = parseInt(m[1], 10);
        const dist = parseFloat(m[2].replace(',', '.'));
        intervals = { reps, dist, unit: m[3].toLowerCase() === 'км' ? 'км' : 'м', text: `${reps}×${dist} ${m[3]}` };
      }
    }
    if (!titleLine) {
      const digitsCount = (line.match(/\d/g) || []).length;
      const hasWords = /[А-Яа-яёЁA-Za-z]{4,}/.test(line);
      if (hasWords && digitsCount <= 2 && !/Темп\b/i.test(line)) {
        titleLine = line[0].toUpperCase() + line.slice(1);
      }
    }
  }
  return { km, pace, dur, title: titleLine, intervals };
}

export function formatKm(km) {
  if (km == null) return '—';
  const n = Number(km);
  if (!Number.isFinite(n)) return '—';
  return (Number.isInteger(n) ? String(n) : n.toFixed(1)).replace('.', ',');
}

// ---------- личные рекорды ----------

const PR_SLOTS = [
  { label: '5 км', min: 4.5, max: 5.5 },
  { label: '10 км', min: 8.5, max: 11.5 },
  { label: '21,1', min: 19.5, max: 22.5 },
  { label: '42,2', min: 40, max: 44 },
];

function recordTimeSec(r) {
  if (r?.time_sec != null) return Number(r.time_sec) || Infinity;
  const t = r?.result_time || r?.time;
  if (!t) return Infinity;
  const parts = String(t).split(':').map((p) => parseInt(p, 10) || 0);
  if (parts.length === 3) return parts[0] * 3600 + parts[1] * 60 + parts[2];
  if (parts.length === 2) return parts[0] * 60 + parts[1];
  return Infinity;
}

function formatRecordTime(r) {
  if (r?.result_time || r?.time) return r.result_time || r.time;
  const sec = recordTimeSec(r);
  if (!Number.isFinite(sec)) return null;
  const h = Math.floor(sec / 3600);
  const m = Math.floor((sec % 3600) / 60);
  const s = sec % 60;
  if (h > 0) return `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
  return `${m}:${String(s).padStart(2, '0')}`;
}

/** records[] → слоты дистанций с лучшим временем (пустые отбрасываем). */
export function mapRecords(records) {
  return PR_SLOTS.map((slot) => {
    const matches = (records || []).filter((r) => {
      const d = Number(r.distance_km || r.distance);
      return d >= slot.min && d <= slot.max;
    });
    if (matches.length === 0) return null;
    const best = matches.reduce((b, m) => (recordTimeSec(m) < recordTimeSec(b) ? m : b));
    const dateIso = best.date || best.training_date;
    return {
      dist: slot.label,
      time: formatRecordTime(best),
      fresh: dateIso ? (Date.now() - Date.parse(String(dateIso).replace(' ', 'T'))) < 14 * 86400000 : false,
    };
  }).filter(Boolean);
}
