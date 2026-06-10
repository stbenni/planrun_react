/**
 * Шаг «Режим тренировок»: AI / Сам / Живой тренер (disabled «Скоро»).
 * Поведение прежнее: coach недоступен; выбор AI/self ведёт дальше. Вёрстка — v3B (BModeSwitch).
 */

import { PrIcon, PrLabel } from '../ui';
import { ObHeading, ObOptionCard } from './obKit';

const MODES = [
  {
    id: 'ai',
    glyph: 'AI',
    grad: true,
    title: 'AI-тренер',
    sub: 'Бесплатно · отвечает мгновенно · 24/7',
    recommend: true,
    features: ['AI создаст персональный план', 'Адаптирует его каждую неделю', 'Анализирует твой прогресс'],
  },
  {
    id: 'self',
    glyph: '✎',
    title: 'Сам',
    sub: 'Полный контроль над планом — без подсказок',
    features: ['Создавай план сам', 'Добавляй тренировки вручную', 'Только календарь и статистика'],
  },
  {
    id: 'coach',
    glyph: 'СК',
    title: 'Живой тренер',
    sub: 'Персональный план · человеческий подход',
    soon: true,
    features: [],
  },
];

export default function StepMode({ formData, onChange }) {
  return (
    <div>
      <ObHeading title={<>Как будешь<br />тренироваться?</>} sub="Можно поменять в любой момент." />
      <div style={{ display: 'flex', flexDirection: 'column', gap: 9, marginTop: 16 }} role="radiogroup" aria-label="Режим тренировок">
        {MODES.map((m) => {
          const active = formData.training_mode === m.id;
          return (
            <ObOptionCard
              key={m.id}
              active={active}
              disabled={m.soon}
              onClick={() => onChange('training_mode', m.id)}
              leading={
                <span
                  style={{
                    width: 44,
                    height: 44,
                    borderRadius: 999,
                    flexShrink: 0,
                    background: m.grad ? 'var(--pr-grad)' : 'var(--pr-card-2)',
                    border: m.grad ? 'none' : '1px solid var(--pr-card-border)',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    fontFamily: 'var(--pr-font-display)',
                    fontSize: 13,
                    fontWeight: 700,
                    color: m.grad ? '#fff' : 'var(--pr-ink)',
                  }}
                >
                  {m.glyph}
                </span>
              }
              title={
                <span style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                  {m.title}
                  {m.recommend && (
                    <PrLabel
                      size={8}
                      color="var(--pr-accent)"
                      style={{ display: 'inline-block', border: '1px solid var(--pr-accent)', borderRadius: 999, padding: '3px 8px' }}
                    >
                      рекомендуем
                    </PrLabel>
                  )}
                  {m.soon && (
                    <PrLabel
                      size={8}
                      style={{ display: 'inline-block', border: '1px solid var(--pr-card-border)', borderRadius: 999, padding: '3px 8px' }}
                    >
                      скоро
                    </PrLabel>
                  )}
                </span>
              }
              sub={m.sub}
              trailing={
                !m.soon && (
                  <span
                    style={{
                      width: 20,
                      height: 20,
                      borderRadius: 999,
                      flexShrink: 0,
                      border: active ? 'none' : '2px solid var(--pr-sub)',
                      background: active ? 'var(--pr-grad)' : 'transparent',
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'center',
                    }}
                  >
                    {active && PrIcon.check('#fff', 11)}
                  </span>
                )
              }
            >
              {active && m.features.length > 0 && (
                <ul style={{ listStyle: 'none', margin: '11px 0 0', padding: '11px 0 0', borderTop: '1px solid var(--pr-line)', display: 'flex', flexDirection: 'column', gap: 6 }}>
                  {m.features.map((f) => (
                    <li key={f} style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 12, color: 'var(--pr-sub)' }}>
                      {PrIcon.check('var(--pr-good)', 13)}
                      {f}
                    </li>
                  ))}
                </ul>
              )}
            </ObOptionCard>
          );
        })}
      </div>
    </div>
  );
}
