/**
 * Виджет тренировочной нагрузки (TRIMP / ATL / CTL / TSB).
 * Показывает SVG-график ATL/CTL/TSB, статус-бейдж и последние тренировки.
 * compact-режим: только бейдж + упрощённый график.
 */

import { useState, useEffect, useCallback, useMemo, useRef, useLayoutEffect } from 'react';
import LogoLoading from '../common/LogoLoading';
import useWorkoutRefreshStore from '../../stores/useWorkoutRefreshStore';
import './TrainingLoadWidget.css';

/* ── TSB status helpers ── */

const TSB_STATES = [
  { min: 15, key: 'recovered', label: 'Восстановлен', cssmod: 'recovered' },
  { min: 5, key: 'fresh', label: 'Свежий', cssmod: 'fresh' },
  { min: -10, key: 'loaded', label: 'Нагрузка', cssmod: 'loaded' },
  { min: -Infinity, key: 'overloaded', label: 'Перегрузка', cssmod: 'overloaded' },
];

function getTsbState(tsb) {
  return TSB_STATES.find((s) => tsb >= s.min) || TSB_STATES[TSB_STATES.length - 1];
}

/* ── Date formatting ── */

const MONTH_SHORT = ['янв', 'фев', 'мар', 'апр', 'май', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];

function fmtDateShort(dateStr) {
  const d = new Date(dateStr);
  return `${d.getDate()} ${MONTH_SHORT[d.getMonth()]}`;
}

/* ── Training recommendations based on current load state ── */

function getRecommendations(atl, ctl, tsb) {
  const optLow = Math.round(ctl * 0.8);
  const optHigh = Math.round(ctl * 1.3);
  const items = [];

  if (tsb >= 15) {
    // Восстановлен — можно нагружать
    items.push({ icon: '↗', text: 'Можно увеличить нагрузку' });
    items.push({ icon: '🎯', text: `Целевой TRIMP: ${optLow}–${optHigh}` });
    items.push({ icon: '💡', text: 'Хорошее время для интенсивных тренировок' });
  } else if (tsb >= 5) {
    // Свежий — оптимально
    items.push({ icon: '✓', text: 'Оптимальное состояние' });
    items.push({ icon: '🎯', text: `Целевой TRIMP: ${optLow}–${optHigh}` });
    items.push({ icon: '💡', text: 'Поддерживайте текущий уровень нагрузки' });
  } else if (tsb >= -10) {
    // Нагрузка — нормально, но следить
    items.push({ icon: '⚡', text: 'Идёт адаптация к нагрузке' });
    items.push({ icon: '🎯', text: `Целевой TRIMP: ${optLow}–${optHigh}` });
    if (atl > ctl * 1.4) {
      items.push({ icon: '⚠', text: 'ATL сильно выше формы — запланируйте отдых' });
    } else {
      items.push({ icon: '💡', text: 'Включите лёгкие тренировки для восстановления' });
    }
  } else {
    // Перегрузка
    items.push({ icon: '⬇', text: 'Снизьте нагрузку для восстановления' });
    items.push({ icon: '🎯', text: `Макс TRIMP: ${optLow}` });
    items.push({ icon: '⚠', text: 'Риск перетренированности — нужен отдых' });
  }

  return items;
}

/* ── SVG chart constants ── */

const VB_W = 800;
const VB_H = 250;
const MARGIN_FULL = { top: 20, right: 10, bottom: 30, left: 44 };
const MARGIN_COMPACT = { top: 12, right: 6, bottom: 22, left: 30 };
const TOOLTIP_EDGE_PADDING = 8;
const TOOLTIP_OFFSET = 12;

/* ── Component ── */

const TrainingLoadWidget = ({ api, viewContext = null, compact = false }) => {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [hoverIdx, setHoverIdx] = useState(null);
  const svgRef = useRef(null);
  const chartWrapRef = useRef(null);
  const tooltipRef = useRef(null);
  const [tooltipLayout, setTooltipLayout] = useState({ left: 0, placement: 'right', ready: false });

  const load = useCallback(async () => {
    if (!api) return;
    try {
      setLoading(true);
      setError(null);
      const res = await api.getTrainingLoad(viewContext);
      const d = res?.data ?? res;
      setData(d);
    } catch (e) {
      setError(e.message || 'Ошибка загрузки');
    } finally {
      setLoading(false);
    }
  }, [api, viewContext]);

  useEffect(() => { load(); }, [load]);

  const workoutRefreshVersion = useWorkoutRefreshStore((s) => s.version);
  useEffect(() => {
    if (workoutRefreshVersion > 0 && api) load();
  }, [workoutRefreshVersion, api, load]);

  /* ── Chart data processing ── */

  const chart = useMemo(() => {
    if (!data?.daily || data.daily.length === 0) return null;

    // Ограничиваем данные последними 30 днями
    const allDaily = data.daily;
    const tl = allDaily.length > 30 ? allDaily.slice(-30) : allDaily;
    const n = tl.length;
    const m = compact ? MARGIN_COMPACT : MARGIN_FULL;
    const cw = VB_W - m.left - m.right;
    const ch = VB_H - m.top - m.bottom;

    // value extents
    let yMin = Infinity;
    let yMax = -Infinity;
    for (let i = 0; i < n; i++) {
      const vals = [tl[i].atl ?? 0, tl[i].ctl ?? 0, tl[i].tsb ?? 0];
      for (const v of vals) {
        if (v < yMin) yMin = v;
        if (v > yMax) yMax = v;
      }
    }

    // add padding
    const yRange = yMax - yMin || 1;
    yMin = yMin - yRange * 0.08;
    yMax = yMax + yRange * 0.08;
    const adjustedRange = yMax - yMin;

    const xScale = (i) => m.left + (i / (n - 1 || 1)) * cw;
    const yScale = (v) => m.top + (1 - (v - yMin) / adjustedRange) * ch;

    // polyline builders
    const buildLine = (key) =>
      tl.map((p, i) => `${xScale(i).toFixed(1)},${yScale(p[key] ?? 0).toFixed(1)}`).join(' ');

    const atlLine = buildLine('atl');
    const ctlLine = buildLine('ctl');
    const tsbLine = buildLine('tsb');

    // Zero line for TSB reference
    const zeroY = yScale(0);

    // Optimal load zone: dynamic corridor around CTL (0.8×CTL – 1.3×CTL)
    let optUpperLine = '';
    let optLowerLine = '';
    let optZoneFill = '';
    {
      let upper = '';
      let lower = '';
      for (let i = 0; i < n; i++) {
        const ctl = tl[i].ctl ?? 0;
        const x = xScale(i).toFixed(1);
        const yUp = yScale(ctl * 1.3).toFixed(1);
        const yLo = yScale(ctl * 0.8).toFixed(1);
        const cmd = i === 0 ? 'M' : 'L';
        upper += `${cmd}${x},${yUp} `;
        lower = `L${x},${yLo} ` + lower;
        optUpperLine += `${x},${yUp} `;
        optLowerLine += `${x},${yLo} `;
      }
      optZoneFill = upper + lower + 'Z';
    }

    // X-axis labels (every ~2 weeks)
    const xLabelCount = compact ? 4 : 6;
    const step = Math.max(1, Math.round(n / xLabelCount));
    const xLabels = [];
    for (let i = 0; i < n; i += step) {
      xLabels.push({ x: xScale(i), label: fmtDateShort(tl[i].date) });
    }
    // always include last
    if (n > 1 && xLabels[xLabels.length - 1]?.x < xScale(n - 1) - 30) {
      xLabels.push({ x: xScale(n - 1), label: fmtDateShort(tl[n - 1].date) });
    }

    // Y-axis labels
    const yTicks = compact ? 3 : 5;
    const yLabels = [];
    for (let i = 0; i <= yTicks; i++) {
      const v = yMin + (adjustedRange * i) / yTicks;
      yLabels.push({ y: yScale(v), label: Math.round(v) });
    }

    // Grid lines
    const gridLines = yLabels.map((l) => l.y);

    return {
      atlLine,
      ctlLine,
      tsbLine,
      zeroY,
      optZoneFill,
      optUpperLine,
      optLowerLine,
      xLabels,
      yLabels,
      gridLines,
      xScale,
      yScale,
      n,
      m,
      cw,
      ch,
    };
  }, [data, compact]);

  /* ── Hover handler ── */

  const handleMouseMove = useCallback(
    (e) => {
      if (!chart || !svgRef.current) return;
      const svg = svgRef.current;
      const rect = svg.getBoundingClientRect();
      const xRel = ((e.clientX - rect.left) / rect.width) * VB_W;
      const idx = Math.round(((xRel - chart.m.left) / chart.cw) * (chart.n - 1));
      const clamped = Math.max(0, Math.min(chart.n - 1, idx));
      setHoverIdx(clamped);
    },
    [chart],
  );

  const handleMouseLeave = useCallback(() => setHoverIdx(null), []);

  const handleTouchMove = useCallback(
    (e) => {
      if (!chart || !svgRef.current || !e.touches[0]) return;
      const svg = svgRef.current;
      const rect = svg.getBoundingClientRect();
      const xRel = ((e.touches[0].clientX - rect.left) / rect.width) * VB_W;
      const idx = Math.round(((xRel - chart.m.left) / chart.cw) * (chart.n - 1));
      setHoverIdx(Math.max(0, Math.min(chart.n - 1, idx)));
    },
    [chart],
  );

  const allDaily = useMemo(() => data?.daily ?? [], [data]);
  const chartDaily = useMemo(
    () => (allDaily.length > 30 ? allDaily.slice(-30) : allDaily),
    [allDaily],
  );
  const lastPoint = allDaily[allDaily.length - 1];
  const currentTSB = lastPoint?.tsb ?? 0;
  const currentATL = lastPoint?.atl ?? 0;
  const currentCTL = lastPoint?.ctl ?? 0;
  const tsbState = getTsbState(currentTSB);
  const recommendations = getRecommendations(currentATL, currentCTL, currentTSB);

  /* ── Tooltip data ── */

  const tooltipData = useMemo(() => {
    if (hoverIdx === null || !chart || !chartDaily[hoverIdx]) return null;
    const p = chartDaily[hoverIdx];
    const x = chart.xScale(hoverIdx);

    return {
      x,
      date: fmtDateShort(p.date),
      atl: Math.round(p.atl ?? 0),
      ctl: Math.round(p.ctl ?? 0),
      tsb: Math.round(p.tsb ?? 0),
    };
  }, [chart, chartDaily, hoverIdx]);

  const updateTooltipLayout = useCallback(() => {
    if (!tooltipData || !chartWrapRef.current || !tooltipRef.current) {
      setTooltipLayout((prev) => (
        prev.ready || prev.left !== 0 || prev.placement !== 'right'
          ? { left: 0, placement: 'right', ready: false }
          : prev
      ));
      return;
    }

    const wrapWidth = chartWrapRef.current.clientWidth;
    const tooltipWidth = tooltipRef.current.offsetWidth;
    const anchorX = (tooltipData.x / VB_W) * wrapWidth;
    const maxLeft = Math.max(
      TOOLTIP_EDGE_PADDING,
      wrapWidth - tooltipWidth - TOOLTIP_EDGE_PADDING,
    );

    let placement = 'right';
    let left = anchorX + TOOLTIP_OFFSET;

    if (left > maxLeft) {
      placement = 'left';
      left = anchorX - tooltipWidth - TOOLTIP_OFFSET;
    }

    left = Math.min(Math.max(TOOLTIP_EDGE_PADDING, left), maxLeft);

    setTooltipLayout((prev) => (
      prev.left === left && prev.placement === placement && prev.ready
        ? prev
        : { left, placement, ready: true }
    ));
  }, [tooltipData]);

  useLayoutEffect(() => {
    updateTooltipLayout();
  }, [updateTooltipLayout]);

  useLayoutEffect(() => {
    if (!tooltipData || !chartWrapRef.current) return undefined;

    let frameId = 0;
    const scheduleUpdate = () => {
      cancelAnimationFrame(frameId);
      frameId = window.requestAnimationFrame(updateTooltipLayout);
    };

    const wrap = chartWrapRef.current;
    const resizeObserver = typeof ResizeObserver !== 'undefined'
      ? new ResizeObserver(scheduleUpdate)
      : null;

    resizeObserver?.observe(wrap);
    if (tooltipRef.current) resizeObserver?.observe(tooltipRef.current);

    window.addEventListener('resize', scheduleUpdate);

    return () => {
      cancelAnimationFrame(frameId);
      window.removeEventListener('resize', scheduleUpdate);
      resizeObserver?.disconnect();
    };
  }, [tooltipData, updateTooltipLayout]);

  /* ── Render states ── */

  if (loading) {
    return <div className="training-load-loading"><LogoLoading size={32} /></div>;
  }

  if (error) {
    return <div className="training-load-empty">{error}</div>;
  }

  if (!data?.available || !data?.daily || data.daily.length === 0) {
    return (
      <div className="training-load-empty">
        {data?.message || 'Недостаточно данных для анализа нагрузки. Нужны тренировки с пульсом.'}
      </div>
    );
  }

  return (
    <div className={`training-load ${compact ? 'training-load--compact' : ''}`}>
      {/* Header */}
      <div className="training-load__header">
        <span className={`training-load__tsb-badge training-load__tsb-badge--${tsbState.cssmod}`}>
          {tsbState.label}
          <span className="training-load__tsb-value">TSB {Math.round(currentTSB)}</span>
        </span>
        <div className="training-load__metrics">
          <div className="training-load__metric">
            <span className="training-load__metric-value">{Math.round(currentATL)}</span>
            <span className="training-load__metric-label">Усталость</span>
          </div>
          <div className="training-load__metric">
            <span className="training-load__metric-value">{Math.round(currentCTL)}</span>
            <span className="training-load__metric-label">Форма</span>
          </div>
        </div>
      </div>

      {/* Body: chart + workouts side by side on desktop, stacked on mobile/compact */}
      <div className="training-load__body">

      {/* SVG Chart */}
      {chart && (
        <div className="training-load__chart-col">
        <div ref={chartWrapRef} className="training-load__chart-wrap">
          <svg
            ref={svgRef}
            className="training-load__chart"
            viewBox={`0 0 ${VB_W} ${VB_H}`}
            preserveAspectRatio={compact ? 'none' : 'xMidYMid meet'}
          >
            {/* Grid */}
            {chart.gridLines.map((y, i) => (
              <line
                key={i}
                className="training-load__grid-line"
                x1={chart.m.left}
                x2={VB_W - chart.m.right}
                y1={y}
                y2={y}
              />
            ))}

            {/* Optimal load zone (0.8×CTL – 1.3×CTL) */}
            <path className="training-load__opt-zone" d={chart.optZoneFill} />
            <polyline className="training-load__opt-zone-border" points={chart.optUpperLine} />
            <polyline className="training-load__opt-zone-border" points={chart.optLowerLine} />

            {/* Zero line (TSB reference) */}
            <line
              className="training-load__zero-line"
              x1={chart.m.left}
              x2={VB_W - chart.m.right}
              y1={chart.zeroY}
              y2={chart.zeroY}
            />

            {/* Lines */}
            <polyline className="training-load__line-ctl" points={chart.ctlLine} />
            <polyline className="training-load__line-atl" points={chart.atlLine} />
            <polyline className="training-load__line-tsb" points={chart.tsbLine} />

            {/* Y-axis labels */}
            {chart.yLabels.map((l, i) => (
              <text
                key={i}
                className="training-load__axis-label"
                x={chart.m.left - 6}
                y={l.y + 3}
                textAnchor="end"
                fontSize={compact ? '9' : '10'}
              >
                {l.label}
              </text>
            ))}

            {/* X-axis labels */}
            {chart.xLabels.map((l, i) => (
              <text
                key={i}
                className="training-load__axis-label"
                x={l.x}
                y={VB_H - 6}
                textAnchor="middle"
                fontSize={compact ? '9' : '10'}
              >
                {l.label}
              </text>
            ))}

            {/* Hover overlay */}
            <rect
              className="training-load__hover-zone"
              x={chart.m.left}
              y={chart.m.top}
              width={chart.cw}
              height={chart.ch}
              onMouseMove={handleMouseMove}
              onMouseLeave={handleMouseLeave}
              onTouchMove={handleTouchMove}
              onTouchEnd={handleMouseLeave}
            />

            {/* Tooltip vertical line */}
            {tooltipData && (
              <line
                className="training-load__tooltip-line"
                x1={tooltipData.x}
                x2={tooltipData.x}
                y1={chart.m.top}
                y2={chart.m.top + chart.ch}
              />
            )}
          </svg>

          {/* HTML Tooltip */}
          {tooltipData && (
            <div
              ref={tooltipRef}
              className={`training-load__html-tooltip training-load__html-tooltip--${tooltipLayout.placement} ${tooltipLayout.ready ? '' : 'training-load__html-tooltip--hidden'}`.trim()}
              style={{ left: `${tooltipLayout.left}px` }}
            >
              <div className="training-load__html-tooltip-date">{tooltipData.date}</div>
              <div className="training-load__html-tooltip-row training-load__html-tooltip-row--atl">Усталость: {tooltipData.atl}</div>
              <div className="training-load__html-tooltip-row training-load__html-tooltip-row--ctl">Форма: {tooltipData.ctl}</div>
              <div className="training-load__html-tooltip-row training-load__html-tooltip-row--tsb">Баланс: {tooltipData.tsb}</div>
            </div>
          )}
        </div>

        {/* Legend */}
        <div className="training-load__legend">
          <span className="training-load__legend-item">
            <span className="training-load__legend-zone" />
            Оптимальный диапазон
          </span>
          <span className="training-load__legend-item">
            <span className="training-load__legend-line" style={{ background: '#ef4444' }} />
            Усталость (ATL)
          </span>
          <span className="training-load__legend-item">
            <span className="training-load__legend-line" style={{ background: '#3b82f6' }} />
            Форма (CTL)
          </span>
          <span className="training-load__legend-item">
            <span className="training-load__legend-line" style={{ background: '#22c55e' }} />
            Баланс (TSB)
          </span>
        </div>
        </div>
      )}

      {/* Recommendations (hidden in compact) */}
      {!compact && (
        <div className="training-load__recs">
          <div className="training-load__recs-title">Рекомендации</div>
          {recommendations.map((r, i) => (
            <div key={i} className="training-load__rec-row">
              <span className="training-load__rec-icon">{r.icon}</span>
              <span className="training-load__rec-text">{r.text}</span>
            </div>
          ))}
        </div>
      )}

      </div>{/* /body */}
    </div>
  );
};

export default TrainingLoadWidget;
