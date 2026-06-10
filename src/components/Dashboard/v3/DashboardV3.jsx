/**
 * DashboardV3 — главный экран бегуна в стиле design_handoff_v2.
 * Заменяет старый Dashboard.jsx для всех пользователей не-тренеров.
 *
 * Структура:
 *  - DashHeaderV3 (приветствие + дата + mode badge)
 *  - DashStickyTabsV3 (sticky nav по секциям, mobile)
 *  - Sections (data-section="today/week/goal/form/pr/more"):
 *     today → TodayHeroV3 + NextWorkoutSectionV3
 *     week → WeekSectionV3
 *     goal → GoalSectionV3
 *     form → TrainingLoadWidget (wrap)
 *     pr → PRSectionV3
 *     more → RacePrediction / PaceZones / Stats / Trend (wrap)
 *  - DashFabAi (mobile)
 *
 * Используется существующий useDashboardData hook для данных.
 */

import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import DashCustomizerV3, { getEnabledWidgets } from './DashCustomizerV3';
import useAuthStore from '../../../stores/useAuthStore';
import usePlanStore from '../../../stores/usePlanStore';
import { useDashboardData } from '../useDashboardData';
import { useDashboardPullToRefresh } from '../useDashboardPullToRefresh';
import { useRef } from 'react';
import SkeletonScreen from '../../common/SkeletonScreen';
import PlanGeneratingState from '../PlanGeneratingState';
import { RunningIcon, CalendarIcon, SettingsIcon } from '../../common/Icons';
import GoalCountdownWidget from '../GoalCountdownWidget';

import DashHeaderV3 from './DashHeaderV3';
import DashFabAi from './DashFabAi';
import TodayHeroV3 from './TodayHeroV3';
import NextWorkoutSectionV3 from './NextWorkoutSectionV3';
import WeekSectionV3 from './WeekSectionV3';
import GoalSectionV3 from './GoalSectionV3';
import PRSectionV3 from './PRSectionV3';
import PaceZonesSectionV3 from './PaceZonesSectionV3';
import FormSectionV3 from './FormSectionV3';
import StatsSectionV3 from './StatsSectionV3';
import TrendsSmallV3 from './TrendsSmallV3';
import RacePredictionV3 from './RacePredictionV3';
import ModeSwitchPopup from './ModeSwitchPopup';
import { getDisplayName } from '../../../utils/displayName';

import './DashboardV3.css';

function isAiPlanMode(trainingMode) {
  return trainingMode === 'ai';
}

export default function DashboardV3({
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

  const [modeOpen, setModeOpen] = useState(false);
  const [modeBusy, setModeBusy] = useState(false);

  const handleModeSelect = useCallback(async (newMode) => {
    const cur = user?.training_mode;
    if (!newMode || newMode === cur) { setModeOpen(false); return; }

    // ── Уход ОТ тренера (coach → ai/self) ──────────────────────────────
    // Завершаем работу с тренером (removeCoach → бэк ставит self). Для «Сам»
    // на этом всё (план остаётся, ручной). Для AI — дальше онбординг (метрики + план).
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

    // ── Вход К тренеру (ai/self → coach) ───────────────────────────────
    // Уже привязан — просто включаем режим. Нет тренера — в каталог;
    // режим сам станет coach, когда тренер примет заявку (acceptRequest).
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

    // ── ai/self из ai/self — онбординг для сбора метрик + план/календарь ──
    setModeOpen(false);
    navigate('/onboarding', { state: { mode: newMode } });
  }, [api, user, navigate, updateUser]);

  const clearPlanMessage = useCallback(() => setPlanGenerationMessage(null), [setPlanGenerationMessage]);

  const dashboardRef = useRef(null);

  // Управление виджетами — какие секции видны
  const [enabledWidgets, setEnabledWidgets] = useState(() => getEnabledWidgets());
  const [customizerOpen, setCustomizerOpen] = useState(false);
  useEffect(() => {
    const onChange = () => setEnabledWidgets(getEnabledWidgets());
    window.addEventListener('dashboard-v3-widgets-changed', onChange);
    return () => window.removeEventListener('dashboard-v3-widgets-changed', onChange);
  }, []);
  const showWidget = useCallback((id) => enabledWidgets.has(id), [enabledWidgets]);

  const {
    hasAnyPlannedWorkout,
    loading,
    loadDashboardData,
    nextWorkout,
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
  // План ещё генерируется (в т.ч. после F5 — статус восстановлен из usePlanStore.initPlanStatus).
  // Показываем прогресс-экран, пока плана нет, вместо пустого дашборда.
  const showPlanGenerating = supportsAiPlan && planGenerating && !planExists && !planError;

  const handleWorkoutPress = useCallback((workout) => {
    if (!onNavigate || !workout) return;
    onNavigate('calendar', { date: workout.date, week: workout.weekNumber, day: workout.dayKey });
  }, [onNavigate]);

  const handleOpenChat = useCallback(() => {
    if (onNavigate) onNavigate('chat');
  }, [onNavigate]);

  // Считаем краткий summary для шапки десктопа.
  // `days` в плане — объект { mon: [...], tue: [...], ... } (не массив).
  const weekSummary = useMemo(() => {
    if (!plan) return '';
    const phases = Array.isArray(plan?.phases) ? plan.phases : null;
    const allWeeks = phases ? phases.flatMap((p) => p?.weeks_data || []) : (plan?.weeks_data || []);
    const todayIso = new Date().toISOString().slice(0, 10);
    const currentWeek = allWeeks.find((w) => {
      if (!w?.start_date) return false;
      const start = new Date(w.start_date);
      const end = new Date(start);
      end.setDate(start.getDate() + 6);
      return todayIso >= start.toISOString().slice(0, 10) && todayIso <= end.toISOString().slice(0, 10);
    });
    if (!currentWeek) return '';
    // total_volume хранится как строка "X км" — парсим число
    const km = parseFloat(String(currentWeek.total_volume || '').replace(/[^\d.]/g, '')) || 0;
    // Считаем ключевые тренировки, перебирая массивы day-items
    let keys = 0;
    const daysObj = currentWeek.days || {};
    if (daysObj && typeof daysObj === 'object') {
      for (const dk of Object.keys(daysObj)) {
        const items = daysObj[dk];
        const arr = Array.isArray(items) ? items : (items ? [items] : []);
        if (arr.some((it) => it && (it.is_key_workout || it.key))) keys += 1;
      }
    }
    if (km === 0 && keys === 0) return '';
    return `${keys ? `${keys} ключ. · ` : ''}${Math.round(km)} км`;
  }, [plan]);

  if (needsOnboarding) {
    return (
      <div className="dashboard dashboard-v3 dashboard-empty-onboarding">
        <div className="dashboard-empty-onboarding-inner">
          <div className="dashboard-empty-onboarding-icon" aria-hidden><RunningIcon size={64} /></div>
          <h1 className="dashboard-empty-onboarding-title">Добро пожаловать в PlanRun</h1>
          <p className="dashboard-empty-onboarding-text">
            Выберите режим тренировок, цель и заполните профиль — после этого здесь появится ваш план и прогресс.
          </p>
          <button type="button" className="dashboard-empty-onboarding-btn" onClick={() => navigate('/onboarding')}>
            Настроить план
          </button>
        </div>
      </div>
    );
  }

  if (loading && !noPlanChecked) {
    return (
      <div className="dashboard dashboard-v3">
        <SkeletonScreen type="dashboard" />
      </div>
    );
  }

  if (showPlanGenerating) {
    return <PlanGeneratingState label={generationLabel} />;
  }

  if (showAiEmptyState) {
    return (
      <div className="dashboard dashboard-v3 dashboard-empty-no-plan">
        <div className="dashboard-empty-onboarding-inner">
          <div className="dashboard-empty-onboarding-icon" aria-hidden><CalendarIcon size={64} /></div>
          <h1 className="dashboard-empty-onboarding-title">Создайте план тренировок</h1>
          <p className="dashboard-empty-onboarding-text">
            У вас пока нет плана. Настройте цели и режим тренировок — AI-тренер составит персональный план.
          </p>
          <button type="button" className="btn btn-primary dashboard-empty-onboarding-btn" disabled={regenerating}>
            {regenerating ? 'Генерация...' : 'Создать план'}
          </button>
        </div>
      </div>
    );
  }

  const trainingMode = supportsAiPlan ? 'ai' : 'trainer';

  return (
    <div className="dashboard dashboard-v3" ref={dashboardRef}>
      <div className="dashboard-v3__header">
        <DashHeaderV3 user={user} mode={user?.training_mode || 'ai'} weekSummary={weekSummary} onModeClick={() => setModeOpen(true)} />
      </div>

      <div className="dashboard-v3__columns">
        {/* MAIN COLUMN — десктоп: левая, мобайл: верх */}
        <div className="dashboard-v3__col dashboard-v3__col--main">
          {/* SECTION: today (включает Hero + Next) */}
          <div className="dashboard-v3__section" data-section="today">
            {todayWorkout ? (
              <TodayHeroV3
                workout={todayWorkout}
                plan={plan}
                api={api}
                onOpenChat={handleOpenChat}
                onStart={() => handleWorkoutPress(todayWorkout)}
                onReschedule={() => handleWorkoutPress(todayWorkout)}
                onMarkDone={() => handleWorkoutPress(todayWorkout)}
              />
            ) : hasAnyPlannedWorkout ? (
              <div className="card dashboard-v3__rest">
                <div className="dashboard-v3__rest-emoji" aria-hidden>💤</div>
                <h2 className="dashboard-v3__rest-title">Сегодня день восстановления</h2>
                <p className="dashboard-v3__rest-text">Полный отдых или лёгкая активность.</p>
              </div>
            ) : (
              <div className="card dashboard-v3__empty">
                <div className="dashboard-v3__empty-emoji" aria-hidden><CalendarIcon size={42} /></div>
                <p>Кажется, у вас нет ни одной тренировки.</p>
                <button type="button" className="btn btn-primary" onClick={() => onNavigate?.('calendar')}>
                  Открыть календарь
                </button>
              </div>
            )}

            {nextWorkout && showWidget('next') && (
              <NextWorkoutSectionV3 workout={nextWorkout} onOpen={() => handleWorkoutPress(nextWorkout)} />
            )}
          </div>

          {/* SECTION: week */}
          {showWidget('week') && (
            <div className="dashboard-v3__section" data-section="week">
              <WeekSectionV3
                plan={plan}
                workoutsByDate={workoutsByDate}
                progressDataMap={progressDataMap}
                compact
              />
            </div>
          )}

          {/* SECTION: form (TSB/ATL/CTL) */}
          {showWidget('form') && (
            <div className="dashboard-v3__section" data-section="form">
              <FormSectionV3 api={api} />
            </div>
          )}

          {/* SECTION: stats */}
          {showWidget('stats') && (
            <div className="dashboard-v3__section" data-section="more">
              <StatsSectionV3 workoutsByDate={workoutsByDate} />
            </div>
          )}
        </div>

        {/* SIDE COLUMN — десктоп: правая, мобайл: ниже main */}
        <aside className="dashboard-v3__col dashboard-v3__col--side">
          {/* SECTION: goal */}
          {showWidget('goal') && (
            <div className="dashboard-v3__section" data-section="goal">
              <GoalSectionV3 user={user} plan={plan} api={api} />
              {!user?.race_date && !user?.target_marathon_date && (
                <GoalCountdownWidget user={user} workoutsByDate={workoutsByDate} />
              )}
            </div>
          )}

          {/* SECTION: pr */}
          {showWidget('pr') && (
            <div className="dashboard-v3__section" data-section="pr">
              <PRSectionV3 api={api} />
            </div>
          )}

          {/* Trends + Race Prediction + Pace Zones */}
          {(showWidget('trends') || showWidget('race') || showWidget('pace')) && (
            <div className="dashboard-v3__section">
              {showWidget('trends') && <TrendsSmallV3 workoutsByDate={workoutsByDate} />}
              {showWidget('race') && <RacePredictionV3 api={api} user={user} />}
              {showWidget('pace') && <PaceZonesSectionV3 api={api} />}
            </div>
          )}
        </aside>
      </div>

      {/* Bottom hint — настройка дэшборда */}
      <button
        type="button"
        className="dashboard-v3__customize-hint"
        onClick={() => setCustomizerOpen(true)}
      >
        <SettingsIcon size={18} />
        <span className="dashboard-v3__customize-hint-text">
          <span className="dashboard-v3__customize-hint-title">Настроить дэшборд</span>
          <span className="dashboard-v3__customize-hint-sub">Можно убрать ненужные виджеты</span>
        </span>
        <span className="dashboard-v3__customize-hint-arrow" aria-hidden>→</span>
      </button>

      <DashCustomizerV3 isOpen={customizerOpen} onClose={() => setCustomizerOpen(false)} />

      <ModeSwitchPopup
        open={modeOpen}
        currentMode={user?.training_mode || 'ai'}
        busy={modeBusy}
        onClose={() => setModeOpen(false)}
        onSelect={handleModeSelect}
      />

      <DashFabAi onOpen={handleOpenChat} mode={trainingMode} />
    </div>
  );
}
