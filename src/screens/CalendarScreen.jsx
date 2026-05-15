/**
 * Экран календаря тренировок (веб-версия)
 */

import React, { useState, useEffect, useRef, useCallback, useMemo } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { MoreHorizontal, RefreshCw, Sparkles, Trash2 } from 'lucide-react';
import useAuthStore from '../stores/useAuthStore';
import usePlanStore from '../stores/usePlanStore';
import usePreloadStore from '../stores/usePreloadStore';
import useWorkoutRefreshStore from '../stores/useWorkoutRefreshStore';
import { useIsTabActive } from '../hooks/useIsTabActive';
import { isNativeCapacitor } from '../services/TokenStorageService';
import WeekCalendar from '../components/Calendar/WeekCalendar';
import MonthlyCalendar from '../components/Calendar/MonthlyCalendar';
import DayModal from '../components/Calendar/DayModal';
import ResultModal from '../components/Calendar/ResultModal';
import AddTrainingModal from '../components/Calendar/AddTrainingModal';
import { WorkoutDetailsModal } from '../components/Stats';
import SkeletonScreen from '../components/common/SkeletonScreen';
import '../assets/css/calendar_v2.css';
import '../assets/css/short-desc.css';
import './CalendarScreen.css';
import './StatsScreen.css';

const normalizeWorkoutDetailsDayData = (raw) => {
  if (!raw || typeof raw !== 'object') return null;
  return {
    ...raw,
    planDays: raw.planDays ?? raw.plan_days ?? [],
    dayExercises: raw.dayExercises ?? raw.day_exercises ?? [],
    workouts: Array.isArray(raw.workouts) ? raw.workouts : [],
  };
};

const CalendarScreen = ({ targetUserId = null, viewContext: externalViewContext = null, canEdit: externalCanEdit = true, isOwner: externalIsOwner = true, canView: externalCanView = true, viewMode: externalViewMode = null }) => {
  const isTabActive = useIsTabActive('/calendar');
  const preloadTriggered = usePreloadStore((s) => s.preloadTriggered);
  const location = useLocation();
  const navigate = useNavigate();
  const { api, user } = useAuthStore();
  const role = user?.role || 'user';
  const isCoach = role === 'coach' || role === 'admin';

  // Список атлетов для селектора тренера
  const [coachAthletes, setCoachAthletes] = useState([]);
  useEffect(() => {
    if (!isCoach || !api) return;
    api.getCoachAthletes().then(res => {
      setCoachAthletes(res?.data?.athletes || res?.athletes || []);
    }).catch(() => {});
  }, [isCoach, api]);

  // Режим тренера: ?athlete=slug
  const athleteSlug = React.useMemo(() => {
    const params = new URLSearchParams(location.search);
    return params.get('athlete') || null;
  }, [location.search]);

  const [athleteData, setAthleteData] = React.useState(null);
  const [athleteLoading, setAthleteLoading] = React.useState(false);

  React.useEffect(() => {
    if (!athleteSlug || !api) { setAthleteData(null); return; }
    let cancelled = false;
    setAthleteLoading(true);
    (async () => {
      try {
        const res = await api.getUserBySlug(athleteSlug);
        const data = res?.data ?? res;
        if (!cancelled && data?.user) {
          setAthleteData({
            user: data.user,
            access: data.access || {},
          });
        }
      } catch { /* ignore */ }
      finally { if (!cancelled) setAthleteLoading(false); }
    })();
    return () => { cancelled = true; };
  }, [athleteSlug, api]);

  // Определяем viewContext/canEdit/isOwner/canView из атлета или из пропсов
  const viewContext = useMemo(() => {
    return athleteSlug && athleteData
      ? { slug: athleteData.user.username_slug || athleteSlug }
      : externalViewContext;
  }, [athleteSlug, athleteData, externalViewContext]);
  const canEdit = athleteSlug ? (athleteData?.access?.can_edit ?? (athleteData?.user?.id === user?.id)) : externalCanEdit;
  const isOwner = athleteSlug ? (athleteData?.access?.is_owner ?? (athleteData?.user?.id === user?.id)) : externalIsOwner;
  const canView = athleteSlug ? (athleteData?.access?.can_view ?? (athleteData?.user?.id === user?.id)) : externalCanView;

  // Используем targetUserId если передан, иначе текущего пользователя
  const calendarUserId = targetUserId || (athleteData?.user?.id) || user?.id;
  const [plan, setPlan] = useState(null);
  const handledNavigationStateKeyRef = useRef(null);
  const [workoutsData, setWorkoutsData] = useState({}); // Данные о тренировках по датам (сводка)
  const [workoutsListByDate, setWorkoutsListByDate] = useState({}); // Отдельные тренировки по датам (для проверки типов)
  const [executedByDate, setExecutedByDate] = useState({}); // Карта дат → категории executed_exercises (ofp/sbu)
  const [resultsData, setResultsData] = useState({}); // Данные о результатах по датам
  const [loading, setLoading] = useState(true);
  const [dayModal, setDayModal] = useState({ isOpen: false, date: null, week: null, day: null });
  const [resultModal, setResultModal] = useState({ isOpen: false, date: null, week: null, day: null });
  const [addTrainingModal, setAddTrainingModal] = useState({ isOpen: false, date: null, planDay: null, editResultData: null });
  const [dayModalRefreshKey, setDayModalRefreshKey] = useState(0);
  const [workoutDetailsModal, setWorkoutDetailsModal] = useState({ isOpen: false, date: null, dayData: null, loading: false, weekNumber: null, dayKey: null, selectedWorkoutId: null });
  const {
    recalculating,
    recalculatePlan,
    generatingNext,
    generateNextPlan,
    planReadinessCheck,
    readinessSubmitting,
    submitPlanReadinessCheck,
    dismissPlanReadinessCheck,
  } = usePlanStore();
  const planFromStore = usePlanStore((s) => s.plan);
  const isGenerating = usePlanStore((s) => s.isGenerating);
  const generationLabel = usePlanStore((s) => s.generationLabel);
  const [showRecalcConfirm, setShowRecalcConfirm] = useState(false);
  const [recalcReason, setRecalcReason] = useState('');
  const [showNextPlanModal, setShowNextPlanModal] = useState(false);
  const [nextPlanGoals, setNextPlanGoals] = useState('');
  const [showClearPlanConfirm, setShowClearPlanConfirm] = useState(false);
  const [clearingPlan, setClearingPlan] = useState(false);
  const [readinessPainScore, setReadinessPainScore] = useState(0);
  const [readinessWorsened, setReadinessWorsened] = useState(null);
  const [readinessTechniqueChanged, setReadinessTechniqueChanged] = useState(null);
  const [readinessText, setReadinessText] = useState('');
  const [planActionsMenuOpen, setPlanActionsMenuOpen] = useState(false);
  const planActionsMenuRef = useRef(null);

  useEffect(() => {
    if (!planReadinessCheck) return;
    setReadinessPainScore(0);
    setReadinessWorsened(null);
    setReadinessTechniqueChanged(null);
    setReadinessText('');
  }, [planReadinessCheck?.id]);

  useEffect(() => {
    if (!planActionsMenuOpen) return undefined;

    const handlePointerDown = (event) => {
      if (planActionsMenuRef.current && !planActionsMenuRef.current.contains(event.target)) {
        setPlanActionsMenuOpen(false);
      }
    };

    const handleKeyDown = (event) => {
      if (event.key === 'Escape') {
        setPlanActionsMenuOpen(false);
      }
    };

    document.addEventListener('pointerdown', handlePointerDown);
    document.addEventListener('keydown', handleKeyDown);

    return () => {
      document.removeEventListener('pointerdown', handlePointerDown);
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [planActionsMenuOpen]);

  const getWeeksData = (p) => {
    if (!p) return null;
    if (Array.isArray(p.weeks_data) && p.weeks_data.length > 0) return p.weeks_data;
    const phase = p.phases?.[0];
    if (phase && Array.isArray(phase.weeks_data) && phase.weeks_data.length > 0) return phase.weeks_data;
    return null;
  };
  const weeksData = getWeeksData(plan) || getWeeksData(planFromStore);
  const hasPlan = !!weeksData || usePlanStore.getState().hasPlan;

  const isPlanCompleted = hasPlan && weeksData && (() => {
    const weeks = weeksData;
    if (!Array.isArray(weeks) || weeks.length === 0) return false;
    const lastWeek = weeks.reduce((latest, w) => {
      if (!w?.start_date) return latest;
      if (!latest) return w;
      return w.start_date > latest.start_date ? w : latest;
    }, null);
    if (!lastWeek?.start_date) return false;
    const lastWeekEnd = new Date(lastWeek.start_date);
    lastWeekEnd.setDate(lastWeekEnd.getDate() + 6);
    return lastWeekEnd < new Date(new Date().toDateString());
  })();
  const canManagePlan = hasPlan && canEdit && isOwner;

  const handleOpenRecalc = useCallback(() => {
    setRecalcReason('');
    setShowRecalcConfirm(true);
  }, []);

  const handleRecalculate = useCallback(async () => {
    const reason = recalcReason.trim();
    setShowRecalcConfirm(false);
    setRecalcReason('');
    const ok = await recalculatePlan(reason || null);
    if (ok) {
      loadPlan();
    }
  }, [recalculatePlan, recalcReason]);

  const handleOpenNextPlan = useCallback(() => {
    setNextPlanGoals('');
    setShowNextPlanModal(true);
  }, []);

  const handleGenerateNextPlan = useCallback(async () => {
    const goals = nextPlanGoals.trim();
    setShowNextPlanModal(false);
    setNextPlanGoals('');
    const ok = await generateNextPlan(goals || null);
    if (ok) {
      loadPlan();
    }
  }, [generateNextPlan, nextPlanGoals]);

  const handleClearPlan = useCallback(async () => {
    if (!api) return;
    setShowClearPlanConfirm(false);
    setClearingPlan(true);
    try {
      await api.clearPlan();
      setPlan({ weeks_data: [] });
      usePlanStore.getState().clearPlan();
      useWorkoutRefreshStore.getState().triggerRefresh();
    } catch (err) {
      console.error('Error clearing plan:', err);
    } finally {
      setClearingPlan(false);
    }
  }, [api]);

  const handleMobilePrimaryPlanAction = useCallback(() => {
    setPlanActionsMenuOpen(false);
    if (isPlanCompleted) {
      handleOpenNextPlan();
      return;
    }
    handleOpenRecalc();
  }, [handleOpenNextPlan, handleOpenRecalc, isPlanCompleted]);

  const handleMobileClearPlan = useCallback(() => {
    setPlanActionsMenuOpen(false);
    setShowClearPlanConfirm(true);
  }, []);

  const handleSubmitReadinessCheck = useCallback(async () => {
    if (readinessWorsened === null || readinessTechniqueChanged === null) return;
    await submitPlanReadinessCheck({
      current_pain_score: readinessPainScore,
      pain_worsened_after_runs: readinessWorsened,
      technique_changed: readinessTechniqueChanged,
      answer_text: readinessText.trim(),
    });
  }, [readinessPainScore, readinessWorsened, readinessTechniqueChanged, readinessText, submitPlanReadinessCheck]);

  // Инициализируем viewMode: если передан externalViewMode, используем его, иначе 'week'
  // Если externalViewMode задан, он фиксирует режим (для публичных профилей)
  // Если не задан, пользователь может свободно переключаться
  const [viewMode, setViewMode] = useState(() => externalViewMode || 'week');
  
  // Синхронизируем viewMode с externalViewMode только если он задан (для публичных профилей)
  // Это позволяет фиксировать режим на публичных страницах
  useEffect(() => {
    if (externalViewMode !== null && externalViewMode !== undefined) {
      setViewMode(externalViewMode);
    }
  }, [externalViewMode]);

  const getCurrentWeekNumber = (p) => {
    const weeksData = getWeeksData(p);
    if (!weeksData) return null;
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    for (const week of weeksData) {
      if (!week.start_date) continue;
      const startDate = new Date(week.start_date);
      startDate.setHours(0, 0, 0, 0);
      const endDate = new Date(startDate);
      endDate.setDate(endDate.getDate() + 6);
      endDate.setHours(23, 59, 59, 999);
      if (today >= startDate && today <= endDate) {
        return week.number;
      }
    }
    return null;
  };

  const workoutRefreshVersion = useWorkoutRefreshStore((s) => s.version);
  useEffect(() => {
    if (workoutRefreshVersion <= 0 || !api) return;
    const t = setTimeout(() => loadPlan({ silent: true }), 250);
    return () => clearTimeout(t);
  }, [workoutRefreshVersion, api]);

  const hasLoadedRef = useRef(false);
  useEffect(() => {
    const isNative = isNativeCapacitor();
    const shouldPreload = isNative && preloadTriggered;
    if (!isTabActive && !hasLoadedRef.current && !viewContext && !shouldPreload) return;
    hasLoadedRef.current = true;
    // silent: предзагрузка или обновление при возврате (данные уже есть)
    const silent = (shouldPreload && !isTabActive) || !!plan;
    loadPlan({ silent });
  }, [calendarUserId, isTabActive, viewContext, preloadTriggered]);

  // Закрыть все модалки при переходе на другую страницу
  useEffect(() => {
    const isCalendar = location.pathname === '/calendar' || location.pathname.startsWith('/calendar');
    if (!isCalendar) {
      setDayModal({ isOpen: false, date: null, week: null, day: null });
      setResultModal({ isOpen: false, date: null, week: null, day: null });
      setAddTrainingModal({ isOpen: false, date: null, planDay: null, editResultData: null });
      setWorkoutDetailsModal((prev) => ({ ...prev, isOpen: false }));
    }
  }, [location.pathname]);

  // Переход с дашборда с датой (карточка «Сегодня» / «Следующая») — открыть день в модалке
  useEffect(() => {
    const stateDate = location.state?.date;
    if (!stateDate || !plan) return;
    if (handledNavigationStateKeyRef.current === location.key) return;
    handledNavigationStateKeyRef.current = location.key;
    setDayModal({
      isOpen: true,
      date: stateDate,
      week: location.state?.week ?? null,
      day: location.state?.day ?? null,
    });
  }, [location.key, plan, location.state]);

  const loadPlan = async (options = {}) => {
    const silent = options.silent === true;
    if (!api) {
      setLoading(false);
      return;
    }

    try {
      if (!silent) setLoading(true);

      const vc = viewContext || null;
      const uid = viewContext ? null : (calendarUserId !== user?.id ? calendarUserId : null);

      // Все запросы параллельно — не блокируем друг друга
      const [planData, workoutsSummary, workoutsListRes, allResults, executedDatesRes] = await Promise.all([
        api.getPlan(uid, viewContext || undefined).catch((err) => {
          console.error('Error loading plan:', err);
          return null;
        }),
        api.getAllWorkoutsSummary(vc).catch(() => ({})),
        api.getAllWorkoutsList(vc, 500).catch(() => ({ workouts: [] })),
        api.getAllResults(vc).catch(() => ({ results: [] })),
        api.getExecutedDates ? api.getExecutedDates(26).catch(() => ({})) : Promise.resolve({}),
      ]);

      // План
      const plan = planData?.data || planData;
      setPlan(plan);
      if (getWeeksData(plan)) {
        usePlanStore.getState().setPlan(plan);
      }

      // Тренировки (сводка)
      let workouts = {};
      if (workoutsSummary?.data) {
        workouts = workoutsSummary.data;
      } else if (workoutsSummary && typeof workoutsSummary === 'object') {
        workouts = workoutsSummary;
      }
      setWorkoutsData(workouts);

      // Список тренировок по датам
      const raw = workoutsListRes?.data ?? workoutsListRes;
      const workoutsList = Array.isArray(raw?.workouts) ? raw.workouts : (Array.isArray(raw) ? raw : []);
      const listByDate = {};
      workoutsList.forEach((w) => {
        const date = w.start_time ? w.start_time.split('T')[0] : w.date;
        if (date) {
          if (!listByDate[date]) listByDate[date] = [];
          listByDate[date].push(w);
        }
      });
      setWorkoutsListByDate(listByDate);

      // Результаты по датам
      let results = [];
      if (Array.isArray(allResults)) {
        results = allResults;
      } else if (allResults?.data?.results && Array.isArray(allResults.data.results)) {
        results = allResults.data.results;
      } else if (allResults?.results && Array.isArray(allResults.results)) {
        results = allResults.results;
      }
      const resultsByDate = {};
      results.forEach(result => {
        if (result.training_date) {
          if (!resultsByDate[result.training_date]) {
            resultsByDate[result.training_date] = [];
          }
          resultsByDate[result.training_date].push(result);
        }
      });
      setResultsData(resultsByDate);

      // Даты с executed_exercises (для статуса completed на ОФП/СБУ-днях)
      const execMap = executedDatesRes?.data?.executed_by_date ?? executedDatesRes?.executed_by_date ?? {};
      setExecutedByDate(execMap && typeof execMap === 'object' ? execMap : {});
    } catch (error) {
      if (error?.code === 'TIMEOUT' || error?.message?.includes('aborted')) return;
      console.error('Error loading plan:', error);
      if (!silent) setPlan(null);
    } finally {
      setLoading(false);
    }
  };

  const buildImmediateWorkoutDetailsDayData = useCallback((date, immediateDayData = null) => {
    const normalizedImmediate = normalizeWorkoutDetailsDayData(immediateDayData);
    if (normalizedImmediate) return normalizedImmediate;

    const localWorkouts = Array.isArray(workoutsListByDate?.[date]) ? workoutsListByDate[date] : [];
    if (localWorkouts.length === 0) return null;

    return {
      planDays: [],
      dayExercises: [],
      workouts: localWorkouts.map((workout) => ({
        ...workout,
        start_time: workout.start_time || (date ? `${date}T12:00:00` : null),
      })),
    };
  }, [workoutsListByDate]);

  const handleOpenWorkoutDetails = useCallback(async (date, weekNumber = null, dayKey = null, selectedWorkoutId = null, immediateDayData = null) => {
    if (!api || !date) return;
    const initialDayData = buildImmediateWorkoutDetailsDayData(date, immediateDayData);
    try {
      setWorkoutDetailsModal({
        isOpen: true,
        date,
        dayData: initialDayData,
        loading: !initialDayData,
        weekNumber,
        dayKey,
        selectedWorkoutId,
      });
      const response = await api.getDay(date, viewContext || undefined);
      const raw = response?.data != null ? response.data : response;
      const dayData = normalizeWorkoutDetailsDayData(raw);
      setWorkoutDetailsModal((prev) => (
        prev.isOpen && prev.date === date
          ? { ...prev, dayData: dayData || prev.dayData, loading: false }
          : prev
      ));
    } catch (error) {
      console.error('Error loading workout details:', error);
      setWorkoutDetailsModal((prev) => (
        prev.isOpen && prev.date === date
          ? { ...prev, loading: false }
          : prev
      ));
    }
  }, [api, buildImmediateWorkoutDetailsDayData, viewContext]);

  const handleCloseWorkoutDetails = () => {
    setWorkoutDetailsModal({ isOpen: false, date: null, dayData: null, loading: false, weekNumber: null, dayKey: null, selectedWorkoutId: null });
  };

  const handleEditWorkoutResult = async () => {
    const { date, weekNumber, dayKey, dayData } = workoutDetailsModal;
    if (!date || !api) return;
    // Собираем результат: сначала getResult, при отсутствии — из уже загруженных workouts дня (get_day)
    let result = null;
    try {
      const res = await api.getResult(date, viewContext || undefined);
      const raw = res?.result ?? res?.data?.result ?? res;
      if (raw && (raw.distance_km != null || raw.result_time != null || raw.result_distance != null || raw.notes != null)) {
        result = raw;
      }
    } catch {
      // игнорируем, подставим из dayData
    }
    if (result == null && dayData?.workouts?.length) {
      const w = dayData.workouts.find((wo) => wo.is_manual || wo.distance_km != null || wo.result_time != null || wo.notes != null) ?? dayData.workouts[0];
      result = {
        distance_km: w.distance_km ?? w.result_distance,
        result_time: w.result_time,
        pace: w.pace ?? w.avg_pace ?? w.result_pace,
        notes: w.notes,
      };
    }
    result = result || {};
    handleCloseWorkoutDetails();
    setAddTrainingModal({
      isOpen: true,
      date,
      planDay: null,
      editResultData: { date, weekNumber, dayKey, result, dayData },
    });
  };

  if ((loading && !plan) || athleteLoading) {
    return (
      <div className="calendar-container">
        <SkeletonScreen type="calendar" />
      </div>
    );
  }

  // Если запрошен атлет, но доступа нет
  if (athleteSlug && athleteData && !canView) {
    return (
      <div className="calendar-container">
        <div className="empty-container">
          <p className="empty-text">Нет доступа к календарю</p>
          <p className="empty-subtext">Вы не являетесь тренером этого спортсмена</p>
        </div>
      </div>
    );
  }

  // Календарь всегда доступен: при ошибке загрузки показываем сообщение, иначе — сетку (пустую или с планом)
  if (!loading && plan === null) {
    return (
      <div className="calendar-container">
        <div className="empty-container">
          <p className="empty-text">Не удалось загрузить календарь</p>
          <p className="empty-subtext">
            Проверьте подключение и обновите страницу
          </p>
        </div>
      </div>
    );
  }

  // plan может быть с weeks_data или phases[0].weeks_data — нормализуем для календаря
  const planData = plan
    ? { ...plan, weeks_data: getWeeksData(plan) || plan.weeks_data || [] }
    : { weeks_data: [] };

  return (
    <div className="container calendar-screen">
      <div className="content">
        {isCoach && coachAthletes.length > 0 && (
          <div className="coach-athlete-selector">
            <select
              className="coach-athlete-selector__select"
              value={athleteSlug || ''}
              onChange={e => {
                const slug = e.target.value;
                navigate(slug ? `/calendar?athlete=${slug}` : '/calendar', { replace: true });
              }}
            >
              <option value="">Мой календарь</option>
              {coachAthletes.map(a => (
                <option key={a.id} value={a.username_slug}>{a.username}</option>
              ))}
            </select>
          </div>
        )}
        {athleteSlug && athleteData?.user && (
          <div className="coach-mode-banner">
            <span className="coach-mode-banner__label">Режим тренера</span>
            <span className="coach-mode-banner__name">{athleteData.user.username}</span>
          </div>
        )}
        {isPlanCompleted && canEdit && isOwner && !isGenerating && (
          <div className="plan-completed-banner">
            <div className="plan-completed-banner__icon">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M12 2a10 10 0 100 20 10 10 0 000-20zm-1 14.59l-3.3-3.3 1.41-1.41L11 13.76l4.89-4.89 1.41 1.41L11 16.59z" fill="currentColor"/></svg>
            </div>
            <div className="plan-completed-banner__text">
              <strong>План завершён!</strong>
              <span>Создайте новый план — AI-тренер учтёт все ваши тренировки и прогресс.</span>
            </div>
            <button className="btn btn-primary btn--sm" onClick={handleOpenNextPlan}>
              Создать новый план
            </button>
          </div>
        )}
        {isGenerating && (
          <div className="plan-generating-banner">
            <span className="btn-spinner" />
            <span>{generationLabel} Это займёт 3-5 минут.</span>
          </div>
        )}
        {(externalViewMode !== 'week' || canManagePlan) && (
        <div className="calendar-header-row">
          {(externalViewMode !== 'week' || canManagePlan) && (
            <div className="calendar-view-controls">
              {externalViewMode !== 'week' && (
                <div className="calendar-view-toggle">
                  <button
                    className={`view-toggle-btn ${viewMode === 'week' ? 'active' : ''}`}
                    onClick={() => setViewMode('week')}
                    disabled={externalViewMode !== null && externalViewMode !== undefined}
                  >
                    Неделя
                  </button>
                  <button
                    className={`view-toggle-btn ${viewMode === 'full' ? 'active' : ''}`}
                    onClick={() => setViewMode('full')}
                    disabled={externalViewMode !== null && externalViewMode !== undefined}
                  >
                    Месяц
                  </button>
                </div>
              )}
              {canManagePlan && (
                <div className="calendar-mobile-plan-menu" ref={planActionsMenuRef}>
                  <button
                    className={`calendar-mobile-menu-btn${planActionsMenuOpen ? ' is-open' : ''}`}
                    type="button"
                    aria-label="Действия с планом"
                    aria-haspopup="menu"
                    aria-expanded={planActionsMenuOpen}
                    onClick={() => setPlanActionsMenuOpen((open) => !open)}
                  >
                    <MoreHorizontal size={22} strokeWidth={2.2} />
                  </button>
                  {planActionsMenuOpen && (
                    <div className="calendar-mobile-menu-panel" role="menu">
                      <button
                        className="calendar-mobile-menu-item"
                        type="button"
                        role="menuitem"
                        onClick={handleMobilePrimaryPlanAction}
                        disabled={isPlanCompleted ? generatingNext : recalculating}
                      >
                        {isPlanCompleted ? (
                          <Sparkles size={18} strokeWidth={2} />
                        ) : (
                          <RefreshCw size={18} strokeWidth={2} />
                        )}
                        <span>{isPlanCompleted ? (generatingNext ? 'Генерация...' : 'Новый план') : (recalculating ? 'Пересчёт...' : 'Пересчитать план')}</span>
                      </button>
                      <button
                        className="calendar-mobile-menu-item calendar-mobile-menu-item--danger"
                        type="button"
                        role="menuitem"
                        onClick={handleMobileClearPlan}
                        disabled={recalculating || generatingNext || clearingPlan}
                      >
                        {clearingPlan ? (
                          <span className="btn-spinner" />
                        ) : (
                          <Trash2 size={18} strokeWidth={2} />
                        )}
                        <span>{clearingPlan ? 'Очищаем...' : 'Очистить план'}</span>
                      </button>
                    </div>
                  )}
                </div>
              )}
            </div>
          )}
          {canManagePlan && (
            <div className="calendar-plan-actions">
              {hasPlan && isPlanCompleted ? (
                <button
                  className="btn btn-primary btn-next-plan"
                  onClick={handleOpenNextPlan}
                  disabled={generatingNext}
                >
                  {generatingNext ? (
                    <>
                      <span className="btn-spinner" />
                      Генерация...
                    </>
                  ) : (
                    <>
                      <Sparkles size={16} strokeWidth={2} />
                      Новый план
                    </>
                  )}
                </button>
              ) : (
                <button
                  className="btn btn-recalculate"
                  onClick={handleOpenRecalc}
                  disabled={recalculating}
                >
                  {recalculating ? (
                    <>
                      <span className="btn-spinner" />
                      <span className="btn-recalculate-text">Пересчёт...</span>
                    </>
                  ) : (
                    <>
                      <RefreshCw size={16} strokeWidth={2} />
                      <span className="btn-recalculate-text">Пересчитать</span>
                    </>
                  )}
                </button>
              )}
              <button
                className="btn btn-secondary btn--sm calendar-clear-plan-btn"
                onClick={() => setShowClearPlanConfirm(true)}
                disabled={recalculating || generatingNext || clearingPlan}
                title="Удалить план тренировок"
              >
                {clearingPlan ? (
                  <span className="btn-spinner" />
                ) : (
                  <>
                    <Trash2 size={16} strokeWidth={2} />
                    <span className="calendar-clear-plan-text">Очистить план</span>
                  </>
                )}
              </button>
            </div>
          )}
        </div>
        )}

        {showRecalcConfirm && (
          <div className="modal" style={{ display: 'block' }} onClick={() => setShowRecalcConfirm(false)}>
            <div className="modal-content recalc-confirm-modal" onClick={e => e.stopPropagation()}>
              <h3>Пересчитать план</h3>
              <p>Расскажите, почему вы хотите пересчитать план. Чем подробнее — тем точнее ИИ-тренер подберёт новую программу.</p>
              <div className="recalc-reason-hints">
                {['Был перерыв в тренировках', 'Чувствую, что план слишком тяжёлый', 'Получил травму / болел', 'Хочу увеличить нагрузку', 'Изменились цели'].map(hint => (
                  <button
                    key={hint}
                    type="button"
                    className={`recalc-hint-chip${recalcReason.includes(hint) ? ' active' : ''}`}
                    onClick={() => setRecalcReason(prev => {
                      if (prev.includes(hint)) return prev;
                      return prev ? `${prev}. ${hint}` : hint;
                    })}
                  >
                    {hint}
                  </button>
                ))}
              </div>
              <textarea
                className="recalc-reason-input"
                placeholder="Например: не занимался 2 недели из-за простуды, сейчас чувствую себя нормально, хотел бы плавно вернуться..."
                value={recalcReason}
                onChange={e => setRecalcReason(e.target.value)}
                rows={4}
                maxLength={1000}
              />
              <p className="recalc-confirm-note">Прошлые результаты тренировок сохранятся. Пересчёт займёт 3-5 минут.</p>
              <div className="recalc-confirm-actions">
                <button className="btn btn-secondary" onClick={() => setShowRecalcConfirm(false)}>Отмена</button>
                <button className="btn btn-primary" onClick={handleRecalculate}>Пересчитать план</button>
              </div>
            </div>
          </div>
        )}
        {showNextPlanModal && (
          <div className="modal" style={{ display: 'block' }} onClick={() => setShowNextPlanModal(false)}>
            <div className="modal-content recalc-confirm-modal" onClick={e => e.stopPropagation()}>
              <h3>Новый тренировочный план</h3>
              <p>Предыдущий план завершён. AI-тренер создаст новый план, основываясь на всех ваших прошлых тренировках, прогрессе и текущей форме.</p>
              <p style={{ fontSize: 'var(--text-sm)', color: 'var(--text-secondary)', marginTop: 'var(--space-2)' }}>
                Расскажите, какие у вас цели на новый план (необязательно):
              </p>
              <div className="recalc-reason-hints">
                {['Продолжить прогрессию', 'Подготовка к забегу', 'Увеличить дистанцию', 'Улучшить скорость', 'Восстановление после соревнования'].map(hint => (
                  <button
                    key={hint}
                    type="button"
                    className={`recalc-hint-chip${nextPlanGoals.includes(hint) ? ' active' : ''}`}
                    onClick={() => setNextPlanGoals(prev => {
                      if (prev.includes(hint)) return prev;
                      return prev ? `${prev}. ${hint}` : hint;
                    })}
                  >
                    {hint}
                  </button>
                ))}
              </div>
              <textarea
                className="recalc-reason-input"
                placeholder="Например: хочу подготовиться к полумарафону через 3 месяца, в прошлом плане чувствовал себя хорошо..."
                value={nextPlanGoals}
                onChange={e => setNextPlanGoals(e.target.value)}
                rows={4}
                maxLength={2000}
              />
              <p className="recalc-confirm-note">Все ваши прошлые тренировки будут учтены. Генерация займёт 3-5 минут.</p>
              <div className="recalc-confirm-actions">
                <button className="btn btn-secondary" onClick={() => setShowNextPlanModal(false)}>Отмена</button>
                <button className="btn btn-primary" onClick={handleGenerateNextPlan}>Создать новый план</button>
              </div>
            </div>
          </div>
        )}
        {planReadinessCheck && (
          <div className="modal" style={{ display: 'block' }} onClick={dismissPlanReadinessCheck}>
            <div className="modal-content recalc-confirm-modal readiness-check-modal" onClick={e => e.stopPropagation()}>
              <h3>Уточнить самочувствие</h3>
              <p>{planReadinessCheck.question}</p>
              {planReadinessCheck.source?.summary && (
                <p className="recalc-confirm-note">
                  Последний сигнал: {planReadinessCheck.source.date_label}, {planReadinessCheck.source.summary}
                  {planReadinessCheck.source.subsequent_run_count > 0
                    ? `. После него было беговых тренировок: ${planReadinessCheck.source.subsequent_run_count}.`
                    : '.'}
                </p>
              )}

              <div className="readiness-check-field">
                <div className="readiness-check-label">Боль или дискомфорт сейчас</div>
                <div className="readiness-pain-scale">
                  {Array.from({ length: 11 }, (_, value) => (
                    <button
                      key={value}
                      type="button"
                      className={`readiness-score-btn${readinessPainScore === value ? ' active' : ''}`}
                      onClick={() => setReadinessPainScore(value)}
                    >
                      {value}
                    </button>
                  ))}
                </div>
              </div>

              <div className="readiness-check-field">
                <div className="readiness-check-label">После последних пробежек боль усиливалась?</div>
                <div className="readiness-segmented">
                  <button
                    type="button"
                    className={readinessWorsened === false ? 'active' : ''}
                    onClick={() => setReadinessWorsened(false)}
                  >
                    Нет
                  </button>
                  <button
                    type="button"
                    className={readinessWorsened === true ? 'active' : ''}
                    onClick={() => setReadinessWorsened(true)}
                  >
                    Да
                  </button>
                </div>
              </div>

              <div className="readiness-check-field">
                <div className="readiness-check-label">Техника бега менялась из-за ноги?</div>
                <div className="readiness-segmented">
                  <button
                    type="button"
                    className={readinessTechniqueChanged === false ? 'active' : ''}
                    onClick={() => setReadinessTechniqueChanged(false)}
                  >
                    Нет
                  </button>
                  <button
                    type="button"
                    className={readinessTechniqueChanged === true ? 'active' : ''}
                    onClick={() => setReadinessTechniqueChanged(true)}
                  >
                    Да
                  </button>
                </div>
              </div>

              <textarea
                className="recalc-reason-input"
                placeholder="Можно добавить короткий комментарий"
                value={readinessText}
                onChange={e => setReadinessText(e.target.value)}
                rows={3}
                maxLength={1000}
              />

              <div className="recalc-confirm-actions">
                <button className="btn btn-secondary" onClick={dismissPlanReadinessCheck} disabled={readinessSubmitting}>Отмена</button>
                <button
                  className="btn btn-primary"
                  onClick={handleSubmitReadinessCheck}
                  disabled={readinessSubmitting || readinessWorsened === null || readinessTechniqueChanged === null}
                >
                  {readinessSubmitting ? 'Сохраняю...' : 'Сохранить и рассчитать'}
                </button>
              </div>
            </div>
          </div>
        )}
        {showClearPlanConfirm && (
          <div className="modal" style={{ display: 'block' }} onClick={() => setShowClearPlanConfirm(false)}>
            <div className="modal-content recalc-confirm-modal" onClick={e => e.stopPropagation()}>
              <h3>Очистить план</h3>
              <p>Удалить план тренировок, сгенерированный ИИ? Результаты выполненных тренировок сохранятся.</p>
              <div className="recalc-confirm-actions">
                <button className="btn btn-secondary" onClick={() => setShowClearPlanConfirm(false)}>Отмена</button>
                <button className="btn btn-primary" onClick={handleClearPlan}>Очистить план</button>
              </div>
            </div>
          </div>
        )}
        {viewMode === 'week' ? (
          <WeekCalendar
            plan={planData}
            workoutsData={workoutsData}
            workoutsListByDate={workoutsListByDate}
            resultsData={resultsData}
            executedByDate={executedByDate}
            api={api}
            canEdit={canEdit}
            canView={canView}
            viewContext={viewContext}
            onDayPress={(date, weekNumber, dayKey) => {
              if (canEdit || isOwner || canView) {
                setDayModal({ isOpen: true, date, week: weekNumber, day: dayKey });
              }
            }}
            onOpenWorkoutDetails={(date, weekNumber, dayKey, selectedWorkoutId, immediateDayData) => handleOpenWorkoutDetails(date, weekNumber, dayKey, selectedWorkoutId, immediateDayData)}
            onOpenResultModal={(date, week, day) => setResultModal({ isOpen: true, date, week, day })}
            onAddTraining={(date) => setAddTrainingModal({ isOpen: true, date, planDay: null })}
            onEditTraining={(planDay, date) => setAddTrainingModal({ isOpen: true, date, planDay })}
            onTrainingAdded={() => {
              loadPlan({ silent: true });
              useWorkoutRefreshStore.getState().triggerRefresh();
            }}
            currentWeekNumber={getCurrentWeekNumber(planData)}
            initialDate={location.state?.date}
            initialDateKey={location.key}
          />
        ) : (
          <div className="week-calendar-container">
            <MonthlyCalendar
              workoutsData={workoutsData}
              workoutsListByDate={workoutsListByDate}
              resultsData={resultsData}
              executedByDate={executedByDate}
              planData={planData}
              api={api}
              onDateClick={(date) => {
                if (canEdit || isOwner || canView) {
                  // Парсим дату для DayModal
                  const dateStr = typeof date === 'string' ? date : date.toISOString().split('T')[0];
                  setDayModal({ isOpen: true, date: dateStr, week: null, day: null });
                }
              }}
              canEdit={canEdit}
              targetUserId={calendarUserId}
            />
          </div>
        )}
      </div>

      <DayModal
        isOpen={dayModal.isOpen}
        onClose={() => setDayModal({ isOpen: false, date: null, week: null, day: null })}
        date={dayModal.date}
        weekNumber={dayModal.week}
        dayKey={dayModal.day}
        api={api}
        canEdit={canEdit}
        targetUserId={calendarUserId}
        viewContext={viewContext}
        onTrainingAdded={() => {
          loadPlan({ silent: true });
          useWorkoutRefreshStore.getState().triggerRefresh();
        }}
        onEditTraining={(planDay, date) => setAddTrainingModal({ isOpen: true, date, planDay })}
        onOpenResultModal={(date, week, day) => setResultModal({ isOpen: true, date, week, day })}
        refreshKey={dayModalRefreshKey}
      />

      <ResultModal
        isOpen={resultModal.isOpen}
        onClose={() => setResultModal({ isOpen: false, date: null, week: null, day: null })}
        date={resultModal.date}
        weekNumber={resultModal.week}
        dayKey={resultModal.day}
        api={api}
        onSave={() => {
          loadPlan({ silent: true });
          useWorkoutRefreshStore.getState().triggerRefresh();
        }}
      />

      <WorkoutDetailsModal
        isOpen={workoutDetailsModal.isOpen}
        onClose={handleCloseWorkoutDetails}
        date={workoutDetailsModal.date}
        dayData={workoutDetailsModal.dayData}
        loading={workoutDetailsModal.loading}
        weekNumber={workoutDetailsModal.weekNumber}
        dayKey={workoutDetailsModal.dayKey}
        selectedWorkoutId={workoutDetailsModal.selectedWorkoutId}
        onEdit={workoutDetailsModal.dayData?.workouts?.length ? handleEditWorkoutResult : undefined}
        onDelete={canEdit ? () => { handleCloseWorkoutDetails(); loadPlan({ silent: true }); useWorkoutRefreshStore.getState().triggerRefresh(); } : undefined}
      />

      <AddTrainingModal
        isOpen={addTrainingModal.isOpen}
        onClose={() => setAddTrainingModal({ isOpen: false, date: null, planDay: null, editResultData: null })}
        date={addTrainingModal.date}
        api={api}
        initialData={addTrainingModal.planDay ? { ...addTrainingModal.planDay, date: addTrainingModal.date } : null}
        editResultData={addTrainingModal.editResultData}
        viewContext={viewContext}
        onSuccess={() => {
          loadPlan({ silent: true });
          useWorkoutRefreshStore.getState().triggerRefresh();
          setAddTrainingModal({ isOpen: false, date: null, planDay: null, editResultData: null });
          setDayModalRefreshKey((k) => k + 1);
        }}
      />
    </div>
  );
};

export default CalendarScreen;
