/* ───────────────────────────────────────────────────────────────────────
   Calendar v3 — общие константы и хелперы (порт redesign/src/v3-calendar.jsx).
   Тип-цвета мапятся на --workout-* токены; фаза выводится эвристикой
   (как derivePhase в Dashboard/v3); объём из week.total_volume (строка "X км").
   ─────────────────────────────────────────────────────────────────────── */

import {
  getPlanDayForDate,
  getDayCompletionStatus,
} from '../../../utils/calendarHelpers';
import { WORKOUT_TYPE_LABEL, typeLabel, typeColorVar } from '../../../utils/workoutTypes';

export { typeLabel, typeColorVar };
export const TYPE_LABEL = WORKOUT_TYPE_LABEL;

/** Фазы мезоцикла: ключ → { label, color }. */
export const PHASES = {
  base:     { label: 'База',           color: 'var(--workout-easy)' },
  build:    { label: 'Развивающая',    color: 'var(--primary-500)' },
  peak:     { label: 'Пиковая',        color: 'var(--workout-interval)' },
  taper:    { label: 'Подводка',       color: 'var(--workout-long)' },
  recovery: { label: 'Восстановление', color: 'var(--workout-control)' },
};
// Линейная прогрессия мезоцикла для phase-полосы (recovery — deload, вне линейки).
export const PHASE_ORDER = ['base', 'build', 'peak', 'taper'];

/** Фаза по позиции недели в плане (эвристика, как derivePhase в Dashboard/v3). */
export function derivePhaseKey(weeksDone, weeksTotal) {
  if (!weeksTotal || weeksTotal <= 0) return null;
  const pct = weeksDone / weeksTotal;
  if (pct < 0.25) return 'base';
  if (pct < 0.5) return 'build';
  if (pct < 0.8) return 'peak';
  return 'taper';
}

/** Реальное название фазы (RU/EN) → ключ. Forward-compatible: если бэк начнёт
 *  отдавать week.phase, фаза станет точной; иначе используется эвристика. */
export function phaseNameToKey(name) {
  const s = String(name || '').toLowerCase().trim();
  if (!s) return null;
  if (s.includes('баз') || s.includes('base')) return 'base';
  if (s.includes('развив') || s.includes('build') || s.includes('progress')) return 'build';
  if (s.includes('пик') || s.includes('peak')) return 'peak';
  if (s.includes('восстан') || s.includes('recover') || s.includes('deload') || s.includes('разгруз')) return 'recovery';
  if (s.includes('подвод') || s.includes('taper')) return 'taper';
  return null;
}

/** Объём недели из строки total_volume ("52.0 км") → число км. */
export function parseVolumeKm(totalVolume) {
  if (totalVolume == null) return 0;
  const n = parseFloat(String(totalVolume).replace(/[^\d.]/g, ''));
  return Number.isFinite(n) ? n : 0;
}

/** Извлечь км/темп из текста плана (описание дня). */
export function parsePlanMetrics(text) {
  const plain = String(text || '').replace(/<[^>]*>/g, ' ');
  // Темп: "5:30/км", "Темп: 5:30", "в темпе 5:30"
  let pace = null;
  const paceM = plain.match(/(\d{1,2}:\d{2})\s*(?:\/\s*км|мин\/км|\/км)/i)
    || plain.match(/темп[:\s~]*(\d{1,2}:\d{2})/i)
    || plain.match(/@\s*(\d{1,2}:\d{2})/);
  if (paceM) pace = paceM[1];
  // Дистанция: число + км, но не часть темпа (исключаем "MM:SS км")
  let km = null;
  const cleaned = plain.replace(/\d{1,2}:\d{2}\s*\/?\s*км/gi, ' ');
  const kmM = cleaned.match(/(\d+(?:[.,]\d+)?)\s*км/i)
    || plain.match(/(\d+(?:[.,]\d+)?)\s*километр/i);
  if (kmM) km = parseFloat(kmM[1].replace(',', '.'));
  return { km: Number.isFinite(km) ? km : null, pace };
}

/** Очистить HTML и схлопнуть пробелы. */
export function stripHtml(s) {
  return String(s || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
}

export const RUN_SEGMENT_TYPES = ['interval', 'fartlek', 'tempo', 'easy', 'recovery', 'long', 'long-run'];

function fmtSegKm(n) {
  const v = Math.round(n * 10) / 10;
  return `${Number.isInteger(v) ? v : v.toFixed(1)} км`;
}

export function buildRunSegments({ type, text, km, pace } = {}) {
  if (!RUN_SEGMENT_TYPES.includes(type)) return null;
  const txt = stripHtml(text);
  const totalKm = km || null;

  const warmM = txt.match(/размин\w*[^.\d]{0,20}?(\d+(?:[.,]\d+)?)\s*км/i);
  const coolM = txt.match(/замин\w*[^.\d]{0,20}?(\d+(?:[.,]\d+)?)\s*км/i);
  const warmKm = warmM ? parseFloat(warmM[1].replace(',', '.')) : null;
  const coolKm = coolM ? parseFloat(coolM[1].replace(',', '.')) : null;
  const isReps = type === 'interval' || type === 'fartlek';

  const repD = isReps && txt.match(/(\d+)\s*[×xх]\s*(\d+(?:[.,]\d+)?)\s*(км|м)(?![а-яёa-z])/i);
  if (repD) {
    const reps = parseInt(repD[1], 10);
    const distNum = parseFloat(repD[2].replace(',', '.'));
    const unit = repD[3].toLowerCase();
    const workKm = unit === 'км' ? distNum : distNum / 1000;
    if (reps && reps <= 30 && workKm) {
      const recM = txt.match(/(?:восст|пауз|отдых|трусц)\w*[^.\d]{0,20}?(\d+(?:[.,]\d+)?)\s*(км|м)(?![а-яёa-z])/i);
      const warm = warmKm ?? 2;
      const cool = coolKm ?? 2;
      const rec = recM
        ? (recM[2].toLowerCase() === 'км' ? parseFloat(recM[1].replace(',', '.')) : parseFloat(recM[1].replace(',', '.')) / 1000)
        : Math.max(0.2, workKm * 0.4);
      const segs = [{ type: 'easy', w: warm }];
      for (let i = 0; i < reps; i++) {
        segs.push({ type, w: workKm });
        if (i < reps - 1) segs.push({ type: 'easy', w: rec });
      }
      segs.push({ type: 'easy', w: cool });
      return { segs, caption: `Разминка ${fmtSegKm(warm)} → ${repD[2]} ${unit} × ${reps} (восст.) → заминка ${fmtSegKm(cool)}` };
    }
  }

  const repT = isReps && txt.match(/(\d+)\s*[×xх]\s*(\d+(?:[.,]\d+)?)\s*(мин|сек|с)(?![а-яёa-z])/i);
  if (repT) {
    const reps = parseInt(repT[1], 10);
    const valNum = parseFloat(repT[2].replace(',', '.'));
    const unit = repT[3].toLowerCase();
    const workMin = unit === 'мин' ? valNum : valNum / 60;
    if (reps && reps <= 40 && workMin) {
      const recM = txt.match(/(?:восст|пауз|отдых|трусц)\w*[^.\d]{0,20}?(\d+(?:[.,]\d+)?)\s*(мин|сек|с)(?![а-яёa-z])/i);
      const recMin = recM
        ? (recM[2].toLowerCase() === 'мин' ? parseFloat(recM[1].replace(',', '.')) : parseFloat(recM[1].replace(',', '.')) / 60)
        : Math.max(0.5, workMin);
      const warmMin = (warmKm ?? 2) * 6;
      const coolMin = (coolKm ?? 2) * 6;
      const segs = [{ type: 'easy', w: warmMin }];
      for (let i = 0; i < reps; i++) {
        segs.push({ type, w: workMin });
        if (i < reps - 1) segs.push({ type: 'easy', w: recMin });
      }
      segs.push({ type: 'easy', w: coolMin });
      return { segs, caption: `Разминка → ${repT[2]} ${unit} × ${reps} (восст.) → заминка` };
    }
  }

  if (!totalKm) return null;

  const isTempo = type === 'tempo';
  const warm = warmKm ?? (isTempo ? Math.min(2, Math.round(totalKm * 0.2 * 10) / 10) : 0);
  const cool = coolKm ?? (isTempo ? Math.min(2, Math.round(totalKm * 0.2 * 10) / 10) : 0);
  const work = Math.max(0.3, Math.round((totalKm - warm - cool) * 10) / 10);
  const segs = [];
  if (warm > 0) segs.push({ type: 'easy', w: warm });
  segs.push({ type, w: work });
  if (cool > 0) segs.push({ type: 'easy', w: cool });
  if (segs.length === 1) {
    return { segs, caption: pace ? `Равномерно в темпе ${pace}/км` : `Равномерный бег · ${fmtSegKm(totalKm)}` };
  }
  const core = isTempo ? `${fmtSegKm(work)} в темпе` : fmtSegKm(work);
  return { segs, caption: `Разминка ${fmtSegKm(warm)} → ${core} → заминка ${fmtSegKm(cool)}` };
}

// ── Date / week helpers ──────────────────────────────────────────────
export function ymd(date) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}
export function addDays(dateStr, delta) {
  const d = new Date(dateStr + 'T00:00:00');
  d.setDate(d.getDate() + delta);
  return ymd(d);
}
export function todayYmd() {
  const t = new Date();
  t.setHours(0, 0, 0, 0);
  return ymd(t);
}
export function getMondayForDate(dateStr) {
  const d = new Date(dateStr + 'T00:00:00');
  const dow = d.getDay();
  const diff = dow === 0 ? -6 : 1 - dow;
  return addDays(dateStr, diff);
}
export function getMondayOfToday() {
  return getMondayForDate(todayYmd());
}

const EMPTY_DAYS = { mon: null, tue: null, wed: null, thu: null, fri: null, sat: null, sun: null };

/** Виртуальная (пустая) неделя для даты. */
export function getVirtualWeek(startDateStr) {
  return { number: 0, start_date: startDateStr, total_volume: '', days: { ...EMPTY_DAYS } };
}

/** Неделя из плана для понедельника startDateStr, иначе виртуальная. */
export function getWeekForStartDate(plan, startDateStr) {
  const weeksData = plan?.weeks_data;
  if (Array.isArray(weeksData)) {
    let found = weeksData.find((w) => w && String(w.start_date) === String(startDateStr));
    if (found) return { ...found };
    const start = new Date(startDateStr + 'T00:00:00');
    found = weeksData.find((w) => {
      if (!w?.start_date) return false;
      const ws = new Date(w.start_date + 'T00:00:00');
      const we = new Date(ws);
      we.setDate(we.getDate() + 6);
      return start >= ws && start <= we;
    });
    if (found) return { ...found };
  }
  return getVirtualWeek(startDateStr);
}

const DOW_SHORT = ['ПН', 'ВТ', 'СР', 'ЧТ', 'ПТ', 'СБ', 'ВС'];
const DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

/** Индекс дня недели ПН..ВС (0..6) из Date. */
function dowIndex(date) {
  return (date.getDay() + 6) % 7;
}

/**
 * Построить модель одного дня v3:
 * { date, dateObj, dow, d, dayKey, weekNumber, type, typeLabel, title, km, pace,
 *   status, key, activities[], items[], isToday, restExtraType }
 */
// Типичный темп по типу тренировки в плане (для фолбэка, когда AI не проставил темп дню).
// Мемоизируем по объекту плана — считаем один раз.
const _typicalPaceCache = new WeakMap();
function typicalPlanPaceByType(plan) {
  if (!plan || typeof plan !== 'object') return {};
  if (_typicalPaceCache.has(plan)) return _typicalPaceCache.get(plan);
  const weeks = plan.weeks_data ?? plan.phases?.[0]?.weeks_data ?? [];
  const byType = {};
  for (const w of weeks) {
    for (const raw of Object.values(w?.days ?? {})) {
      for (const d of (Array.isArray(raw) ? raw : [raw])) {
        const t = d?.type;
        if (!t) continue;
        const m = parsePlanMetrics(d?.description ?? d?.text ?? '');
        if (m.pace) (byType[t] ||= {})[m.pace] = (byType[t]?.[m.pace] || 0) + 1;
      }
    }
  }
  const out = {};
  for (const [t, counts] of Object.entries(byType)) {
    out[t] = Object.entries(counts).sort((a, b) => b[1] - a[1])[0]?.[0] || null; // самый частый
  }
  _typicalPaceCache.set(plan, out);
  return out;
}

const NO_PACE_TYPES = ['rest', 'free', 'sbu', 'other', 'ofp', 'strength', 'cross', 'walking', 'hiking', 'cycling', 'swimming'];

export function paceToMin(pace) {
  if (!pace) return null;
  const m = String(pace).match(/(\d{1,2}):(\d{2})/);
  if (!m) return null;
  return Number(m[1]) + Number(m[2]) / 60;
}

export function suggestPaceByType(plan, type, km) {
  if (!km || !type || NO_PACE_TYPES.includes(type)) return null;
  return typicalPlanPaceByType(plan)[type] || null;
}

export function estimateTimeMin(km, pace) {
  if (!km) return null;
  const pm = paceToMin(pace);
  return pm ? Math.round(km * pm) : Math.round(km * 5.4);
}

export function buildDayModel(dateStr, plan, data = {}, weekNumber = null) {
  const { workoutsData = {}, resultsData = {}, workoutsListByDate = {}, executedByDate = {} } = data;
  const date = new Date(dateStr + 'T00:00:00');
  date.setHours(0, 0, 0, 0);
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const di = dowIndex(date);

  const planDay = plan ? getPlanDayForDate(dateStr, plan) : null;
  const items = planDay?.items
    ? planDay.items.filter((it) => it && typeof it.type === 'string')
    : [];
  const activities = items.map((it) => ({
    type: it.type,
    key: !!(it.is_key_workout || it.key),
    text: it.text || it.description || '',
    id: it.id,
    exercises: it.exercises || null,
  }));
  const mainItem = activities.find((a) => a.type !== 'rest' && a.type !== 'free') || activities[0] || null;
  const hasPlanned = activities.some((a) => a.type !== 'rest' && a.type !== 'free');
  const completion = getDayCompletionStatus(dateStr, planDay, workoutsData, resultsData, workoutsListByDate, executedByDate);
  const status = completion.status === 'completed' ? 'done'
    : completion.status === 'rest_extra' ? 'rest_extra'
    : hasPlanned ? 'plan' : 'rest';

  const mType = mainItem?.type || 'rest';
  const metrics = mainItem ? parsePlanMetrics(mainItem.text) : { km: null, pace: null };
  const paceSuggested = metrics.pace ? null : suggestPaceByType(plan, mType, metrics.km);
  const lbl = typeLabel(mType);
  const title = mType === 'rest' || !mainItem
    ? 'Отдых'
    : (metrics.km ? `${lbl} ${metrics.km} км` : lbl);

  return {
    date: dateStr,
    dateObj: date,
    dow: DOW_SHORT[di],
    d: date.getDate(),
    dayKey: DAY_KEYS[di],
    weekNumber: weekNumber ?? planDay?.weekNumber ?? null,
    type: mType,
    typeLabel: lbl,
    title,
    km: metrics.km,
    pace: metrics.pace,
    paceSuggested,
    status,
    isToday: date.getTime() === today.getTime(),
    key: activities.some((a) => a.key) || activities.some((a) => a.type === 'control' || a.type === 'race'),
    activities,
    items,
    restExtraType: completion.extraWorkoutType || null,
  };
}

/** Построить 7 дней недели в форме модели v3. */
export function buildWeekDays(plan, week, data = {}) {
  if (!week?.start_date) return [];
  const out = [];
  for (let i = 0; i < 7; i++) {
    const ds = addDays(week.start_date, i);
    out.push(buildDayModel(ds, plan, data, week.number));
  }
  return out;
}

/** Матрица месяца: ведущие null + дни месяца (модель дня). */
export function buildMonthMatrix(plan, year, month, data = {}) {
  const firstDay = new Date(year, month, 1);
  const daysInMonth = new Date(year, month + 1, 0).getDate();
  const lead = (firstDay.getDay() + 6) % 7; // ПН-первый
  const cells = [];
  for (let i = 0; i < lead; i++) cells.push(null);
  for (let d = 1; d <= daysInMonth; d++) {
    cells.push(buildDayModel(ymd(new Date(year, month, d)), plan, data));
  }
  return cells;
}

const MONTH_NAMES = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
export function formatMonthTitle(year, month) {
  return `${MONTH_NAMES[month]} ${year}`;
}

/** 4-недельное окно объёма вокруг текущей недели для VolumeRail. */
export function buildVolumeWindow(plan, currentStartDate) {
  const weeks = Array.isArray(plan?.weeks_data) ? plan.weeks_data.filter((w) => w?.start_date) : [];
  if (weeks.length === 0) return { items: [], max: 0 };
  const sorted = [...weeks].sort((a, b) => String(a.start_date).localeCompare(String(b.start_date)));
  const total = sorted.length;
  let idx = sorted.findIndex((w) => String(w.start_date) === String(currentStartDate));
  if (idx < 0) {
    // найти ближайшую неделю, в которую попадает дата
    idx = sorted.findIndex((w) => {
      const ws = new Date(w.start_date + 'T00:00:00');
      const we = new Date(ws); we.setDate(we.getDate() + 6);
      const cur = new Date(currentStartDate + 'T00:00:00');
      return cur >= ws && cur <= we;
    });
  }
  if (idx < 0) idx = 0;
  // окно из 4 недель: текущая по центру-слева
  let startIdx = Math.max(0, Math.min(idx - 1, total - 4));
  const window = sorted.slice(startIdx, startIdx + 4);
  const items = window.map((w) => ({
    n: w.number,
    vol: parseVolumeKm(w.total_volume),
    current: String(w.start_date) === String(currentStartDate),
    // реальная фаза (week.phase), если есть; иначе эвристика по позиции
    phase: phaseNameToKey(w.phase) || derivePhaseKey(sorted.indexOf(w), total),
    range: formatWeekRange(w.start_date),
    startDate: w.start_date,
  }));
  const max = Math.max(1, ...items.map((it) => it.vol), ...sorted.map((w) => parseVolumeKm(w.total_volume)));
  return { items, max };
}

/** Фаза для текущей недели (по индексу среди всех недель плана). */
export function deriveCurrentPhase(plan, currentStartDate) {
  const weeks = Array.isArray(plan?.weeks_data) ? plan.weeks_data.filter((w) => w?.start_date) : [];
  if (weeks.length === 0) return null;
  const sorted = [...weeks].sort((a, b) => String(a.start_date).localeCompare(String(b.start_date)));
  let idx = sorted.findIndex((w) => String(w.start_date) === String(currentStartDate));
  if (idx < 0) {
    idx = sorted.findIndex((w) => {
      const ws = new Date(w.start_date + 'T00:00:00');
      const we = new Date(ws); we.setDate(we.getDate() + 6);
      const cur = new Date(currentStartDate + 'T00:00:00');
      return cur >= ws && cur <= we;
    });
  }
  if (idx < 0) return null;
  return phaseNameToKey(sorted[idx].phase) || derivePhaseKey(idx, sorted.length);
}

/** Формат диапазона недели "11–17 мая". */
const MONTHS_GEN = ['янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
export function formatWeekRange(startDateStr) {
  const a = new Date(startDateStr + 'T00:00:00');
  const b = new Date(a); b.setDate(b.getDate() + 6);
  const sameMonth = a.getMonth() === b.getMonth();
  if (sameMonth) return `${a.getDate()}–${b.getDate()} ${MONTHS_GEN[b.getMonth()]}`;
  return `${a.getDate()} ${MONTHS_GEN[a.getMonth()]} – ${b.getDate()} ${MONTHS_GEN[b.getMonth()]}`;
}
