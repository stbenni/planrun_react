import { Group, Row, FieldRow, Seg, ToggleRow } from '../primitives';

const PROFILE_VIS = [['public', 'Все'], ['link', 'Ссылка'], ['private', 'Закрыт']];
const TIMEZONES = [
  ['Europe/Moscow', 'Москва (UTC+3)'],
  ['Europe/Kaliningrad', 'Калининград (UTC+2)'],
  ['Asia/Yekaterinburg', 'Екатеринбург (UTC+5)'],
  ['Asia/Novosibirsk', 'Новосибирск (UTC+7)'],
  ['Asia/Vladivostok', 'Владивосток (UTC+10)'],
  ['Europe/Kiev', 'Киев (UTC+2)'],
  ['Europe/Minsk', 'Минск (UTC+3)'],
  ['Asia/Almaty', 'Алматы (UTC+6)'],
  ['Europe/London', 'Лондон (UTC+0)'],
];

export default function ProfileSectionV3({ ctx }) {
  const { formData, onField, avatarSrc, avatarInitials, onAvatarUpload, onRemoveAvatar,
    slugStatus, slugChecking, onCheckSlug, setSlugStatus } = ctx;
  const fullName = [formData.first_name, formData.last_name].filter(Boolean).join(' ') || 'Без имени';
  const birthMonthValue = formData.birth_year && formData.birth_month
    ? `${formData.birth_year}-${String(formData.birth_month).padStart(2, '0')}`
    : '';

  return (
    <>
      <Group>
        <Row className="sv3-id-row">
          <div className="sv3-avatar-wrap">
            <label htmlFor="sv3-avatar-upload" className="sv3-avatar" title="Изменить фото">
              {avatarSrc ? <img src={avatarSrc} alt="" className="sv3-avatar" /> : (avatarInitials || '🙂')}
            </label>
            <input id="sv3-avatar-upload" type="file" className="sv3-avatar-input"
              accept="image/jpeg,image/jpg,image/png,image/gif,image/webp" onChange={onAvatarUpload} />
            <label htmlFor="sv3-avatar-upload" className="sv3-avatar-edit" title="Изменить фото">✎</label>
          </div>
          <div className="sv3-row-main" style={{ marginLeft: 14 }}>
            <div className="sv3-id-big-name">{fullName}</div>
            <div className="sv3-id-handle">@{formData.username || 'username'}</div>
          </div>
          {avatarSrc && <button type="button" className="sv3-link-btn" onClick={onRemoveAvatar}>Удалить</button>}
        </Row>
      </Group>

      <Group label="Личные данные">
        <FieldRow label="Имя">
          <input className="sv3-input" value={formData.first_name || ''} placeholder="Иван"
            onChange={(e) => onField('first_name', e.target.value)} />
        </FieldRow>
        <FieldRow label="Фамилия">
          <input className="sv3-input" value={formData.last_name || ''} placeholder="Петров"
            onChange={(e) => onField('last_name', e.target.value)} />
        </FieldRow>
        <FieldRow label="Пол">
          <Seg options={[['male', 'М'], ['female', 'Ж']]} value={formData.gender}
            onChange={(v) => onField('gender', v)} />
        </FieldRow>
        <FieldRow label="Дата рождения">
          <input type="month" className="sv3-input" min="1900-01" max={`${new Date().getFullYear()}-12`}
            value={birthMonthValue}
            onChange={(e) => {
              const v = e.target.value;
              if (!v) { onField('birth_year', ''); onField('birth_month', ''); return; }
              const [y, m] = v.split('-');
              onField('birth_year', y);
              onField('birth_month', String(parseInt(m, 10)));
            }} />
        </FieldRow>
        <FieldRow label="Рост">
          <input type="number" className="sv3-input--sm" min="50" max="250" placeholder="180"
            value={formData.height_cm || ''} onChange={(e) => onField('height_cm', e.target.value)} />
          <span className="sv3-unit">см</span>
        </FieldRow>
        <FieldRow label="Вес">
          <input type="number" className="sv3-input--sm" min="20" max="300" step="0.1" placeholder="74"
            value={formData.weight_kg || ''} onChange={(e) => onField('weight_kg', e.target.value)} />
          <span className="sv3-unit">кг</span>
        </FieldRow>
        <FieldRow label="Часовой пояс">
          <select className="sv3-select" value={formData.timezone || 'Europe/Moscow'}
            onChange={(e) => onField('timezone', e.target.value)}>
            {TIMEZONES.map(([tz, label]) => <option key={tz} value={tz}>{label}</option>)}
            {formData.timezone && !TIMEZONES.some(([tz]) => tz === formData.timezone) && (
              <option value={formData.timezone}>{formData.timezone}</option>
            )}
          </select>
        </FieldRow>
      </Group>

      <Group label="Адрес профиля"
        footer={
          slugStatus === 'taken' ? <span className="sv3-slug-bad">Адрес занят, выберите другой</span>
            : slugStatus === 'free' ? <span className="sv3-slug-ok">Адрес свободен ✓</span>
              : 'Публичная ссылка на ваш профиль: planrun.ru/' + (formData.username || '…')
        }>
        <Row>
          <span className="sv3-field-label">planrun.ru/</span>
          <input className="sv3-input" value={formData.username || ''} placeholder="ivan_runner"
            autoCapitalize="none" autoCorrect="off"
            onChange={(e) => { onField('username', e.target.value); setSlugStatus(null); }} />
          <button type="button" className="sv3-ghost-btn"
            disabled={slugChecking || !formData.username || formData.username.trim().length < 3}
            onClick={onCheckSlug}>{slugChecking ? '…' : 'Проверить'}</button>
        </Row>
      </Group>

      <Group label="Приватность" footer="Что видно на вашей публичной странице">
        <FieldRow label="Видимость">
          <Seg options={PROFILE_VIS} value={formData.privacy_level} onChange={(v) => onField('privacy_level', v)} />
        </FieldRow>
        <ToggleRow label="Показывать тренера" on={formData.privacy_show_trainer}
          onChange={(v) => onField('privacy_show_trainer', v)} />
        <ToggleRow label="Показывать календарь" on={formData.privacy_show_calendar}
          onChange={(v) => onField('privacy_show_calendar', v)} />
        <ToggleRow label="Показывать метрики" on={formData.privacy_show_metrics}
          onChange={(v) => onField('privacy_show_metrics', v)} />
        <ToggleRow label="Показывать тренировки" on={formData.privacy_show_workouts}
          onChange={(v) => onField('privacy_show_workouts', v)} />
      </Group>

      <Group label="Здоровье" footer="AI-тренер учтёт это при составлении плана — обойдёт нагрузку на больные места.">
        <Row className="sv3-row--col">
          <textarea className="sv3-textarea" rows="3" placeholder="Особенности здоровья, травмы, ограничения…"
            value={formData.health_notes || ''} onChange={(e) => onField('health_notes', e.target.value || null)} />
        </Row>
      </Group>
    </>
  );
}
