/**
 * Шаг «Профиль». Для self — минимум (дата старта + пол). Для AI — полный набор полей,
 * включая расширенный профиль бегуна (темп, последний результат) для race/time_improvement.
 * Логика прежняя 1:1; вёрстка — v3B (поля pr-field, сегменты, круглые дни, тумблеры).
 */

import { PrField, PrToggle } from '../ui';
import { formatPaceMask, paceMaskToSeconds } from '../../utils/paceMask';
import { formatDurationMask, normalizeDuration } from '../../utils/durationMask';
import {
  EXPERIENCE_LEVELS, DAY_LABELS, OFP_PREFERENCES, TRAINING_TIMES, LAST_RACE_DISTANCES, PACE_QUICK_CHIPS,
  WEEKLY_VOLUME_RANGES,
} from './onboardingForm';
import { ObHeading, ObSection, ObSeg, ObDayDot, ObDateCard, ObHint } from './obKit';

const today = () => new Date().toISOString().split('T')[0];

function ToggleRow({ title, hint, on, onChange }) {
  return (
    <div className="pr-card" style={{ padding: '12px 16px', display: 'flex', alignItems: 'center', gap: 12 }}>
      <div style={{ flex: 1 }}>
        <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--pr-ink)' }}>{title}</div>
        {hint && <div style={{ fontSize: 11, color: 'var(--pr-sub)', marginTop: 2 }}>{hint}</div>}
      </div>
      <PrToggle on={on} onChange={onChange} />
    </div>
  );
}

export default function StepProfile({ formData, onChange, onToggleArray }) {
  const isSelf = formData.training_mode === 'self';
  const showExtended = formData.goal_type === 'race' || formData.goal_type === 'time_improvement';

  const setPace = (formatted) => {
    onChange('easy_pace_min', formatted);
    const sec = paceMaskToSeconds(formatted);
    if (sec !== null && sec >= 180 && sec <= 600) onChange('easy_pace_sec', String(sec));
    else if (formatted === '') onChange('easy_pace_sec', '');
  };

  // Развилка истории забегов. Источник VDOT один и самый точный из доступных:
  // «нет» → комфортный темп (грубый fallback), «да» → последний результат (точно).
  const setRaceHistory = (val) => {
    onChange('has_race_history', val);
    if (val === 'no') {
      onChange('is_first_race_at_distance', true);
      onChange('last_race_distance', '');
      onChange('last_race_time', '');
      onChange('last_race_date', '');
    } else {
      onChange('easy_pace_min', '');
      onChange('easy_pace_sec', '');
    }
  };

  const setLastRaceDistance = (val) => {
    onChange('last_race_distance', val);
    onChange('is_first_race_at_distance', val !== '' && val !== formData.race_distance);
  };

  const selectVolumeRange = (range) => {
    onChange('weekly_base_range', range.value);
    onChange('weekly_base_km', String(range.km));
  };
  const selectedRange = WEEKLY_VOLUME_RANGES.find((r) => r.value === formData.weekly_base_range);
  const showExactVolume = selectedRange?.exact;

  return (
    <div>
      <ObHeading title="Твой профиль" sub={isSelf ? 'Для календаря нужен минимум.' : 'Чем точнее — тем лучше план.'} />

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 9, marginTop: 16 }}>
        <PrField
          label="Имя *"
          type="text"
          placeholder="Иван"
          autoComplete="given-name"
          value={formData.first_name}
          onChange={(e) => onChange('first_name', e.target.value)}
        />
        <PrField
          label="Фамилия"
          type="text"
          placeholder="Петров"
          autoComplete="family-name"
          value={formData.last_name}
          onChange={(e) => onChange('last_name', e.target.value)}
        />
      </div>

      {isSelf && (
        <div style={{ marginTop: 14 }}>
          <ObDateCard
            label="Дата начала тренировок"
            value={formData.training_start_date}
            min={today()}
            onChange={(e) => onChange('training_start_date', e.target.value)}
          />
          <ObHint>С какой даты начинается календарь (по умолчанию — следующий понедельник).</ObHint>
        </div>
      )}

      <ObSection>Пол *</ObSection>
      <div style={{ display: 'flex', gap: 6 }} role="radiogroup" aria-label="Пол">
        {[['male', 'Мужской'], ['female', 'Женский']].map(([val, label]) => (
          <ObSeg key={val} active={formData.gender === val} onClick={() => onChange('gender', val)} style={{ flex: 1 }}>
            {label}
          </ObSeg>
        ))}
      </div>

      {!isSelf && (
        <>
          <div style={{ display: 'grid', gridTemplateColumns: formData.goal_type !== 'weight_loss' ? '1fr 1fr 1fr' : '1fr 1fr', gap: 9, marginTop: 14 }}>
            <PrField
              label="Год рожд."
              type="number" min="1930" max={new Date().getFullYear()} placeholder="1990"
              value={formData.birth_year}
              onChange={(e) => onChange('birth_year', e.target.value)}
            />
            <PrField
              label="Рост, см"
              type="number" min="100" max="250" placeholder="178"
              value={formData.height_cm}
              onChange={(e) => onChange('height_cm', e.target.value)}
            />
            {/* Для «Похудение» вес уже собран в шаге «Цель» — не дублируем */}
            {formData.goal_type !== 'weight_loss' && (
              <PrField
                label="Вес, кг"
                type="number" min="30" max="250" step="0.1" placeholder="72"
                value={formData.weight_kg}
                onChange={(e) => onChange('weight_kg', e.target.value)}
              />
            )}
          </div>

          <ObSection>Опыт в беге *</ObSection>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 6 }} role="radiogroup" aria-label="Уровень подготовки">
            {EXPERIENCE_LEVELS.map((lvl) => {
              const active = formData.experience_level === lvl.value;
              return (
                <ObSeg key={lvl.value} active={active} onClick={() => onChange('experience_level', lvl.value)} style={{ padding: '9px 4px' }}>
                  <span style={{ display: 'block' }}>{lvl.title}</span>
                  <span style={{ display: 'block', fontSize: 9.5, fontWeight: 600, opacity: 0.75, marginTop: 2 }}>{lvl.period}</span>
                </ObSeg>
              );
            })}
          </div>

          <ObSection>Сколько бегаешь сейчас? · км/нед</ObSection>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 6 }} role="radiogroup" aria-label="Текущий объём в неделю">
            {WEEKLY_VOLUME_RANGES.map((r) => (
              <ObSeg key={r.value} active={formData.weekly_base_range === r.value} onClick={() => selectVolumeRange(r)}>
                {r.label}
              </ObSeg>
            ))}
          </div>
          <ObHint>В среднем за последние недели. Бегаешь нерегулярно — выбери ближайшее.</ObHint>
          {showExactVolume && (
            <PrField
              label="Точный объём, км/нед"
              type="number" min="0" max="400" step="1" placeholder="52"
              value={formData.weekly_base_km}
              onChange={(e) => onChange('weekly_base_km', e.target.value)}
              style={{ marginTop: 9 }}
            />
          )}

          {/* Расширенный профиль — для race / time_improvement */}
          {showExtended && (
            <div className="pr-card" style={{ marginTop: 18, padding: '14px 16px', background: 'var(--pr-card-2)' }}>
              <div style={{ fontFamily: 'var(--pr-font-display)', fontSize: 14, fontWeight: 700, color: 'var(--pr-ink)' }}>
                Расскажи больше о своём беге
              </div>
              <div style={{ fontSize: 11.5, color: 'var(--pr-sub)', marginTop: 3 }}>
                Эти данные помогут создать более точный план (необязательно).
              </div>

              <ObSection>Бегал на официальном забеге?</ObSection>
              <div style={{ display: 'flex', gap: 6 }}>
                {[['yes', 'Да, есть результат'], ['no', 'Нет / не помню']].map(([val, label]) => (
                  <ObSeg key={val} active={formData.has_race_history === val} onClick={() => setRaceHistory(val)} style={{ flex: 1 }}>
                    {label}
                  </ObSeg>
                ))}
              </div>

              {/* Ветка «нет» — комфортный темп (грубая оценка уровня) */}
              {formData.has_race_history === 'no' && (
                <>
                  <ObSection>Комфортный темп · мин:сек/км</ObSection>
                  <div style={{ display: 'flex', gap: 6, alignItems: 'stretch' }}>
                    <PrField
                      type="text" inputMode="numeric" autoComplete="off" maxLength={5} placeholder="7:00"
                      value={formData.easy_pace_min || ''}
                      onChange={(e) => setPace(formatPaceMask(e.target.value))}
                      style={{ width: 86, flexShrink: 0 }}
                    />
                    {PACE_QUICK_CHIPS.map((p) => (
                      <ObSeg
                        key={p}
                        active={formData.easy_pace_min === p}
                        onClick={() => {
                          onChange('easy_pace_min', p);
                          const [m, s] = p.split(':').map(Number);
                          onChange('easy_pace_sec', String(m * 60 + s));
                        }}
                        style={{ flex: 1, fontVariantNumeric: 'tabular-nums' }}
                      >
                        {p}
                      </ObSeg>
                    ))}
                  </div>
                  <ObHint>Ориентир: 5:00 опытный · 6:00 уверенный · 7:00 начинающий · 8:00 очень спокойный.</ObHint>
                </>
              )}

              {/* Ветка «да» — последний официальный результат (точный источник VDOT) */}
              {formData.has_race_history === 'yes' && (
                <>
                  <ObSection>Последний официальный результат</ObSection>
                  <div style={{ display: 'grid', gridTemplateColumns: formData.last_race_distance === 'other' ? '1fr 1fr' : '1fr', gap: 9 }}>
                    <select className="pr-field" value={formData.last_race_distance} onChange={(e) => setLastRaceDistance(e.target.value)}>
                      {LAST_RACE_DISTANCES.map((d) => <option key={d.value || 'none'} value={d.value}>{d.label}</option>)}
                    </select>
                    {formData.last_race_distance === 'other' && (
                      <PrField
                        type="number" min="0" max="200" step="0.1" placeholder="км"
                        value={formData.last_race_distance_km}
                        onChange={(e) => onChange('last_race_distance_km', e.target.value)}
                      />
                    )}
                  </div>
                  {formData.last_race_distance && (
                    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 9, marginTop: 9 }}>
                      <PrField
                        label="Результат"
                        type="text" inputMode="numeric" autoComplete="off" maxLength={8} placeholder="0:43:18"
                        value={formData.last_race_time}
                        onChange={(e) => onChange('last_race_time', formatDurationMask(e.target.value))}
                        onBlur={(e) => onChange('last_race_time', normalizeDuration(e.target.value))}
                      />
                      <PrField
                        label="Когда"
                        type="month" max={new Date().toISOString().slice(0, 7)}
                        value={formData.last_race_date}
                        onChange={(e) => onChange('last_race_date', e.target.value)}
                      />
                    </div>
                  )}
                </>
              )}
            </div>
          )}

          <ObSection>Удобные дни для бега</ObSection>
          <div style={{ display: 'flex', justifyContent: 'space-between' }} role="group" aria-label="Дни для бега">
            {Object.entries(DAY_LABELS).map(([key, label]) => (
              <ObDayDot
                key={key}
                active={formData.preferred_days.includes(key)}
                label={label}
                onClick={() => onToggleArray('preferred_days', key, !formData.preferred_days.includes(key))}
              />
            ))}
          </div>
          {formData.preferred_days.length > 0 && (
            <ObHint>Тренировок в неделю: <strong style={{ color: 'var(--pr-ink)' }}>{formData.preferred_days.length}</strong></ObHint>
          )}

          <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginTop: 18 }}>
            <ToggleRow
              title="Планируешь делать ОФП?"
              hint="Общая физподготовка — силовые, растяжка"
              on={formData.will_do_ofp === 'yes'}
              onChange={(v) => onChange('will_do_ofp', v ? 'yes' : 'no')}
            />
            {formData.will_do_ofp === 'yes' && (
              <>
                <ObSection style={{ margin: '8px 0 0' }}>Дни для ОФП</ObSection>
                <div style={{ display: 'flex', justifyContent: 'space-between' }} role="group" aria-label="Дни для ОФП">
                  {Object.entries(DAY_LABELS).map(([key, label]) => (
                    <ObDayDot
                      key={key}
                      active={formData.preferred_ofp_days.includes(key)}
                      label={label}
                      onClick={() => onToggleArray('preferred_ofp_days', key, !formData.preferred_ofp_days.includes(key))}
                    />
                  ))}
                </div>
                <label style={{ display: 'block', marginTop: 4 }}>
                  <div className="pr-field-label">Где удобно делать ОФП?</div>
                  <select className="pr-field" value={formData.ofp_preference} onChange={(e) => onChange('ofp_preference', e.target.value)}>
                    {OFP_PREFERENCES.map((o) => <option key={o.value || 'any'} value={o.value}>{o.label}</option>)}
                  </select>
                </label>
              </>
            )}
            <ToggleRow
              title="Есть беговая дорожка"
              on={formData.has_treadmill}
              onChange={(v) => onChange('has_treadmill', v)}
            />
          </div>

          <label style={{ display: 'block', marginTop: 14 }}>
            <div className="pr-field-label">Время тренировок</div>
            <select className="pr-field" value={formData.training_time_pref} onChange={(e) => onChange('training_time_pref', e.target.value)}>
              {TRAINING_TIMES.map((t) => <option key={t.value || 'any'} value={t.value}>{t.label}</option>)}
            </select>
          </label>

          <PrField
            label="Ограничения по здоровью"
            multiline
            rows={3}
            placeholder="Травмы, ограничения, рекомендации врача (необязательно)"
            value={formData.health_notes}
            onChange={(e) => onChange('health_notes', e.target.value)}
            style={{ marginTop: 14 }}
          />
        </>
      )}
    </div>
  );
}
