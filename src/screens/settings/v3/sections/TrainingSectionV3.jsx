import { Group, Row, FieldRow, Seg, ToggleRow, DayPicker } from '../primitives';
import { daysOfWeek } from '../../profileForm';
import { formatPaceMask, paceMaskToSeconds } from '../../../../utils/paceMask';

const MODE_FOOTER = {
  ai: 'AI строит и адаптирует план автоматически',
  coach: 'Тренер ведёт план вручную, AI не вмешивается',
  self: 'Ты добавляешь тренировки сам',
};
const DIST = [['5k', '5к'], ['10k', '10к'], ['half', '21.1'], ['marathon', '42.2']];

export default function TrainingSectionV3({ ctx }) {
  const { formData, onField, onToggleRunDay, onToggleOfpDay } = ctx;
  const goal = formData.goal_type || 'health';
  const isRaceLike = goal === 'race' || goal === 'time_improvement';

  const setPace = (raw) => {
    const formatted = formatPaceMask(raw);
    onField('easy_pace_min', formatted);
    const totalSec = paceMaskToSeconds(formatted);
    if (totalSec !== null && totalSec >= 180 && totalSec <= 600) onField('easy_pace_sec', String(totalSec));
    else if (formatted === '') onField('easy_pace_sec', '');
  };

  return (
    <>
      <Group label="Режим" footer={MODE_FOOTER[formData.training_mode || 'ai']}>
        <Row>
          <Seg options={[['ai', 'AI'], ['coach', 'Тренер'], ['self', 'Сам']]}
            value={formData.training_mode || 'ai'} onChange={(v) => onField('training_mode', v)} />
        </Row>
      </Group>

      <Group label="Цель"
        footer={goal === 'health' ? 'Программа без целевой даты' : goal === 'weight_loss' ? 'Бег + дефицит для снижения веса' : null}>
        <FieldRow label="Тип">
          <Seg options={[['health', 'Здоровье'], ['race', 'Забег'], ['weight_loss', 'Вес'], ['time_improvement', 'Время']]}
            value={goal} onChange={(v) => onField('goal_type', v)} />
        </FieldRow>
        {isRaceLike && (
          <>
            <FieldRow label="Дистанция">
              <Seg options={DIST} value={formData.race_distance} onChange={(v) => onField('race_distance', v)} />
            </FieldRow>
            <FieldRow label="Дата гонки">
              <input type="date" className="sv3-input" value={formData.race_date || ''}
                onChange={(e) => onField('race_date', e.target.value || null)} />
            </FieldRow>
            <FieldRow label="Целевое время">
              <input type="time" step="1" className="sv3-input sv3-input--num" value={formData.race_target_time || ''}
                onChange={(e) => onField('race_target_time', e.target.value || null)} />
            </FieldRow>
          </>
        )}
        {goal === 'weight_loss' && (
          <>
            <FieldRow label="Целевой вес">
              <input type="number" className="sv3-input--sm" min="20" max="300" step="0.1"
                value={formData.weight_goal_kg || ''} onChange={(e) => onField('weight_goal_kg', e.target.value)} />
              <span className="sv3-unit">кг</span>
            </FieldRow>
            <FieldRow label="К дате">
              <input type="date" className="sv3-input" value={formData.weight_goal_date || ''}
                onChange={(e) => onField('weight_goal_date', e.target.value || null)} />
            </FieldRow>
          </>
        )}
        {goal === 'health' && (
          <>
            <FieldRow label="Программа">
              <select className="sv3-select" value={formData.health_program || ''}
                onChange={(e) => onField('health_program', e.target.value || null)}>
                <option value="">Не указано</option>
                <option value="start_running">Начать бегать</option>
                <option value="couch_to_5k">Couch to 5K</option>
                <option value="regular_running">Регулярный бег</option>
                <option value="custom">Своя программа</option>
              </select>
            </FieldRow>
            {formData.health_program && (
              <FieldRow label="Срок">
                <input type="number" className="sv3-input--sm" min="1" max="52" value={formData.health_plan_weeks || ''}
                  onChange={(e) => onField('health_plan_weeks', e.target.value)} />
                <span className="sv3-unit">нед</span>
              </FieldRow>
            )}
          </>
        )}
      </Group>

      <Group label="Опыт и объём">
        <FieldRow label="Уровень">
          <Seg options={[['novice', 'Новичок'], ['beginner', 'Начин.'], ['intermediate', 'Средн.'], ['advanced', 'Продв.'], ['expert', 'Опытн.']]}
            value={formData.experience_level || 'novice'} onChange={(v) => onField('experience_level', v)} />
        </FieldRow>
        <FieldRow label="Текущая база">
          <input type="number" className="sv3-input--sm" min="0" max="200" step="0.1" placeholder="50"
            value={formData.weekly_base_km || ''} onChange={(e) => onField('weekly_base_km', e.target.value)} />
          <span className="sv3-unit">км/нед</span>
        </FieldRow>
        <FieldRow label="Дата начала">
          <input type="date" className="sv3-input" value={formData.training_start_date || ''}
            onChange={(e) => onField('training_start_date', e.target.value || null)} />
        </FieldRow>
        <ToggleRow label="Есть беговая дорожка" on={formData.has_treadmill}
          onChange={(v) => onField('has_treadmill', v)} />
      </Group>

      <Group label="График">
        <Row className="sv3-row--col">
          <span className="sv3-row-title">Дни бега</span>
          <DayPicker days={daysOfWeek} value={Array.isArray(formData.preferred_days) ? formData.preferred_days : []}
            onToggle={onToggleRunDay} variant="run" />
        </Row>
        <Row className="sv3-row--col">
          <span className="sv3-row-title">Дни ОФП / СБУ</span>
          <DayPicker days={daysOfWeek} value={Array.isArray(formData.preferred_ofp_days) ? formData.preferred_ofp_days : []}
            onToggle={onToggleOfpDay} variant="ofp" />
        </Row>
        <FieldRow label="ОФП">
          <select className="sv3-select" value={formData.ofp_preference || ''}
            onChange={(e) => onField('ofp_preference', e.target.value || null)}>
            <option value="">Не указано</option>
            <option value="gym">Зал</option>
            <option value="home">Дома</option>
            <option value="both">Зал и дома</option>
            <option value="group_classes">Группы</option>
            <option value="online">Онлайн</option>
          </select>
        </FieldRow>
        <FieldRow label="Время">
          <Seg options={[['morning', 'Утро'], ['day', 'День'], ['evening', 'Вечер']]}
            value={formData.training_time_pref} onChange={(v) => onField('training_time_pref', v)} />
        </FieldRow>
      </Group>

      <Group label="Темп" footer="Темп для разминки и восстановления. Ориентир: 5:00 опытный · 6:00 уверенный · 7:00 начинающий.">
        <FieldRow label="Лёгкий темп">
          <input type="text" inputMode="numeric" maxLength={5} className="sv3-input--sm sv3-input--num" placeholder="7:00"
            value={formData.easy_pace_min || ''} onChange={(e) => setPace(e.target.value)} />
          <span className="sv3-unit">/км</span>
        </FieldRow>
        {goal === 'race' && (
          <ToggleRow label="Первый забег на дистанции"
            on={formData.is_first_race_at_distance === 1 || formData.is_first_race_at_distance === true}
            onChange={(v) => onField('is_first_race_at_distance', v ? 1 : 0)} />
        )}
      </Group>

      {goal === 'race' && !(formData.is_first_race_at_distance === 1 || formData.is_first_race_at_distance === true) && (
        <Group label="Последний забег" footer="Помогает точнее рассчитать VDOT и темпы">
          <FieldRow label="Дистанция">
            <select className="sv3-select" value={formData.last_race_distance || ''}
              onChange={(e) => onField('last_race_distance', e.target.value || null)}>
              <option value="">Не указано</option>
              <option value="5k">5 км</option>
              <option value="10k">10 км</option>
              <option value="half">Полумарафон</option>
              <option value="marathon">Марафон</option>
              <option value="other">Другая</option>
            </select>
          </FieldRow>
          {formData.last_race_distance === 'other' && (
            <FieldRow label="Дистанция, км">
              <input type="number" className="sv3-input--sm" min="0" max="200" step="0.1"
                value={formData.last_race_distance_km || ''} onChange={(e) => onField('last_race_distance_km', e.target.value)} />
            </FieldRow>
          )}
          <FieldRow label="Результат">
            <input type="time" step="1" className="sv3-input sv3-input--num" value={formData.last_race_time || ''}
              onChange={(e) => onField('last_race_time', e.target.value || null)} />
          </FieldRow>
          <FieldRow label="Дата">
            <input type="date" className="sv3-input" value={formData.last_race_date || ''}
              onChange={(e) => onField('last_race_date', e.target.value || null)} />
          </FieldRow>
        </Group>
      )}
    </>
  );
}
