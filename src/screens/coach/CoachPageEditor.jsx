import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import useAuthStore from '../../stores/useAuthStore';
import { CoachAvatar } from '../../components/Coach/CoachPrimitives';
import { getDisplayName } from '../../utils/displayName';
import './CoachPageEditor.css';

const SPEC_OPTIONS = [
  ['marathon', 'Марафон'], ['half_marathon', 'Полумарафон'], ['5k_10k', '5К / 10К'],
  ['ultra', 'Ультра'], ['trail', 'Трейл'], ['beginner', 'Новичкам'],
  ['speed', 'Скорость'], ['injury_recovery', 'Травмы'], ['health', 'Здоровье'],
  ['nutrition', 'Питание'], ['mental', 'Ментальные'],
];

const PRICE_TYPES = [['individual', 'Индивидуально'], ['group', 'Группа'], ['consultation', 'Консультация'], ['custom', 'Другое']];
const PRICE_PERIODS = [['month', 'в месяц'], ['week', 'в неделю'], ['one_time', 'разово'], ['custom', 'другое']];

export default function CoachPageEditor() {
  const navigate = useNavigate();
  const { api, user } = useAuthStore();

  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [savedAt, setSavedAt] = useState(0);
  const [form, setForm] = useState({
    coach_bio: '', coach_philosophy: '', coach_specialization: [],
    coach_experience_years: '', coach_accepts: true, coach_certifications: '',
    coach_prices_on_request: false,
  });
  const [pricing, setPricing] = useState([]);

  useEffect(() => {
    if (!api) return;
    let cancelled = false;
    setLoading(true);
    api.getMyCoachProfile()
      .then((res) => {
        if (cancelled) return;
        const d = res?.data ?? res ?? {};
        setForm({
          coach_bio: d.coach_bio || '',
          coach_philosophy: d.coach_philosophy || '',
          coach_specialization: Array.isArray(d.coach_specialization) ? d.coach_specialization : [],
          coach_experience_years: d.coach_experience_years ?? '',
          coach_accepts: !!d.coach_accepts,
          coach_certifications: d.coach_certifications || '',
          coach_prices_on_request: !!d.coach_prices_on_request,
        });
        setPricing(Array.isArray(d.pricing) ? d.pricing : []);
      })
      .catch((e) => { if (!cancelled) setError(e?.message || 'Не удалось загрузить'); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [api]);

  const set = (key, val) => setForm((f) => ({ ...f, [key]: val }));
  const toggleSpec = (s) => setForm((f) => ({
    ...f,
    coach_specialization: f.coach_specialization.includes(s)
      ? f.coach_specialization.filter((x) => x !== s)
      : [...f.coach_specialization, s],
  }));

  const addPrice = () => setPricing((p) => [...p, { type: 'individual', label: '', price: '', period: 'month', currency: 'rub' }]);
  const setPrice = (idx, key, val) => setPricing((p) => p.map((it, i) => (i === idx ? { ...it, [key]: val } : it)));
  const removePrice = (idx) => setPricing((p) => p.filter((_, i) => i !== idx));

  const bioLen = form.coach_bio.trim().length;
  const bioOk = bioLen >= 100 && bioLen <= 500;
  const canSave = !saving && bioOk && form.coach_specialization.length > 0;

  const handleSave = async () => {
    if (!api || !canSave) return;
    setSaving(true);
    setError('');
    try {
      await api.updateCoachProfile({
        coach_bio: form.coach_bio,
        coach_philosophy: form.coach_philosophy,
        coach_specialization: form.coach_specialization,
        coach_experience_years: form.coach_experience_years === '' ? null : form.coach_experience_years,
        coach_accepts: form.coach_accepts,
        coach_certifications: form.coach_certifications,
      });
      await api.updateCoachPricing(
        pricing.map((it) => ({ type: it.type, label: it.label, price: it.price === '' ? null : Number(it.price), period: it.period, currency: it.currency || 'rub' })),
        form.coach_prices_on_request,
      );
      setSavedAt(Date.now());
    } catch (e) {
      setError(e?.message || 'Не удалось сохранить');
    } finally {
      setSaving(false);
    }
  };

  const mySlug = user?.username_slug || user?.username;
  const preview = () => { if (mySlug) navigate(`/${mySlug}`); };

  const saveLabel = saving ? 'Сохранение…' : (savedAt && Date.now() - savedAt < 3000 ? '✓ Сохранено' : 'Сохранить');

  const body = (
    <>
      <div className="cpe-cover">
        <div className="cpe-cover-img" />
        <div className="cpe-cover-foot">
          <div className="cpe-ava-wrap">
            <CoachAvatar athlete={user} size={76} radius="50%" apiBaseUrl={api?.baseUrl || '/api'} />
          </div>
          <div className="cpe-cover-name">{getDisplayName(user)}</div>
        </div>
      </div>

      <Group label="Видимость анкеты" footer="Когда выключено — профиль скрыт из поиска тренеров">
        <ToggleRow label="Принимаю новых учеников" on={form.coach_accepts} onChange={(v) => set('coach_accepts', v)} />
        <ToggleRow label="Верифицирован" sub="Подтверждается администрацией" on={user?.role === 'admin'} disabled />
      </Group>

      <Group label="О себе" footer={bioOk ? `${bioLen}/500` : `Описание ${bioLen}/500 — нужно от 100 до 500 символов`}>
        <textarea
          className={`cpe-area ${!bioOk && bioLen > 0 ? 'is-invalid' : ''}`}
          rows={4}
          placeholder="Расскажи о методике, результатах учеников, своих рекордах"
          value={form.coach_bio}
          onChange={(e) => set('coach_bio', e.target.value)}
        />
        <div className="cpe-sublbl">Подход / философия</div>
        <textarea
          className="cpe-area"
          rows={3}
          placeholder="Твой принцип работы с атлетами"
          value={form.coach_philosophy}
          onChange={(e) => set('coach_philosophy', e.target.value)}
        />
      </Group>

      <Group label="Опыт">
        <Field label="Стаж (лет)">
          <input
            type="number" min="1" max="50" className="cpe-inp-sm" placeholder="—"
            value={form.coach_experience_years}
            onChange={(e) => set('coach_experience_years', e.target.value)}
          />
        </Field>
      </Group>

      <Group label="Специализация" footer={`Выбрано: ${form.coach_specialization.length}`}>
        <div className="cpe-chips">
          {SPEC_OPTIONS.map(([key, label]) => (
            <button
              key={key} type="button"
              className={`cpe-chip ${form.coach_specialization.includes(key) ? 'is-on' : ''}`}
              onClick={() => toggleSpec(key)}
            >
              {label}
            </button>
          ))}
        </div>
      </Group>

      <Group label="Тарифы" footer="Покажутся на твоей публичной странице">
        <ToggleRow
          label="Цена по запросу" sub="Скрыть цены, показать «по запросу»"
          on={form.coach_prices_on_request} onChange={(v) => set('coach_prices_on_request', v)}
        />
        {!form.coach_prices_on_request && (
          <>
            {pricing.map((it, idx) => (
              <div key={it.id || idx} className="cpe-price-row">
                <select className="cpe-select" value={it.type} onChange={(e) => setPrice(idx, 'type', e.target.value)}>
                  {PRICE_TYPES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                </select>
                <input className="cpe-inp" placeholder="Название" value={it.label || ''} onChange={(e) => setPrice(idx, 'label', e.target.value)} />
                <input type="number" className="cpe-price-inp" placeholder="₽" value={it.price ?? ''} onChange={(e) => setPrice(idx, 'price', e.target.value)} />
                <select className="cpe-select cpe-select--sm" value={it.period} onChange={(e) => setPrice(idx, 'period', e.target.value)}>
                  {PRICE_PERIODS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                </select>
                <button type="button" className="cpe-del" onClick={() => removePrice(idx)} aria-label="Удалить">✕</button>
              </div>
            ))}
            <button type="button" className="cpe-add" onClick={addPrice}>+ Добавить тариф</button>
          </>
        )}
      </Group>

      <Group label="Квалификация" footer="Сертификаты, образование — по одному в строке">
        <textarea
          className="cpe-area"
          rows={3}
          placeholder="IAAF Level II&#10;Высшее физкультурное&#10;Первая помощь"
          value={form.coach_certifications}
          onChange={(e) => set('coach_certifications', e.target.value)}
        />
      </Group>

      {error && <div className="cpe-error">{error}</div>}
    </>
  );

  if (loading) {
    return <div className="cpe"><div className="cpe-loading">Загрузка…</div></div>;
  }

  return (
    <div className="cpe">
      <div className="cpe-inner">
        <div className="cpe-bar">
          <button type="button" className="cpe-back" onClick={() => navigate(-1)}>‹</button>
          <span className="cpe-bar-title">Моя страница тренера</span>
          <div className="cpe-spacer" />
          <button type="button" className="cpe-ghost" onClick={preview}>Предпросмотр</button>
        </div>
        {body}
        <button type="button" className="cpe-primary cpe-primary--full" disabled={!canSave} onClick={handleSave}>{saveLabel}</button>
      </div>
    </div>
  );
}

function Group({ label, footer, children }) {
  return (
    <div className="cpe-group-wrap">
      {label && <div className="cpe-group-lbl">{label}</div>}
      <div className="cpe-group">{children}</div>
      {footer && <div className="cpe-group-foot">{footer}</div>}
    </div>
  );
}

function Field({ label, children }) {
  return (
    <div className="cpe-field">
      <span className="cpe-field-lbl">{label}</span>
      <div className="cpe-field-val">{children}</div>
    </div>
  );
}

function ToggleRow({ label, sub, on, onChange, disabled }) {
  return (
    <div className={`cpe-row ${disabled ? 'is-disabled' : ''}`}>
      <div className="cpe-row-main">
        <div className="cpe-row-label">{label}</div>
        {sub && <div className="cpe-row-sub">{sub}</div>}
      </div>
      <button
        type="button"
        className={`cpe-switch ${on ? 'is-on' : ''}`}
        disabled={disabled}
        onClick={() => !disabled && onChange?.(!on)}
        aria-pressed={on}
      >
        <span className="cpe-knob" />
      </button>
    </div>
  );
}
