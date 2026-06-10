/**
 * Шаг «AI-оценка цели». Данные приходят из api.assessGoal (вызывает оркестратор с debounce).
 * Контракт результата 1:1 с прежним RegisterScreen: verdict / messages / predictions / training_paces.
 */

import { ClipboardListIcon, TimeIcon, CheckIcon, AlertTriangleIcon, XCircleIcon } from '../common/Icons';

const PACE_BARS = {
  easy: 'var(--info-500)',
  marathon: 'var(--primary-400)',
  threshold: 'var(--warning-500)',
  interval: 'var(--danger-500)',
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
  realistic: { cls: 'realistic', title: 'Цель реалистична', Icon: CheckIcon },
  challenging: { cls: 'challenging', title: 'Амбициозная цель', Icon: AlertTriangleIcon },
  // caution — смягчённый registration-вердикт (бывший unrealistic): не блокирует, ободряет
  caution: { cls: 'challenging', title: 'Очень амбициозная цель', Icon: AlertTriangleIcon },
  unrealistic: { cls: 'unrealistic', title: 'Цель труднодостижима', Icon: XCircleIcon },
};

export default function StepAssessment({ formData, assessment, loading, onApplySuggestion }) {
  const v = assessment?.verdict ? VERDICT[assessment.verdict] : null;

  return (
    <div className="ob-step">
      <div className="ob-eyebrow">AI ПРОВЕРЯЕТ ЦЕЛЬ{assessment?.vdot ? ` · VDOT ${assessment.vdot}` : ''}</div>
      <h1 className="ob-h1">Оценка цели</h1>
      <p className="ob-sub">Реалистичность и тренировочные темпы</p>

      <div className="ob-assess" style={{ marginTop: 18 }}>
        {/* Нет данных */}
        {!assessment && !loading && (
          <div className="ob-card">
            <div className="ob-assess-loading">
              <ClipboardListIcon size={18} aria-hidden />
              <span>
                {!formData.race_distance
                  ? 'Укажите дистанцию забега на шаге «Цель».'
                  : !formData.race_date
                    ? 'Укажите дату забега на шаге «Цель».'
                    : 'Заполните профиль — оценка появится автоматически.'}
              </span>
            </div>
          </div>
        )}

        {/* Загрузка */}
        {loading && (
          <div className="ob-card">
            <div className="ob-assess-loading">
              <span className="ob-spin"><TimeIcon size={18} aria-hidden /></span>
              <span>Оцениваем цель...</span>
            </div>
          </div>
        )}

        {/* Результат */}
        {assessment && !loading && v && (
          <>
            <div className={`ob-verdict ob-verdict--${v.cls}`}>
              <div className="ob-verdict__head">
                <span className="ob-verdict__badge"><v.Icon size={22} aria-hidden /></span>
                <span className="ob-verdict__title">{v.title}</span>
              </div>
              {assessment.messages?.map((msg, i) => (
                <div key={i}>
                  <p className="ob-verdict__msg">{msg.text}</p>
                  {msg.suggestions?.length > 0 && (
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginTop: 10 }}>
                      {msg.suggestions.map((s, j) => (
                        s.action ? (
                          <button key={j} type="button" className="ob-adjust"
                            onClick={() => onApplySuggestion(s.action.field, s.action.value)}>
                            <span className="ob-adjust__text">{s.text}</span>
                            <span className="ob-adjust__tag">применить</span>
                          </button>
                        ) : (
                          <p key={j} className="ob-hint">{s.text}</p>
                        )
                      ))}
                    </div>
                  )}
                </div>
              ))}
            </div>

            {assessment.vdot && (
              <div className="ob-vdot">
                VDOT: <strong>{assessment.vdot}</strong>
                {assessment.vdot_source ? ` (на основе: ${assessment.vdot_source})` : ''}
              </div>
            )}

            {/* Прозрачность: на чём построен прогноз — чтобы вердикт не выглядел «магией» */}
            {(() => {
              const basis = buildBasis(formData);
              return basis ? <div className="ob-hint" style={{ marginTop: -6 }}>Прогноз учитывает {basis}.</div> : null;
            })()}

            {assessment.predictions && Object.keys(assessment.predictions).length > 0 && (
              <div>
                <div className="ob-section-title">ПРОГНОЗ ПО ДИСТАНЦИЯМ</div>
                <div className="ob-card">
                  {Object.entries(assessment.predictions).map(([dist, time]) => (
                    <div key={dist} className="ob-pred-row">
                      <span className="ob-pred-row__label">{DIST_LABELS[dist] || dist.toUpperCase()}</span>
                      <span className="ob-pred-row__value">{time}</span>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {assessment.training_paces && (
              <div>
                <div className="ob-section-title">ТРЕНИРОВОЧНЫЕ ТЕМПЫ /КМ</div>
                <div className="ob-pace-grid">
                  {['easy', 'marathon', 'threshold', 'interval'].map((zone) => (
                    assessment.training_paces[zone] ? (
                      <div key={zone} className="ob-pace-cell">
                        <span className="ob-pace-cell__bar" style={{ background: PACE_BARS[zone] }} />
                        <div style={{ minWidth: 0 }}>
                          <div className="ob-pace-cell__label">{PACE_LABELS[zone]}</div>
                          <div className="ob-pace-cell__value">{assessment.training_paces[zone]}</div>
                        </div>
                      </div>
                    ) : null
                  ))}
                </div>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  );
}
