/**
 * Шаг «Профиль». Для self — минимум (дата старта + пол). Для AI — полный набор полей,
 * включая расширенный профиль бегуна (темп, последний результат) для race/time_improvement.
 */

import {
  LeafIcon, WalkingIcon, RunningIcon, ZapIcon, TrophyIcon,
  CalendarIcon, GraduationCapIcon, PaceIcon, MedalIcon,
} from '../common/Icons';
import { formatPaceMask, paceMaskToSeconds } from '../../utils/paceMask';
import { formatDurationMask, normalizeDuration } from '../../utils/durationMask';
import {
  EXPERIENCE_LEVELS, DAY_LABELS, OFP_PREFERENCES, TRAINING_TIMES, LAST_RACE_DISTANCES, PACE_QUICK_CHIPS,
  WEEKLY_VOLUME_RANGES,
} from './onboardingForm';

const EXP_ICONS = { leaf: LeafIcon, walking: WalkingIcon, running: RunningIcon, zap: ZapIcon, trophy: TrophyIcon };
const today = () => new Date().toISOString().split('T')[0];

export default function StepProfile({ formData, onChange, onToggleArray, eyebrow }) {
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
  // is_first_race_at_distance выводим автоматически (не отдельным полем).
  const setRaceHistory = (val) => {
    onChange('has_race_history', val);
    if (val === 'no') {
      // Не бегал на забегах → это первый забег; чистим поля результата
      onChange('is_first_race_at_distance', true);
      onChange('last_race_distance', '');
      onChange('last_race_time', '');
      onChange('last_race_date', '');
    } else {
      // Бегал → первый ли это забег на ЦЕЛЕВОЙ дистанции выведем из выбора ниже; темп чистим
      onChange('easy_pace_min', '');
      onChange('easy_pace_sec', '');
    }
  };

  // Дистанция последнего результата → первый ли это забег на целевой дистанции
  const setLastRaceDistance = (val) => {
    onChange('last_race_distance', val);
    onChange('is_first_race_at_distance', val !== '' && val !== formData.race_distance);
  };

  // Выбор диапазона объёма: weekly_base_km = середина диапазона; для верхних — можно уточнить точно.
  const selectVolumeRange = (range) => {
    onChange('weekly_base_range', range.value);
    onChange('weekly_base_km', String(range.km));
  };
  const selectedRange = WEEKLY_VOLUME_RANGES.find((r) => r.value === formData.weekly_base_range);
  const showExactVolume = selectedRange?.exact;

  return (
    <div className="ob-step">
      <div className="ob-eyebrow">{eyebrow || (isSelf ? 'ШАГ 2 ИЗ 2' : 'ШАГ 3 ИЗ 3')}</div>
      <h1 className="ob-h1">Твой профиль</h1>
      <p className="ob-sub">{isSelf ? 'Для календаря нужен минимум' : 'Чем точнее — тем лучше план'}</p>

      <div style={{ marginTop: 20 }}>
        {/* Имя/Фамилия — для всех режимов; имя обязательно, фамилия опциональна */}
        <div className="ob-row">
          <div className="ob-field ob-field--inline">
            <label className="ob-label">Имя <span className="ob-req">*</span></label>
            <input
              type="text"
              className="ob-input"
              placeholder="Иван"
              autoComplete="given-name"
              value={formData.first_name}
              onChange={(e) => onChange('first_name', e.target.value)}
            />
          </div>
          <div className="ob-field ob-field--inline">
            <label className="ob-label">Фамилия</label>
            <input
              type="text"
              className="ob-input"
              placeholder="Петров"
              autoComplete="family-name"
              value={formData.last_name}
              onChange={(e) => onChange('last_name', e.target.value)}
            />
          </div>
        </div>

        {isSelf && (
          <div className="ob-field">
            <label className="ob-label">
              <CalendarIcon size={14} aria-hidden style={{ verticalAlign: '-2px', marginRight: 6 }} />
              Дата начала тренировок
            </label>
            <input
              type="date"
              className="ob-input"
              value={formData.training_start_date || ''}
              min={today()}
              onChange={(e) => onChange('training_start_date', e.target.value)}
            />
            <span className="ob-hint">С какой даты начинается календарь (по умолчанию — следующий понедельник).</span>
          </div>
        )}

        {/* Пол — обязателен всегда */}
        <div className="ob-field">
          <label className="ob-label">Пол <span className="ob-req">*</span></label>
          <div className="ob-seg-group">
            {[['male', 'Мужской'], ['female', 'Женский']].map(([val, label]) => (
              <button
                key={val}
                type="button"
                className={`ob-bigseg ${formData.gender === val ? 'ob-bigseg--active' : ''}`}
                onClick={() => onChange('gender', val)}
              >
                {label}
              </button>
            ))}
          </div>
        </div>

        {!isSelf && (
          <>
            <div className="ob-row">
              <div className="ob-field ob-field--inline">
                <label className="ob-label">Год рожд.</label>
                <input type="number" min="1930" max={new Date().getFullYear()} placeholder="1990"
                  className="ob-input" value={formData.birth_year}
                  onChange={(e) => onChange('birth_year', e.target.value)} />
              </div>
              <div className="ob-field ob-field--inline">
                <label className="ob-label">Рост, см</label>
                <input type="number" min="100" max="250" placeholder="178"
                  className="ob-input" value={formData.height_cm}
                  onChange={(e) => onChange('height_cm', e.target.value)} />
              </div>
              {/* Для «Похудение» вес уже собран в шаге «Цель» (текущий вес) — не дублируем */}
              {formData.goal_type !== 'weight_loss' && (
                <div className="ob-field ob-field--inline">
                  <label className="ob-label">Вес, кг</label>
                  <input type="number" min="30" max="250" step="0.1" placeholder="72"
                    className="ob-input" value={formData.weight_kg}
                    onChange={(e) => onChange('weight_kg', e.target.value)} />
                </div>
              )}
            </div>

            <div className="ob-field">
              <label className="ob-label">
                <GraduationCapIcon size={14} aria-hidden style={{ verticalAlign: '-2px', marginRight: 6 }} />
                Опыт в беге <span className="ob-req">*</span>
              </label>
              <div className="ob-seg-group ob-seg-group--wrap" role="radiogroup" aria-label="Уровень подготовки">
                {EXPERIENCE_LEVELS.map((lvl) => {
                  const Icon = EXP_ICONS[lvl.iconKey];
                  const active = formData.experience_level === lvl.value;
                  return (
                    <button
                      key={lvl.value}
                      type="button"
                      role="radio"
                      aria-checked={active}
                      className={`ob-seg ${active ? 'ob-seg--active' : ''}`}
                      style={{ flex: '1 1 28%', display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 4, padding: '10px 4px' }}
                      onClick={() => onChange('experience_level', lvl.value)}
                    >
                      <Icon size={20} aria-hidden />
                      <span>{lvl.title}</span>
                      <span style={{ fontSize: 10, opacity: 0.75 }}>{lvl.period}</span>
                    </button>
                  );
                })}
              </div>
            </div>

            <div className="ob-field">
              <label className="ob-label">
                <RunningIcon size={14} aria-hidden style={{ verticalAlign: '-2px', marginRight: 6 }} />
                Сколько бегаешь сейчас?
              </label>
              <div className="ob-seg-group ob-seg-group--wrap" role="radiogroup" aria-label="Текущий объём в неделю">
                {WEEKLY_VOLUME_RANGES.map((r) => (
                  <button
                    key={r.value}
                    type="button"
                    role="radio"
                    aria-checked={formData.weekly_base_range === r.value}
                    className={`ob-seg ${formData.weekly_base_range === r.value ? 'ob-seg--active' : ''}`}
                    style={{ flex: '1 1 30%' }}
                    onClick={() => selectVolumeRange(r)}
                  >
                    {r.label}
                  </button>
                ))}
              </div>
              <span className="ob-hint">В среднем за последние недели, км/нед. Бегаешь нерегулярно — выбери ближайшее.</span>
              {showExactVolume && (
                <>
                  <input
                    type="number" min="0" max="400" step="1" placeholder="точно, км/нед"
                    className="ob-input" style={{ marginTop: 8 }}
                    value={formData.weekly_base_km}
                    onChange={(e) => onChange('weekly_base_km', e.target.value)}
                  />
                  <span className="ob-hint">Можешь указать точный объём.</span>
                </>
              )}
            </div>

            {/* Расширенный профиль — для race / time_improvement */}
            {showExtended && (
              <div className="ob-extended">
                <h3 className="ob-extended__title">Расскажи больше о своём беге</h3>
                <p className="ob-extended__desc">Эти данные помогут создать более точный план (необязательно).</p>

                {/* Развилка: один самый точный источник фитнес-уровня (→ VDOT) */}
                <div className="ob-field">
                  <label className="ob-label">
                    <MedalIcon size={14} aria-hidden style={{ verticalAlign: '-2px', marginRight: 6 }} />
                    Бегал на официальном забеге?
                  </label>
                  <div className="ob-seg-group">
                    {[['yes', 'Да, есть результат'], ['no', 'Нет / не помню']].map(([val, label]) => (
                      <button
                        key={val}
                        type="button"
                        className={`ob-bigseg ${formData.has_race_history === val ? 'ob-bigseg--active' : ''}`}
                        onClick={() => setRaceHistory(val)}
                      >
                        {label}
                      </button>
                    ))}
                  </div>
                </div>

                {/* Ветка «нет» — комфортный темп (грубая оценка уровня) */}
                {formData.has_race_history === 'no' && (
                  <div className="ob-field">
                    <label className="ob-label">
                      <PaceIcon size={14} aria-hidden style={{ verticalAlign: '-2px', marginRight: 6 }} />
                      Комфортный темп (мин:сек/км)
                    </label>
                    <div className="ob-pace-row">
                      <input
                        type="text" inputMode="numeric" autoComplete="off" maxLength={5} placeholder="7:00"
                        className="ob-input ob-input--num ob-pace-input"
                        value={formData.easy_pace_min || ''}
                        onChange={(e) => setPace(formatPaceMask(e.target.value))}
                      />
                      <div className="ob-pace-quick" role="group" aria-label="Быстрый выбор темпа">
                        {PACE_QUICK_CHIPS.map((p) => (
                          <button
                            key={p}
                            type="button"
                            className={`ob-seg ob-seg--accent ob-seg--num ${formData.easy_pace_min === p ? 'ob-seg--active' : ''}`}
                            onClick={() => {
                              onChange('easy_pace_min', p);
                              const [m, s] = p.split(':').map(Number);
                              onChange('easy_pace_sec', String(m * 60 + s));
                            }}
                          >
                            {p}
                          </button>
                        ))}
                      </div>
                    </div>
                    <span className="ob-hint">Ориентир: 5:00 опытный · 6:00 уверенный · 7:00 начинающий · 8:00 очень спокойный.</span>
                  </div>
                )}

                {/* Ветка «да» — последний официальный результат (точный источник VDOT) */}
                {formData.has_race_history === 'yes' && (
                  <div className="ob-field">
                    <label className="ob-label">
                      <MedalIcon size={14} aria-hidden style={{ verticalAlign: '-2px', marginRight: 6 }} />
                      Последний официальный результат
                    </label>
                    <div className="ob-row">
                      <div className="ob-field ob-field--inline">
                        <select
                          className="ob-select"
                          value={formData.last_race_distance}
                          onChange={(e) => setLastRaceDistance(e.target.value)}
                        >
                          {LAST_RACE_DISTANCES.map((d) => <option key={d.value || 'none'} value={d.value}>{d.label}</option>)}
                        </select>
                      </div>
                      {formData.last_race_distance === 'other' && (
                        <div className="ob-field ob-field--inline">
                          <input type="number" min="0" max="200" step="0.1" placeholder="км"
                            className="ob-input" value={formData.last_race_distance_km}
                            onChange={(e) => onChange('last_race_distance_km', e.target.value)} />
                        </div>
                      )}
                    </div>
                    {formData.last_race_distance && (
                      <div className="ob-row">
                        <div className="ob-field ob-field--inline">
                          <label className="ob-label">Результат</label>
                          <input type="text" inputMode="numeric" autoComplete="off" maxLength={8}
                            placeholder="0:43:18"
                            className="ob-input ob-input--num"
                            value={formData.last_race_time}
                            onChange={(e) => onChange('last_race_time', formatDurationMask(e.target.value))}
                            onBlur={(e) => onChange('last_race_time', normalizeDuration(e.target.value))} />
                        </div>
                        <div className="ob-field ob-field--inline">
                          <label className="ob-label">Когда</label>
                          <input type="month" max={new Date().toISOString().slice(0, 7)} className="ob-input"
                            value={formData.last_race_date}
                            onChange={(e) => onChange('last_race_date', e.target.value)} />
                        </div>
                      </div>
                    )}
                  </div>
                )}
              </div>
            )}

            {/* Дни для бега */}
            <div className="ob-field">
              <label className="ob-label">Удобные дни для бега</label>
              <div className="ob-days" role="group" aria-label="Дни для бега">
                {Object.entries(DAY_LABELS).map(([key, label]) => {
                  const active = formData.preferred_days.includes(key);
                  return (
                    <button
                      key={key}
                      type="button"
                      aria-pressed={active}
                      className={`ob-day ${active ? 'ob-day--active' : ''}`}
                      onClick={() => onToggleArray('preferred_days', key, !active)}
                    >
                      {label}
                    </button>
                  );
                })}
              </div>
              {formData.preferred_days.length > 0 && (
                <span className="ob-hint">Тренировок в неделю: <strong>{formData.preferred_days.length}</strong></span>
              )}
            </div>

            {/* ОФП */}
            <div className="ob-field">
              <label className="ob-toggle">
                <span className="ob-toggle__label">
                  <span>Планируете делать ОФП?</span>
                  <span className="ob-toggle__hint">Общая физподготовка — силовые, растяжка</span>
                </span>
                <input
                  type="checkbox"
                  className="ob-toggle__input"
                  checked={formData.will_do_ofp === 'yes'}
                  onChange={(e) => onChange('will_do_ofp', e.target.checked ? 'yes' : 'no')}
                />
                <span className="ob-toggle__track" aria-hidden><span className="ob-toggle__thumb" /></span>
              </label>
            </div>

            {formData.will_do_ofp === 'yes' && (
              <>
                <div className="ob-field">
                  <label className="ob-label">Дни для ОФП</label>
                  <div className="ob-days" role="group" aria-label="Дни для ОФП">
                    {Object.entries(DAY_LABELS).map(([key, label]) => {
                      const active = formData.preferred_ofp_days.includes(key);
                      return (
                        <button
                          key={key}
                          type="button"
                          aria-pressed={active}
                          className={`ob-day ${active ? 'ob-day--active' : ''}`}
                          onClick={() => onToggleArray('preferred_ofp_days', key, !active)}
                        >
                          {label}
                        </button>
                      );
                    })}
                  </div>
                </div>
                <div className="ob-field">
                  <label className="ob-label">Где удобно делать ОФП?</label>
                  <select className="ob-select" value={formData.ofp_preference}
                    onChange={(e) => onChange('ofp_preference', e.target.value)}>
                    {OFP_PREFERENCES.map((o) => <option key={o.value || 'any'} value={o.value}>{o.label}</option>)}
                  </select>
                </div>
              </>
            )}

            <div className="ob-row">
              <div className="ob-field ob-field--inline">
                <label className="ob-label">Время тренировок</label>
                <select className="ob-select" value={formData.training_time_pref}
                  onChange={(e) => onChange('training_time_pref', e.target.value)}>
                  {TRAINING_TIMES.map((t) => <option key={t.value || 'any'} value={t.value}>{t.label}</option>)}
                </select>
              </div>
              <div className="ob-field ob-field--inline" style={{ display: 'flex', alignItems: 'flex-end' }}>
                <label className="ob-toggle" style={{ width: '100%', paddingBottom: 8 }}>
                  <span className="ob-toggle__label"><span>Есть беговая дорожка</span></span>
                  <input type="checkbox" className="ob-toggle__input"
                    checked={formData.has_treadmill}
                    onChange={(e) => onChange('has_treadmill', e.target.checked)} />
                  <span className="ob-toggle__track" aria-hidden><span className="ob-toggle__thumb" /></span>
                </label>
              </div>
            </div>

            <div className="ob-field">
              <label className="ob-label">Ограничения по здоровью</label>
              <textarea rows="3" className="ob-textarea"
                placeholder="Травмы, ограничения, рекомендации врача (необязательно)"
                value={formData.health_notes}
                onChange={(e) => onChange('health_notes', e.target.value)} />
            </div>
          </>
        )}
      </div>
    </div>
  );
}
