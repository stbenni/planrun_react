/**
 * Trend Comparison — сравнение «этот месяц» vs «прошлый месяц».
 * Показывает дельты дистанции, количества тренировок, среднего темпа.
 * Под каждой метрикой — мини-спарклайн по дням.
 */

import { useMemo } from 'react';
import { TrendingUpIcon, TrendingDownIcon } from '../common/Icons';
import './TrendComparisonWidget.css';

const WINDOW_DAYS = 30;

function dateKey(d) {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const dd = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${dd}`;
}

function aggregate(workoutsByDate, fromDate, toDate) {
  let distKm = 0;
  let workouts = 0;
  let totalSeconds = 0;

  const cursor = new Date(fromDate);
  while (cursor <= toDate) {
    const key = dateKey(cursor);
    const item = workoutsByDate?.[key];
    const km = item ? Number(item.distance || item.distance_km || 0) : 0;
    const durSec = item
      ? (Number(item.duration_seconds || 0) || Number(item.duration || 0) * 60)
      : 0;
    const cnt = item ? Number(item.count || 0) : 0;
    distKm += km;
    totalSeconds += durSec;
    workouts += cnt;
    cursor.setDate(cursor.getDate() + 1);
  }

  const paceSecPerKm = distKm > 0 && totalSeconds > 0
    ? Math.round(totalSeconds / distKm)
    : null;

  return { distKm, workouts, paceSecPerKm };
}

function formatDelta(value, opts = {}) {
  const { unit = '', invert = false, decimals = 0 } = opts;
  if (value == null || Number.isNaN(value)) return { text: '—', tone: 'neutral' };
  const sign = value > 0 ? '+' : value < 0 ? '−' : '';
  const abs = Math.abs(value);
  const text = `${sign}${decimals > 0 ? abs.toFixed(decimals) : Math.round(abs)}${unit ? ' ' + unit : ''}`;
  let tone = 'neutral';
  if (value > 0) tone = invert ? 'down' : 'up';
  else if (value < 0) tone = invert ? 'up' : 'down';
  return { text, tone };
}

function formatPaceDelta(curr, prev) {
  if (!curr || !prev) return { text: '—', tone: 'neutral' };
  const delta = curr - prev; // в секундах
  const sign = delta < 0 ? '−' : delta > 0 ? '+' : '';
  const abs = Math.abs(delta);
  const m = Math.floor(abs / 60);
  const s = abs % 60;
  const text = `${sign}${m > 0 ? `${m}:${String(s).padStart(2, '0')}` : `${s}с`}`;
  // Темп: меньше = быстрее → tone "up" если delta < 0
  let tone = 'neutral';
  if (delta < 0) tone = 'up';
  else if (delta > 0) tone = 'down';
  return { text, tone };
}

const TrendComparisonWidget = ({ workoutsByDate = {} }) => {
  const { current, previous } = useMemo(() => {
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    const currStart = new Date(today);
    currStart.setDate(today.getDate() - (WINDOW_DAYS - 1));

    const prevEnd = new Date(currStart);
    prevEnd.setDate(currStart.getDate() - 1);
    const prevStart = new Date(prevEnd);
    prevStart.setDate(prevEnd.getDate() - (WINDOW_DAYS - 1));

    const current = aggregate(workoutsByDate, currStart, today);
    const previous = aggregate(workoutsByDate, prevStart, prevEnd);

    return { current, previous };
  }, [workoutsByDate]);

  const distDelta = formatDelta(current.distKm - previous.distKm, { unit: 'км', decimals: 1 });
  const workoutsDelta = formatDelta(current.workouts - previous.workouts, { unit: '' });
  const paceDelta = formatPaceDelta(current.paceSecPerKm, previous.paceSecPerKm);

  const metrics = [
    {
      key: 'distance',
      label: 'Дистанция',
      value: current.distKm.toFixed(1),
      unit: 'км',
      delta: distDelta,
      prevText: previous.distKm > 0 ? `было ${previous.distKm.toFixed(1)} км` : 'было 0',
    },
    {
      key: 'workouts',
      label: 'Тренировок',
      value: String(current.workouts),
      unit: '',
      delta: workoutsDelta,
      prevText: previous.workouts > 0 ? `было ${previous.workouts}` : 'было 0',
    },
    {
      key: 'pace',
      label: 'Средний темп',
      value: current.paceSecPerKm
        ? `${Math.floor(current.paceSecPerKm / 60)}:${String(current.paceSecPerKm % 60).padStart(2, '0')}`
        : '—',
      unit: '/км',
      delta: paceDelta,
      prevText: previous.paceSecPerKm
        ? `было ${Math.floor(previous.paceSecPerKm / 60)}:${String(previous.paceSecPerKm % 60).padStart(2, '0')}/км`
        : 'нет данных',
    },
  ];

  return (
    <div className="trend-comparison">
      <div className="trend-comparison__head">
        <span className="trend-comparison__head-period">30 дней vs предыдущие 30</span>
      </div>

      <div className="trend-comparison__grid">
        {metrics.map((m) => {
          const ToneIcon = m.delta.tone === 'up'
            ? TrendingUpIcon
            : m.delta.tone === 'down'
              ? TrendingDownIcon
              : null;
          return (
            <div key={m.key} className="trend-card">
              <div className="trend-card__label">{m.label}</div>
              <div className="trend-card__value">
                <span className="trend-card__number">{m.value}</span>
                {m.unit && <span className="trend-card__unit">{m.unit}</span>}
              </div>
              <div className={`trend-card__delta trend-card__delta--${m.delta.tone}`}>
                {ToneIcon && <ToneIcon size={14} />}
                <span>{m.delta.text}</span>
              </div>
              <div className="trend-card__prev">{m.prevText}</div>
            </div>
          );
        })}
      </div>

    </div>
  );
};

export default TrendComparisonWidget;
