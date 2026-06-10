/**
 * Шаг «Цель» (только для режимов с планом, не для self).
 * Цель + условные поля. Полный набор полей бэкенда из прежнего RegisterScreen.
 */

import {
  HeartIcon, MedalIcon, FlameIcon, TimeIcon, LeafIcon, RunningIcon, SettingsIcon, TargetIcon, CalendarIcon,
} from '../common/Icons';
import { GOALS, HEALTH_PROGRAMS, HEALTH_PLAN_WEEKS, RACE_DISTANCES } from './onboardingForm';
import { formatDurationMask, normalizeDuration } from '../../utils/durationMask';

const ICONS = {
  heart: HeartIcon, medal: MedalIcon, flame: FlameIcon, time: TimeIcon,
  leaf: LeafIcon, running: RunningIcon, settings: SettingsIcon,
};

const tomorrow = () => new Date(Date.now() + 86400000).toISOString().split('T')[0];
const inFourWeeks = () => new Date(Date.now() + 28 * 86400000).toISOString().split('T')[0];
const today = () => new Date().toISOString().split('T')[0];

/**
 * Клиентская оценка реалистичности темпа похудения (мгновенный фидбэк, без round-trip).
 * Опирается на медицинский консенсус: безопасно ~0.5–1% массы тела в неделю.
 * Возвращает { kind: 'ok'|'warn'|'bad', rate, text } либо null если данных не хватает.
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
  const perWeek = totalLoss / weeks; // кг/нед
  const pctPerWeek = (perWeek / cur) * 100; // % массы тела/нед
  const rate = perWeek.toFixed(1);

  // <0.5 кг — нездорово мало целевой? нет, мало — это просто медленно (ок).
  // Пороги по % массы тела/нед: ≤1% безопасно, 1–1.5% агрессивно, >1.5% нереально.
  if (pctPerWeek > 1.5) {
    return { kind: 'bad', rate, text: `Это ~${rate} кг/нед — слишком быстро и рискованно для здоровья. Безопасный темп — до 1% веса в неделю. Лучше отодвинуть дату.` };
  }
  if (pctPerWeek > 1.0) {
    return { kind: 'warn', rate, text: `Это ~${rate} кг/нед — амбициозно. Достижимо при строгом режиме, но безопаснее чуть медленнее.` };
  }
  return { kind: 'ok', rate, text: `Это ~${rate} кг/нед — здоровый, устойчивый темп. Отличная цель.` };
}

export default function StepGoal({ formData, onChange, eyebrow }) {
  const goal = formData.goal_type;
  const isRace = goal === 'race' || goal === 'time_improvement';
  const weightVerdict = goal === 'weight_loss'
    ? assessWeightLoss({ currentKg: formData.weight_kg, targetKg: formData.weight_goal_kg, dateStr: formData.weight_goal_date })
    : null;

  return (
    <div className="ob-step">
      <div className="ob-eyebrow">{eyebrow || 'ШАГ 2 ИЗ 3'}</div>
      <h1 className="ob-h1"><TargetIcon size={26} aria-hidden style={{ verticalAlign: '-4px', marginRight: 8, color: 'var(--primary-500)' }} />Какая цель?</h1>
      <p className="ob-sub">Под неё соберём план</p>

      <div className="ob-goals" role="radiogroup" aria-label="Тип цели">
        {GOALS.map((g) => {
          const Icon = ICONS[g.iconKey];
          const active = goal === g.value;
          return (
            <button
              key={g.value}
              type="button"
              role="radio"
              aria-checked={active}
              className={`ob-goal ${active ? 'ob-goal--active' : ''}`}
              onClick={() => onChange('goal_type', g.value)}
            >
              <Icon size={28} className="ob-goal__icon" aria-hidden />
              <span className="ob-goal__title">{g.title}</span>
              <span className="ob-goal__desc">{g.desc}</span>
            </button>
          );
        })}
      </div>

      {/* Забег / Улучшить время */}
      {isRace && (
        <div style={{ marginTop: 18 }}>
          <div className="ob-field">
            <label className="ob-label">Целевая дистанция</label>
            <div className="ob-seg-group">
              {RACE_DISTANCES.map((d) => (
                <button
                  key={d.value}
                  type="button"
                  className={`ob-seg ${formData.race_distance === d.value ? 'ob-seg--active' : ''}`}
                  onClick={() => onChange('race_distance', d.value)}
                >
                  {d.value === 'half' ? '21.1' : d.value === 'marathon' ? '42.2' : d.label}
                </button>
              ))}
            </div>
          </div>

          <div className="ob-row">
            <div className="ob-field ob-field--inline">
              <label className="ob-label">Дата забега {goal === 'race' && <span className="ob-req">*</span>}</label>
              <input
                type="date"
                className="ob-input"
                value={formData.race_date}
                min={tomorrow()}
                onChange={(e) => onChange('race_date', e.target.value)}
                required={goal === 'race'}
              />
            </div>
            <div className="ob-field ob-field--inline">
              <label className="ob-label">Целевое время</label>
              <input
                type="text"
                inputMode="numeric"
                autoComplete="off"
                maxLength={8}
                placeholder="3:30:00"
                className="ob-input ob-input--num"
                value={formData.race_target_time}
                onChange={(e) => onChange('race_target_time', formatDurationMask(e.target.value))}
                onBlur={(e) => onChange('race_target_time', normalizeDuration(e.target.value))}
              />
            </div>
          </div>
          <span className="ob-hint">Целевое время — в формате Ч:ММ:СС (например 1:45:00). Для «Улучшить время» дата — дата марафона, в будущем.</span>
        </div>
      )}

      {/* Похудение */}
      {goal === 'weight_loss' && (
        <div style={{ marginTop: 18 }}>
          <div className="ob-row">
            <div className="ob-field ob-field--inline">
              <label className="ob-label">Текущий вес, кг <span className="ob-req">*</span></label>
              <input
                type="number" min="30" max="250" step="0.1" placeholder="85"
                className="ob-input"
                value={formData.weight_kg}
                onChange={(e) => onChange('weight_kg', e.target.value)}
              />
            </div>
            <div className="ob-field ob-field--inline">
              <label className="ob-label">Целевой вес, кг <span className="ob-req">*</span></label>
              <input
                type="number" min="30" max="250" step="0.1" placeholder="75"
                className="ob-input"
                value={formData.weight_goal_kg}
                onChange={(e) => onChange('weight_goal_kg', e.target.value)}
              />
            </div>
          </div>
          <div className="ob-field">
            <label className="ob-label">К дате <span className="ob-req">*</span></label>
            <input
              type="date"
              className="ob-input"
              value={formData.weight_goal_date}
              min={inFourWeeks()}
              onChange={(e) => onChange('weight_goal_date', e.target.value)}
            />
          </div>
          {weightVerdict ? (
            <div className={`ob-weight-verdict ob-weight-verdict--${weightVerdict.kind}`}>
              {weightVerdict.text}
            </div>
          ) : (
            <span className="ob-hint">Безопасный темп — до 1% веса в неделю. Минимум 4 недели от сегодня.</span>
          )}
        </div>
      )}

      {/* Здоровье */}
      {goal === 'health' && (
        <div style={{ marginTop: 18 }}>
          <div className="ob-field">
            <label className="ob-label">Программа <span className="ob-req">*</span></label>
            <div className="ob-programs">
              {HEALTH_PROGRAMS.map((p) => {
                const Icon = ICONS[p.iconKey];
                const active = formData.health_program === p.value;
                return (
                  <button
                    key={p.value}
                    type="button"
                    className={`ob-program ${active ? 'ob-program--active' : ''}`}
                    onClick={() => onChange('health_program', p.value)}
                  >
                    <Icon size={22} className="ob-program__icon" aria-hidden />
                    <span className="ob-program__body">
                      <span className="ob-program__name">{p.name}</span>
                      <span className="ob-program__meta">{p.duration} · {p.desc}</span>
                    </span>
                  </button>
                );
              })}
            </div>
          </div>

          {formData.health_program === 'custom' && (
            <div className="ob-field">
              <label className="ob-label">На какой срок план? <span className="ob-req">*</span></label>
              <select
                className="ob-select"
                value={formData.health_plan_weeks}
                onChange={(e) => onChange('health_plan_weeks', e.target.value)}
              >
                <option value="">Выберите...</option>
                {HEALTH_PLAN_WEEKS.map((w) => <option key={w.value} value={w.value}>{w.label}</option>)}
              </select>
            </div>
          )}
        </div>
      )}

      {/* Дата старта — общее обязательное поле */}
      {goal && (
        <div className="ob-field" style={{ marginTop: 18 }}>
          <label className="ob-label">
            <CalendarIcon size={14} aria-hidden style={{ verticalAlign: '-2px', marginRight: 6 }} />
            С какого дня начинаем? <span className="ob-req">*</span>
          </label>
          <input
            type="date"
            className="ob-input"
            value={formData.training_start_date}
            min={today()}
            onChange={(e) => onChange('training_start_date', e.target.value)}
            required
          />
          <span className="ob-hint">План будет рассчитан от этой даты до цели.</span>
        </div>
      )}
    </div>
  );
}
