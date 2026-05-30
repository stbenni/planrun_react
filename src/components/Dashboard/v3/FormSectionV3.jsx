/**
 * FormSectionV3 — ФОРМА И НАГРУЗКА (v2 design).
 * Hero TSB-число + лейбл «Свежий/Усталость» + полный chart ATL/CTL/TSB + 3 mini-stats + рекомендация.
 *
 * Fetch: api.getTrainingLoad() возвращает { available, current: {atl,ctl,tsb,acwr,acwr_status}, daily: [{date,atl,ctl,tsb}], ... }.
 */

import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { InfoIcon } from '../../common/Icons';
import './FormSectionV3.css';

const TERM_HINTS = {
  acwr: {
    title: 'ACWR — Acute:Chronic Workload Ratio',
    body: 'Отношение нагрузки за 7 дней к среднему за 28 дней. 0.8–1.3 — оптимально, ›1.5 — риск травмы.',
  },
  trimp: {
    title: 'TRIMP — Training Impulse',
    body: 'Условная единица нагрузки одной тренировки (длительность × средний пульс). Чем выше — тем тяжелее.',
  },
  tsb: {
    title: 'TSB — Training Stress Balance',
    body: 'Свежесть = CTL − ATL. Положительное → готов к нагрузке. Отрицательное → накоплена усталость.',
  },
};

function tsbStatus(tsb) {
  if (tsb >= 5) return { label: 'Свежий', color: 'var(--success-500)' };
  if (tsb >= -10) return { label: 'Норма', color: 'var(--info-500, #3B82F6)' };
  if (tsb >= -20) return { label: 'Усталость', color: 'var(--warning-500)' };
  return { label: 'Перегруз', color: 'var(--danger-500)' };
}

function acwrLabel(status) {
  if (status === 'optimal') return { text: 'опт.', color: 'var(--success-500)' };
  if (status === 'detrained') return { text: 'мало', color: 'var(--warning-500)' };
  if (status === 'caution') return { text: 'риск', color: 'var(--warning-500)' };
  if (status === 'risk') return { text: '⚠ перегруз', color: 'var(--danger-500)' };
  return { text: '—', color: 'var(--text-tertiary)' };
}

export default function FormSectionV3({ api }) {
  const [data, setData] = useState(null);
  const [days, setDays] = useState(28);

  useEffect(() => {
    if (!api?.getTrainingLoad) return undefined;
    let cancelled = false;
    api.getTrainingLoad(null, days)
      .then((res) => { if (!cancelled) setData(res?.data || res); })
      .catch(() => { if (!cancelled) setData(null); });
    return () => { cancelled = true; };
  }, [api, days]);

  if (!data?.available) {
    return (
      <div className="card form-v3 form-v3--empty">
        <div className="form-v3__eyebrow">ФОРМА И НАГРУЗКА</div>
        <div className="form-v3__placeholder">Нужно ≥7 дней с данными для расчёта.</div>
      </div>
    );
  }

  const { current, daily } = data;
  const tsb = Math.round(current.tsb);
  const ctl = Math.round(current.ctl);
  const atl = Math.round(current.atl);
  const status = tsbStatus(tsb);
  const acwr = acwrLabel(current.acwr_status);

  const series = (daily || []).slice(-days);
  const ctlData = series.map((d) => Number(d.ctl) || 0);
  const atlData = series.map((d) => Number(d.atl) || 0);
  const tsbData = series.map((d) => Number(d.tsb) || 0);

  // TRIMP сегодня = последний day-trimp (берём из recent_workouts или daily)
  const todayTrimp = series.length > 0 ? Math.round((series[series.length - 1].trimp || 0)) : 0;
  // 7д trimp
  const trimp7d = series.slice(-7).reduce((s, d) => s + (Number(d.trimp) || 0), 0);

  return (
    <div className="card form-v3">
      <div className="form-v3__head">
        <div>
          <div className="form-v3__eyebrow">ФОРМА И НАГРУЗКА</div>
          <div className="form-v3__hero">
            <span className="form-v3__hero-num" style={{ color: status.color }}>
              {tsb >= 0 ? `+${tsb}` : tsb}
            </span>
            <div className="form-v3__hero-info">
              <div className="form-v3__hero-label" style={{ color: status.color }}>
                {status.label}
                <span className="form-v3__hero-dot" style={{ background: status.color, color: status.color }} />
              </div>
              <div className="form-v3__hero-sub">TSB · {tsb >= 0 ? 'готов к нагрузке' : 'отдыхай'}</div>
            </div>
          </div>
        </div>
        <select
          className="form-v3__toggle"
          value={days}
          onChange={(e) => setDays(parseInt(e.target.value, 10))}
        >
          <option value={28}>28 дн</option>
          <option value={56}>56 дн</option>
          <option value={90}>90 дн</option>
        </select>
      </div>

      <div className="form-v3__chart">
        <LineChart
          dates={series.map((d) => d.date)}
          series={[
            { data: ctlData, color: 'var(--info-500, #3B82F6)', label: 'CTL' },
            { data: atlData, color: 'var(--warning-500)', label: 'ATL' },
            { data: tsbData, color: 'var(--success-500)', label: 'TSB' },
          ]}
          h={120}
        />
      </div>

      <div className="form-v3__legend">
        <LegendItem color="var(--success-500)" label="TSB свежесть" value={tsb >= 0 ? `+${tsb}` : tsb} info="tsb" />
        <LegendItem color="var(--info-500, #3B82F6)" label="CTL форма" value={ctl} />
        <LegendItem color="var(--warning-500)" label="ATL усталость" value={atl} />
      </div>

      <div className="form-v3__mini-row">
        <MiniStat label="ACWR" value={current.acwr ? current.acwr.toFixed(1) : '—'} sub={acwr.text} color={acwr.color} info="acwr" />
        <MiniStat label="TRIMP сегодня" value={todayTrimp || '—'} info="trimp" />
        <MiniStat label="TRIMP · 7 дн" value={Math.round(trimp7d) || '—'} info="trimp" />
      </div>

      {tsb >= 5 && (
        <div className="form-v3__reco">
          <span aria-hidden>💡</span>{' '}
          <b>Рекомендация:</b> можно увеличить нагрузку. Целевой TRIMP сегодня: 40–65.
        </div>
      )}
      {tsb < -15 && (
        <div className="form-v3__reco form-v3__reco--warn">
          <span aria-hidden>⚠</span>{' '}
          <b>Рекомендация:</b> запланирована высокая усталость — отдых или лёгкая активность.
        </div>
      )}
    </div>
  );
}

function MiniStat({ label, value, sub, color, info }) {
  return (
    <div className="form-v3__mini">
      <div className="form-v3__mini-lbl">
        {label}
        {info && <InfoBadge term={info} />}
      </div>
      <div className="form-v3__mini-row-val">
        <span className="form-v3__mini-val" style={color ? { color } : undefined}>{value}</span>
        {sub && <span className="form-v3__mini-sub" style={color ? { color } : undefined}>{sub}</span>}
      </div>
    </div>
  );
}

function LegendItem({ color, label, value, info }) {
  return (
    <div className="form-v3__legend-item">
      <span className="form-v3__legend-line" style={{ background: color }} />
      <span className="form-v3__legend-label">
        {label}
        {info && <InfoBadge term={info} />}
      </span>
      <span className="form-v3__legend-value" style={{ color }}>{value}</span>
    </div>
  );
}

/**
 * Маленькая «i» рядом с термином. Hover → тултип через portal в body,
 * чтобы не обрезался overflow:hidden карточки.
 */
function InfoBadge({ term }) {
  const hint = TERM_HINTS[term];
  const iconRef = useRef(null);
  const [open, setOpen] = useState(false);
  const [pos, setPos] = useState({ left: 0, top: 0 });

  const recalcPos = () => {
    const el = iconRef.current;
    if (!el) return;
    const rect = el.getBoundingClientRect();
    const tipWidth = 220;
    const gap = 8;
    // Центрируем над иконкой, но не вылезаем за viewport
    let left = rect.left + rect.width / 2 - tipWidth / 2;
    left = Math.max(8, Math.min(window.innerWidth - tipWidth - 8, left));
    const top = rect.top - gap;
    setPos({ left, top });
  };

  useEffect(() => {
    if (!open) return undefined;
    recalcPos();
    const onChange = () => recalcPos();
    window.addEventListener('resize', onChange);
    window.addEventListener('scroll', onChange, true);
    return () => {
      window.removeEventListener('resize', onChange);
      window.removeEventListener('scroll', onChange, true);
    };
  }, [open]);

  if (!hint) return null;

  return (
    <>
      <span
        ref={iconRef}
        className="form-v3__info-icon"
        aria-label="Что это"
        tabIndex={0}
        onMouseEnter={() => setOpen(true)}
        onMouseLeave={() => setOpen(false)}
        onFocus={() => setOpen(true)}
        onBlur={() => setOpen(false)}
        onClick={(e) => { e.stopPropagation(); setOpen((v) => !v); }}
      >
        <InfoIcon size={11} />
      </span>
      {open && typeof document !== 'undefined' && createPortal(
        <div
          className="form-v3__info-tip"
          role="tooltip"
          style={{ left: pos.left, top: pos.top }}
        >
          <span className="form-v3__info-tip-title">{hint.title}</span>
          <span className="form-v3__info-tip-body">{hint.body}</span>
        </div>,
        document.body
      )}
    </>
  );
}

function LineChart({ series, dates = [], w = 300, h = 120 }) {
  const wrapRef = useRef(null);
  const [hoverIdx, setHoverIdx] = useState(null);

  if (!series || series.length === 0 || !series[0].data?.length) return null;
  const allValues = series.flatMap((s) => s.data);
  const max = Math.max(...allValues, 1);
  const min = Math.min(...allValues, 0);
  const range = max - min || 1;
  const len = series[0].data.length;
  const step = w / Math.max(len - 1, 1);
  const yFor = (v) => h - ((v - min) / range) * (h - 8) - 4;

  const handleMove = (e) => {
    const wrap = wrapRef.current;
    if (!wrap) return;
    const rect = wrap.getBoundingClientRect();
    const x = e.clientX - rect.left;
    const ratio = Math.max(0, Math.min(1, x / rect.width));
    const idx = Math.round(ratio * (len - 1));
    setHoverIdx(idx);
  };

  const handleLeave = () => setHoverIdx(null);

  const hoverDate = hoverIdx != null ? dates[hoverIdx] : null;
  const hoverPercent = hoverIdx != null ? (hoverIdx / Math.max(len - 1, 1)) * 100 : 0;

  return (
    <div
      ref={wrapRef}
      className="form-v3__chart-wrap"
      onMouseMove={handleMove}
      onMouseLeave={handleLeave}
      onTouchStart={(e) => {
        const t = e.touches?.[0];
        if (t) handleMove({ clientX: t.clientX });
      }}
      onTouchMove={(e) => {
        const t = e.touches?.[0];
        if (t) handleMove({ clientX: t.clientX });
      }}
      onTouchEnd={handleLeave}
    >
      <svg
        width="100%"
        height={h}
        viewBox={`0 0 ${w} ${h}`}
        preserveAspectRatio="none"
        style={{ display: 'block', overflow: 'visible', maxWidth: '100%' }}
      >
        <line x1={0} x2={w} y1={yFor(0)} y2={yFor(0)} stroke="var(--card-border)" strokeDasharray="2 2" />
        {series.map((s, si) => {
          const d = 'M ' + s.data.map((v, i) => `${i * step} ${yFor(v)}`).join(' L ');
          return (
            <g key={si}>
              <path
                d={d}
                stroke={s.color}
                strokeWidth="2"
                fill="none"
                strokeLinejoin="round"
                strokeLinecap="round"
                vectorEffect="non-scaling-stroke"
              />
              <circle
                cx={(len - 1) * step}
                cy={yFor(s.data[len - 1])}
                r="3"
                fill={s.color}
              />
              {hoverIdx != null && (
                <circle
                  cx={hoverIdx * step}
                  cy={yFor(s.data[hoverIdx])}
                  r="3.5"
                  fill={s.color}
                  stroke="var(--card-bg, #fff)"
                  strokeWidth="1.5"
                />
              )}
            </g>
          );
        })}
        {hoverIdx != null && (
          <line
            x1={hoverIdx * step}
            x2={hoverIdx * step}
            y1={0}
            y2={h}
            stroke="var(--text-tertiary)"
            strokeDasharray="2 3"
            strokeWidth="1"
            vectorEffect="non-scaling-stroke"
            opacity="0.5"
          />
        )}
      </svg>
      {hoverIdx != null && (
        <div
          className="form-v3__chart-tooltip"
          style={{ left: `calc(${hoverPercent}% + 0px)` }}
        >
          {hoverDate && (
            <div className="form-v3__chart-tooltip-date">{formatTooltipDate(hoverDate)}</div>
          )}
          {series.map((s) => (
            <div key={s.label} className="form-v3__chart-tooltip-row">
              <span className="form-v3__chart-tooltip-dot" style={{ background: s.color }} />
              <span className="form-v3__chart-tooltip-label">{s.label}</span>
              <span className="form-v3__chart-tooltip-val" style={{ color: s.color }}>
                {formatChartValue(s.data[hoverIdx])}
              </span>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

function formatTooltipDate(iso) {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short', weekday: 'short' });
}

function formatChartValue(v) {
  const n = Number(v) || 0;
  const r = Math.round(n);
  return r >= 0 ? `+${r}` : `${r}`;
}
