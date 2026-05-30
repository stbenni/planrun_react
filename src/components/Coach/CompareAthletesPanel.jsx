/**
 * CompareAthletesPanel — модальное сравнение 2-4 атлетов рядом.
 *
 * Открывается при выборе 2-4 атлетов и нажатии «Сравнить» в bulk-bar.
 * Показывает атлетов колонками рядом: avatar/name + ключевые метрики
 * (compliance, объём 7 дн, VDOT, дни до гонки, sparkline).
 */

import { useEffect } from 'react';
import { createPortal } from 'react-dom';
import { CloseIcon } from '../common/Icons';
import { CoachAvatar, GroupTag, Sparkline } from './CoachPrimitives';
import { coachHelpers } from '../../stores/useCoachStore';
import './CompareAthletesPanel.css';

const DISTANCE_LABELS = {
  '5k': '5 км', '10k': '10 км', half: 'Полу',
  half_marathon: 'Полу', marathon: 'Марафон', ultra: 'Ультра',
};

function daysToRace(iso) {
  if (!iso) return null;
  const t = Date.parse(iso);
  if (Number.isNaN(t)) return null;
  const d = Math.ceil((t - Date.now()) / 86400000);
  return d > 0 ? d : null;
}

export default function CompareAthletesPanel({ isOpen, athletes, onClose, onOpenAthlete }) {
  useEffect(() => {
    if (!isOpen) return undefined;
    const onKey = (e) => { if (e.key === 'Escape') onClose?.(); };
    document.addEventListener('keydown', onKey);
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = prev;
    };
  }, [isOpen, onClose]);

  if (!isOpen || !Array.isArray(athletes) || athletes.length < 2) return null;

  const target = (typeof document !== 'undefined' && document.getElementById('modal-root')) || document?.body;
  if (!target) return null;

  const rows = [
    {
      label: 'ВЫПОЛНЕНИЕ',
      render: (a) => {
        const total = Number(a.week_total || 0);
        const done = Number(a.week_completed || 0);
        const pct = total > 0 ? Math.round((done / total) * 100) : null;
        const color = pct == null ? 'var(--text-tertiary)' :
          pct >= 80 ? 'var(--success-500)' :
          pct >= 50 ? 'var(--warning-500)' : 'var(--danger-500)';
        return (
          <span style={{ color }}>
            {pct == null ? '—' : `${pct}%`}
            <span className="cmp-sub">{done}/{total}</span>
          </span>
        );
      },
    },
    {
      label: 'ОБЪЁМ · 7 ДН',
      render: (a) => {
        const spark = Array.isArray(a.volume_spark) ? a.volume_spark : [];
        if (spark.length === 0) return <span className="cmp-muted">—</span>;
        const sum = spark.reduce((s, x) => s + Number(x || 0), 0);
        return <>{sum} <span className="cmp-sub">км</span></>;
      },
    },
    {
      label: 'VDOT',
      render: (a) => a.vdot ? <>{a.vdot}{a.pace_trend && <span className="cmp-trend"> {a.pace_trend}</span>}</> : <span className="cmp-muted">—</span>,
    },
    {
      label: 'ДО ГОНКИ',
      render: (a) => {
        const d = daysToRace(a.race_date);
        return d != null ? <>{d}<span className="cmp-sub"> дн</span></> : <span className="cmp-muted">—</span>;
      },
    },
    {
      label: 'ПОСЛЕДНЯЯ АКТИВНОСТЬ',
      render: (a) => {
        const d = coachHelpers.daysSince(a.last_activity);
        if (d === Infinity) return <span className="cmp-muted">—</span>;
        if (d === 0) return 'Сегодня';
        if (d === 1) return 'Вчера';
        if (d < 7) return `${d} дн назад`;
        return <span className="cmp-warn">{d} дн</span>;
      },
    },
  ];

  const content = (
    <>
      <div className="cmp__scrim" onClick={onClose} aria-hidden />
      <div className="cmp" role="dialog" aria-modal="true" aria-label="Сравнение атлетов">
        <header className="cmp__head">
          <div>
            <div className="cmp__eyebrow">СРАВНЕНИЕ</div>
            <h2 className="cmp__title">{athletes.length} атлета рядом</h2>
          </div>
          <button type="button" className="cmp__close" onClick={onClose} aria-label="Закрыть">
            <CloseIcon size={18} />
          </button>
        </header>

        <div className="cmp__columns" style={{ gridTemplateColumns: `repeat(${athletes.length}, minmax(0, 1fr))` }}>
          {athletes.map((a) => {
            const atRisk = coachHelpers.isAtRisk(a);
            const fresh = coachHelpers.hasFreshUpload(a);
            const ring = atRisk ? 'var(--danger-500)' : fresh ? 'var(--success-500)' : null;
            const group = Array.isArray(a.groups) && a.groups.length > 0 ? a.groups[0] : null;
            const distLabel = a.race_distance ? (DISTANCE_LABELS[a.race_distance] || a.race_distance) : null;
            return (
              <div key={a.id} className="cmp__col">
                <button
                  type="button"
                  className="cmp__col-head"
                  onClick={() => onOpenAthlete?.(a.id)}
                  title="Открыть профиль"
                >
                  <CoachAvatar athlete={a} size={48} ring={ring} />
                  <div className="cmp__col-name">{a.name || a.username}</div>
                  {group && <GroupTag group={group} />}
                  <div className="cmp__col-goal">
                    {distLabel || '—'}
                    {a.race_target_time ? ` · ${a.race_target_time}` : ''}
                  </div>
                </button>
                {rows.map((row, i) => (
                  <div key={i} className="cmp__metric">
                    <div className="cmp__metric-label">{row.label}</div>
                    <div className="cmp__metric-value">{row.render(a)}</div>
                  </div>
                ))}
                <div className="cmp__chart">
                  {Array.isArray(a.volume_spark) && a.volume_spark.length > 1 ? (
                    <Sparkline
                      data={a.volume_spark}
                      w={200}
                      h={48}
                      color={atRisk ? 'var(--danger-500)' : 'var(--primary-500)'}
                      thick
                    />
                  ) : (
                    <span className="cmp-muted">нет данных</span>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      </div>
    </>
  );

  return createPortal(content, target);
}
