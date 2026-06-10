/* PhasesListV3 — карточка «Фазы мезоцикла» (список недель с фазой/объёмом + ⓘ-пояснение).
   Общая для недельного и месячного видов (десктоп, правый rail). */
import React from 'react';
import InfoTip from './InfoTip';
import { PHASES } from './calV3';

// Краткие пояснения фаз для тултипа (ключ → описание)
const PHASE_HELP = [
  ['base', 'набираем объём'],
  ['build', 'растёт нагрузка'],
  ['peak', 'пик интенсивности'],
  ['taper', 'снижаем перед целью'],
  ['recovery', 'разгрузочная неделя'],
];

function PhasesHelp() {
  return (
    <div className="calv3-phasehelp">
      <div className="calv3-phasehelp__title">Фазы мезоцикла</div>
      {PHASE_HELP.map(([key, desc]) => (
        <div key={key} className="calv3-phasehelp__row">
          <span className="calv3-phasehelp__dot" style={{ background: PHASES[key].color }} />
          <span><b>{PHASES[key].label}</b> — {desc}</span>
        </div>
      ))}
    </div>
  );
}

export default function PhasesListV3({ items }) {
  if (!items?.length) return null;
  return (
    <div className="calv3-card">
      <div className="calv3-card-label calv3-card-label--row">
        <span>ФАЗЫ МЕЗОЦИКЛА</span>
        <InfoTip label="Что такое фазы мезоцикла"><PhasesHelp /></InfoTip>
      </div>
      <div className="calv3-phases-list">
        {items.map((w) => {
          const ph = PHASES[w.phase] || PHASES.build;
          return (
            <div key={w.n} className="calv3-phase-row">
              <span className="calv3-phase-row__bar" style={{ background: ph.color }} />
              <div className="calv3-phase-row__body">
                <div className="calv3-phase-row__title">Нед {w.n} · {ph.label}</div>
                <div className="calv3-phase-row__sub">{w.range} · {w.vol} км</div>
              </div>
              {w.current && <span className="calv3-tag">СЕЙЧАС</span>}
            </div>
          );
        })}
      </div>
    </div>
  );
}
