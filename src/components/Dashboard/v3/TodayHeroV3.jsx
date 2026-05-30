/**
 * TodayHeroV3 — карточка «Сегодня» в стиле v2 design handoff.
 * Структура:
 *  - Diagonal radial accent в верхнем правом углу (цвет типа)
 *  - Type ribbon (точка + лейбл + опционально КЛЮЧЕВАЯ)
 *  - Big title (название тренировки)
 *  - Metrics row (км / темп / время)
 *  - Interval bar (визуализация сегментов из exercises) — опционально
 *  - AI quote block (фетчим брифинг)
 *  - CTAs (Начать / Перенести / Выполнено)
 */

import { useEffect, useMemo, useState } from 'react';
import { ArrowLeftRightIcon, CheckIcon } from '../../common/Icons';
import { WORKOUT_TYPE_COLOR } from '../../Coach/CoachPrimitives';
import './TodayHeroV3.css';

const TYPE_LABELS = {
  rest: 'Отдых', tempo: 'Темповая', interval: 'Интервалы', long: 'Длительная',
  race: 'Гонка', other: 'ОФП', free: 'Свободно', easy: 'Лёгкая', sbu: 'СБУ',
  fartlek: 'Фартлек', control: 'Контрольная', walking: 'Ходьба',
};

/** Прилагательное-акцент для title (что цветным после числа). */
const TYPE_ACCENT = {
  easy: 'лёгкий бег',
  tempo: 'в темпе',
  interval: 'интервалы',
  long: 'длительный',
  race: 'гонка',
  fartlek: 'фартлек',
  control: 'контрольный',
  walking: 'ходьба',
  free: 'свободный',
};

const BRIEFING_MAX_AGE_HOURS = 36;

export default function TodayHeroV3({
  workout,
  large = false,
  api,
  onOpenChat,
  onStart,
  onReschedule,
  onMarkDone,
}) {
  const [briefing, setBriefing] = useState(null);

  useEffect(() => {
    if (!api?.getLatestProactiveMessage) return undefined;
    let cancelled = false;
    api.getLatestProactiveMessage('daily_briefing', BRIEFING_MAX_AGE_HOURS)
      .then((res) => {
        if (cancelled) return;
        const msg = res?.data?.message ?? res?.message ?? null;
        if (msg?.content) setBriefing(msg);
      })
      .catch(() => {});
    return () => { cancelled = true; };
  }, [api]);

  const type = workout?.type || workout?.planDays?.[0]?.type;
  const typeColor = type ? (WORKOUT_TYPE_COLOR[type] || 'var(--gray-400)') : 'var(--gray-400)';
  const typeLabel = type ? (TYPE_LABELS[type] || type) : null;
  const isKey = !!(workout?.is_key_workout || workout?.planDays?.[0]?.is_key_workout);

  // Бэк отдаёт только { type, text }. Парсим из текста км/темп/время.
  const description = workout?.text || workout?.description || workout?.planDays?.[0]?.description || '';
  const parsed = useMemo(() => parseDescription(description), [description]);

  // Заголовок:
  //   - беговые типы → "X км / type-accent" (либо "4×1 км / в темпе" для интервалов)
  //   - ОФП/СБУ → просто TYPE_LABEL
  const isRunningType = type && !['other', 'sbu', 'rest', 'walking', 'free'].includes(type);

  const { titleLead, titleAccent } = useMemo(() => {
    if (!isRunningType) return { titleLead: typeLabel || '—', titleAccent: '' };

    // Lead: "4×1 км" если интервалы, иначе "X км"
    let lead = null;
    if (parsed.intervals) {
      lead = parsed.intervals.text;
    } else {
      const kmVal = workout?.distance_km ?? parsed.km;
      if (kmVal != null) lead = `${formatKm(kmVal)} км`;
    }
    if (!lead) lead = typeLabel || '—';

    // Accent: type adjective (e.g. "в темпе", "лёгкий бег")
    const accent = TYPE_ACCENT[type] || '';
    return { titleLead: lead, titleAccent: accent };
  }, [isRunningType, type, typeLabel, parsed.intervals, parsed.km, workout?.distance_km]);

  const km = workout?.distance_km ?? workout?.distance ?? workout?.planDays?.[0]?.distance_km ?? parsed.km ?? null;
  const pace = workout?.pace ?? workout?.planDays?.[0]?.pace ?? parsed.pace ?? null;
  const dur = workout?.duration_minutes ?? workout?.duration_min ?? parsed.dur ?? null;

  // Сегменты для interval bar — из планируемых упражнений или описания
  const segments = useMemo(() => parseSegments(workout, parsed, type), [workout, parsed, type]);

  return (
    <div className={`today-v3 ${large ? 'today-v3--lg' : ''}`}>
      <span
        className="today-v3__accent"
        style={{ '--accent-color': typeColor }}
        aria-hidden
      />

      {typeLabel && (
        <div className="today-v3__ribbon">
          <span
            className="today-v3__dot"
            style={{ background: typeColor, '--dot-color': typeColor }}
            aria-hidden
          />
          <span className="today-v3__type-label">
            {typeLabel.toUpperCase()}{isKey && ' · КЛЮЧЕВАЯ'}
          </span>
        </div>
      )}

      <h1 className="today-v3__title">
        {titleLead}
        {titleAccent && (
          <>
            <br />
            <span style={{ color: typeColor }}>{titleAccent}</span>
          </>
        )}
      </h1>

      {isRunningType ? (
        <div className="today-v3__metrics">
          <Metric n={formatKm(km)} l="км" />
          <Metric n={pace || '—'} l="темп /км" accent />
          <Metric n={dur ? `${dur}′` : '—'} l="время ~" />
        </div>
      ) : description ? (
        <div className="today-v3__description">
          {description.split(/\r?\n/).slice(0, 5).map((line, i) => (
            <div key={i} className="today-v3__description-line">{line}</div>
          ))}
          {description.split(/\r?\n/).length > 5 && (
            <div className="today-v3__description-more">…ещё {description.split(/\r?\n/).length - 5}</div>
          )}
        </div>
      ) : null}

      {segments.length >= 2 && (
        <>
          <div className="today-v3__interval-bar" role="img" aria-label="Сегменты тренировки">
            {segments.map((s, i) => (
              <div
                key={i}
                style={{
                  flex: s.flex,
                  background: WORKOUT_TYPE_COLOR[s.type] || typeColor,
                }}
              />
            ))}
          </div>
          <div className="today-v3__interval-labels">
            <span>Разм</span>
            <span>{segments[Math.floor(segments.length / 2)]?.label || 'Основная'}</span>
            <span>Зам</span>
          </div>
        </>
      )}

      {briefing && (
        <button
          type="button"
          className="today-v3__quote"
          onClick={(e) => { e.stopPropagation(); onOpenChat?.(); }}
        >
          <div className="today-v3__quote-head">
            <span className="today-v3__quote-avatar" aria-hidden>AI</span>
            <span className="today-v3__quote-speaker">
              AI-ТРЕНЕР
              {briefing.created_at && (
                <span className="today-v3__quote-time"> · {formatTime(briefing.created_at)}</span>
              )}
            </span>
            <span className="today-v3__quote-cta">Спросить →</span>
          </div>
          <p className="today-v3__quote-text">{briefing.content}</p>
        </button>
      )}

      <div className="today-v3__actions">
        <button type="button" className="today-v3__cta-main" onClick={onStart}>
          Начать тренировку →
        </button>
        {onReschedule && (
          <button type="button" className="today-v3__cta-icon" onClick={onReschedule} title="Перенести">
            <ArrowLeftRightIcon size={18} />
          </button>
        )}
        {onMarkDone && (
          <button type="button" className="today-v3__cta-icon" onClick={onMarkDone} title="Отметить выполненной">
            <CheckIcon size={18} />
          </button>
        )}
      </div>
    </div>
  );
}

function Metric({ n, l, accent }) {
  return (
    <div className="today-v3__metric">
      <div className={`today-v3__metric-n ${accent ? 'today-v3__metric-n--accent' : ''}`}>{n}</div>
      <div className="today-v3__metric-l">{l}</div>
    </div>
  );
}

/** Разделяем заголовок на основной фрагмент + цветной акцент. */
function splitTitle(text) {
  if (!text) return { titleLead: '—', titleAccent: '' };
  const t = String(text).trim();
  if (t.length < 14) return { titleLead: t, titleAccent: '' };
  const parts = t.split(/\s+/);
  if (parts.length < 3) return { titleLead: parts[0], titleAccent: parts.slice(1).join(' ') };
  const accentIdx = Math.max(0, parts.length - 2);
  return {
    titleLead: parts.slice(0, accentIdx).join(' '),
    titleAccent: parts.slice(accentIdx).join(' '),
  };
}

/**
 * Парсинг многострочного описания тренировки из training_plan_days.description.
 * Типовой формат: "6 км · 0:34:30\nТемп: 5:45 мин/км\nлёгкий бег"
 * или "4×1 км · 0:42:00\nТемп: 4:30 мин/км\nтемповая ключевая"
 *
 * Возвращает { km, pace, dur, title, intervals }.
 * NB: \b в JS regex не работает с кириллицей, поэтому используем lookahead на разделитель.
 */
function parseDescription(text) {
  if (!text) return { km: null, pace: null, dur: null, title: null, intervals: null };
  const lines = String(text).split(/\r?\n/).map((l) => l.trim()).filter(Boolean);

  let km = null;
  let pace = null;
  let dur = null;
  let titleLine = null;
  let intervals = null;

  for (const line of lines) {
    // km — без \b (он не работает с кириллицей). Lookahead на пробел/конец/знак препинания.
    if (km == null) {
      const m = line.match(/(\d+(?:[.,]\d+)?)\s*км(?=$|[\s·,.])/i);
      if (m) km = parseFloat(m[1].replace(',', '.'));
    }
    // pace — "5:45 мин/км" или "5:45 /км"
    if (pace == null) {
      const m = line.match(/(\d{1,2}:\d{2})\s*(?:мин\/км|\/км)/i);
      if (m) pace = m[1];
    }
    // duration — формат "X:YY:ZZ" (часы:минуты:секунды или 0:минуты:секунды)
    if (dur == null) {
      const m = line.match(/(\d{1,2}):(\d{2}):(\d{2})/);
      if (m) {
        dur = parseInt(m[1], 10) * 60 + parseInt(m[2], 10);
      }
    }
    // intervals — "4×1 км" или "6×400 м"
    if (intervals == null) {
      const m = line.match(/(\d+)\s*[×x]\s*(\d+(?:[.,]\d+)?)\s*(к?м)/i);
      if (m) {
        const reps = parseInt(m[1], 10);
        const dist = parseFloat(m[2].replace(',', '.'));
        intervals = { reps, dist, unit: m[3].toLowerCase() === 'км' ? 'км' : 'м', text: `${reps}×${dist} ${m[3]}` };
      }
    }
    // Title: предпочитаем строку без цифр-метрик (например, "лёгкий бег" или "темповая ключевая")
    if (!titleLine) {
      const digitsCount = (line.match(/\d/g) || []).length;
      const hasWords = /[А-Яа-яёЁA-Za-z]{4,}/.test(line);
      // строка-кандидат: ≥4 буквы подряд + ≤2 цифры
      if (hasWords && digitsCount <= 2 && !/Темп\b/i.test(line)) {
        titleLine = line[0].toUpperCase() + line.slice(1);
      }
    }
  }

  return { km, pace, dur, title: titleLine, intervals };
}

function formatKm(km) {
  if (km == null) return '—';
  const n = Number(km);
  if (!Number.isFinite(n)) return '—';
  // v2-стиль: всегда «X,Y» (например 8,0 не 8)
  return n.toFixed(1).replace('.', ',');
}

function formatTime(iso) {
  const d = new Date(String(iso).replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return '';
  return d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}

/**
 * Строим interval bar:
 *  - Если есть exercises с distance_m — используем их.
 *  - Если в parsed.intervals = "4×1 км" — собираем синтетические сегменты:
 *      разминка (warmup) + N интервалов (тип) + (N−1) восстановлений + заминка (cooldown).
 */
function parseSegments(workout, parsed, type) {
  if (!workout) return [];
  const exercises = Array.isArray(workout.exercises) ? workout.exercises : [];
  if (exercises.length >= 2) {
    return exercises.slice(0, 12).map((ex) => ({
      flex: Math.max(1, Number(ex.distance_m || ex.duration_sec || 1)),
      type: ex.category === 'run' ? (ex.type || type || 'easy') : 'easy',
      label: ex.name || '',
    }));
  }

  if (parsed?.intervals && (type === 'tempo' || type === 'interval' || type === 'fartlek')) {
    const { reps, dist, unit } = parsed.intervals;
    const segDist = unit === 'км' ? dist : dist / 1000; // приводим к км
    const warmup = 1.5;
    const cooldown = 1.5;
    const recovery = 0.4;
    const segments = [];
    segments.push({ flex: warmup, type: 'easy', label: 'разм' });
    for (let i = 0; i < reps; i++) {
      segments.push({ flex: segDist, type: type, label: '' });
      if (i < reps - 1) segments.push({ flex: recovery, type: 'easy', label: '' });
    }
    segments.push({ flex: cooldown, type: 'easy', label: 'зам' });
    return segments;
  }

  return [];
}
