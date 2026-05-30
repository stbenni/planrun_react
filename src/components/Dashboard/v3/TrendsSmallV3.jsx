/**
 * TrendsSmallV3 — объём этого месяца vs прошлый + sparkline тренда (4 недели).
 */

import { useEffect, useMemo, useRef, useState } from 'react';
import { Sparkline } from '../../Coach/CoachPrimitives';
import { TrendingUpIcon, TrendingDownIcon } from '../../common/Icons';
import './TrendsSmallV3.css';

export default function TrendsSmallV3({ workoutsByDate }) {
  const trend = useMemo(() => computeTrend(workoutsByDate), [workoutsByDate]);

  const isUp = trend.deltaPct != null && trend.deltaPct >= 0;
  const sparkColor = isUp ? 'var(--success-500)' : 'var(--warning-500)';

  const chartRef = useRef(null);
  const [chartWidth, setChartWidth] = useState(0);
  useEffect(() => {
    if (!chartRef.current) return undefined;
    const el = chartRef.current;
    const update = () => setChartWidth(el.clientWidth);
    update();
    const ro = new ResizeObserver(update);
    ro.observe(el);
    return () => ro.disconnect();
  }, []);

  return (
    <div className="card trends-v3">
      <div className="trends-v3__eyebrow">ОБЪЁМ · vs прошлый месяц</div>
      <div className="trends-v3__row">
        <span className="trends-v3__num">{trend.currentKm || '—'}</span>
        <span className="trends-v3__unit">км</span>
        <span className="trends-v3__spacer" />
        {trend.deltaPct != null && (
          <span className={`trends-v3__delta ${isUp ? 'trends-v3__delta--up' : 'trends-v3__delta--down'}`}>
            {isUp ? <TrendingUpIcon size={12} /> : <TrendingDownIcon size={12} />}
            {isUp ? '+' : ''}{trend.deltaPct}%
          </span>
        )}
      </div>
      <div className="trends-v3__chart" ref={chartRef}>
        {trend.spark.length >= 2 && chartWidth > 0 && (
          <Sparkline
            data={trend.spark}
            labels={trend.sparkLabels}
            w={chartWidth}
            h={48}
            color={sparkColor}
            bg
            thick
          />
        )}
      </div>
    </div>
  );
}

function computeTrend(workoutsByDate) {
  if (!workoutsByDate || typeof workoutsByDate !== 'object') {
    return { currentKm: 0, prevKm: 0, deltaPct: null, spark: [], sparkLabels: [] };
  }
  const now = Date.now();
  const dayMs = 86400000;

  // Текущий месяц = последние 30 дней. Прошлый = 30..60 дней назад.
  let curr = 0;
  let prev = 0;
  // Spark — недельные суммы за последние 4 недели (старая → новая)
  const weeks = [0, 0, 0, 0];

  for (const [date, summary] of Object.entries(workoutsByDate)) {
    const t = Date.parse(date);
    if (Number.isNaN(t)) continue;
    const daysAgo = Math.floor((now - t) / dayMs);
    const items = Array.isArray(summary) ? summary : [summary];
    const km = items.reduce((s, it) => {
      const d = Number(it?.distance ?? it?.total_distance ?? it?.distance_km ?? 0) || 0;
      return s + d;
    }, 0);
    if (km === 0) continue;
    if (daysAgo < 30) curr += km;
    else if (daysAgo < 60) prev += km;
    if (daysAgo < 28) {
      const wIdx = 3 - Math.floor(daysAgo / 7);
      if (wIdx >= 0 && wIdx < 4) weeks[wIdx] += km;
    }
  }

  // Лейблы для tooltip: «27 апр – 3 мая» и т.д.
  const today = new Date();
  const sparkLabels = [];
  for (let i = 3; i >= 0; i--) {
    const end = new Date(today);
    end.setDate(today.getDate() - i * 7);
    const start = new Date(end);
    start.setDate(end.getDate() - 6);
    const fmt = (d) => d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
    sparkLabels.push(`${fmt(start)} – ${fmt(end)}`);
  }

  const deltaPct = prev > 0 ? Math.round(((curr - prev) / prev) * 100) : null;
  return {
    currentKm: Math.round(curr),
    prevKm: Math.round(prev),
    deltaPct,
    spark: weeks.map((w) => Math.round(w)),
    sparkLabels,
  };
}
