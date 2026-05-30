/**
 * WeekSectionV3 — лента 7 дней текущей недели.
 * Каждый день: dow + date / цветная stripe типа / название тренировки + key-pill /
 * статус (done/today/planned) / км справа.
 */

import { useMemo } from 'react';
import { WORKOUT_TYPE_COLOR } from '../../Coach/CoachPrimitives';
import { CheckIcon } from '../../common/Icons';
import './WeekSectionV3.css';

const DOW = ['ВС', 'ПН', 'ВТ', 'СР', 'ЧТ', 'ПТ', 'СБ'];
const TYPE_LABELS = {
  rest: 'Отдых', tempo: 'Темповая', interval: 'Интервалы', long: 'Длительная',
  race: 'Гонка', other: 'ОФП', free: 'Свободно', easy: 'Лёгкая', sbu: 'СБУ',
  fartlek: 'Фартлек', control: 'Контрольная', walking: 'Ходьба',
};

/** Имя в мужском роде «Лёгкий 8 км» / «Длительный 22 км». */
const TYPE_PROPER = {
  easy: 'Лёгкий',
  long: 'Длительный',
  tempo: 'Темповый',
  race: 'Гонка',
  fartlek: 'Фартлек',
  control: 'Контрольный',
  walking: 'Ходьба',
};

/** Суффикс для интервальной формы «4×1 км в темпе». */
const TYPE_INTERVAL_SUFFIX = {
  tempo: 'в темпе',
  fartlek: 'фартлек',
};

function isoDayFromDate(d) { return d.toISOString().slice(0, 10); }
function fmtRangeShort(start, end) {
  return `${start.getDate()}–${end.getDate()} ${end.toLocaleDateString('ru-RU', { month: 'short' })}`;
}

export default function WeekSectionV3({ plan, workoutsByDate, progressDataMap, compact = false }) {
  const week = useMemo(() => buildWeek(plan), [plan]);

  if (!week) {
    return (
      <div className="card week-v3 week-v3--empty">
        <div className="week-v3__eyebrow">НЕДЕЛЯ</div>
        <div className="week-v3__placeholder">Плана на неделю нет</div>
      </div>
    );
  }

  // total — сумма km, если есть, иначе из total_volume строки
  const sumKm = week.days.reduce((s, d) => s + (Number(d.km) || 0), 0);
  const totalKm = sumKm > 0 ? sumKm : (parseFloat(String(week.totalVolume || '').replace(/[^\d.]/g, '')) || 0);
  const plannedDays = week.days.filter((d) => d.type && d.type !== 'rest' && d.type !== 'free').length;
  const doneCnt = week.days.filter((d) => isDone(d, workoutsByDate, progressDataMap)).length;
  const todayIso = isoDayFromDate(new Date());

  return (
    <div className="card week-v3">
      <div className="week-v3__head">
        <div className="week-v3__eyebrow">
          НЕДЕЛЯ {week.weekNumber || ''}{week.weekNumber ? ' · ' : ''}{fmtRangeShort(week.start, week.end).toUpperCase()}
        </div>
        <div className="week-v3__head-row">
          <span className={`week-v3__total ${compact ? 'week-v3__total--compact' : ''}`}>{Math.round(totalKm)}</span>
          <span className="week-v3__total-unit">км запланировано</span>
          <span className="week-v3__spacer" />
          {plannedDays > 0 && (
            <span className="week-v3__progress">
              <CheckIcon size={14} /> {doneCnt}/{plannedDays}
            </span>
          )}
        </div>
      </div>

      <div className="week-v3__list">
        {week.days.map((d) => {
          const isToday = d.date === todayIso;
          const done = isDone(d, workoutsByDate, progressDataMap);
          const typeColor = d.type ? (WORKOUT_TYPE_COLOR[d.type] || 'var(--gray-400)') : 'var(--gray-400)';
          return (
            <div
              key={d.date}
              className={`week-v3__day ${isToday ? 'week-v3__day--today' : ''} ${done ? 'week-v3__day--done' : ''}`}
            >
              <div className="week-v3__day-cal">
                <div className="week-v3__day-dow">{DOW[d.dayOfWeek]}</div>
                <div className="week-v3__day-date">{d.dateNum}</div>
              </div>
              <span className="week-v3__day-stripe" style={{ background: typeColor }} />
              <div className="week-v3__day-info">
                <div className="week-v3__day-name-row">
                  <span className="week-v3__day-name">{d.label}</span>
                  {d.isKey && <span className="week-v3__day-key">КЛЮЧ</span>}
                </div>
                {d.km > 0 && <div className="week-v3__day-km">{d.km} км</div>}
              </div>
              {done && (
                <span className="week-v3__check" aria-label="Выполнено">
                  <CheckIcon size={12} />
                </span>
              )}
              {isToday && !done && <span className="week-v3__today-pill">СЕГОДНЯ</span>}
            </div>
          );
        })}
      </div>
    </div>
  );
}

const DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

function buildWeek(plan) {
  if (!plan) return null;
  const phases = Array.isArray(plan?.phases) ? plan.phases : null;
  const allWeeks = phases
    ? phases.flatMap((p) => Array.isArray(p?.weeks_data) ? p.weeks_data : [])
    : (Array.isArray(plan?.weeks_data) ? plan.weeks_data : []);
  if (allWeeks.length === 0) return null;

  const todayIso = isoDayFromDate(new Date());
  // Найти неделю, содержащую сегодняшнюю дату (бэк отдаёт start_date понедельника)
  const currentWeek = allWeeks.find((w) => {
    if (!w?.start_date) return false;
    const start = new Date(w.start_date);
    const end = new Date(start);
    end.setDate(start.getDate() + 6);
    return todayIso >= isoDayFromDate(start) && todayIso <= isoDayFromDate(end);
  }) || allWeeks[0];

  const start = new Date(currentWeek.start_date);
  const end = new Date(start);
  end.setDate(start.getDate() + 6);

  // Backend выдаёт `days` как объект { mon: [items], tue: [items], ... }.
  // Каждый items — { type, text, id, key? }. Дистанцию вытаскиваем regex'ом из text.
  const days = [];
  for (let i = 0; i < 7; i++) {
    const d = new Date(start);
    d.setDate(start.getDate() + i);
    const iso = isoDayFromDate(d);
    const dayKey = DAY_KEYS[i];
    const items = currentWeek.days?.[dayKey];
    const itemsArr = Array.isArray(items) ? items : (items ? [items] : []);
    const primary = itemsArr.find((it) => it && it.type !== 'rest' && it.type !== 'free') || itemsArr[0] || null;
    const type = primary?.type || 'rest';
    const text = primary?.text || '';
    const { label, km } = buildDayLabel(type, text);
    days.push({
      date: iso,
      dayOfWeek: d.getDay(),
      dateNum: d.getDate(),
      type,
      label,
      km,
      isKey: !!(primary?.is_key_workout || primary?.key),
    });
  }
  return {
    start,
    end,
    days,
    weekNumber: currentWeek.number || currentWeek.week_number,
    totalVolume: currentWeek.total_volume,
  };
}

/** Парсит description тренировки и собирает читаемый label + km для отображения. */
function buildDayLabel(type, text) {
  if (type === 'rest' || type === 'free') return { label: 'Отдых', km: 0 };

  // Извлекаем км и интервалы из description (regex с lookahead вместо \b — для кириллицы)
  const km = extractKm(text);
  const intervals = extractIntervals(text);

  if (type === 'other') return { label: 'ОФП', km };
  if (type === 'sbu') return { label: 'СБУ', km };
  if (type === 'walking') return { label: km > 0 ? `Ходьба ${km} км` : 'Ходьба', km };

  // Интервалы — для tempo/interval/fartlek используем форму «4×1 км в темпе»
  if (intervals) {
    const suffix = TYPE_INTERVAL_SUFFIX[type];
    if (type === 'interval') return { label: intervals.text, km };
    if (suffix) return { label: `${intervals.text} ${suffix}`, km };
  }

  // Беговые типы с км — «Лёгкий 6 км»
  if (km > 0 && TYPE_PROPER[type]) {
    return { label: `${TYPE_PROPER[type]} ${km} км`, km };
  }

  // Fallback — обычный type label
  return { label: TYPE_LABELS[type] || type, km };
}

function extractKm(text) {
  if (!text) return 0;
  const lines = String(text).split(/\r?\n/);
  for (const line of lines) {
    // первая «X км» в начале строки или после пробела/переноса
    const m = line.match(/(?:^|\s)(\d+(?:[.,]\d+)?)\s*км(?=$|[\s·,.])/i);
    if (m) {
      const v = parseFloat(m[1].replace(',', '.'));
      if (Number.isFinite(v) && v > 0) return v;
    }
  }
  return 0;
}

function extractIntervals(text) {
  if (!text) return null;
  const m = String(text).match(/(\d+)\s*[×x]\s*(\d+(?:[.,]\d+)?)\s*(км|м)\b/i);
  if (!m) return null;
  const reps = parseInt(m[1], 10);
  const dist = m[2].replace(',', '.');
  return { reps, dist, unit: m[3], text: `${reps}×${dist} ${m[3]}` };
}

function isDone(day, workoutsByDate, progressDataMap) {
  if (progressDataMap && progressDataMap[day.date]) return true;
  if (workoutsByDate && workoutsByDate[day.date] && workoutsByDate[day.date].length > 0) return true;
  return false;
}
