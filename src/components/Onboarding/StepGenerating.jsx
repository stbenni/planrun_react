/**
 * Финальный экран: «Собираю твой план» (ai/both) или «Календарь готов» (self).
 * Чисто презентационный — генерация идёт в очереди на бэкенде; дашборд покажет статус.
 */

import { CheckIcon } from '../common/Icons';

const GEN_STEPS = [
  { state: 'done', text: 'Анализирую профиль и цель' },
  { state: 'done', text: 'Расставляю ключевые тренировки' },
  { state: 'active', text: 'Балансирую объём по фазам' },
  { state: 'todo', text: 'Подстраиваю под твои дни' },
];

export default function StepGenerating({ isPlanMode, planMessage, onDone }) {
  return (
    <div className="ob-step ob-generating">
      <div className="ob-gen-mark-wrap">
        <div className={`ob-gen-mark ${isPlanMode ? '' : 'ob-gen-mark--self'}`}>
          {isPlanMode ? 'AI' : <CheckIcon size={36} />}
        </div>
        {isPlanMode && <div className="ob-gen-ring" aria-hidden />}
      </div>

      <h1 className="ob-gen-title">{isPlanMode ? 'Собираю твой план' : 'Календарь готов!'}</h1>
      <p className="ob-gen-sub">
        {planMessage || (isPlanMode
          ? 'Это займёт 3–5 минут. На дашборде отобразится статус.'
          : 'Добавляйте тренировки на любую дату.')}
      </p>

      {isPlanMode && (
        <>
          <div className="ob-gen-bar"><div className="ob-gen-bar__fill" /></div>
          <div className="ob-gen-steps">
            {GEN_STEPS.map((s, i) => (
              <div key={i} className={`ob-gen-step ob-gen-step--${s.state}`}>
                <span className="ob-gen-step__mark">{s.state === 'done' ? '✓' : s.state === 'active' ? '⟳' : '○'}</span>
                <span>{s.text}</span>
              </div>
            ))}
          </div>
        </>
      )}

      <button type="button" className="ob-cta" style={{ marginTop: 24 }} onClick={onDone}>
        {isPlanMode ? 'Перейти на дашборд →' : 'Открыть календарь →'}
      </button>
    </div>
  );
}
