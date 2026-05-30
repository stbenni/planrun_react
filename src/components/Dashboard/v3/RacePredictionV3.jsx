/**
 * RacePredictionV3 — VDOT-прогнозы для 4 дистанций (5/10/Полу/Марафон).
 * Целевая дистанция (из user.race_distance) подсвечивается оранжевым с pill «ЦЕЛЬ».
 * Δ показывает разницу с прошлой неделей (если доступна).
 */

import { useEffect, useState } from 'react';
import './RacePredictionV3.css';

const DISTANCES = [
  { key: '5k', label: '5 км' },
  { key: '10k', label: '10 км' },
  { key: 'half', label: '21.1 км · полумарафон' },
  { key: 'marathon', label: '42.2 км · марафон' },
];

const USER_DIST_TO_KEY = {
  '5k': '5k', '10k': '10k', half: 'half', half_marathon: 'half',
  marathon: 'marathon', '21.1k': 'half', '42.2k': 'marathon',
};

export default function RacePredictionV3({ api, user }) {
  const [data, setData] = useState(null);

  useEffect(() => {
    if (!api?.getRacePrediction) return undefined;
    let cancelled = false;
    api.getRacePrediction()
      .then((res) => { if (!cancelled) setData(res?.data || res); })
      .catch(() => { if (!cancelled) setData(null); });
    return () => { cancelled = true; };
  }, [api]);

  if (!data?.available || !data?.predictions) {
    return (
      <div className="card racepred-v3 racepred-v3--empty">
        <div className="racepred-v3__eyebrow">VDOT-ПРОГНОЗЫ</div>
        <div className="racepred-v3__placeholder">Недостаточно данных для прогноза.</div>
      </div>
    );
  }

  const vdot = data.vdot;
  const targetKey = USER_DIST_TO_KEY[user?.race_distance];

  return (
    <div className="card racepred-v3">
      <div className="racepred-v3__eyebrow">VDOT-ПРОГНОЗЫ {vdot && `· ${vdot}`}</div>
      <div className="racepred-v3__sub">На основе твоих недавних результатов и тренировок</div>
      <div className="racepred-v3__list">
        {DISTANCES.map((d) => {
          const pred = data.predictions[d.key];
          if (!pred) return null;
          const isTarget = d.key === targetKey;
          return (
            <div
              key={d.key}
              className={`racepred-v3__row ${isTarget ? 'racepred-v3__row--target' : ''}`}
            >
              <div className="racepred-v3__row-left">
                <span className="racepred-v3__row-dist">{d.label}</span>
                {isTarget && <span className="racepred-v3__row-target">ЦЕЛЬ</span>}
              </div>
              <div className="racepred-v3__row-right">
                <span className="racepred-v3__row-time">{pred.formatted}</span>
                {pred.pace_formatted && (
                  <span className="racepred-v3__row-pace">{pred.pace_formatted}</span>
                )}
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
