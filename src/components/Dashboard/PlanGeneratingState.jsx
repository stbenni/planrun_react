/**
 * Полноэкранное (в пределах дашборда) состояние «План генерируется».
 * Показывается на дашборде, когда usePlanStore.isGenerating === true — в т.ч. после
 * перезагрузки страницы (initPlanStatus восстанавливает статус с бэкенда). Раньше при F5
 * во время генерации пользователь видел пустой дашборд; теперь — наглядный прогресс.
 *
 * Визуально согласован с экраном генерации в онбординге (StepGenerating).
 */

import './PlanGeneratingState.css';

const GEN_STEPS = [
  { state: 'done', text: 'Анализирую профиль и цель' },
  { state: 'done', text: 'Расставляю ключевые тренировки' },
  { state: 'active', text: 'Балансирую объём по фазам' },
  { state: 'todo', text: 'Подстраиваю под твои дни' },
];

export default function PlanGeneratingState({ label }) {
  return (
    <div className="dashboard pg-state">
      <div className="pg-state__inner">
        <div className="pg-state__mark-wrap">
          <div className="pg-state__mark">AI</div>
          <div className="pg-state__ring" aria-hidden />
        </div>

        <h1 className="pg-state__title">{label || 'Собираю твой план'}</h1>
        <p className="pg-state__sub">Это займёт 3–5 минут. Можно закрыть страницу — прогресс не потеряется.</p>

        <div className="pg-state__bar"><div className="pg-state__bar-fill" /></div>

        <div className="pg-state__steps">
          {GEN_STEPS.map((s, i) => (
            <div key={i} className={`pg-state__step pg-state__step--${s.state}`}>
              <span className="pg-state__step-mark">{s.state === 'done' ? '✓' : s.state === 'active' ? '⟳' : '○'}</span>
              <span>{s.text}</span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
