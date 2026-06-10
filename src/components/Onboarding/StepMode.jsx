/**
 * Шаг «Режим тренировок»: AI / Сам / Живой тренер (disabled «Скоро»).
 * Поведение как в старом RegisterScreen: coach недоступен; выбор AI/self ведёт дальше.
 */

import { CheckIcon, BotIcon, PenLineIcon, UserIcon } from '../common/Icons';

const MODES = [
  {
    id: 'ai',
    Icon: BotIcon,
    iconClass: 'ob-mode__icon--ai',
    title: 'AI-тренер',
    sub: 'Бесплатно · 24/7',
    recommend: true,
    features: ['AI создаст персональный план', 'Адаптирует его каждую неделю', 'Анализирует твой прогресс'],
  },
  {
    id: 'self',
    Icon: PenLineIcon,
    title: 'Сам',
    sub: 'Полный контроль',
    features: ['Создавай план сам', 'Добавляй тренировки вручную', 'Только календарь и статистика'],
  },
  {
    id: 'coach',
    Icon: UserIcon,
    title: 'Живой тренер',
    sub: 'от 1000 ₽/мес',
    soon: true,
    features: ['Персональный тренер', 'Корректировки плана в реальном времени', 'Поддержка и мотивация'],
  },
];

export default function StepMode({ formData, onChange, eyebrow }) {
  return (
    <div className="ob-step">
      <div className="ob-eyebrow">{eyebrow || 'ШАГ 1 ИЗ 3'}</div>
      <h1 className="ob-h1">Как будешь<br />тренироваться?</h1>
      <p className="ob-sub">Можно поменять в любой момент</p>

      <div className="ob-modes">
        {MODES.map((m) => {
          const active = formData.training_mode === m.id;
          const Icon = m.Icon;
          return (
            <button
              key={m.id}
              type="button"
              className={`ob-mode ${active ? 'ob-mode--active' : ''} ${m.soon ? 'ob-mode--disabled' : ''}`}
              disabled={m.soon}
              aria-pressed={active}
              onClick={() => { if (!m.soon) onChange('training_mode', m.id); }}
            >
              <div className="ob-mode__head">
                <div className={`ob-mode__icon ${m.iconClass || ''}`}>
                  <Icon size={24} aria-hidden />
                </div>
                <div className="ob-mode__titles">
                  <div className="ob-mode__title-row">
                    <span className="ob-mode__title">{m.title}</span>
                    {m.recommend && <span className="ob-badge-recommend">РЕКОМЕНДУЕМ</span>}
                    {m.soon && <span className="ob-badge-soon">СКОРО</span>}
                  </div>
                  <div className="ob-mode__sub">{m.sub}</div>
                </div>
                {!m.soon && (
                  <div className="ob-mode__radio">{active ? <CheckIcon size={12} /> : ''}</div>
                )}
              </div>
              {active && !m.soon && (
                <ul className="ob-mode__features">
                  {m.features.map((f) => (
                    <li key={f}><CheckIcon size={14} className="ob-goal__icon" aria-hidden />{f}</li>
                  ))}
                </ul>
              )}
            </button>
          );
        })}
      </div>
    </div>
  );
}
