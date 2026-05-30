/**
 * PaceZonesSectionV3 — таблица тренировочных зон с темпами.
 * Фетчит training_paces через api.getRacePrediction() (там есть formattedPaces для VDOT юзера).
 */

import { useEffect, useState } from 'react';
import { WORKOUT_TYPE_COLOR } from '../../Coach/CoachPrimitives';
import './PaceZonesSectionV3.css';

const DEFAULT_ZONES = [
  { type: 'rest', name: 'Восстановительный', pace: '—', use: 'после тяжёлых' },
  { type: 'easy', name: 'Лёгкий (E)', pace: '—', use: 'база, 75–80% объёма' },
  { type: 'long', name: 'Марафонский (M)', pace: '—', use: 'длительные' },
  { type: 'tempo', name: 'Пороговый (T)', pace: '—', use: 'темповые' },
  { type: 'interval', name: 'Интервальный (I)', pace: '—', use: 'VO2max' },
];

/** Маппинг ключей backend training_paces на нашу шкалу зон. */
function buildZonesFromPaces(paces) {
  if (!paces) return null;
  return [
    { type: 'rest', name: 'Восстановительный', pace: deriveRecoveryPace(paces.easy), use: 'после тяжёлых' },
    { type: 'easy', name: 'Лёгкий (E)', pace: paces.easy || '—', use: 'база, 75–80% объёма' },
    { type: 'long', name: 'Марафонский (M)', pace: paces.marathon || '—', use: 'длительные' },
    { type: 'tempo', name: 'Пороговый (T)', pace: paces.threshold || '—', use: 'темповые' },
    { type: 'interval', name: 'Интервальный (I)', pace: paces.interval || '—', use: 'VO2max' },
  ];
}

/**
 * Восстановительный темп = easy + 30–45 сек/км сверху.
 * Парсим easy «6:00 – 6:30» или «6:00», добавляем секунды.
 */
function deriveRecoveryPace(easyStr) {
  if (!easyStr) return '—';
  // Если есть диапазон "X:YY – X:YY", берём верхнюю границу (более медленную) и добавляем 30 сек.
  // Если одиночный темп — также +30 сек.
  const parts = String(easyStr).split(/[–\-]/).map((s) => s.trim());
  const slow = parts[parts.length - 1];
  const m = slow.match(/(\d{1,2}):(\d{2})/);
  if (!m) return '—';
  const minToSec = (mm, ss) => mm * 60 + ss;
  const baseSec = minToSec(parseInt(m[1], 10), parseInt(m[2], 10));
  const recoveryLow = baseSec + 30;
  const recoveryHigh = baseSec + 60;
  const fmt = (s) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
  return `${fmt(recoveryLow)} – ${fmt(recoveryHigh)}`;
}

export default function PaceZonesSectionV3({ zones, api }) {
  const [fetched, setFetched] = useState(null);

  useEffect(() => {
    if (zones) return undefined;
    if (!api?.getRacePrediction) return undefined;
    let cancelled = false;
    api.getRacePrediction()
      .then((res) => {
        if (cancelled) return;
        const data = res?.data || res;
        if (data?.training_paces) {
          setFetched(buildZonesFromPaces(data.training_paces));
        }
      })
      .catch(() => {});
    return () => { cancelled = true; };
  }, [api, zones]);

  const list = (Array.isArray(zones) && zones.length > 0 ? zones : fetched) || DEFAULT_ZONES;
  return (
    <div className="card pace-v3">
      <div className="pace-v3__eyebrow">ТРЕНИРОВОЧНЫЕ ЗОНЫ</div>
      <div className="pace-v3__list">
        {list.map((z) => (
          <div key={z.name} className="pace-v3__row">
            <span className="pace-v3__stripe" style={{ background: WORKOUT_TYPE_COLOR[z.type] || 'var(--gray-400)' }} />
            <div className="pace-v3__info">
              <div className="pace-v3__name">{z.name}</div>
              <div className="pace-v3__use">{z.use}</div>
            </div>
            <span className="pace-v3__pace">{z.pace || '—'}</span>
          </div>
        ))}
      </div>
    </div>
  );
}
