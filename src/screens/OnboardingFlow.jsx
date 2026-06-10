/**
 * OnboardingFlow — полноэкранный wizard специализации (v3 дизайн).
 * Заменяет SpecializationModal: режим → цель → профиль → (AI-оценка для забега) → генерация.
 * Вся логика данных перенесена 1:1 из прежнего RegisterScreen (specializationOnly):
 * те же поля, та же валидация по шагам, тот же debounce assessGoal и completeSpecialization.
 */

import { useState, useRef, useEffect, useCallback } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { motion, AnimatePresence } from 'framer-motion';
import useAuthStore from '../stores/useAuthStore';
import getAuthClient from '../api/getAuthClient';
import { CheckIcon, CloseIcon } from '../components/common/Icons';
import StepMode from '../components/Onboarding/StepMode';
import StepGoal from '../components/Onboarding/StepGoal';
import StepAssessment from '../components/Onboarding/StepAssessment';
import StepProfile from '../components/Onboarding/StepProfile';
import StepGenerating from '../components/Onboarding/StepGenerating';
import {
  createInitialOnboardingState,
  seedOnboardingFromUser,
  buildSpecializationPayload,
  isPlanGenerationMode,
} from '../components/Onboarding/onboardingForm';
import './OnboardingFlow.css';

const STEP_LABELS = { mode: 'Режим', goal: 'Цель', profile: 'Профиль', assess: 'Оценка' };
const BRAND_FEATURES = [
  'AI или сам — на выбор',
  'Импорт из Strava, Polar, Coros',
  'План подстраивается под прогресс',
];

/** Набор и порядок шагов зависят от режима и цели (как в старом RegisterScreen).
 *  При смене режима (skipMode) шаг выбора режима пропускаем — он уже выбран в попапе. */
function buildSteps(formData, skipMode) {
  let steps;
  if (formData.training_mode === 'self') steps = ['mode', 'profile'];
  else {
    steps = ['mode', 'goal', 'profile'];
    if (formData.goal_type === 'race' || formData.goal_type === 'time_improvement') {
      steps.push('assess');
    }
  }
  return skipMode ? steps.filter((s) => s !== 'mode') : steps;
}

export default function OnboardingFlow() {
  const navigate = useNavigate();
  const location = useLocation();
  const { api, updateUser, setPlanGenerationMessage, user } = useAuthStore();
  const client = api || getAuthClient();

  // Смена режима из дашборда: предвыбранный режим + предзаполнение известными метриками.
  const switchMode = location.state?.mode || null;
  const [formData, setFormData] = useState(() => (
    switchMode ? seedOnboardingFromUser(user, switchMode) : createInitialOnboardingState()
  ));
  const [index, setIndex] = useState(0);
  const [dir, setDir] = useState(1);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  const [assessment, setAssessment] = useState(null);
  const [assessLoading, setAssessLoading] = useState(false);
  const assessTimer = useRef(null);

  const [phase, setPhase] = useState('form'); // 'form' | 'generating'
  const [planMessage, setPlanMessage] = useState(null);
  const pendingUserRef = useRef(null);

  const steps = buildSteps(formData, !!switchMode);
  const safeIndex = Math.min(index, steps.length - 1);
  const stepName = steps[safeIndex];
  const isLast = safeIndex === steps.length - 1;
  const isSelf = formData.training_mode === 'self';
  const planMode = isPlanGenerationMode(formData.training_mode);

  const handleChange = useCallback((field, value) => {
    setError('');
    setFormData((prev) => ({ ...prev, [field]: value }));
  }, []);

  const handleToggleArray = useCallback((field, value, checked) => {
    setFormData((prev) => {
      const arr = prev[field] || [];
      const next = checked ? [...arr, value] : arr.filter((x) => x !== value);
      const updates = { [field]: next };
      if (field === 'preferred_days') updates.sessions_per_week = String(next.length);
      return { ...prev, ...updates };
    });
  }, []);

  // AI-оценка цели — debounce 800ms, контракт как в прежнем RegisterScreen.
  useEffect(() => {
    const isRaceGoal = formData.goal_type === 'race' || formData.goal_type === 'time_improvement';
    if (!isRaceGoal || !formData.race_distance || !formData.race_date) {
      setAssessment(null);
      return undefined;
    }
    clearTimeout(assessTimer.current);
    assessTimer.current = setTimeout(async () => {
      setAssessLoading(true);
      try {
        const result = await client.assessGoal({
          // Контекст регистрации: бэк смягчает вердикт (unrealistic→caution) и НЕ блокирует
          // прохождение — собирает осторожный стартовый план и уточняет по первым тренировкам.
          _assessment_context: 'registration',
          goal_type: formData.goal_type,
          race_distance: formData.race_distance,
          race_date: formData.race_date,
          race_target_time: formData.race_target_time || '',
          training_start_date: formData.training_start_date,
          weekly_base_km: formData.weekly_base_km || 0,
          weekly_base_range: formData.weekly_base_range || '', // явный сигнал: 'none' = осознанный 0
          sessions_per_week: formData.preferred_days?.length || formData.sessions_per_week || 3,
          experience_level: formData.experience_level,
          birth_year: formData.birth_year || '',
          last_race_distance: formData.last_race_distance || '',
          last_race_distance_km: formData.last_race_distance_km || '',
          last_race_time: formData.last_race_time || '',
          easy_pace_sec: formData.easy_pace_sec || '',
          is_first_race_at_distance: formData.is_first_race_at_distance ? 1 : 0,
        });
        if (result?.verdict) setAssessment(result);
      } catch {
        /* оценка необязательна — игнорируем сбой */
      } finally {
        setAssessLoading(false);
      }
    }, 800);
    return () => clearTimeout(assessTimer.current);
  }, [
    client, formData.goal_type, formData.race_distance, formData.race_date, formData.race_target_time,
    formData.training_start_date, formData.weekly_base_km, formData.experience_level,
    formData.last_race_distance, formData.last_race_distance_km, formData.last_race_time,
    formData.easy_pace_sec, formData.sessions_per_week, formData.preferred_days?.length,
    formData.is_first_race_at_distance, formData.birth_year, formData.weekly_base_range,
  ]);

  /** Валидация текущего шага — возвращает текст ошибки или ''. */
  const validateStep = () => {
    if (stepName === 'mode') {
      if (!formData.training_mode) return 'Пожалуйста, выберите режим тренировок';
    }
    if (stepName === 'goal') {
      if (!formData.goal_type) return 'Выберите цель';
      if (formData.goal_type === 'race' || formData.goal_type === 'time_improvement') {
        if (!formData.race_date) return 'Укажите дату забега';
      } else if (formData.goal_type === 'weight_loss') {
        if (!formData.weight_kg) return 'Укажите текущий вес';
        if (!formData.weight_goal_kg) return 'Укажите целевой вес';
        if (!formData.weight_goal_date) return 'Укажите дату достижения цели';
      } else if (formData.goal_type === 'health') {
        if (!formData.health_program) return 'Выберите программу';
        if (formData.health_program === 'custom' && !formData.health_plan_weeks) return 'Укажите срок плана';
      }
      if (!formData.training_start_date) return 'Укажите дату начала тренировок';
    }
    if (stepName === 'profile') {
      if (!formData.first_name || !formData.first_name.trim()) return 'Укажите имя';
      if (!formData.gender) return 'Пожалуйста, выберите пол';
      if (!isSelf && !formData.experience_level) return 'Укажите ваш опыт';
    }
    return '';
  };

  // На шаге оценки НЕ блокируем прохождение: бэк в registration-контексте отдаёт
  // вердикт 'caution' (blocks_registration:false) и собирает осторожный стартовый план.
  // Раньше блокировка по 'unrealistic' оставляла юзера в тупике (мёртвый soften-флоу).
  const submitDisabled =
    loading ||
    (stepName === 'mode' && !formData.training_mode);

  // Осторожный вердикт (амбициозно/нереально) — кнопка проходима, но с мягким акцентом.
  const cautionVerdict = ['caution', 'unrealistic', 'challenging'].includes(assessment?.verdict);

  const handleSubmit = async () => {
    setLoading(true);
    setError('');
    try {
      const result = await client.completeSpecialization(buildSpecializationPayload(formData));
      if (result.success) {
        let userData = null;
        try { userData = await client.getCurrentUser(); } catch { userData = null; }
        pendingUserRef.current = userData;
        setPlanMessage(result.plan_message || null);
        setPhase('generating');
      } else {
        setError(result.error || 'Ошибка сохранения');
      }
    } catch (e) {
      setError(e.message || 'Ошибка сохранения');
    } finally {
      setLoading(false);
    }
  };

  const handleNext = () => {
    const err = validateStep();
    if (err) { setError(err); return; }
    if (isLast) { handleSubmit(); return; }
    setError('');
    setDir(1);
    setIndex(safeIndex + 1);
  };

  const handleBack = () => {
    setError('');
    setDir(-1);
    setIndex(Math.max(0, safeIndex - 1));
  };

  const handleFinish = () => {
    const userData = pendingUserRef.current;
    if (userData) {
      updateUser(userData);
    } else {
      const current = useAuthStore.getState().user || {};
      updateUser({ ...current, onboarding_completed: 1, authenticated: true });
    }
    if (planMessage) setPlanGenerationMessage(planMessage);
    navigate('/', { replace: true, state: { registrationSuccess: true } });
  };

  // Экран генерации/успеха
  if (phase === 'generating') {
    return (
      <div className="ob-shell">
        <div className="ob-brand" aria-hidden>
          <div className="ob-brand__logo">
            <span className="ob-brand__logo-mark">P</span>
            <span className="ob-brand__logo-text">planrun</span>
          </div>
        </div>
        <div className="ob-main">
          <div className="ob-body">
            <StepGenerating isPlanMode={planMode} planMessage={planMessage} onDone={handleFinish} />
          </div>
        </div>
      </div>
    );
  }

  const eyebrow = `ШАГ ${safeIndex + 1} ИЗ ${steps.length}`;

  const stepVariants = {
    enter: (d) => ({ opacity: 0, x: d > 0 ? 28 : -28 }),
    center: { opacity: 1, x: 0 },
    exit: (d) => ({ opacity: 0, x: d > 0 ? -28 : 28 }),
  };

  return (
    <div className="ob-shell">
      {/* Бренд-панель (только desktop) */}
      <div className="ob-brand">
        <div>
          <div className="ob-brand__logo">
            <span className="ob-brand__logo-mark">P</span>
            <span className="ob-brand__logo-text">planrun</span>
          </div>
          <h1 className="ob-brand__title">План бега,<br />который ведёт<br />к цели</h1>
          <p className="ob-brand__lead">
            AI построит персональный план под твою цель. Подключи Strava — анализируем каждую тренировку.
          </p>
          <div style={{ marginTop: 40, display: 'flex', flexDirection: 'column', gap: 4 }}>
            {steps.map((s, i) => {
              const done = i < safeIndex;
              const active = i === safeIndex;
              return (
                <div key={s} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '8px 10px', borderRadius: 10, background: active ? 'rgba(255,255,255,0.16)' : 'transparent' }}>
                  <span style={{ width: 24, height: 24, borderRadius: '50%', display: 'grid', placeItems: 'center', fontSize: 11, fontWeight: 800, fontFamily: '"Jost", sans-serif', background: done || active ? '#fff' : 'rgba(255,255,255,0.2)', color: done || active ? 'var(--primary-500)' : '#fff' }}>
                    {done ? '✓' : i + 1}
                  </span>
                  <span style={{ fontSize: 14, fontWeight: active ? 700 : 500, color: active || done ? '#fff' : 'rgba(255,255,255,0.6)' }}>{STEP_LABELS[s]}</span>
                </div>
              );
            })}
          </div>
        </div>
        <div className="ob-brand__features">
          {BRAND_FEATURES.map((t) => (
            <div key={t} className="ob-brand__feature">
              <span className="ob-brand__feature-icon"><CheckIcon size={17} /></span>
              <span>{t}</span>
            </div>
          ))}
        </div>
      </div>

      {/* Колонка формы */}
      <div className="ob-main">
        {switchMode && (
          <button type="button" className="ob-close" onClick={() => navigate('/')} aria-label="Закрыть">
            <CloseIcon size={20} />
          </button>
        )}
        <div className="ob-stepper">
          {steps.map((s, i) => (
            <div key={s} className={`ob-stepper__seg ${i <= safeIndex ? 'ob-stepper__seg--done' : ''}`} />
          ))}
        </div>

        <div className="ob-body">
          <AnimatePresence mode="wait" custom={dir}>
            <motion.div
              key={stepName}
              custom={dir}
              variants={stepVariants}
              initial="enter"
              animate="center"
              exit="exit"
              transition={{ duration: 0.26, ease: [0.33, 1, 0.68, 1] }}
            >
              {stepName === 'mode' && <StepMode formData={formData} onChange={handleChange} eyebrow={eyebrow} />}
              {stepName === 'goal' && <StepGoal formData={formData} onChange={handleChange} eyebrow={eyebrow} />}
              {stepName === 'profile' && (
                <StepProfile formData={formData} onChange={handleChange} onToggleArray={handleToggleArray} eyebrow={eyebrow} />
              )}
              {stepName === 'assess' && (
                <StepAssessment
                  formData={formData}
                  assessment={assessment}
                  loading={assessLoading}
                  onApplySuggestion={handleChange}
                />
              )}
            </motion.div>
          </AnimatePresence>

          {error && <div className="ob-error">{error}</div>}

          <div className="ob-actions">
            {safeIndex > 0 && (
              <button type="button" className="ob-back-btn" onClick={handleBack} disabled={loading}>← Назад</button>
            )}
            <button
              type="button"
              className={`ob-cta ${stepName === 'assess' && cautionVerdict ? 'ob-cta--warning' : ''}`}
              onClick={handleNext}
              disabled={submitDisabled}
            >
              {loading
                ? 'Сохраняю...'
                : stepName === 'assess' && cautionVerdict
                  ? 'Всё равно создать план →'
                  : isLast
                    ? (planMode ? 'Создать план →' : 'Создать календарь →')
                    : 'Дальше →'}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
