/**
 * Personal Records — лучшие результаты на ключевых дистанциях за 52 недели.
 * 4 карточки: 5K / 10K / Half / Marathon. У каждой — время, темп, дата, VDOT.
 * Пустая карточка = ещё нет результата (показывает «—»).
 */

import { useEffect, useState } from 'react';
import { TrophyIcon } from '../common/Icons';
import './PersonalRecordsWidget.css';

const BUCKETS = [
  { key: '5k', label: '5K' },
  { key: '10k', label: '10K' },
  { key: 'half', label: 'Полу' },
  { key: 'marathon', label: 'Марафон' },
];

const MONTHS_SHORT = ['янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];

function formatTime(sec) {
  if (!sec || sec <= 0) return '—';
  const h = Math.floor(sec / 3600);
  const m = Math.floor((sec % 3600) / 60);
  const s = sec % 60;
  if (h > 0) return `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
  return `${m}:${String(s).padStart(2, '0')}`;
}

function formatPace(sec) {
  if (!sec || sec <= 0) return '';
  const m = Math.floor(sec / 60);
  const s = sec % 60;
  return `${m}:${String(s).padStart(2, '0')}`;
}

function formatDate(iso) {
  if (!iso) return '';
  const [y, m, d] = String(iso).split('-').map(Number);
  if (!y || !m || !d) return '';
  return `${d} ${MONTHS_SHORT[m - 1]} ${String(y).slice(-2)}`;
}

const PersonalRecordsWidget = ({ api }) => {
  const [records, setRecords] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!api?.getPersonalRecords) {
      setLoading(false);
      return;
    }
    let cancelled = false;
    (async () => {
      try {
        const res = await api.getPersonalRecords();
        const list = res?.data?.records ?? res?.records ?? [];
        const byKey = {};
        list.forEach((r) => {
          if (r?.distance_label) byKey[r.distance_label] = r;
        });
        if (!cancelled) setRecords(byKey);
      } catch {
        if (!cancelled) setRecords({});
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, [api]);

  if (loading) {
    return (
      <div className="pr-widget">
        {BUCKETS.map((b) => (
          <div key={b.key} className="pr-card pr-card--skeleton" />
        ))}
      </div>
    );
  }

  return (
    <div className="pr-widget">
      {BUCKETS.map((b) => {
        const r = records?.[b.key];
        const hasData = !!r && r.time_sec > 0;
        return (
          <div
            key={b.key}
            className={`pr-card ${hasData ? '' : 'pr-card--empty'}`}
          >
            <div className="pr-card__head">
              <span className="pr-card__label">{b.label}</span>
              {hasData && r.vdot && (
                <span className="pr-card__vdot">VDOT {r.vdot}</span>
              )}
            </div>
            {hasData ? (
              <>
                <div className="pr-card__time">
                  <TrophyIcon size={16} className="pr-card__trophy" />
                  <span>{formatTime(r.time_sec)}</span>
                </div>
                <div className="pr-card__meta">
                  <span className="pr-card__pace">{formatPace(r.pace_sec)}/км</span>
                  <span className="pr-card__date">{formatDate(r.date)}</span>
                </div>
              </>
            ) : (
              <>
                <div className="pr-card__time pr-card__time--empty">—</div>
                <div className="pr-card__meta">
                  <span className="pr-card__empty-hint">нет результата</span>
                </div>
              </>
            )}
          </div>
        );
      })}
    </div>
  );
};

export default PersonalRecordsWidget;
