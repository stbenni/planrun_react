/**
 * Coach Insights Feed — Whoop-style карточки разбора тренировок.
 * Каждая карточка: тип + дата сверху, цитата AI ("Voice of Coach") крупно,
 * под ней — компактная строка метрик (км/время/темп/пульс).
 * Клик → переход на день в календаре.
 */

import { useState, useEffect } from 'react';
import { BotIcon } from '../common/Icons';
import './CoachInsightsWidget.css';

const TYPE_LABELS = {
  easy: 'Лёгкий бег',
  long: 'Длительный',
  'long-run': 'Длительный',
  tempo: 'Темповый',
  interval: 'Интервалы',
  fartlek: 'Фартлек',
  control: 'Контрольный',
  race: 'Соревнование',
  marathon: 'Марафон',
  mixed: 'Смешанная',
  recovery: 'Восстановление',
  other: 'ОФП',
  sbu: 'СБУ',
  rest: 'Отдых',
  unknown: 'Тренировка',
};

const TYPE_STRIP_CLASS = {
  easy: 'easy',
  long: 'long',
  'long-run': 'long',
  tempo: 'tempo',
  interval: 'interval',
  fartlek: 'interval',
  control: 'control',
  race: 'race',
  marathon: 'race',
  other: 'other',
  sbu: 'sbu',
  recovery: 'easy',
  rest: 'rest',
};

const MONTHS_FULL = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];

function formatDate(iso) {
  if (!iso) return '';
  const [y, m, d] = String(iso).split('-').map(Number);
  if (!y || !m || !d) return '';
  return `${d} ${MONTHS_FULL[m - 1]}`;
}

function formatDuration(min) {
  if (!min || min <= 0) return null;
  const h = Math.floor(min / 60);
  const m = Math.round(min % 60);
  if (h > 0) return `${h}ч ${String(m).padStart(2, '0')}м`;
  return `${m} мин`;
}

/** Обрезка длинного LLM-разбора. */
function trimReview(text, maxChars = 260) {
  if (!text) return null;
  const clean = String(text).trim().replace(/\s+/g, ' ');
  if (clean.length <= maxChars) return clean;
  return clean.slice(0, maxChars - 1).replace(/[\s,;:.!?-]+$/, '') + '…';
}

/** Fallback-фраза если AI ещё не дал review (бок-симуляция Whoop "Voice"). */
function buildFallbackVoice(a, typeName) {
  const dist = a.actual_distance_km ? Number(a.actual_distance_km).toFixed(1) : null;
  const dur = formatDuration(a.actual_duration_min);
  const pace = a.actual_avg_pace && String(a.actual_avg_pace).trim() !== '0:00'
    ? String(a.actual_avg_pace).trim()
    : null;

  if (dist && pace && dur) {
    return `${typeName} ${dist} км отработан за ${dur}, средний темп ${pace}/км.`;
  }
  if (dist && pace) {
    return `${typeName} ${dist} км в темпе ${pace}/км.`;
  }
  if (dist) {
    return `${typeName} ${dist} км.`;
  }
  return `Тренировка зафиксирована. AI-тренер разберёт её, как только появятся данные.`;
}

const CoachInsightsWidget = ({ api, limit = 5, onNavigate }) => {
  const [analyses, setAnalyses] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!api?.getRecentWorkoutAnalyses) {
      setLoading(false);
      return;
    }
    let cancelled = false;
    (async () => {
      try {
        const res = await api.getRecentWorkoutAnalyses(limit);
        const list = res?.data?.analyses ?? res?.analyses ?? [];
        if (!cancelled) setAnalyses(Array.isArray(list) ? list : []);
      } catch {
        if (!cancelled) setAnalyses([]);
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, [api, limit]);

  if (loading) {
    return (
      <div className="coach-insights">
        <div className="coach-insights__skeleton" />
        <div className="coach-insights__skeleton" />
      </div>
    );
  }

  if (!analyses || analyses.length === 0) {
    return (
      <div className="coach-insights coach-insights--empty">
        <BotIcon size={28} />
        <p>AI-тренер пока не разобрал ни одной тренировки.</p>
        <span>Заверши пробежку — здесь появится разбор: что получилось, что улучшить, какой ритм.</span>
      </div>
    );
  }

  return (
    <ul className="coach-insights">
      {analyses.map((a) => {
        const typeKey = (a.planned_type || a.detected_type || '').toLowerCase();
        const stripKey = TYPE_STRIP_CLASS[typeKey] || 'run';
        const typeName = TYPE_LABELS[typeKey] || 'Тренировка';
        const dateLabel = formatDate(a.workout_date);

        const dist = a.actual_distance_km && a.actual_distance_km > 0
          ? Number(a.actual_distance_km).toFixed(1)
          : null;
        const pace = a.actual_avg_pace && String(a.actual_avg_pace).trim() !== '0:00'
          ? String(a.actual_avg_pace).trim()
          : null;
        const duration = formatDuration(a.actual_duration_min);
        const hr = a.actual_avg_hr && a.actual_avg_hr > 30 ? a.actual_avg_hr : null;

        const review = trimReview(a.llm_review_text);
        const voice = review || buildFallbackVoice(a, typeName);
        const isFallback = !review;
        const hasMetrics = dist || pace || duration || hr;

        const handleClick = () => {
          if (onNavigate && a.workout_date) {
            onNavigate('calendar', { date: a.workout_date });
          }
        };

        return (
          <li key={a.id} className={`coach-insight coach-insight--${stripKey}`}>
            <button type="button" className="coach-insight__btn" onClick={handleClick}>
              <span className="coach-insight__strip" aria-hidden />

              <div className="coach-insight__body">
                <div className="coach-insight__head">
                  <span className="coach-insight__title">{typeName}</span>
                  <span className="coach-insight__sep" aria-hidden>·</span>
                  <span className="coach-insight__date">{dateLabel}</span>
                </div>

                <div className={`coach-insight__voice${isFallback ? ' coach-insight__voice--fallback' : ''}`}>
                  <span className="coach-insight__quote" aria-hidden>“</span>
                  <p className="coach-insight__voice-text">{voice}</p>
                </div>

                {hasMetrics && (
                  <div className="coach-insight__metrics">
                    {dist && (
                      <span className="coach-insight__metric">
                        <span className="coach-insight__metric-value">{dist}</span>
                        <span className="coach-insight__metric-unit">км</span>
                      </span>
                    )}
                    {duration && (
                      <span className="coach-insight__metric">
                        <span className="coach-insight__metric-value">{duration}</span>
                      </span>
                    )}
                    {pace && (
                      <span className="coach-insight__metric">
                        <span className="coach-insight__metric-value">{pace}</span>
                        <span className="coach-insight__metric-unit">/км</span>
                      </span>
                    )}
                    {hr && (
                      <span className="coach-insight__metric coach-insight__metric--muted">
                        <span className="coach-insight__metric-value">{hr}</span>
                        <span className="coach-insight__metric-unit">уд</span>
                      </span>
                    )}
                  </div>
                )}
              </div>
            </button>
          </li>
        );
      })}
    </ul>
  );
};

export default CoachInsightsWidget;
