/**
 * DashboardV3B — дашборд бегуна v3B «Телеметрия» (моки BRunnerMobile/BRunnerDesktop).
 * Логика перенесена из DashboardV3: useDashboardData, переключение режимов,
 * empty/generating-состояния. Вёрстка пересобрана с нуля на токенах --pr-*.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import useAuthStore from '../../../stores/useAuthStore';
import usePlanStore from '../../../stores/usePlanStore';
import { useDashboardData } from '../useDashboardData';
import { useDashboardPullToRefresh } from '../useDashboardPullToRefresh';
import { PrRing, PrLabel, PrIcon, PrSheet, PrButton } from '../../ui';
import { getDisplayName } from '../../../utils/displayName';
import MobileDash from './MobileDash';
import DesktopDash from './DesktopDash';
import { buildWeekModel, computeStreak, MODE_LABEL } from './dashData';

const BRIEFING_MAX_AGE_HOURS = 36;
const PROVIDER_NAMES = { strava: 'Strava', garmin: 'Garmin', polar: 'Polar', suunto: 'Suunto', coros: 'COROS', telegram: 'Telegram' };

function isAiPlanMode(trainingMode) {
  return trainingMode === 'ai';
}

function useIsMobile(breakpoint = 1024) {
  const [mobile, setMobile] = useState(() => typeof window !== 'undefined' && window.innerWidth < breakpoint);
  useEffect(() => {
    const onResize = () => setMobile(window.innerWidth < breakpoint);
    window.addEventListener('resize', onResize);
    return () => window.removeEventListener('resize', onResize);
  }, [breakpoint]);
  return mobile;
}

/** Смена режима тренировок (мок BModeSwitch из r3b-modals). */
function ModeSheet({ open, currentMode, busy, onClose, onSelect }) {
  const modes = [
    { id: 'ai', glyph: 'AI', grad: true, name: 'AI-тренер', desc: 'Бесплатно · отвечает мгновенно · 24/7' },
    { id: 'coach', glyph: 'СК', name: 'Живой тренер', desc: 'Персональный план · человеческий подход' },
    { id: 'self', glyph: '✎', name: 'Сам', desc: 'Полный контроль над планом — без подсказок' },
  ];
  return (
    <PrSheet open={open} onClose={onClose} title="Режим тренировок">
      <div style={{ fontSize: 12.5, color: 'var(--pr-sub)', lineHeight: 1.5, marginBottom: 14 }}>
        План, история и прогресс сохраняются при смене режима.
      </div>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 9, opacity: busy ? 0.6 : 1 }}>
        {modes.map((m) => {
          const cur = m.id === currentMode;
          return (
            <div
              key={m.id}
              className="pr-card pr-hover"
              onClick={() => !busy && onSelect(m.id)}
              style={{
                padding: '14px 16px',
                display: 'flex',
                alignItems: 'center',
                gap: 13,
                cursor: 'pointer',
                border: cur ? '1.5px solid var(--pr-accent)' : '1px solid var(--pr-card-border)',
                background: cur ? 'var(--pr-card-2)' : 'var(--pr-card)',
                boxShadow: cur ? 'var(--pr-glow)' : 'none',
              }}
            >
              <span style={{ width: 44, height: 44, borderRadius: 999, flexShrink: 0, background: m.grad ? 'var(--pr-grad)' : 'var(--pr-card-2)', border: m.grad ? 'none' : '1px solid var(--pr-card-border)', display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: 'var(--pr-font-display)', fontSize: 13, fontWeight: 700, color: m.grad ? '#fff' : 'var(--pr-ink)' }}>
                {m.glyph}
              </span>
              <div style={{ flex: 1 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                  <span style={{ fontSize: 14.5, fontWeight: 700, color: 'var(--pr-ink)' }}>{m.name}</span>
                  {cur && (
                    <PrLabel size={8} color="var(--pr-accent)" style={{ display: 'inline-block', border: '1px solid var(--pr-accent)', borderRadius: 999, padding: '3px 8px' }}>
                      текущий
                    </PrLabel>
                  )}
                </div>
                <div style={{ fontSize: 11.5, color: 'var(--pr-sub)', marginTop: 3 }}>{m.desc}</div>
              </div>
              {!cur && PrIcon.arrow('var(--pr-sub)', 15)}
            </div>
          );
        })}
      </div>
      <div className="pr-card" style={{ padding: '11px 14px', marginTop: 12, background: 'var(--pr-card-2)', display: 'flex', gap: 10, alignItems: 'flex-start' }}>
        <span style={{ width: 7, height: 7, borderRadius: 999, background: 'var(--pr-accent)', marginTop: 4, flexShrink: 0 }} />
        <div style={{ fontSize: 11.5, color: 'var(--pr-sub)', lineHeight: 1.5 }}>
          Переход к живому тренеру: выбери тренера в каталоге и отправь заявку — после подтверждения план перейдёт под его управление.
        </div>
      </div>
    </PrSheet>
  );
}

/** Полноэкранная генерация плана (язык BObGenerating). */
function GeneratingState({ label }) {
  const [elapsed, setElapsed] = useState(0);
  useEffect(() => {
    const t = setInterval(() => setElapsed((s) => s + 1), 1000);
    return () => clearInterval(t);
  }, []);
  const pct = Math.min(0.93, 1 - Math.exp(-elapsed / 75));
  return (
    <div style={{ minHeight: '70vh', display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 20, padding: 24, fontFamily: 'var(--pr-font-body)' }}>
      <PrRing pct={pct} size={140} stroke={11}>
        <div style={{ fontFamily: 'var(--pr-font-display)', fontSize: 30, fontWeight: 700, color: 'var(--pr-ink)' }}>
          {Math.round(pct * 100)}%
        </div>
        <PrLabel size={8}>сборка</PrLabel>
      </PrRing>
      <div style={{ textAlign: 'center' }}>
        <div style={{ fontFamily: 'var(--pr-font-display)', fontSize: 21, fontWeight: 700, color: 'var(--pr-ink)' }}>
          {label || 'Собираю твой план'}
        </div>
        <div style={{ fontSize: 13, color: 'var(--pr-sub)', marginTop: 6, lineHeight: 1.5 }}>
          Обычно это занимает 2–3 минуты.<br />Можно закрыть страницу — прогресс не потеряется.
        </div>
      </div>
    </div>
  );
}

function CenterMessage({ icon, title, text, action }) {
  return (
    <div style={{ minHeight: '70vh', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 24, fontFamily: 'var(--pr-font-body)' }}>
      <div className="pr-card" style={{ maxWidth: 420, padding: '28px 26px', textAlign: 'center', display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 12 }}>
        <span style={{ width: 56, height: 56, borderRadius: 999, background: 'var(--pr-grad)', display: 'flex', alignItems: 'center', justifyContent: 'center', boxShadow: 'var(--pr-glow)' }}>
          {PrIcon[icon]('#fff', 26)}
        </span>
        <div style={{ fontFamily: 'var(--pr-font-display)', fontSize: 20, fontWeight: 700, color: 'var(--pr-ink)' }}>{title}</div>
        <div style={{ fontSize: 13, color: 'var(--pr-sub)', lineHeight: 1.55 }}>{text}</div>
        {action}
      </div>
    </div>
  );
}

function DashSkeleton() {
  return (
    <div style={{ padding: '20px 16px', display: 'flex', flexDirection: 'column', gap: 12 }}>
      {[128, 180, 90, 90].map((h, i) => (
        <div key={i} className="pr-card pr-skeleton" style={{ height: h }} />
      ))}
    </div>
  );
}

export default function DashboardV3B({
  api,
  user,
  isTabActive = true,
  onNavigate,
  registrationMessage,
  isNewRegistration,
}) {
  const navigate = useNavigate();
  const setPlanGenerationMessage = useAuthStore((s) => s.setPlanGenerationMessage);
  const updateUser = useAuthStore((s) => s.updateUser);
  const needsOnboarding = !!(user && !user.onboarding_completed);
  const isMobile = useIsMobile();

  const [modeOpen, setModeOpen] = useState(false);
  const [modeBusy, setModeBusy] = useState(false);

  const handleModeSelect = useCallback(async (newMode) => {
    const cur = user?.training_mode;
    if (!newMode || newMode === cur) { setModeOpen(false); return; }

    // Уход ОТ тренера (coach → ai/self): removeCoach, дальше профиль или онбординг.
    if (cur === 'coach' && (newMode === 'ai' || newMode === 'self')) {
      setModeBusy(true);
      try {
        const res = await api.getMyCoaches();
        const coaches = res?.data?.coaches || res?.coaches || [];
        const coach = coaches[0] || null;
        const name = coach ? getDisplayName(coach) : 'тренером';
        if (!window.confirm(`Завершить работу с ${name}? План перейдёт под ${newMode === 'ai' ? 'AI' : 'ваше'} управление.`)) {
          setModeBusy(false);
          return;
        }
        if (coach) await api.removeCoach({ coachId: coach.id });
        if (newMode === 'self') {
          await api.request('update_profile', { training_mode: 'self' }, 'POST');
          updateUser({ ...user, training_mode: 'self' });
          setModeOpen(false);
        } else {
          setModeOpen(false);
          navigate('/onboarding', { state: { mode: 'ai' } });
        }
      } catch (e) {
        console.error('leave coach failed:', e);
      } finally {
        setModeBusy(false);
      }
      return;
    }

    // Вход К тренеру (ai/self → coach): есть тренер — включаем режим, нет — каталог.
    if (newMode === 'coach') {
      setModeBusy(true);
      try {
        const res = await api.getMyCoaches();
        const coaches = res?.data?.coaches || res?.coaches || [];
        if (coaches.length > 0) {
          await api.request('update_profile', { training_mode: 'coach' }, 'POST');
          updateUser({ ...user, training_mode: 'coach' });
          setModeOpen(false);
        } else {
          setModeOpen(false);
          navigate('/trainers');
        }
      } catch (e) {
        console.error('enter coach failed:', e);
        setModeOpen(false);
        navigate('/trainers');
      } finally {
        setModeBusy(false);
      }
      return;
    }

    // ai/self из ai/self — онбординг для сбора метрик + план/календарь.
    setModeOpen(false);
    navigate('/onboarding', { state: { mode: newMode } });
  }, [api, user, navigate, updateUser]);

  const clearPlanMessage = useCallback(() => setPlanGenerationMessage(null), [setPlanGenerationMessage]);
  const dashboardRef = useRef(null);

  const {
    hasAnyPlannedWorkout,
    loading,
    loadDashboardData,
    noPlanChecked,
    plan,
    planError,
    planExists,
    planGenerating,
    progressDataMap,
    regenerating,
    todayWorkout,
    workoutsByDate,
  } = useDashboardData({
    api,
    clearPlanMessage,
    isNewRegistration,
    isTabActive,
    registrationMessage,
    user,
  });
  useDashboardPullToRefresh(dashboardRef, loadDashboardData);

  const generationLabel = usePlanStore((s) => s.generationLabel);
  const supportsAiPlan = isAiPlanMode(user?.training_mode);
  const showAiEmptyState = supportsAiPlan && noPlanChecked && !planGenerating && !planError && !planExists && !loading;
  const showPlanGenerating = supportsAiPlan && planGenerating && !planExists && !planError;

  // ---- доп. данные экрана (форма, прогноз, рекорды, брифинг, синк) ----
  const [trainingLoad, setTrainingLoad] = useState(null);
  const [prediction, setPrediction] = useState(null);
  const [records, setRecords] = useState(null);
  const [briefing, setBriefing] = useState(null);
  const [syncedProvider, setSyncedProvider] = useState(null);

  useEffect(() => {
    if (!api?.getTrainingLoad) return undefined;
    let cancelled = false;
    api.getTrainingLoad(null, 90)
      .then((res) => { if (!cancelled) setTrainingLoad(res?.data || res); })
      .catch(() => {});
    return () => { cancelled = true; };
  }, [api]);

  useEffect(() => {
    if (!api?.getRacePrediction) return undefined;
    let cancelled = false;
    api.getRacePrediction()
      .then((res) => { if (!cancelled) setPrediction(res?.data || res); })
      .catch(() => {});
    return () => { cancelled = true; };
  }, [api]);

  useEffect(() => {
    if (isMobile || !api?.getPersonalRecords) return undefined;
    let cancelled = false;
    import('./dashData').then(({ mapRecords }) => {
      api.getPersonalRecords()
        .then((res) => {
          if (cancelled) return;
          setRecords(mapRecords(res?.data?.records || res?.records || []));
        })
        .catch(() => {});
    });
    return () => { cancelled = true; };
  }, [api, isMobile]);

  const completed = !!todayWorkout?.completed;
  useEffect(() => {
    if (!api?.getLatestProactiveMessage) return undefined;
    let cancelled = false;
    const proactiveType = completed ? 'post_workout_analysis' : 'daily_briefing';
    api.getLatestProactiveMessage(proactiveType, BRIEFING_MAX_AGE_HOURS)
      .then((res) => {
        if (cancelled) return;
        const msg = res?.data?.message ?? res?.message ?? null;
        if (msg?.content) setBriefing(msg.content);
      })
      .catch(() => {});
    return () => { cancelled = true; };
  }, [api, completed]);

  useEffect(() => {
    if (isMobile || !api?.getIntegrationsStatus) return undefined;
    let cancelled = false;
    api.getIntegrationsStatus()
      .then((res) => {
        if (cancelled) return;
        const map = res?.data ?? res ?? {};
        const key = Object.keys(PROVIDER_NAMES).find((k) => map[k]);
        if (key) setSyncedProvider(PROVIDER_NAMES[key]);
      })
      .catch(() => {});
    return () => { cancelled = true; };
  }, [api, isMobile]);

  const weekModel = useMemo(
    () => buildWeekModel(plan, workoutsByDate, progressDataMap),
    [plan, workoutsByDate, progressDataMap]
  );
  const streak = useMemo(
    () => computeStreak(plan, workoutsByDate, progressDataMap),
    [plan, workoutsByDate, progressDataMap]
  );

  const handleWorkoutPress = useCallback((workout) => {
    if (!onNavigate || !workout) return;
    onNavigate('calendar', { date: workout.date, week: workout.weekNumber, day: workout.dayKey });
  }, [onNavigate]);

  const firstName = (() => {
    const raw = user?.name || user?.username || '';
    return raw ? String(raw).trim().split(/\s+/)[0] : '';
  })();

  if (needsOnboarding) {
    return (
      <CenterMessage
        icon="run"
        title="Добро пожаловать в PlanRun"
        text="Выбери режим тренировок, цель и заполни профиль — после этого здесь появятся твой план и прогресс."
        action={<PrButton onClick={() => navigate('/onboarding')}>Настроить план →</PrButton>}
      />
    );
  }

  if (loading && !noPlanChecked) {
    return <DashSkeleton />;
  }

  if (showPlanGenerating) {
    return <GeneratingState label={generationLabel} />;
  }

  if (showAiEmptyState) {
    return (
      <CenterMessage
        icon="cal"
        title="Создай план тренировок"
        text="Плана пока нет. Настрой цель и режим — AI-тренер соберёт персональный план."
        action={
          <PrButton disabled={regenerating} onClick={() => navigate('/onboarding', { state: { mode: 'ai' } })}>
            {regenerating ? 'Генерация…' : 'Создать план →'}
          </PrButton>
        }
      />
    );
  }

  const shared = {
    api,
    user,
    firstName,
    mode: user?.training_mode || 'ai',
    streak,
    weekModel,
    trainingLoad,
    briefing,
    todayWorkout,
    hasAnyPlannedWorkout,
    plan,
    workoutsByDate,
    onModeClick: () => setModeOpen(true),
    onStart: () => handleWorkoutPress(todayWorkout),
    onOpenCalendar: () => onNavigate?.('calendar'),
    onOpenStats: () => onNavigate?.('stats'),
  };

  return (
    <div ref={dashboardRef} style={{ fontFamily: 'var(--pr-font-body)', maxWidth: isMobile ? 560 : 'none', margin: '0 auto' }}>
      {isMobile ? (
        <MobileDash {...shared} vdot={prediction?.vdot ?? null} />
      ) : (
        <DesktopDash
          {...shared}
          prediction={prediction}
          records={records}
          syncedProvider={syncedProvider}
          onNavigate={onNavigate}
        />
      )}
      <ModeSheet
        open={modeOpen}
        currentMode={user?.training_mode || 'ai'}
        busy={modeBusy}
        onClose={() => setModeOpen(false)}
        onSelect={handleModeSelect}
      />
    </div>
  );
}
