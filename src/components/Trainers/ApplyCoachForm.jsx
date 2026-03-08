/**
 * ApplyCoachForm — 5-шаговая анкета «Стать тренером»
 */

import React, { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import useAuthStore from '../../stores/useAuthStore';
import './ApplyCoachForm.css';

const SPECIALIZATIONS = [
  { key: 'marathon', label: 'Марафон' },
  { key: 'half_marathon', label: 'Полумарафон' },
  { key: '5k_10k', label: '5/10 км' },
  { key: 'ultra', label: 'Ультра' },
  { key: 'trail', label: 'Трейл' },
  { key: 'beginner', label: 'Начинающие' },
  { key: 'injury_recovery', label: 'Травмы и восстановление' },
  { key: 'nutrition', label: 'Питание' },
  { key: 'mental', label: 'Ментальные навыки' },
];

const PRICING_TYPES = [
  { value: 'individual', label: 'Индивидуальные тренировки' },
  { value: 'group', label: 'Групповые тренировки' },
  { value: 'consultation', label: 'Разовая консультация' },
  { value: 'custom', label: 'Другое' },
];

const PRICING_PERIODS = [
  { value: 'month', label: 'в месяц' },
  { value: 'week', label: 'в неделю' },
  { value: 'one_time', label: 'разово' },
];

const TOTAL_STEPS = 5;

export default function ApplyCoachForm() {
  const navigate = useNavigate();
  const { user, api } = useAuthStore();
  const [step, setStep] = useState(1);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState(false);

  // Step 1
  const [specialization, setSpecialization] = useState([]);
  // Step 2
  const [experienceYears, setExperienceYears] = useState('');
  const [runnerAchievements, setRunnerAchievements] = useState('');
  const [athleteAchievements, setAthleteAchievements] = useState('');
  // Step 3
  const [bio, setBio] = useState('');
  const [philosophy, setPhilosophy] = useState('');
  // Step 4
  const [certifications, setCertifications] = useState('');
  const [contactsExtra, setContactsExtra] = useState('');
  const [acceptsNew, setAcceptsNew] = useState(true);
  // Step 5
  const [pricesOnRequest, setPricesOnRequest] = useState(false);
  const [pricingItems, setPricingItems] = useState([
    { type: 'individual', label: 'Индивидуальные тренировки', price: '', currency: 'RUB', period: 'month' },
  ]);

  useEffect(() => {
    if (user?.role === 'coach') {
      setError('Вы уже тренер');
    }
  }, [user]);

  const toggleSpec = (key) => {
    setSpecialization(prev =>
      prev.includes(key) ? prev.filter(s => s !== key) : [...prev, key]
    );
  };

  const addPricingItem = () => {
    setPricingItems(prev => [...prev, { type: 'custom', label: '', price: '', currency: 'RUB', period: 'month' }]);
  };

  const removePricingItem = (index) => {
    setPricingItems(prev => prev.filter((_, i) => i !== index));
  };

  const updatePricingItem = (index, field, value) => {
    setPricingItems(prev => prev.map((item, i) => {
      if (i !== index) return item;
      const updated = { ...item, [field]: value };
      if (field === 'type' && value !== 'custom') {
        updated.label = PRICING_TYPES.find(t => t.value === value)?.label || '';
      }
      return updated;
    }));
  };

  const validateStep = () => {
    setError('');
    switch (step) {
      case 1:
        if (specialization.length === 0) { setError('Выберите хотя бы одну специализацию'); return false; }
        return true;
      case 2:
        if (!experienceYears || experienceYears < 1 || experienceYears > 50) { setError('Укажите опыт от 1 до 50 лет'); return false; }
        return true;
      case 3:
        if (bio.length < 100) { setError(`Описание слишком короткое (${bio.length}/100 мин.)`); return false; }
        if (bio.length > 500) { setError(`Описание слишком длинное (${bio.length}/500 макс.)`); return false; }
        return true;
      case 4:
        return true;
      case 5:
        return true;
      default:
        return true;
    }
  };

  const nextStep = () => {
    if (validateStep()) setStep(s => Math.min(s + 1, TOTAL_STEPS));
  };
  const prevStep = () => setStep(s => Math.max(s - 1, 1));

  const handleSubmit = async () => {
    if (!validateStep()) return;
    setSubmitting(true);
    setError('');
    try {
      const pricing = pricesOnRequest ? [] : pricingItems.filter(p => p.label.trim()).map(p => ({
        ...p,
        price: p.price ? parseFloat(p.price) : null,
      }));

      await api.applyCoach({
        coach_specialization: specialization,
        coach_bio: bio,
        coach_philosophy: philosophy || undefined,
        coach_experience_years: parseInt(experienceYears),
        coach_runner_achievements: runnerAchievements || undefined,
        coach_athlete_achievements: athleteAchievements || undefined,
        coach_certifications: certifications || undefined,
        coach_contacts_extra: contactsExtra || undefined,
        coach_accepts_new: acceptsNew ? 1 : 0,
        coach_prices_on_request: pricesOnRequest ? 1 : 0,
        coach_pricing: pricing,
      });

      setSuccess(true);
    } catch (e) {
      setError(e.message || 'Ошибка при отправке заявки');
    } finally {
      setSubmitting(false);
    }
  };

  if (success) {
    return (
      <div className="apply-coach-form">
        <div className="apply-coach-success">
          <h2>Заявка отправлена!</h2>
          <p>Мы рассмотрим вашу заявку и свяжемся с вами. Обычно это занимает 1-2 дня.</p>
          <button className="btn btn-primary" onClick={() => navigate('/trainers')}>Вернуться</button>
        </div>
      </div>
    );
  }

  if (user?.role === 'coach') {
    return (
      <div className="apply-coach-form">
        <div className="apply-coach-success">
          <h2>Вы уже тренер</h2>
          <button className="btn btn-primary" onClick={() => navigate('/trainers')}>К моим ученикам</button>
        </div>
      </div>
    );
  }

  return (
    <div className="apply-coach-form">
      <h1 className="apply-coach-title">Стать тренером</h1>

      {/* Progress bar */}
      <div className="apply-coach-progress">
        {Array.from({ length: TOTAL_STEPS }, (_, i) => (
          <div key={i} className={`progress-step ${i + 1 <= step ? 'progress-step--active' : ''} ${i + 1 < step ? 'progress-step--done' : ''}`}>
            <span className="progress-step-num">{i + 1}</span>
          </div>
        ))}
        <div className="progress-bar-track">
          <div className="progress-bar-fill" style={{ width: `${((step - 1) / (TOTAL_STEPS - 1)) * 100}%` }} />
        </div>
      </div>

      {error && <div className="apply-coach-error">{error}</div>}

      {/* Step 1: Специализация */}
      {step === 1 && (
        <div className="apply-coach-step">
          <h2>Специализация</h2>
          <p className="step-hint">Выберите, с кем вы работаете. Эти теги помогут спортсменам найти вас в каталоге.</p>
          <div className="spec-grid">
            {SPECIALIZATIONS.map(s => (
              <label key={s.key} className={`spec-chip ${specialization.includes(s.key) ? 'spec-chip--selected' : ''}`}>
                <input type="checkbox" checked={specialization.includes(s.key)} onChange={() => toggleSpec(s.key)} />
                {s.label}
              </label>
            ))}
          </div>
        </div>
      )}

      {/* Step 2: Опыт */}
      {step === 2 && (
        <div className="apply-coach-step">
          <h2>Опыт и достижения</h2>
          <p className="step-hint">Опишите кратко: это помогает спортсменам понять ваш уровень и стиль.</p>
          <div className="form-group">
            <label>Опыт тренерской работы (лет) *</label>
            <input type="number" min="1" max="50" value={experienceYears} onChange={e => setExperienceYears(e.target.value)} placeholder="Сколько лет вы тренируете бегунов" />
          </div>
          <div className="form-group">
            <label>Свои достижения как бегун</label>
            <textarea value={runnerAchievements} onChange={e => setRunnerAchievements(e.target.value)} placeholder="Например: марафон 2:45, участие в Boston Marathon" rows={3} />
          </div>
          <div className="form-group">
            <label>Достижения учеников</label>
            <textarea value={athleteAchievements} onChange={e => setAthleteAchievements(e.target.value)} placeholder="Например: 10 учеников финишировали марафон, 2 — BQ" rows={3} />
          </div>
        </div>
      )}

      {/* Step 3: О себе */}
      {step === 3 && (
        <div className="apply-coach-step">
          <h2>О себе и подход</h2>
          <p className="step-hint">Этот текст будет виден в вашей карточке в каталоге. Пишите живым языком.</p>
          <div className="form-group">
            <label>О себе (100–500 символов) *</label>
            <textarea value={bio} onChange={e => setBio(e.target.value)} placeholder="Расскажите о себе: опыт, подход к тренировкам, с кем вы работаете" rows={5} maxLength={500} />
            <span className="char-count">{bio.length}/500</span>
          </div>
          <div className="form-group">
            <label>Тренерская философия (до 200 символов)</label>
            <textarea value={philosophy} onChange={e => setPhilosophy(e.target.value)} placeholder="Например: «Прогресс через постепенность и восстановление»" rows={2} maxLength={200} />
          </div>
        </div>
      )}

      {/* Step 4: Сертификации */}
      {step === 4 && (
        <div className="apply-coach-step">
          <h2>Сертификации и контакты</h2>
          <div className="form-group">
            <label>Сертификации</label>
            <textarea value={certifications} onChange={e => setCertifications(e.target.value)} placeholder="IAAF, NGB, курсы по бегу, First Aid, CPR и т.п." rows={3} />
          </div>
          <div className="form-group">
            <label>Дополнительные контакты</label>
            <input type="text" value={contactsExtra} onChange={e => setContactsExtra(e.target.value)} placeholder="Telegram, WhatsApp — если не совпадают с email" />
          </div>
          <label className="checkbox-label">
            <input type="checkbox" checked={acceptsNew} onChange={e => setAcceptsNew(e.target.checked)} />
            Принимаю новых учеников
          </label>
        </div>
      )}

      {/* Step 5: Стоимость */}
      {step === 5 && (
        <div className="apply-coach-step">
          <h2>Стоимость услуг</h2>
          <p className="step-hint">Цены помогут спортсменам сориентироваться. Можно указать «По запросу».</p>
          <label className="checkbox-label">
            <input type="checkbox" checked={pricesOnRequest} onChange={e => setPricesOnRequest(e.target.checked)} />
            Цены по запросу (не указывать конкретные суммы)
          </label>
          {!pricesOnRequest && (
            <div className="pricing-list">
              {pricingItems.map((item, i) => (
                <div key={i} className="pricing-item">
                  <div className="pricing-item-row">
                    <select value={item.type} onChange={e => updatePricingItem(i, 'type', e.target.value)}>
                      {PRICING_TYPES.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
                    </select>
                    {item.type === 'custom' && (
                      <input type="text" value={item.label} onChange={e => updatePricingItem(i, 'label', e.target.value)} placeholder="Название услуги" />
                    )}
                  </div>
                  <div className="pricing-item-row">
                    <input type="number" value={item.price} onChange={e => updatePricingItem(i, 'price', e.target.value)} placeholder="Цена" min="0" />
                    <span className="pricing-currency">руб.</span>
                    <select value={item.period} onChange={e => updatePricingItem(i, 'period', e.target.value)}>
                      {PRICING_PERIODS.map(p => <option key={p.value} value={p.value}>{p.label}</option>)}
                    </select>
                    {pricingItems.length > 1 && (
                      <button type="button" className="btn-icon-remove" onClick={() => removePricingItem(i)} title="Удалить">&times;</button>
                    )}
                  </div>
                </div>
              ))}
              <button type="button" className="btn btn-secondary btn-sm" onClick={addPricingItem}>+ Добавить услугу</button>
            </div>
          )}
        </div>
      )}

      {/* Navigation */}
      <div className="apply-coach-nav">
        {step > 1 && <button className="btn btn-secondary" onClick={prevStep}>Назад</button>}
        {step < TOTAL_STEPS && <button className="btn btn-primary" onClick={nextStep}>Далее</button>}
        {step === TOTAL_STEPS && (
          <button className="btn btn-primary" onClick={handleSubmit} disabled={submitting}>
            {submitting ? 'Отправка...' : 'Отправить заявку'}
          </button>
        )}
      </div>
    </div>
  );
}
