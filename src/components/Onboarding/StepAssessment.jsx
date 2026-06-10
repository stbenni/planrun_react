/**
 * Шаг «AI-оценка цели». Данные приходят из api.assessGoal (вызывает оркестратор с debounce).
 * Контракт результата прежний: verdict / messages / predictions / training_paces.
 * Вёрстка — v3B: кольцо VDOT, вердикт-карта с бордер-акцентом, зоны темпа точками.
 */

import { PrRing, PrLabel, PrLiveDot } from '../ui';
import { ObHeading, ObSection, ObHint } from './obKit';

const PACE_DOTS = {
  easy: 'var(--pr-sub)',
  marathon: 'var(--pr-good)',
  threshold: 'var(--pr-accent)',
  interval: 'var(--pr-accent-2)',
};
const PACE_LABELS = { easy: 'Лёгкий', marathon: 'Марафонский', threshold: 'Пороговый', interval: 'Интервальный' };
const DIST_LABELS = { '5k': '5 км', '10k': '10 км', half: 'Полумарафон', marathon: 'Марафон' };

/** Человекочитаемое описание того, на чём построен прогноз (прозрачность вердикта). */
function buildBasis(formData) {
  const distName = { '5k': '5 км', '10k': '10 км', half: 'полумарафон', marathon: 'марафон', other: 'другая дистанция' };
  if (formData.last_race_distance && formData.last_race_time) {
    const d = distName[formData.last_race_distance] || formData.last_race_distance;
    return `последний результат: ${d} за ${formData.last_race_time}`;
  }
  if (formData.easy_pace_min) {
    return `комфортный темп ${formData.easy_pace_min}/км`;
  }
  return null;
}

const VERDICT = {
  realistic: { color: 'var(--pr-good)', title: 'Цель реалистична' },
  challenging: { color: 'var(--pr-accent)', title: 'Амбициозная цель' },
  // caution — смягчённый registration-вердикт (бывший unrealistic): не блокирует, ободряет
  caution: { color: 'var(--pr-accent)', title: 'Очень амбициозная цель' },
  unrealistic: { color: 'var(--pr-bad)', title: 'Цель труднодостижима' },
};

export default function StepAssessment({ formData, assessment, loading, onApplySuggestion }) {
  const v = assessment?.verdict ? VERDICT[assessment.verdict] : null;

  return (
    <div>
      <ObHeading title="Оценка цели" sub="Реалистичность и тренировочные темпы." />

      <div style={{ display: 'flex', flexDirection: 'column', gap: 10, marginTop: 16 }}>
        {/* Нет данных */}
        {!assessment && !loading && (
          <div className="pr-card" style={{ padding: '14px 16px', fontSize: 12.5, color: 'var(--pr-sub)', lineHeight: 1.5 }}>
            {!formData.race_distance
              ? 'Укажи дистанцию забега на шаге «Цель».'
              : !formData.race_date
                ? 'Укажи дату забега на шаге «Цель».'
                : 'Заполни профиль — оценка появится автоматически.'}
          </div>
        )}

        {/* Загрузка */}
        {loading && (
          <div className="pr-card" style={{ padding: '14px 16px', display: 'flex', alignItems: 'center', gap: 10 }}>
            <PrLiveDot label="ai проверяет цель" />
            <span style={{ fontSize: 12.5, color: 'var(--pr-sub)' }}>Оцениваем…</span>
          </div>
        )}

        {/* Результат */}
        {assessment && !loading && v && (
          <>
            <div className="pr-card" style={{ padding: '16px 18px', border: `1px solid ${v.color}` }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
                {assessment.vdot && (
                  <PrRing pct={Math.min(1, assessment.vdot / 70)} size={84} stroke={8}>
                    <div style={{ fontFamily: 'var(--pr-font-display)', fontSize: 20, fontWeight: 700, color: 'var(--pr-ink)', lineHeight: 1 }}>
                      {assessment.vdot}
                    </div>
                    <PrLabel size={7.5}>vdot</PrLabel>
                  </PrRing>
                )}
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ fontFamily: 'var(--pr-font-display)', fontSize: 15, fontWeight: 700, color: v.color }}>
                    {v.title}
                  </div>
                  {assessment.messages?.map((msg, i) => (
                    <div key={i} style={{ fontSize: 12.5, color: 'var(--pr-ink)', opacity: 0.9, lineHeight: 1.5, marginTop: 6 }}>
                      {msg.text}
                    </div>
                  ))}
                </div>
              </div>
              {assessment.messages?.some((m) => m.suggestions?.length > 0) && (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 7, marginTop: 12 }}>
                  {assessment.messages.flatMap((msg, i) =>
                    (msg.suggestions || []).map((s, j) =>
                      s.action ? (
                        <button
                          key={`${i}-${j}`}
                          type="button"
                          className="pr-press"
                          onClick={() => onApplySuggestion(s.action.field, s.action.value)}
                          style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: 10,
                            padding: '10px 13px',
                            borderRadius: 12,
                            background: 'var(--pr-card-2)',
                            border: '1px solid var(--pr-card-border)',
                            cursor: 'pointer',
                            textAlign: 'left',
                          }}
                        >
                          <span style={{ flex: 1, fontSize: 12, fontWeight: 600, color: 'var(--pr-ink)', fontFamily: 'var(--pr-font-body)' }}>{s.text}</span>
                          <PrLabel size={8.5} color="var(--pr-accent)" style={{ border: '1px solid var(--pr-accent)', borderRadius: 999, padding: '4px 9px' }}>
                            применить
                          </PrLabel>
                        </button>
                      ) : (
                        <div key={`${i}-${j}`} style={{ fontSize: 11.5, color: 'var(--pr-sub)', lineHeight: 1.45 }}>{s.text}</div>
                      )
                    )
                  )}
                </div>
              )}
            </div>

            {(() => {
              const basis = buildBasis(formData);
              const src = assessment.vdot_source ? `VDOT на основе: ${assessment.vdot_source}. ` : '';
              return (basis || src) ? <ObHint style={{ marginTop: 0 }}>{src}{basis ? `Прогноз учитывает ${basis}.` : ''}</ObHint> : null;
            })()}

            {assessment.predictions && Object.keys(assessment.predictions).length > 0 && (
              <div>
                <ObSection style={{ margin: '8px 0 8px' }}>Прогноз по дистанциям</ObSection>
                <div className="pr-card" style={{ padding: '4px 16px' }}>
                  {Object.entries(assessment.predictions).map(([dist, time], i) => (
                    <div key={dist} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '9px 0', borderTop: i ? '1px solid var(--pr-line)' : 'none' }}>
                      <span style={{ fontSize: 13, fontWeight: 600, color: 'var(--pr-sub)' }}>{DIST_LABELS[dist] || dist.toUpperCase()}</span>
                      <span style={{ fontFamily: 'var(--pr-font-display)', fontSize: 14, fontWeight: 700, color: 'var(--pr-ink)' }}>{time}</span>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {assessment.training_paces && (
              <div>
                <ObSection style={{ margin: '8px 0 8px' }}>Тренировочные темпы · /км</ObSection>
                <div className="pr-card" style={{ padding: '4px 16px' }}>
                  {['easy', 'marathon', 'threshold', 'interval'].map((zone, i) =>
                    assessment.training_paces[zone] ? (
                      <div key={zone} style={{ display: 'flex', alignItems: 'center', gap: 9, padding: '8px 0', borderTop: i ? '1px solid var(--pr-line)' : 'none' }}>
                        <span style={{ width: 7, height: 7, borderRadius: 999, background: PACE_DOTS[zone], flexShrink: 0 }} />
                        <span style={{ flex: 1, fontSize: 12.5, fontWeight: 600, color: 'var(--pr-sub)' }}>{PACE_LABELS[zone]}</span>
                        <span style={{ fontFamily: 'var(--pr-font-display)', fontSize: 13.5, fontWeight: 700, color: 'var(--pr-ink)' }}>
                          {assessment.training_paces[zone]}
                        </span>
                      </div>
                    ) : null
                  )}
                </div>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  );
}
