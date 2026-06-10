/* VolumeRailV3 — объём по неделям (порт VolumeRail из v3-calendar).
   horizontal=false → тонкие горизонтальные бары (мобайл, в карточке месяца);
   horizontal=true  → вертикальные бары (десктоп side-rail). */
import React from 'react';
import { PHASES } from './calV3';

export default function VolumeRailV3({ items = [], max = 1, horizontal = false }) {
  if (!items.length) return null;
  return (
    <div className={`calv3-vol ${horizontal ? 'calv3-vol--h' : 'calv3-vol--v'}`}>
      {items.map((w) => {
        const ph = PHASES[w.phase] || PHASES.build;
        const pct = max > 0 ? Math.min(1, w.vol / max) : 0;
        return (
          <div key={w.n} className="calv3-vol__row">
            <div className={`calv3-vol__name${w.current ? ' is-current' : ''}`}>
              {horizontal ? `Нед ${w.n}` : `Н${w.n}`}
            </div>
            {horizontal ? (
              <div className="calv3-vol__col">
                <span className="calv3-vol__col-num" style={w.current ? { color: ph.color } : undefined}>{w.vol}</span>
                <div className="calv3-vol__vbar">
                  <div className="calv3-vol__vfill" style={{ height: `${pct * 100}%`, background: ph.color, opacity: w.current ? 1 : 0.55 }} />
                </div>
              </div>
            ) : (
              <>
                <div className="calv3-vol__bar">
                  <div className="calv3-vol__fill" style={{ width: `${pct * 100}%`, background: ph.color, opacity: w.current ? 1 : 0.55 }} />
                </div>
                <span className={`calv3-vol__num${w.current ? ' is-current' : ''}`}>
                  {w.vol}<span className="calv3-vol__num-unit">км</span>
                </span>
              </>
            )}
          </div>
        );
      })}
    </div>
  );
}
