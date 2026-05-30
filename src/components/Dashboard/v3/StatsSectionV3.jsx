/**
 * StatsSectionV3 — статистика бега за период (Мес/Квартал/Год) в стиле v2.
 * 4 tile'а: Дистанция / Тренировок / Время / Средн. темп.
 *
 * Источник: workoutsByDate (передаётся снаружи). Агрегируем по period.
 */

import { useMemo, useState } from 'react';
import './StatsSectionV3.css';

const PERIODS = [
  { id: 'month', label: 'Мес', days: 30 },
  { id: 'quarter', label: 'Квартал', days: 90 },
  { id: 'year', label: 'Год', days: 365 },
];

export default function StatsSectionV3({ workoutsByDate }) {
  const [period, setPeriod] = useState('month');

  const stats = useMemo(() => computeStats(workoutsByDate, period), [workoutsByDate, period]);

  return (
    <div className="card stats-v3">
      <div className="stats-v3__head">
        <div className="stats-v3__eyebrow">СТАТИСТИКА</div>
        <div className="stats-v3__period">
          {PERIODS.map((p) => (
            <button
              key={p.id}
              type="button"
              onClick={() => setPeriod(p.id)}
              className={`stats-v3__period-btn ${period === p.id ? 'stats-v3__period-btn--active' : ''}`}
            >
              {p.label}
            </button>
          ))}
        </div>
      </div>

      <div className="stats-v3__grid">
        <Tile label="ДИСТАНЦИЯ" value={stats.distance || '—'} unit="км" />
        <Tile label="ТРЕНИРОВОК" value={stats.workouts || '—'} unit="" />
        <Tile label="ВРЕМЯ" value={stats.time || '—'} unit="ч" />
        <Tile label="СРЕДН. ТЕМП" value={stats.pace || '—'} unit="/км" />
      </div>
    </div>
  );
}

function Tile({ label, value, unit }) {
  return (
    <div className="stats-v3__tile">
      <div className="stats-v3__tile-lbl">{label}</div>
      <div className="stats-v3__tile-row">
        <span className="stats-v3__tile-val">{value}</span>
        {unit && <span className="stats-v3__tile-unit">{unit}</span>}
      </div>
    </div>
  );
}

function computeStats(workoutsByDate, period) {
  if (!workoutsByDate || typeof workoutsByDate !== 'object') {
    return { distance: 0, workouts: 0, time: '0:00', pace: '—' };
  }
  const cutoffMs = Date.now() - PERIODS.find((p) => p.id === period).days * 86400000;
  let distance = 0;
  let workouts = 0;
  let totalMinutes = 0;

  for (const [date, summaryOrList] of Object.entries(workoutsByDate)) {
    const t = Date.parse(date);
    if (Number.isNaN(t) || t < cutoffMs) continue;
    const items = Array.isArray(summaryOrList) ? summaryOrList : [summaryOrList];
    for (const it of items) {
      if (!it) continue;
      // Бэк отдаёт { count, distance (км), duration (мин), pace, hr, ... }
      const d = Number(it.distance ?? it.total_distance ?? it.distance_km) || 0;
      const mins = Number(it.duration ?? it.total_duration ?? it.duration_minutes) || 0;
      if (d > 0) {
        distance += d;
        workouts += Number(it.count ?? it.workout_count ?? 1);
        totalMinutes += mins;
      }
    }
  }

  // Средний темп = totalMinutes / distance
  let paceStr = '—';
  if (distance > 0 && totalMinutes > 0) {
    const paceMinPerKm = totalMinutes / distance;
    const m = Math.floor(paceMinPerKm);
    const s = Math.round((paceMinPerKm - m) * 60);
    paceStr = `${m}:${String(s).padStart(2, '0')}`;
  }

  // Время как HH:MM
  const h = Math.floor(totalMinutes / 60);
  const mm = Math.round(totalMinutes - h * 60);
  const timeStr = `${h}:${String(mm).padStart(2, '0')}`;

  return {
    distance: Math.round(distance),
    workouts,
    time: timeStr,
    pace: paceStr,
  };
}
