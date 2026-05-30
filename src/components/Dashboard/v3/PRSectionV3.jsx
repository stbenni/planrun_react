/**
 * PRSectionV3 — 4 карточки личных рекордов (5K/10K/Полу/Марафон).
 * Фетчит данные из API.getPersonalRecords() и мапит на 4 фиксированных дистанции.
 */

import { useEffect, useState } from 'react';
import './PRSectionV3.css';

// Диапазоны как у backend StatsService::getBestRacesProgression()
const SLOTS = [
  { label: '5K', min: 4.5, max: 5.5 },
  { label: '10K', min: 8.5, max: 11.5 },
  { label: '21.1K', min: 19.5, max: 22.5 },
  { label: '42.2K', min: 40.0, max: 44.0 },
];

const MONTHS_GEN = ['янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];

function formatDateShort(iso) {
  if (!iso) return null;
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return null;
  return `${d.getDate()} ${MONTHS_GEN[d.getMonth()]}`;
}

function isFreshDate(iso) {
  if (!iso) return false;
  const t = Date.parse(iso);
  if (Number.isNaN(t)) return false;
  return (Date.now() - t) < 14 * 86400000; // 14 дней
}

export default function PRSectionV3({ api, compact = false }) {
  const [records, setRecords] = useState(null);

  useEffect(() => {
    if (!api?.getPersonalRecords) return undefined;
    let cancelled = false;
    api.getPersonalRecords()
      .then((res) => {
        if (cancelled) return;
        const list = res?.data?.records || res?.records || [];
        setRecords(mapRecordsToSlots(list));
      })
      .catch(() => { if (!cancelled) setRecords(SLOTS.map((s) => ({ label: s.label, time: '—' }))); });
    return () => { cancelled = true; };
  }, [api]);

  const list = records || SLOTS.map((s) => ({ label: s.label, time: '—' }));

  return (
    <div className="card pr-v3">
      <div className="pr-v3__eyebrow">ЛИЧНЫЕ РЕКОРДЫ</div>
      <div className={`pr-v3__grid ${compact ? 'pr-v3__grid--compact' : ''}`}>
        {list.map((pr) => {
          const empty = !pr.time || pr.time === '—';
          return (
            <div
              key={pr.label}
              className={`pr-v3__card ${empty ? 'pr-v3__card--empty' : ''} ${pr.fresh ? 'pr-v3__card--fresh' : ''}`}
            >
              {pr.fresh && <span className="pr-v3__badge">★ НОВЫЙ</span>}
              <div className="pr-v3__card-head">
                <span className="pr-v3__card-label">{pr.label}</span>
                {pr.vdot && <span className="pr-v3__vdot">VDOT {pr.vdot}</span>}
              </div>
              <div className={`pr-v3__time ${empty ? 'pr-v3__time--empty' : ''}`}>
                {pr.time || '—'}
              </div>
              {pr.date && <div className="pr-v3__date">{pr.date}</div>}
            </div>
          );
        })}
      </div>
    </div>
  );
}

function mapRecordsToSlots(records) {
  return SLOTS.map((slot) => {
    const matches = records.filter((r) => {
      const d = Number(r.distance_km || r.distance);
      return d >= slot.min && d <= slot.max;
    });
    if (matches.length === 0) return { label: slot.label, time: '—' };
    // Берём лучшее (минимальное) время. Backend отдаёт `time_sec`, fallback на result_time/time.
    const best = matches.reduce((b, m) => {
      const bt = recordTimeSec(b);
      const mt = recordTimeSec(m);
      return mt < bt ? m : b;
    });
    return {
      label: slot.label,
      time: formatTime(best),
      date: formatDateShort(best.date || best.training_date),
      vdot: best.vdot ? Math.round(best.vdot) : null,
      fresh: isFreshDate(best.date || best.training_date),
    };
  });
}

function recordTimeSec(r) {
  if (r?.time_sec != null) return Number(r.time_sec) || Infinity;
  const t = r?.result_time || r?.time;
  if (!t) return Infinity;
  const parts = String(t).split(':').map((p) => parseInt(p, 10) || 0);
  if (parts.length === 3) return parts[0] * 3600 + parts[1] * 60 + parts[2];
  if (parts.length === 2) return parts[0] * 60 + parts[1];
  return Infinity;
}

function formatTime(r) {
  if (r?.result_time || r?.time) return r.result_time || r.time;
  const sec = recordTimeSec(r);
  if (!Number.isFinite(sec)) return '—';
  const h = Math.floor(sec / 3600);
  const m = Math.floor((sec % 3600) / 60);
  const s = sec % 60;
  if (h > 0) return `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
  return `${m}:${String(s).padStart(2, '0')}`;
}
