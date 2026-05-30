/**
 * NextWorkoutSectionV3 — карточка следующей запланированной тренировки.
 * Цветная stripe + название + meta + км справа + кнопка-ссылка «Открыть детали».
 */

import { WORKOUT_TYPE_COLOR } from '../../Coach/CoachPrimitives';
import './NextWorkoutSectionV3.css';

const TYPE_LABELS = {
  rest: 'Отдых', tempo: 'Темповая', interval: 'Интервалы', long: 'Длительная',
  race: 'Гонка', other: 'ОФП', free: 'Свободно', easy: 'Лёгкая', sbu: 'СБУ',
  fartlek: 'Фартлек', control: 'Контрольная', walking: 'Ходьба',
};

const TYPE_PROPER = {
  easy: 'Лёгкий',
  long: 'Длительный',
  tempo: 'Темповый',
  race: 'Гонка',
  fartlek: 'Фартлек',
  control: 'Контрольный',
  walking: 'Ходьба',
};

const TYPE_INTERVAL_SUFFIX = {
  tempo: 'в темпе',
  fartlek: 'фартлек',
};

/** Дефолтная зона ЧСС по типу тренировки (если в описании нет явной). */
const TYPE_DEFAULT_HR_ZONE = {
  easy: 'ЧСС зона 2',
  long: 'ЧСС зона 2',
  tempo: 'ЧСС зона 3',
  interval: 'ЧСС зона 4–5',
  fartlek: 'ЧСС зона 3–4',
  race: 'ЧСС зона 4',
};

const DOW_SHORT = ['ВС', 'ПН', 'ВТ', 'СР', 'ЧТ', 'ПТ', 'СБ'];
const MONTHS_GEN = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];

function formatDateLine(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  return `${DOW_SHORT[d.getDay()]} · ${d.getDate()} ${MONTHS_GEN[d.getMonth()]}`;
}

export default function NextWorkoutSectionV3({ workout, onOpen }) {
  if (!workout) {
    return null;
  }
  const type = workout.type || workout.planDays?.[0]?.type;
  const typeColor = type ? (WORKOUT_TYPE_COLOR[type] || 'var(--gray-400)') : 'var(--gray-400)';

  // Парсим description бэка (multiline): "X км · 0:34:30\nТемп: 5:45 мин/км\nлёгкий бег"
  const description = workout?.text || workout?.description || workout?.planDays?.[0]?.description || '';
  const parsed = parseDescription(description);

  const km = workout.distance_km ?? workout.distance ?? workout.planDays?.[0]?.distance_km ?? parsed.km ?? null;
  const pace = workout.pace ?? workout.planDays?.[0]?.pace ?? parsed.pace ?? null;
  const dur = workout.duration_minutes ?? workout.duration_min ?? parsed.dur ?? null;
  const hrZone = workout.hr_zone || workout.zone || parsed.hrZone || TYPE_DEFAULT_HR_ZONE[type] || null;

  // Title: workout.label override → построить из intervals/type+km → TYPE_LABEL
  let label = workout.label || workout.title;
  if (!label) {
    if (type === 'rest' || type === 'free') {
      label = 'Отдых';
    } else if (type === 'other') {
      label = 'ОФП';
    } else if (type === 'sbu') {
      label = 'СБУ';
    } else if (parsed.intervals && (type === 'tempo' || type === 'interval' || type === 'fartlek')) {
      const suffix = TYPE_INTERVAL_SUFFIX[type] || '';
      label = suffix ? `${parsed.intervals.text} ${suffix}` : parsed.intervals.text;
    } else if (km && TYPE_PROPER[type]) {
      label = `${TYPE_PROPER[type]} ${km} км`;
    } else {
      label = TYPE_LABELS[type] || type || '—';
    }
  }

  const metaParts = [];
  if (pace) metaParts.push(`${pace} /км`);
  if (hrZone && type !== 'other' && type !== 'sbu') metaParts.push(hrZone);
  if (dur) metaParts.push(`≈ ${dur} мин`);

  return (
    <div className="card next-v3" onClick={onOpen} role={onOpen ? 'button' : undefined} tabIndex={onOpen ? 0 : undefined}>
      <div className="next-v3__eyebrow">СЛЕДУЮЩАЯ ТРЕНИРОВКА · {formatDateLine(workout.date)}</div>
      <div className="next-v3__row">
        <span className="next-v3__stripe" style={{ background: typeColor }} />
        <div className="next-v3__info">
          <div className="next-v3__title">{label}</div>
          {metaParts.length > 0 && <div className="next-v3__meta">{metaParts.join(' · ')}</div>}
        </div>
        {km != null && (
          <div className="next-v3__km">
            <span>{km}</span>
            <span className="next-v3__km-unit"> км</span>
          </div>
        )}
      </div>
      {onOpen && (
        <button type="button" className="next-v3__cta" onClick={(e) => { e.stopPropagation(); onOpen(); }}>
          Открыть детали →
        </button>
      )}
    </div>
  );
}

/**
 * Парсинг description бэка (multiline). Возвращает { km, pace, dur, intervals, hrZone }.
 * NB: \b не работает с кириллицей — используем lookahead.
 */
function parseDescription(text) {
  if (!text) return { km: null, pace: null, dur: null, intervals: null, hrZone: null };
  const lines = String(text).split(/\r?\n/).map((l) => l.trim()).filter(Boolean);

  let km = null;
  let pace = null;
  let dur = null;
  let intervals = null;
  let hrZone = null;

  for (const line of lines) {
    if (km == null) {
      const m = line.match(/(?:^|\s)(\d+(?:[.,]\d+)?)\s*км(?=$|[\s·,.])/i);
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
      const m = line.match(/(\d+)\s*[×x]\s*(\d+(?:[.,]\d+)?)\s*(км|м)\b/i);
      if (m) {
        intervals = { reps: parseInt(m[1], 10), dist: m[2].replace(',', '.'), unit: m[3], text: `${m[1]}×${m[2]} ${m[3]}` };
      }
    }
    if (hrZone == null) {
      // "ЧСС зона 2" / "пульс в зоне 2" / "зона 4"
      const m = line.match(/(?:ЧСС|пульс|зон[аеу]?)\s*(?:в\s*)?(?:зон[аеу]?\s*)?(\d(?:[–-]\d)?)/i);
      if (m) hrZone = `ЧСС зона ${m[1]}`;
    }
  }

  return { km, pace, dur, intervals, hrZone };
}
