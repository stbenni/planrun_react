/**
 * Шаг «Цель» (только для режимов с планом, не для self).
 * Логика и набор полей прежние; вёрстка — v3B по моку BObGoal:
 * карточки целей в сетке (иконка/название/подпись), программы-радио, дата человеком.
 */

import { PrField } from '../ui';
import { ObHeading, ObSection, ObSeg, ObGoalCard, ObOptionCard, ObDateCard, ObHint } from './obKit';
import { GOALS, HEALTH_PROGRAMS, HEALTH_PLAN_WEEKS, RACE_DISTANCES } from './onboardingForm';
import { formatDurationMask, normalizeDuration } from '../../utils/durationMask';

const GOAL_ICONS = { heart: 'heart', medal: 'run', flame: 'flame', time: 'stats' };
const DIST_SHORT = { '5k': '5 км', '10k': '10 км', half: '21,1', marathon: '42,2' };

const tomorrow = () => new Date(Date.now() + 86400000).toISOString().split('T')[0];
const inFourWeeks = () => new Date(Date.now() + 28 * 86400000).toISOString().split('T')[0];
const today = () => new Date().toISOString().split('T')[0];

/**
 * Клиентская оценка реалистичности темпа похудения (мгновенный фидбэк, без round-trip).
 * Опирается на медицинский консенсус: безопасно ~0.5–1% массы тела в неделю.
 */
function assessWeightLoss({ currentKg, targetKg, dateStr }) {
  const cur = parseFloat(currentKg);
  const tgt = parseFloat(targetKg);
  if (!cur || !tgt || !dateStr) return null;
  if (tgt >= cur) {
    return { kind: 'warn', text: 'Целевой вес не меньше текущего — для похудения он должен быть ниже.' };
  }
  const weeks = (new Date(dateStr) - new Date()) / (7 * 86400000);
  if (weeks < 1) return null;
  const totalLoss = cur - tgt;
  const perWeek = totalLoss / weeks;
  const pctPerWeek = (perWeek / cur) * 100;
  const rate = perWeek.toFixed(1);

  if (pctPerWeek > 1.5) {
    return { kind: 'bad', rate, text: `Это ~${rate} кг/нед — слишком быстро и рискованно для здоровья. Безопасный темп — до 1% веса в неделю. Лучше отодвинуть дату.` };
  }
  if (pctPerWeek > 1.0) {
    return { kind: 'warn', rate, text: `Это ~${rate} кг/нед — амбициозно. Достижимо при строгом режиме, но безопаснее чуть медленнее.` };
  }
  return { kind: 'ok', rate, text: `Это ~${rate} кг/нед — здоровый, устойчивый темп. Отличная цель.` };
}

const VERDICT_COLORS = { ok: 'var(--pr-good)', warn: 'var(--pr-accent)', bad: 'var(--pr-bad)' };

export default function StepGoal({ formData, onChange }) {
  const goal = formData.goal_type;
  const isRace = goal === 'race' || goal === 'time_improvement';
  const weightVerdict = goal === 'weight_loss'
    ? assessWeightLoss({ currentKg: formData.weight_kg, targetKg: formData.weight_goal_kg, dateStr: formData.weight_goal_date })
    : null;

  return (
    <div>
      <ObHeading title="Какая цель?" sub="Под неё соберём персональный план." />

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8, marginTop: 16 }} role="radiogroup" aria-label="Тип цели">
        {GOALS.map((g) => (
          <ObGoalCard
            key={g.value}
            active={goal === g.value}
            icon={GOAL_ICONS[g.iconKey] || 'run'}
            title={g.title}
            sub={g.desc}
            onClick={() => onChange('goal_type', g.value)}
          />
        ))}
      </div>

      {/* Забег / Улучшить время */}
      {isRace && (
        <>
          <ObSection>Целевая дистанция</ObSection>
          <div style={{ display: 'flex', gap: 6 }} role="radiogroup" aria-label="Целевая дистанция">
            {RACE_DISTANCES.map((d) => (
              <ObSeg
                key={d.value}
                active={formData.race_distance === d.value}
                onClick={() => onChange('race_distance', d.value)}
                style={{ flex: 1 }}
              >
                {DIST_SHORT[d.value] || d.label}
              </ObSeg>
            ))}
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 9, marginTop: 14 }}>
            <PrField
              label={goal === 'race' ? 'Дата забега *' : 'Дата забега'}
              type="date"
              value={formData.race_date}
              min={tomorrow()}
              onChange={(e) => onChange('race_date', e.target.value)}
              required={goal === 'race'}
            />
            <PrField
              label="Целевое время"
              type="text"
              inputMode="numeric"
              autoComplete="off"
              maxLength={8}
              placeholder="3:30:00"
              value={formData.race_target_time}
              onChange={(e) => onChange('race_target_time', formatDurationMask(e.target.value))}
              onBlur={(e) => onChange('race_target_time', normalizeDuration(e.target.value))}
            />
          </div>
          <ObHint>Целевое время — в формате Ч:ММ:СС (например 1:45:00). Для «Улучшить время» дата — дата забега, в будущем.</ObHint>
        </>
      )}

      {/* Похудение */}
      {goal === 'weight_loss' && (
        <>
          <ObSection>Параметры цели</ObSection>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 9 }}>
            <PrField
              label="Текущий вес, кг *"
              type="number" min="30" max="250" step="0.1" placeholder="85"
              value={formData.weight_kg}
              onChange={(e) => onChange('weight_kg', e.target.value)}
            />
            <PrField
              label="Целевой вес, кг *"
              type="number" min="30" max="250" step="0.1" placeholder="75"
              value={formData.weight_goal_kg}
              onChange={(e) => onChange('weight_goal_kg', e.target.value)}
            />
          </div>
          <PrField
            label="К дате *"
            type="date"
            value={formData.weight_goal_date}
            min={inFourWeeks()}
            onChange={(e) => onChange('weight_goal_date', e.target.value)}
            style={{ marginTop: 9 }}
          />
          {weightVerdict ? (
            <div
              className="pr-card"
              style={{
                marginTop: 9,
                padding: '11px 14px',
                border: `1px solid ${VERDICT_COLORS[weightVerdict.kind]}`,
                fontSize: 12,
                lineHeight: 1.45,
                color: 'var(--pr-ink)',
              }}
            >
              {weightVerdict.text}
            </div>
          ) : (
            <ObHint>Безопасный темп — до 1% веса в неделю. Минимум 4 недели от сегодня.</ObHint>
          )}
        </>
      )}

      {/* Здоровье */}
      {goal === 'health' && (
        <>
          <ObSection>Программа</ObSection>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }} role="radiogroup" aria-label="Программа">
            {HEALTH_PROGRAMS.map((p) => (
              <ObOptionCard
                key={p.value}
                active={formData.health_program === p.value}
                onClick={() => onChange('health_program', p.value)}
                title={p.name}
                sub={`${p.duration} · ${p.desc}`}
              />
            ))}
          </div>
          {formData.health_program === 'custom' && (
            <>
              <ObSection>На какой срок план? *</ObSection>
              <select
                className="pr-field"
                value={formData.health_plan_weeks}
                onChange={(e) => onChange('health_plan_weeks', e.target.value)}
              >
                <option value="">Выберите...</option>
                {HEALTH_PLAN_WEEKS.map((w) => <option key={w.value} value={w.value}>{w.label}</option>)}
              </select>
            </>
          )}
        </>
      )}

      {/* Дата старта — общее обязательное поле */}
      {goal && (
        <div style={{ marginTop: 14 }}>
          <ObDateCard
            label="Старт плана"
            value={formData.training_start_date}
            min={today()}
            onChange={(e) => onChange('training_start_date', e.target.value)}
          />
          <ObHint>План будет рассчитан от этой даты до цели.</ObHint>
        </div>
      )}
    </div>
  );
}
