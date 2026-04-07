/**
 * ApplyCoachForm — 5-шаговая анкета «Стать тренером»
 */

import { useState, useEffect, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import useAuthStore from '../../stores/useAuthStore';
import {
  CheckIcon,
  ClipboardListIcon,
  GraduationCapIcon,
  LinkIcon,
  PenLineIcon,
  TargetIcon,
  TrophyIcon,
} from '../common/Icons';
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

const STEP_META = [
  {
    title: 'Специализация',
    short: 'Каталог и позиционирование',
    summary: 'Эти теги влияют на то, как вас будут находить в каталоге и кому покажется ваш профиль.',
    hint: 'Отметьте направления, в которых вы действительно ведете спортсменов и можете уверенно помочь.',
    tip: 'Лучше выбрать 3-5 точных направлений, чем пытаться охватить все сразу.',
    Icon: TargetIcon,
  },
  {
    title: 'Опыт и достижения',
    short: 'Подтвердите экспертизу',
    summary: 'Здесь спортсмены понимают, почему вам можно доверить подготовку и на чем держится ваша практика.',
    hint: 'Опыт можно указать коротко, а достижения лучше писать фактами, цифрами и понятными примерами.',
    tip: 'Конкретика работает лучше общих слов: стаж, старты, результаты учеников, заметный прогресс.',
    Icon: TrophyIcon,
  },
  {
    title: 'О себе и подход',
    short: 'Сформулируйте стиль работы',
    summary: 'Это главный текст карточки тренера: он должен быстро объяснить ваш подход и кому вы подходите.',
    hint: 'Напишите живое описание без лишнего шума: с кем вы работаете, как строите процесс и что для вас важно.',
    tip: 'Пишите так, будто отвечаете будущему ученику в личном сообщении, а не в официальной анкете.',
    Icon: PenLineIcon,
  },
  {
    title: 'Сертификации и контакты',
    short: 'Усиление доверия',
    summary: 'Этот шаг добавляет контекст и снижает барьер перед первым контактом со спортсменом.',
    hint: 'Сертификаты и внешние контакты не обязательны, но помогают быстрее начать диалог и показать уровень подготовки.',
    tip: 'Если общение вне платформы пока не планируете, достаточно указать обучение и профильные курсы.',
    Icon: LinkIcon,
  },
  {
    title: 'Стоимость услуг',
    short: 'Формат сотрудничества',
    summary: 'Финальный шаг показывает, как с вами работать и чего ожидать по формату и стоимости.',
    hint: 'Укажите цену или честно оставьте вариант «по запросу», чтобы снизить лишние вопросы до первого контакта.',
    tip: 'Если условия зависят от уровня спортсмена, лучше отразить это в названии услуги или в формате оплаты.',
    Icon: ClipboardListIcon,
  },
];

export default function ApplyCoachForm() {
  const navigate = useNavigate();
  const { user, api } = useAuthStore();
  const panelRef = useRef(null);
  const stepItemRefs = useRef([]);
  const didInitStepRef = useRef(false);
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

  useEffect(() => {
    if (!didInitStepRef.current) {
      didInitStepRef.current = true;
      return;
    }

    stepItemRefs.current[step - 1]?.scrollIntoView({
      behavior: 'smooth',
      block: 'nearest',
      inline: 'center',
    });

    panelRef.current?.scrollIntoView({
      behavior: 'smooth',
      block: 'start',
    });
  }, [step]);

  const activeStep = STEP_META[step - 1];
  const ActiveStepIcon = activeStep.Icon;
  const progressPercent = Math.round((step / TOTAL_STEPS) * 100);

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
      <div className="apply-coach-page">
        <div className="apply-coach-shell">
          <div className="apply-coach-state-card card">
            <div className="apply-coach-state-icon apply-coach-state-icon--success" aria-hidden="true">
              <CheckIcon size={28} />
            </div>
            <h2>Заявка отправлена</h2>
            <p>Мы рассмотрим вашу заявку и свяжемся с вами. Обычно это занимает 1-2 дня.</p>
            <button type="button" className="btn btn-primary" onClick={() => navigate('/trainers')}>
              Вернуться в раздел
            </button>
          </div>
        </div>
      </div>
    );
  }

  if (user?.role === 'coach') {
    return (
      <div className="apply-coach-page">
        <div className="apply-coach-shell">
          <div className="apply-coach-state-card card">
            <div className="apply-coach-state-icon" aria-hidden="true">
              <GraduationCapIcon size={28} />
            </div>
            <h2>Профиль тренера уже активен</h2>
            <p>Заявку отправлять не нужно. У вас уже открыт доступ к разделу тренеров и ученикам.</p>
            <button type="button" className="btn btn-primary" onClick={() => navigate('/trainers')}>
              К моим ученикам
            </button>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="apply-coach-page">
      <div className="apply-coach-shell">
        <section className="apply-coach-header card">
          <div className="apply-coach-header-main">
            <div className="apply-coach-header-badge">
              <GraduationCapIcon size={16} />
              Заявка на роль тренера
            </div>
            <div className="apply-coach-header-copy">
              <h1 className="apply-coach-title">Стать тренером</h1>
              <p className="apply-coach-subtitle">
                Собираем профиль без лишней бюрократии: пройдите 5 шагов, после модерации анкета
                появится в каталоге и откроет прием заявок от спортсменов.
              </p>
            </div>
          </div>
          <div className="apply-coach-header-meta" aria-label="Параметры анкеты">
            <div className="apply-coach-header-meta-item">
              <span className="apply-coach-header-meta-label">Шагов</span>
              <strong>{TOTAL_STEPS}</strong>
            </div>
            <div className="apply-coach-header-meta-item">
              <span className="apply-coach-header-meta-label">Модерация</span>
              <strong>1-2 дня</strong>
            </div>
          </div>
        </section>

        <section className="apply-coach-journey card" aria-label="Навигация по анкете">
          <div className="apply-coach-journey-head">
            <div className="apply-coach-journey-head-copy">
              <div className="apply-coach-journey-label">Заполнение профиля</div>
              <div className="apply-coach-journey-summary">{activeStep.short}</div>
            </div>
            <div className="apply-coach-progress-value">{progressPercent}%</div>
          </div>

          <div className="apply-coach-progress-track" aria-hidden="true">
            <div className="apply-coach-progress-fill" style={{ width: `${progressPercent}%` }} />
          </div>

          <div className="apply-coach-step-list">
            {STEP_META.map((meta, index) => {
              const stepNumber = index + 1;
              const Icon = meta.Icon;
              const isCurrent = stepNumber === step;
              const isDone = stepNumber < step;
              const canNavigate = stepNumber < step;

              return (
                <button
                  key={meta.title}
                  ref={(node) => {
                    stepItemRefs.current[index] = node;
                  }}
                  type="button"
                  className={`apply-coach-step-item ${isCurrent ? 'apply-coach-step-item--current' : ''} ${isDone ? 'apply-coach-step-item--done' : ''}`}
                  onClick={() => canNavigate && setStep(stepNumber)}
                  disabled={!canNavigate}
                  aria-current={isCurrent ? 'step' : undefined}
                >
                  <span className="apply-coach-step-marker" aria-hidden="true">
                    {isDone ? <CheckIcon size={16} /> : stepNumber}
                  </span>
                  <span className="apply-coach-step-copy">
                    <span className="apply-coach-step-title">
                      <Icon size={16} />
                      {meta.title}
                    </span>
                    <span className="apply-coach-step-desc">{meta.short}</span>
                  </span>
                </button>
              );
            })}
          </div>

        </section>

        <section ref={panelRef} className="apply-coach-panel card">
          <div className="apply-coach-journey-active">
            <div className="apply-coach-journey-active-main">
              <span className="apply-coach-journey-active-icon" aria-hidden="true">
                <ActiveStepIcon size={20} />
              </span>
              <div className="apply-coach-journey-active-copy">
                <span className="apply-coach-journey-active-kicker">Сейчас шаг {step} из {TOTAL_STEPS}</span>
                <strong>{activeStep.title}</strong>
                <p>{activeStep.summary}</p>
              </div>
            </div>
            <div className="apply-coach-journey-active-tip">
              <span className="apply-coach-journey-active-tip-label">Что важно</span>
              <p>{activeStep.tip}</p>
            </div>
          </div>

          {error && <div className="apply-coach-message apply-coach-message--error" role="alert">{error}</div>}

          <div className="apply-coach-form">
              {/* Step 1: Специализация */}
              {step === 1 && (
                <div className="apply-coach-step-content">
                  <div className="apply-coach-spec-grid">
                    {SPECIALIZATIONS.map((item) => (
                      <label
                        key={item.key}
                        className={`apply-coach-spec-chip ${specialization.includes(item.key) ? 'apply-coach-spec-chip--selected' : ''}`}
                      >
                        <input
                          className="apply-coach-spec-input"
                          type="checkbox"
                          checked={specialization.includes(item.key)}
                          onChange={() => toggleSpec(item.key)}
                        />
                        <span>{item.label}</span>
                      </label>
                    ))}
                  </div>
                </div>
              )}

              {/* Step 2: Опыт */}
              {step === 2 && (
                <div className="apply-coach-step-content">
                  <div className="apply-coach-field">
                    <label htmlFor="coach-experience-years">Опыт тренерской работы (лет) *</label>
                    <input
                      id="coach-experience-years"
                      className="apply-coach-input"
                      type="number"
                      min="1"
                      max="50"
                      inputMode="numeric"
                      value={experienceYears}
                      onChange={(e) => setExperienceYears(e.target.value)}
                      placeholder="Сколько лет вы тренируете бегунов"
                    />
                  </div>
                  <div className="apply-coach-field">
                    <label htmlFor="coach-runner-achievements">Свои достижения как бегун</label>
                    <textarea
                      id="coach-runner-achievements"
                      className="apply-coach-textarea"
                      value={runnerAchievements}
                      onChange={(e) => setRunnerAchievements(e.target.value)}
                      placeholder="Например: марафон 2:45, участие в Boston Marathon"
                      rows={4}
                    />
                  </div>
                  <div className="apply-coach-field">
                    <label htmlFor="coach-athlete-achievements">Достижения учеников</label>
                    <textarea
                      id="coach-athlete-achievements"
                      className="apply-coach-textarea"
                      value={athleteAchievements}
                      onChange={(e) => setAthleteAchievements(e.target.value)}
                      placeholder="Например: 10 учеников финишировали марафон, 2 выполнили BQ"
                      rows={4}
                    />
                  </div>
                </div>
              )}

              {/* Step 3: О себе */}
              {step === 3 && (
                <div className="apply-coach-step-content">
                  <div className="apply-coach-field">
                    <label htmlFor="coach-bio">О себе (100–500 символов) *</label>
                    <textarea
                      id="coach-bio"
                      className="apply-coach-textarea apply-coach-textarea--lg"
                      value={bio}
                      onChange={(e) => setBio(e.target.value)}
                      placeholder="Расскажите о себе: опыт, подход к тренировкам, с кем вы работаете"
                      rows={6}
                      maxLength={500}
                    />
                    <span className="apply-coach-char-count">{bio.length}/500</span>
                  </div>
                  <div className="apply-coach-field">
                    <label htmlFor="coach-philosophy">Тренерская философия (до 200 символов)</label>
                    <textarea
                      id="coach-philosophy"
                      className="apply-coach-textarea"
                      value={philosophy}
                      onChange={(e) => setPhilosophy(e.target.value)}
                      placeholder="Например: «Прогресс через постепенность, дисциплину и восстановление»"
                      rows={3}
                      maxLength={200}
                    />
                  </div>
                </div>
              )}

              {/* Step 4: Сертификации */}
              {step === 4 && (
                <div className="apply-coach-step-content">
                  <div className="apply-coach-field">
                    <label htmlFor="coach-certifications">Сертификации</label>
                    <textarea
                      id="coach-certifications"
                      className="apply-coach-textarea"
                      value={certifications}
                      onChange={(e) => setCertifications(e.target.value)}
                      placeholder="IAAF, NGB, курсы по бегу, First Aid, CPR и т.п."
                      rows={4}
                    />
                  </div>
                  <div className="apply-coach-field">
                    <label htmlFor="coach-contacts-extra">Дополнительные контакты</label>
                    <input
                      id="coach-contacts-extra"
                      className="apply-coach-input"
                      type="text"
                      value={contactsExtra}
                      onChange={(e) => setContactsExtra(e.target.value)}
                      placeholder="Telegram, WhatsApp или другие контакты"
                    />
                  </div>
                  <label className="apply-coach-checkbox">
                    <input type="checkbox" checked={acceptsNew} onChange={(e) => setAcceptsNew(e.target.checked)} />
                    <span>Принимаю новых учеников</span>
                  </label>
                </div>
              )}

              {/* Step 5: Стоимость */}
              {step === 5 && (
                <div className="apply-coach-step-content">
                  <label className="apply-coach-checkbox">
                    <input type="checkbox" checked={pricesOnRequest} onChange={(e) => setPricesOnRequest(e.target.checked)} />
                    <span>Цены по запросу, без публикации конкретных сумм</span>
                  </label>

                  {!pricesOnRequest && (
                    <div className="apply-coach-pricing-list">
                      {pricingItems.map((item, index) => (
                        <div key={index} className="apply-coach-pricing-item">
                          <div className="apply-coach-pricing-row">
                            <div className="apply-coach-field apply-coach-field--compact">
                              <label htmlFor={`coach-pricing-type-${index}`}>Тип услуги</label>
                              <select
                                id={`coach-pricing-type-${index}`}
                                className="apply-coach-select"
                                value={item.type}
                                onChange={(e) => updatePricingItem(index, 'type', e.target.value)}
                              >
                                {PRICING_TYPES.map((pricingType) => (
                                  <option key={pricingType.value} value={pricingType.value}>
                                    {pricingType.label}
                                  </option>
                                ))}
                              </select>
                            </div>

                            {item.type === 'custom' && (
                              <div className="apply-coach-field apply-coach-field--compact apply-coach-field--grow">
                                <label htmlFor={`coach-pricing-label-${index}`}>Название услуги</label>
                                <input
                                  id={`coach-pricing-label-${index}`}
                                  className="apply-coach-input"
                                  type="text"
                                  value={item.label}
                                  onChange={(e) => updatePricingItem(index, 'label', e.target.value)}
                                  placeholder="Например: анализ подготовки к марафону"
                                />
                              </div>
                            )}
                          </div>

                          <div className="apply-coach-pricing-row">
                            <div className="apply-coach-field apply-coach-field--compact">
                              <label htmlFor={`coach-pricing-price-${index}`}>Цена</label>
                              <input
                                id={`coach-pricing-price-${index}`}
                                className="apply-coach-input"
                                type="number"
                                inputMode="decimal"
                                value={item.price}
                                onChange={(e) => updatePricingItem(index, 'price', e.target.value)}
                                placeholder="0"
                                min="0"
                              />
                            </div>
                            <div className="apply-coach-field apply-coach-field--compact">
                              <label htmlFor={`coach-pricing-period-${index}`}>Период</label>
                              <select
                                id={`coach-pricing-period-${index}`}
                                className="apply-coach-select"
                                value={item.period}
                                onChange={(e) => updatePricingItem(index, 'period', e.target.value)}
                              >
                                {PRICING_PERIODS.map((period) => (
                                  <option key={period.value} value={period.value}>
                                    {period.label}
                                  </option>
                                ))}
                              </select>
                            </div>
                            {pricingItems.length > 1 && (
                              <div className="apply-coach-pricing-actions">
                                <button
                                  type="button"
                                  className="btn btn--danger-text btn--sm apply-coach-remove-btn"
                                  onClick={() => removePricingItem(index)}
                                >
                                  Удалить
                                </button>
                              </div>
                            )}
                          </div>
                        </div>
                      ))}

                      <button type="button" className="btn btn-secondary btn--sm" onClick={addPricingItem}>
                        Добавить услугу
                      </button>
                    </div>
                  )}
                </div>
              )}

            <div className="apply-coach-nav">
              <div className="apply-coach-nav-note">Профиль можно заполнить подробно сразу, без сокращений и общих фраз.</div>
              <div className="apply-coach-nav-actions">
                {step > 1 && (
                  <button type="button" className="btn btn-secondary" onClick={prevStep}>
                    Назад
                  </button>
                )}
                {step < TOTAL_STEPS && (
                  <button type="button" className="btn btn-primary" onClick={nextStep}>
                    Далее
                  </button>
                )}
                {step === TOTAL_STEPS && (
                  <button type="button" className="btn btn-primary" onClick={handleSubmit} disabled={submitting}>
                    {submitting ? 'Отправка...' : 'Отправить заявку'}
                  </button>
                )}
              </div>
            </div>
          </div>
        </section>
      </div>
    </div>
  );
}
