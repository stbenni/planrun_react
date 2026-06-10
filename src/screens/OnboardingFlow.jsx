/**
 * OnboardingFlow — полноэкранный wizard специализации (v3B «Телеметрия», мок BObShell).
 * Режим → цель → профиль → (AI-оценка) → генерация → план готов.
 * Логика данных 1:1 с прежней версией: те же поля, валидация по шагам,
 * debounce assessGoal и completeSpecialization; добавлен поллинг готовности плана.
 */

import { useState, useRef, useEffect, useCallback } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { motion, AnimatePresence } from 'framer-motion';
import useAuthStore from '../stores/useAuthStore';
import getAuthClient from '../api/getAuthClient';
import { PrLogo, PrLabel, PrButton } from '../components/ui';
import StepMode from '../components/Onboarding/StepMode';
import StepGoal from '../components/Onboarding/StepGoal';
import StepAssessment from '../components/Onboarding/StepAssessment';
import StepProfile from '../components/Onboarding/StepProfile';
import StepGenerating from '../components/Onboarding/StepGenerating';
import StepReady from '../components/Onboarding/StepReady';
import { ObError } from '../components/Onboarding/obKit';
import { buildPlanSummary } from '../components/Onboarding/planSummary';
import {
  createInitialOnboardingState,
  seedOnboardingFromUser,
  buildSpecializationPayload,
  isPlanGenerationMode,
  HEALTH_PROGRAMS,
} from '../components/Onboarding/onboardingForm';

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

const DIST_FULL = { '5k': '5 км', '10k': '10 км', half: 'Полумарафон', marathon: 'Марафон' };

/** Подзаголовок экрана генерации: «Марафон · 04.10 · цель 3:29:59». */
function genSubtitle(formData, planMessage) {
  if (formData.goal_type === 'race' || formData.goal_type === 'time_improvement') {
    const parts = [
      DIST_FULL[formData.race_distance],
      formData.race_date
        ? new Date(`${formData.race_date}T00:00:00`).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' })
        : null,
      formData.race_target_time ? `цель ${formData.race_target_time}` : null,
    ].filter(Boolean);
    if (parts.length) return `${parts.join(' · ')}.`;
  }
  if (formData.goal_type === 'health') {
    const p = HEALTH_PROGRAMS.find((x) => x.value === formData.health_program);
    if (p) return `Программа «${p.name}».`;
  }
  if (formData.goal_type === 'weight_loss' && formData.weight_kg && formData.weight_goal_kg) {
    return `Цель: ${formData.weight_kg} → ${formData.weight_goal_kg} кг.`;
  }
  return planMessage || '';
}

export function Shell({ filled, total, onClose, children }) {
  return (
    <div style={{ minHeight: '100vh', background: 'var(--pr-bg)', display: 'flex', flexDirection: 'column', fontFamily: 'var(--pr-font-body)' }}>
      <div style={{ width: '100%', maxWidth: 560, margin: '0 auto', flex: 1, display: 'flex', flexDirection: 'column', padding: '0 22px' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12, paddingTop: 'calc(16px + env(safe-area-inset-top, 0px))', flexShrink: 0 }}>
          <PrLogo />
          <div style={{ flex: 1, display: 'flex', gap: 5 }}>
            {Array.from({ length: total }).map((_, i) => (
              <div key={i} style={{ flex: 1, height: 4, borderRadius: 999, background: i < filled ? 'var(--pr-grad)' : 'var(--pr-track)' }} />
            ))}
          </div>
          <PrLabel size={9}>{filled} / {total}</PrLabel>
          {onClose && (
            <button
              type="button"
              onClick={onClose}
              aria-label="Закрыть"
              style={{
                width: 30,
                height: 30,
                borderRadius: 999,
                border: '1px solid var(--pr-card-border)',
                background: 'var(--pr-card)',
                color: 'var(--pr-sub)',
                fontSize: 13,
                cursor: 'pointer',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
              }}
            >
              ✕
            </button>
          )}
        </div>
        {children}
      </div>
    </div>
  );
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

  const [phase, setPhase] = useState('form'); // 'form' | 'generating' | 'ready'
  const [planMessage, setPlanMessage] = useState(null);
  const [summary, setSummary] = useState(null);
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
  const submitDisabled =
    loading ||
    (stepName === 'mode' && !formData.training_mode);

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
        setPhase(planMode ? 'generating' : 'ready');
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

  const handleFinish = (navTo = '/') => {
    const userData = pendingUserRef.current;
    if (userData) {
      updateUser(userData);
    } else {
      const current = useAuthStore.getState().user || {};
      updateUser({ ...current, onboarding_completed: 1, authenticated: true });
    }
    if (planMessage && navTo === '/') setPlanGenerationMessage(planMessage);
    navigate(navTo, { replace: true, state: { registrationSuccess: true } });
  };

  // План собран в очереди — забираем его и показываем сводку «План готов».
  const handlePlanReady = useCallback(async () => {
    let s = null;
    try {
      const raw = await client.getPlan();
      const planData = raw?.data ?? raw;
      s = buildPlanSummary(planData, formData);
    } catch {
      s = null;
    }
    setSummary(s);
    setPhase('ready');
  }, [client, formData]);

  // Экран генерации
  if (phase === 'generating') {
    return (
      <Shell filled={steps.length} total={steps.length}>
        <StepGenerating
          client={client}
          subtitle={genSubtitle(formData, planMessage)}
          onReady={handlePlanReady}
          onDashboard={() => handleFinish('/')}
        />
      </Shell>
    );
  }

  // Экран «План готов» / «Календарь готов»
  if (phase === 'ready') {
    return (
      <Shell filled={steps.length} total={steps.length}>
        <StepReady
          planMode={planMode}
          summary={summary}
          formData={formData}
          planMessage={planMessage}
          onOpenCalendar={() => handleFinish('/calendar')}
        />
      </Shell>
    );
  }

  const stepVariants = {
    enter: (d) => ({ opacity: 0, x: d > 0 ? 28 : -28 }),
    center: { opacity: 1, x: 0 },
    exit: (d) => ({ opacity: 0, x: d > 0 ? -28 : 28 }),
  };

  return (
    <Shell
      filled={safeIndex + 1}
      total={steps.length}
      onClose={switchMode ? () => navigate('/') : undefined}
    >
      <div style={{ flex: 1, display: 'flex', flexDirection: 'column' }}>
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
            {stepName === 'mode' && <StepMode formData={formData} onChange={handleChange} />}
            {stepName === 'goal' && <StepGoal formData={formData} onChange={handleChange} />}
            {stepName === 'profile' && (
              <StepProfile formData={formData} onChange={handleChange} onToggleArray={handleToggleArray} />
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

        {error && <ObError>{error}</ObError>}

        <div style={{ display: 'flex', gap: 10, marginTop: 'auto', padding: '18px 0 calc(18px + env(safe-area-inset-bottom, 0px))' }}>
          {safeIndex > 0 && (
            <PrButton variant="secondary" onClick={handleBack} disabled={loading}>← Назад</PrButton>
          )}
          <PrButton onClick={handleNext} disabled={submitDisabled} style={{ flex: 1 }}>
            {loading
              ? 'Сохраняю…'
              : stepName === 'assess' && cautionVerdict
                ? 'Всё равно создать план →'
                : isLast
                  ? (planMode ? 'Создать план →' : 'Создать календарь →')
                  : 'Дальше →'}
          </PrButton>
        </div>
      </div>
    </Shell>
  );
}
