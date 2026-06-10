/* PhaseRibbonV3 — полоса фазы мезоцикла (порт PhaseRibbon из v3-calendar). */
import React from 'react';
import { PHASES, PHASE_ORDER } from './calV3';

export default function PhaseRibbonV3({ phase = 'build' }) {
  const ph = PHASES[phase];
  if (!ph) return null;
  return (
    <div className="calv3-phase">
      <span className="calv3-phase__label">ФАЗА</span>
      <div className="calv3-phase__track">
        {PHASE_ORDER.map((p) => {
          const on = p === phase;
          return (
            <div
              key={p}
              className={`calv3-phase__seg${on ? ' is-on' : ''}`}
              style={on ? { background: PHASES[p].color } : undefined}
            />
          );
        })}
      </div>
      <span className="calv3-phase__name" style={{ color: ph.color }}>{ph.label}</span>
    </div>
  );
}
