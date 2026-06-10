import { useState } from 'react';
import { formatHoursMinutes } from '../statsV3Utils';
import { typeColorVar, typeLabel } from '../../Calendar/v3/calV3';
import { ActivityTypeIcon, ActivityIcon } from '../../common/Icons';
import StaHexBadge from './StaHexBadge';

export const SPORTS = [
  { id: 'all', label: 'Все' },
  { id: 'run', label: 'Бег', type: 'running' },
  { id: 'walk', label: 'Ходьба', type: 'walking' },
  { id: 'ofp', label: 'ОФП', type: 'other' },
  { id: 'sbu', label: 'СБУ', type: 'sbu' },
];
export const PERIODS = [['week', 'Неделя'], ['month', 'Месяц'], ['quarter', '3 мес'], ['year', 'Год']];
const PERIOD_LABEL = { week: 'НЕДЕЛЯ', month: 'МЕСЯЦ', quarter: '3 МЕСЯЦА', year: 'ГОД' };
const MONTHS_SHORT = ['янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];

function fmtPaceSec(sec) {
  if (!sec || sec <= 0) return '—';
  const m = Math.floor(sec / 60);
  const s = sec % 60;
  return `${m}:${String(s).padStart(2, '0')}`;
}
function fmtTipDate(iso) {
  if (!iso) return '';
  const [y, m, d] = String(iso).split('-').map(Number);
  if (!y || !m || !d) return '';
  return `${d} ${MONTHS_SHORT[m - 1]}`;
}
function clamp01(x) { return Math.max(0, Math.min(1, x)); }

export function SportSwitch({ sport, setSport }) {
  return (
    <div className="statv3-sportswitch" role="tablist" aria-label="Вид активности">
      {SPORTS.map((s) => (
        <button
          key={s.id}
          type="button"
          role="tab"
          aria-selected={sport === s.id}
          className={`statv3-sportbtn ${sport === s.id ? 'is-active' : ''}`}
          onClick={() => setSport(s.id)}
        >
          <span className="statv3-sportbtn__ic" aria-hidden>
            {s.type ? <ActivityTypeIcon type={s.type} size={15} /> : <ActivityIcon size={15} />}
          </span>{s.label}
        </button>
      ))}
    </div>
  );
}

export function PeriodSeg({ period, setPeriod }) {
  return (
    <div className="statv3-seg" role="tablist" aria-label="Период">
      {PERIODS.map(([id, l]) => (
        <button
          key={id}
          type="button"
          role="tab"
          aria-selected={period === id}
          className={`statv3-seg__btn ${period === id ? 'is-active' : ''}`}
          onClick={() => setPeriod(id)}
        >
          {l}
        </button>
      ))}
    </div>
  );
}

export function MiniCard({ label, value, unit }) {
  return (
    <div className="statv3-mini">
      <div className="statv3-mini__l">{label}</div>
      <div className="statv3-mini__v">
        <span className="statv3-mini__num">{value}</span>
        {unit && <span className="statv3-mini__unit">{unit}</span>}
      </div>
    </div>
  );
}

export function MiniRow({ d }) {
  return (
    <div className="statv3-minis">
      <MiniCard label="ВРЕМЯ" value={formatHoursMinutes(d.totalTimeMin)} unit="ч" />
      <MiniCard label="ТРЕНИРОВОК" value={d.totalWorkouts} />
      <MiniCard label="СР. ТЕМП" value={d.avgPace} unit="/км" />
    </div>
  );
}

export function HeroVolume({ d, period, rightSlot = null }) {
  const maxBar = Math.max(1, ...d.series);
  const deltaPositive = d.deltaPct != null && d.deltaPct >= 0;
  return (
    <div className="statv3-hero">
      <div className="statv3-hero__top">
        <div>
          <div className="statv3-eyebrow">ОБЪЁМ ЗА ПЕРИОД · {PERIOD_LABEL[period]}</div>
          <div className="statv3-hero__value">
            <span className="statv3-hero__num">{d.totalDistance}</span>
            <span className="statv3-hero__unit">км</span>
          </div>
          {rightSlot && d.deltaPct != null && (
            <div className={`statv3-delta statv3-delta--inline ${deltaPositive ? 'is-up' : 'is-down'}`}>
              {deltaPositive ? '↑' : '↓'} {Math.abs(d.deltaPct)}% к прошлому периоду
            </div>
          )}
        </div>
        {rightSlot || (d.deltaPct != null && (
          <div className={`statv3-delta ${deltaPositive ? 'is-up' : 'is-down'}`}>
            {deltaPositive ? '↑' : '↓'} {Math.abs(d.deltaPct)}%
          </div>
        ))}
      </div>

      {d.hasData ? (
        <>
          <div className="statv3-bars-wrap">
            <div className="statv3-bars" aria-hidden>
              {d.series.map((v, i) => (
                <div
                  key={i}
                  className={`statv3-bar ${i === d.highlightIdx ? 'is-current' : ''}`}
                  style={{ height: `${Math.max(4, (v / maxBar) * 72)}px` }}
                />
              ))}
            </div>
            <div className="statv3-bars-x">
              <span>{d.startLabel}</span>
              <span className="statv3-bars-x__now">{d.endLabel}</span>
            </div>
          </div>

          <div className="statv3-substats">
            <div className="statv3-substat">
              <div className="statv3-substat__v">{d.avgPerBucket}</div>
              <div className="statv3-substat__l">в ср. / {d.bucketUnit}</div>
            </div>
            <span className="statv3-substat__sep" />
            <div className="statv3-substat">
              <div className="statv3-substat__v">{d.bestBucket}</div>
              <div className="statv3-substat__l">макс {d.bucketUnit}</div>
            </div>
            <span className="statv3-substat__sep" />
            <div className="statv3-substat">
              <div className="statv3-substat__v statv3-substat__v--prev">{d.prevTotal}</div>
              <div className="statv3-substat__l">прошлый период</div>
            </div>
          </div>
        </>
      ) : (
        <div className="statv3-hero__empty">Нет данных за выбранный период</div>
      )}
    </div>
  );
}

export function ActivityChart({ d }) {
  return (
    <div className="card statv3-card">
      <div className="statv3-cardhead">График активности</div>
      {d.useHeat ? (
        <div className="statv3-heat" style={{ gridTemplateColumns: `repeat(${Math.min(d.heat.length, 15)}, 1fr)` }}>
          {d.heat.map((v, i) => <span key={i} className={`statv3-heat__cell is-${v}`} />)}
        </div>
      ) : (
        <div className="statv3-actbars">
          {d.series.map((v, i) => {
            const mx = Math.max(1, ...d.series);
            return (
              <div
                key={i}
                className={`statv3-actbar ${i === d.highlightIdx ? 'is-current' : ''}`}
                style={{ height: `${(v / mx) * 100}%` }}
              />
            );
          })}
        </div>
      )}
    </div>
  );
}

function fmtRecentDate(w) {
  const src = w.start_time || (w.date ? w.date + 'T00:00:00' : null);
  if (!src) return '';
  const dt = new Date(src);
  if (Number.isNaN(dt.getTime())) return '';
  return dt.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
}

export function RecentList({ recent, onWorkoutClick, onShare }) {
  const [showAll, setShowAll] = useState(false);
  const list = showAll ? recent : recent.slice(0, 8);
  const hasMore = recent.length > 8;
  return (
    <div className="card statv3-card">
      <div className="statv3-recent-head">
        <div className="statv3-cardhead">Последние тренировки</div>
        {onShare && recent.length > 0 && (
          <button type="button" className="statv3-sharebtn" onClick={() => onShare(recent[0])}>↗ Поделиться</button>
        )}
      </div>
      {recent.length === 0 ? (
        <div className="statv3-records-empty">Нет тренировок за период</div>
      ) : (
        <>
          <div className="statv3-recent">
            {list.map((w, i) => {
              const type = (w.detected_type || w.plan_type || w.activity_type || 'running').toLowerCase();
              const isStrength = type === 'other'; // ОФП — без дистанции/темпа: показываем время + пульс
              const durMin = w.duration_seconds != null && w.duration_seconds > 0
                ? Math.round(w.duration_seconds / 60)
                : (w.duration_minutes != null && w.duration_minutes > 0 ? Math.round(w.duration_minutes) : null);
              const durStr = durMin ? (durMin >= 60 ? `${Math.floor(durMin / 60)} ч ${durMin % 60} мин` : `${durMin} мин`) : null;
              // ОФП: показываем только то, что есть (время, пульс); если ничего — правую часть скрываем.
              const strengthBits = [];
              if (durStr) strengthBits.push(durStr);
              if (w.avg_heart_rate) strengthBits.push(`♥${w.avg_heart_rate}`);
              return (
                <button
                  key={w.id ?? i}
                  type="button"
                  className="statv3-recent__row"
                  onClick={() => onWorkoutClick && onWorkoutClick(w)}
                >
                  <span className="statv3-recent__icon" style={{ color: typeColorVar(type) }}>
                    <ActivityTypeIcon type={type} size={17} />
                  </span>
                  <span className="statv3-recent__main">
                    <span className="statv3-recent__title">{typeLabel(type)}</span>
                    <span className="statv3-recent__date">{fmtRecentDate(w)}</span>
                  </span>
                  <span className="statv3-recent__right">
                    {isStrength ? (
                      strengthBits.length > 0 ? (
                        <>
                          <span className="statv3-recent__km">{strengthBits[0]}</span>
                          {strengthBits[1] && <span className="statv3-recent__sub">{strengthBits[1]}</span>}
                        </>
                      ) : null
                    ) : (
                      <>
                        <span className="statv3-recent__km">{w.distance_km} км</span>
                        <span className="statv3-recent__sub">
                          {w.avg_pace ? `${w.avg_pace}` : '—'}{w.avg_heart_rate ? ` · ♥${w.avg_heart_rate}` : ''}
                        </span>
                      </>
                    )}
                  </span>
                </button>
              );
            })}
          </div>
          {hasMore && (
            <button type="button" className="statv3-showall" onClick={() => setShowAll((v) => !v)}>
              {showAll ? 'Свернуть' : `Показать все (${recent.length})`}
            </button>
          )}
        </>
      )}
    </div>
  );
}

export function TrendCard({ m }) {
  const [hover, setHover] = useState(null);
  const w = 300;
  const h = 76;
  const data = m.data;
  const len = data.length;
  const all = [...data, ...(typeof m.goal === 'number' ? [m.goal] : [])];
  const max = Math.max(...all);
  const min = Math.min(...all);
  const rg = max - min || 1;
  const step = w / Math.max(len - 1, 1);
  const y = (v) => h - ((v - min) / rg) * (h - 16) - 9;
  const line = 'M ' + data.map((v, i) => `${i * step} ${y(v)}`).join(' L ');
  const area = `${line} L ${w} ${h} L 0 ${h} Z`;
  const goalY = typeof m.goal === 'number' ? y(m.goal) : null;
  const gradId = `statv3-grad-${m.key}`;

  const move = (clientX, el) => {
    const rect = el.getBoundingClientRect();
    setHover(Math.round(clamp01((clientX - rect.left) / rect.width) * (len - 1)));
  };
  const fmtVal = (v) => (m.isPace ? `${fmtPaceSec(v)}${m.unit}` : `${v}${m.unit}`);

  return (
    <div className="statv3-trend">
      <div className="statv3-trend__top">
        <span className="statv3-trend__dot" style={{ background: m.color }} />
        <span className="statv3-trend__label">{m.label}</span>
        <span className="statv3-trend__spacer" />
        <span className="statv3-trend__value">{m.value}</span>
        {m.unit && <span className="statv3-trend__unit">{m.unit}</span>}
      </div>
      <div className="statv3-trend__delta-row">
        <span className={`statv3-trend__delta ${m.good ? 'is-good' : 'is-bad'}`}>{m.delta}</span>
        <span className="statv3-trend__delta-lbl">{m.deltaLabel}</span>
        <span className="statv3-trend__spacer" />
        {m.startLbl && <span className="statv3-trend__start">{m.startLbl}</span>}
        {m.goalLbl && (
          <span className="statv3-trend__goal" style={{ color: m.color }}>
            <span className="statv3-trend__goal-line" style={{ borderColor: m.color }} />{m.goalLbl}
          </span>
        )}
      </div>
      <div
        className="statv3-chart-hover"
        onMouseMove={(e) => move(e.clientX, e.currentTarget)}
        onMouseLeave={() => setHover(null)}
        onTouchStart={(e) => { const t = e.touches[0]; if (t) move(t.clientX, e.currentTarget); }}
        onTouchMove={(e) => { const t = e.touches[0]; if (t) move(t.clientX, e.currentTarget); }}
        onTouchEnd={() => setHover(null)}
      >
        <svg className="statv3-trend__svg" width="100%" height={h} viewBox={`0 0 ${w} ${h}`} preserveAspectRatio="none">
          <defs>
            <linearGradient id={gradId} x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor={m.color} stopOpacity="0.20" />
              <stop offset="100%" stopColor={m.color} stopOpacity="0" />
            </linearGradient>
          </defs>
          {goalY != null && (
            <line x1={0} x2={w} y1={goalY} y2={goalY} stroke={m.color} strokeWidth="1" strokeDasharray="4 4" opacity="0.5" />
          )}
          <path d={area} fill={`url(#${gradId})`} />
          <path d={line} stroke={m.color} strokeWidth="2.5" fill="none" strokeLinejoin="round" strokeLinecap="round" vectorEffect="non-scaling-stroke" />
          {hover != null && (
            <line x1={hover * step} x2={hover * step} y1={0} y2={h} stroke="var(--text-tertiary)" strokeWidth="1" strokeDasharray="2 3" opacity="0.5" vectorEffect="non-scaling-stroke" />
          )}
          {data.map((v, i) => {
            const isLast = i === len - 1;
            const isHover = i === hover;
            return (
              <circle key={i} cx={i * step} cy={y(v)} r={isHover ? 4.5 : isLast ? 4.5 : 2} fill={(isHover || isLast) ? m.color : 'var(--card-bg, #fff)'} stroke={m.color} strokeWidth="1.5" />
            );
          })}
        </svg>
        {hover != null && data[hover] != null && (
          <div className="statv3-tip" style={{ left: `${(hover / Math.max(len - 1, 1)) * 100}%` }}>
            {m.dates && m.dates[hover] && <span className="statv3-tip__date">нед {fmtTipDate(m.dates[hover])}</span>}
            <span className="statv3-tip__row"><span className="statv3-tip__dot" style={{ background: m.color }} /><span className="statv3-tip__val">{fmtVal(data[hover])}</span></span>
          </div>
        )}
      </div>
      {m.xLabels && (
        <div className="statv3-trend__x">
          {m.xLabels.map((l, i) => <span key={i}>{l}</span>)}
        </div>
      )}
    </div>
  );
}

function tsbStatus(tsb) {
  if (tsb >= 5) return { label: 'Свежий', color: 'var(--success-500)', sub: 'готов к нагрузке' };
  if (tsb >= -10) return { label: 'Норма', color: 'var(--info-500, #3B82F6)', sub: 'обычная форма' };
  if (tsb >= -20) return { label: 'Усталость', color: 'var(--warning-500)', sub: 'нужен лёгкий объём' };
  return { label: 'Перегруз', color: 'var(--danger-500)', sub: 'нужен отдых' };
}

function LoadChart({ load }) {
  const [hover, setHover] = useState(null);
  const w = 300;
  const h = 90;
  const { ctl, atl, tsb, dates } = load;
  const len = ctl.length;
  const allVals = [...ctl, ...atl, ...tsb, 0];
  const max = Math.max(...allVals);
  const min = Math.min(...allVals);
  const rg = max - min || 1;
  const step = w / Math.max(len - 1, 1);
  const y = (v) => h - ((v - min) / rg) * (h - 8) - 4;
  const path = (arr) => 'M ' + arr.map((v, i) => `${i * step} ${y(v)}`).join(' L ');
  const lines = [
    [ctl, 'var(--info-500, #3B82F6)', 'CTL'],
    [atl, 'var(--warning-500)', 'ATL'],
    [tsb, 'var(--success-500)', 'TSB'],
  ];
  const move = (clientX, el) => {
    const rect = el.getBoundingClientRect();
    setHover(Math.round(clamp01((clientX - rect.left) / rect.width) * (len - 1)));
  };
  return (
    <div
      className="statv3-chart-hover"
      onMouseMove={(e) => move(e.clientX, e.currentTarget)}
      onMouseLeave={() => setHover(null)}
      onTouchStart={(e) => { const t = e.touches[0]; if (t) move(t.clientX, e.currentTarget); }}
      onTouchMove={(e) => { const t = e.touches[0]; if (t) move(t.clientX, e.currentTarget); }}
      onTouchEnd={() => setHover(null)}
    >
      <svg className="statv3-loadchart" width="100%" height={h} viewBox={`0 0 ${w} ${h}`} preserveAspectRatio="none">
        <line x1={0} x2={w} y1={y(0)} y2={y(0)} stroke="var(--card-border)" strokeDasharray="2 2" />
        {hover != null && (
          <line x1={hover * step} x2={hover * step} y1={0} y2={h} stroke="var(--text-tertiary)" strokeWidth="1" strokeDasharray="2 3" opacity="0.5" vectorEffect="non-scaling-stroke" />
        )}
        {lines.map(([arr, c], i) => (
          <g key={i}>
            <path d={path(arr)} stroke={c} strokeWidth="2" fill="none" strokeLinejoin="round" vectorEffect="non-scaling-stroke" />
            <circle cx={(len - 1) * step} cy={y(arr[len - 1])} r="3" fill={c} />
            {hover != null && <circle cx={hover * step} cy={y(arr[hover])} r="3.5" fill={c} stroke="var(--card-bg, #fff)" strokeWidth="1.5" />}
          </g>
        ))}
      </svg>
      {hover != null && (
        <div className="statv3-tip" style={{ left: `${(hover / Math.max(len - 1, 1)) * 100}%` }}>
          {dates && dates[hover] && <span className="statv3-tip__date">{fmtTipDate(dates[hover])}</span>}
          {lines.map(([arr, c, lbl]) => (
            <span key={lbl} className="statv3-tip__row">
              <span className="statv3-tip__dot" style={{ background: c }} />
              <span className="statv3-tip__lbl">{lbl}</span>
              <span className="statv3-tip__val" style={{ color: c }}>{arr[hover] >= 0 && lbl === 'TSB' ? `+${arr[hover]}` : arr[hover]}</span>
            </span>
          ))}
        </div>
      )}
    </div>
  );
}

function Legend({ color, label, value }) {
  return (
    <div className="statv3-legend">
      <span className="statv3-legend__line" style={{ background: color }} />
      <span className="statv3-legend__label">{label}</span>
      <span className="statv3-legend__value" style={{ color }}>{value}</span>
    </div>
  );
}

export function LoadCard({ load, headLabel = 'Форма и нагрузка' }) {
  if (!load?.available) {
    return (
      <div className="card statv3-card">
        <div className="statv3-cardhead">{headLabel}</div>
        <div className="statv3-records-empty">Нужно ≥7 дней с данными для расчёта формы.</div>
      </div>
    );
  }
  const st = tsbStatus(load.curTsb);
  return (
    <div className="card statv3-card">
      <div className="statv3-cardhead">{headLabel}</div>
      <div className="statv3-load-hero">
        <span className="statv3-load-hero__num" style={{ color: st.color }}>
          {load.curTsb >= 0 ? `+${load.curTsb}` : load.curTsb}
        </span>
        <div className="statv3-load-hero__info">
          <div className="statv3-load-hero__label" style={{ color: st.color }}>{st.label}</div>
          <div className="statv3-load-hero__sub">TSB · {st.sub}</div>
        </div>
      </div>
      <div className="statv3-load-chart-wrap"><LoadChart load={load} /></div>
      <div className="statv3-legends">
        <Legend color="var(--info-500, #3B82F6)" label="CTL форма" value={load.curCtl} />
        <Legend color="var(--warning-500)" label="ATL усталость" value={load.curAtl} />
        <Legend color="var(--success-500)" label="TSB свежесть" value={load.curTsb >= 0 ? `+${load.curTsb}` : load.curTsb} />
      </div>
    </div>
  );
}

const PR_DISTS = [
  { key: '5k', label: '5 КМ' },
  { key: '10k', label: '10 КМ' },
  { key: 'half', label: '21.1 КМ' },
  { key: 'marathon', label: '42.2 КМ' },
];
const PRED_DISTS = [
  { key: '5k', label: '5 км' },
  { key: '10k', label: '10 км' },
  { key: 'half', label: 'Полумарафон' },
  { key: 'marathon', label: 'Марафон' },
];

function fmtTime(sec) {
  if (!sec || sec <= 0) return '—';
  const hh = Math.floor(sec / 3600);
  const mm = Math.floor((sec % 3600) / 60);
  const ss = sec % 60;
  if (hh > 0) return `${hh}:${String(mm).padStart(2, '0')}:${String(ss).padStart(2, '0')}`;
  return `${mm}:${String(ss).padStart(2, '0')}`;
}
function fmtPrDate(iso) {
  if (!iso) return '';
  const [y, m, dd] = String(iso).split('-').map(Number);
  if (!y || !m || !dd) return '';
  return `${dd} ${MONTHS_SHORT[m - 1]}`;
}
function isFresh(iso) {
  if (!iso) return false;
  const t = new Date(iso + 'T00:00:00').getTime();
  return Number.isFinite(t) && (Date.now() - t) < 14 * 24 * 60 * 60 * 1000;
}

export function PrGrid({ records, compact = false }) {
  return (
    <div className={`statv3-pr-grid ${compact ? 'is-compact' : ''}`}>
      {PR_DISTS.map((dist) => {
        const r = records?.[dist.key];
        const has = !!r && r.time_sec > 0;
        const fresh = has && isFresh(r.date);
        return (
          <div key={dist.key} className={`statv3-pr ${has ? '' : 'is-empty'} ${fresh ? 'is-fresh' : ''}`}>
            {fresh && <span className="statv3-pr__badge">{compact ? '★' : '★ НОВЫЙ'}</span>}
            <div className="statv3-pr__head">
              <span className="statv3-pr__dist">{dist.label}</span>
              {has && r.vdot && !compact ? <span className="statv3-pr__vdot">VDOT {r.vdot}</span> : null}
            </div>
            <div className={`statv3-pr__time ${has ? '' : 'is-empty'}`}>{fmtTime(r?.time_sec)}</div>
            {!compact && <div className="statv3-pr__date">{has ? fmtPrDate(r.date) : 'нет данных'}</div>}
          </div>
        );
      })}
    </div>
  );
}

export function PredCard({ pred }) {
  const vdot = pred?.vdot;
  const predictions = pred?.available ? pred?.predictions : null;
  return (
    <div className="card statv3-card">
      <div className="statv3-cardhead">Прогноз{vdot ? ` по VDOT ${vdot}` : ' результатов'}</div>
      <div className="statv3-records-sub statv3-records-sub--tight">Чего ты способен достичь сейчас</div>
      {predictions ? (
        <div className="statv3-pred-list">
          {PRED_DISTS.map((dist) => {
            const p = predictions[dist.key];
            if (!p) return null;
            return (
              <div key={dist.key} className="statv3-pred-row">
                <span className="statv3-pred-row__dist">{dist.label}</span>
                <span className="statv3-pred-row__right">
                  <span className="statv3-pred-row__time">{p.formatted}</span>
                  {p.pace_formatted && <span className="statv3-pred-row__pace">{p.pace_formatted}</span>}
                </span>
              </div>
            );
          })}
        </div>
      ) : (
        <div className="statv3-records-empty">Недостаточно данных для прогноза — добавь недавний результат забега.</div>
      )}
    </div>
  );
}

export function PointsHero({ ach }) {
  return (
    <div className="statv3-badgehero">
      <div className="statv3-badgehero__top">
        <div className="statv3-badgehero__trophy" aria-hidden>🏆</div>
        <div className="statv3-badgehero__info">
          <div className="statv3-badgehero__pts">
            <span className="statv3-badgehero__num">{ach.totalPoints}</span>
            <span className="statv3-badgehero__lbl">очков</span>
          </div>
          <div className="statv3-badgehero__meta">
            Уровень: <b>{ach.level}</b> · {ach.gotCount} из {ach.allCount} наград
          </div>
        </div>
      </div>
      <div className="statv3-badgehero__bar">
        <div className="statv3-badgehero__bar-fill" style={{ width: `${ach.progressPct}%` }} />
      </div>
      <div className="statv3-badgehero__bar-lbls">
        <span>{ach.level}</span>
        {ach.nextLevel
          ? <span>до «{ach.nextLevel}» — {ach.pointsToNext} очков</span>
          : <span>максимальный уровень</span>}
      </div>
    </div>
  );
}

export function AchCategory({ c }) {
  return (
    <div className="card statv3-card">
      <div className="statv3-ach-cathead">
        <div className="statv3-cardhead">{c.cat}</div>
        <span className="statv3-ach-count">{c.badges.filter((b) => b.got).length}/{c.badges.length}</span>
      </div>
      <div className="statv3-ach-grid">
        {c.badges.map((b, i) => <StaHexBadge key={i} b={b} size={58} />)}
      </div>
    </div>
  );
}
