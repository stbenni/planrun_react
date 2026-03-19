import { useCallback, useEffect, useMemo, useState } from 'react';
import usePlanStore from '../../stores/usePlanStore';
import usePreloadStore from '../../stores/usePreloadStore';
import useWorkoutRefreshStore from '../../stores/useWorkoutRefreshStore';
import { processStatsData } from '../Stats/StatsUtils';
import { getDayCompletionStatus, getPlanDayForDate, planTypeToCategory, workoutTypeToCategory } from '../../utils/calendarHelpers';
import { addDaysToDateStr, dayItemsToWorkoutAndPlanDays, getDayItems, getTodayInTimezone } from './dashboardDateUtils';

const DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

function isAiPlanMode(trainingMode) {
  return trainingMode === 'ai' || trainingMode === 'both';
}

function getSummaryObject(workoutsSummaryRes) {
  return workoutsSummaryRes?.workouts ?? (
    workoutsSummaryRes &&
    typeof workoutsSummaryRes === 'object' &&
    !Array.isArray(workoutsSummaryRes)
      ? workoutsSummaryRes
      : {}
  );
}

function buildResultsData(allResults) {
  const resultsData = {};
  (allResults?.results || []).forEach((result) => {
    if (!result?.training_date) return;
    if (!resultsData[result.training_date]) {
      resultsData[result.training_date] = [];
    }
    resultsData[result.training_date].push(result);
  });
  return resultsData;
}

function buildWorkoutsList(workoutsListRes) {
  return workoutsListRes?.data?.workouts ?? workoutsListRes?.workouts ?? [];
}

function buildWorkoutsListByDate(workoutsList) {
  const workoutsListByDate = {};
  workoutsList.forEach((workout) => {
    const date = workout.date ?? workout.start_time?.split?.('T')?.[0];
    if (!date) return;
    if (!workoutsListByDate[date]) {
      workoutsListByDate[date] = [];
    }
    workoutsListByDate[date].push(workout);
  });
  return workoutsListByDate;
}

function hasAnyPlannedWorkout(weeksData) {
  for (const week of weeksData || []) {
    if (!week?.days) continue;
    for (const dayData of Object.values(week.days)) {
      if (getDayItems(dayData).length > 0) {
        return true;
      }
    }
  }
  return false;
}

function buildProgressDataMap(plan, summaryObj, allResults, workoutsListByDate) {
  const resultsData = buildResultsData(allResults);
  const allDates = new Set([
    ...Object.keys(resultsData),
    ...Object.keys(workoutsListByDate),
    ...Object.keys(summaryObj || {}),
  ]);
  const progressDataMap = {};

  allDates.forEach((dateStr) => {
    const planDay = getPlanDayForDate(dateStr, plan);
    const status = getDayCompletionStatus(dateStr, planDay, summaryObj, resultsData, workoutsListByDate);
    if (status.status === 'completed') {
      progressDataMap[dateStr] = true;
    }
  });

  return progressDataMap;
}

function findDashboardWorkouts(plan, user) {
  const weeksData = plan?.weeks_data || [];
  const ianaTimezone = (
    user?.timezone ||
    (typeof Intl !== 'undefined' && Intl.DateTimeFormat?.().resolvedOptions().timeZone) ||
    'Europe/Moscow'
  );
  const todayStr = getTodayInTimezone(ianaTimezone);

  let todayWorkout = null;
  let todayPlanDays = null;
  let nextWorkout = null;
  let nextPlanDays = null;
  let currentWeek = null;

  for (const week of weeksData) {
    if (!week?.start_date || !week?.days) continue;
    const endDateStr = addDaysToDateStr(week.start_date, 6);
    const inThisWeek = todayStr >= week.start_date && todayStr <= endDateStr;
    if (!inThisWeek) continue;

    currentWeek = week;
    const startDate = new Date(`${week.start_date}T12:00:00`);
    const todayDate = new Date(`${todayStr}T12:00:00`);
    const dayIndex = Math.round((todayDate - startDate) / (24 * 60 * 60 * 1000));
    const dayKey = DAY_KEYS[dayIndex >= 0 && dayIndex <= 6 ? dayIndex : 0];
    const items = getDayItems(week.days?.[dayKey]);
    const todayPayload = dayItemsToWorkoutAndPlanDays(items, todayStr, week.number, dayKey);
    if (todayPayload) {
      todayWorkout = todayPayload.workout;
      todayPlanDays = todayPayload.planDays;
    }
  }

  for (const week of weeksData) {
    if (!week?.start_date || !week?.days) continue;
    for (let i = 0; i < 7; i += 1) {
      const workoutDateStr = addDaysToDateStr(week.start_date, i);
      if (workoutDateStr <= todayStr) continue;
      const dayKey = DAY_KEYS[i];
      const items = getDayItems(week.days?.[dayKey]);
      const nextPayload = dayItemsToWorkoutAndPlanDays(items, workoutDateStr, week.number, dayKey);
      if (!nextPayload) continue;
      nextWorkout = nextPayload.workout;
      nextPlanDays = nextPayload.planDays;
      break;
    }
    if (nextWorkout) break;
  }

  return {
    todayWorkout: todayWorkout ? { ...todayWorkout, planDays: todayPlanDays } : null,
    nextWorkout: nextWorkout ? { ...nextWorkout, planDays: nextPlanDays } : null,
    currentWeek,
  };
}

function hasWorkoutForCategory(dateStr, category, workoutsList, allResults, summaryObj) {
  const workoutsOnDate = workoutsList.filter((workout) => (
    (workout.date ?? workout.start_time?.split?.('T')?.[0]) === dateStr
  ));

  for (const workout of workoutsOnDate) {
    const workoutCategory = workoutTypeToCategory(workout.activity_type ?? workout.activity_type_name ?? 'running');
    if (workoutCategory === category) {
      return true;
    }
  }

  const result = (allResults?.results || []).find((item) => item?.training_date === dateStr);
  if (result) {
    const resultCategory = workoutTypeToCategory(result.activity_type ?? result.activity_type_name ?? 'running');
    if (resultCategory === category) {
      return true;
    }
  }

  const summaryItem = summaryObj?.[dateStr];
  if (summaryItem && (summaryItem.count > 0 || summaryItem.distance || summaryItem.duration || summaryItem.duration_seconds)) {
    const summaryCategory = workoutTypeToCategory(summaryItem.activity_type ?? 'running');
    if (summaryCategory === category) {
      return true;
    }
  }

  return false;
}

function calculateWeekProgress(currentWeek, workoutsList, allResults, summaryObj) {
  if (!currentWeek?.start_date) {
    return { completed: 0, total: 0 };
  }

  const plannedDays = [];
  for (let i = 0; i < 7; i += 1) {
    const dateStr = addDaysToDateStr(currentWeek.start_date, i);
    const items = getDayItems(currentWeek.days?.[DAY_KEYS[i]]);
    items.forEach((item) => {
      const plannedCategory = planTypeToCategory(item?.type);
      if (plannedCategory) {
        plannedDays.push({ date: dateStr, plannedCategory });
      }
    });
  }

  const completed = plannedDays.filter((plannedDay) => (
    hasWorkoutForCategory(plannedDay.date, plannedDay.plannedCategory, workoutsList, allResults, summaryObj)
  )).length;

  return { completed, total: plannedDays.length };
}

function buildMetrics(summaryObj, allResults, plan) {
  const processed = processStatsData({ workouts: summaryObj }, allResults, plan, 'last7days');
  return {
    distance: processed.totalDistance ?? 0,
    workouts: processed.totalWorkouts ?? 0,
    time: Math.round((processed.totalTime ?? 0) / 60),
  };
}

export function useDashboardData({
  api,
  user,
  isTabActive = true,
  registrationMessage,
  isNewRegistration,
  clearPlanMessage,
}) {
  const planStatusFromStore = usePlanStore((state) => state.planStatus);
  const workoutRefreshVersion = useWorkoutRefreshStore((state) => state.version);
  const isAiTrainingMode = isAiPlanMode(user?.training_mode);

  const [todayWorkout, setTodayWorkout] = useState(null);
  const [weekProgress, setWeekProgress] = useState({ completed: 0, total: 0 });
  const [metrics, setMetrics] = useState({ distance: 0, workouts: 0, time: 0 });
  const [loading, setLoading] = useState(true);
  const [nextWorkout, setNextWorkout] = useState(null);
  const [progressDataMap, setProgressDataMap] = useState({});
  const [planExists, setPlanExists] = useState(false);
  const [plan, setPlan] = useState(null);
  const [hasAnyPlannedWorkoutState, setHasAnyPlannedWorkout] = useState(false);
  const [showPlanMessage, setShowPlanMessage] = useState(false);
  const [planError, setPlanError] = useState(null);
  const [regenerating, setRegenerating] = useState(false);
  const [planGenerating, setPlanGenerating] = useState(false);

  const loadDashboardData = useCallback(async (options = {}) => {
    const silent = options.silent === true;
    if (!api) {
      setLoading(false);
      return;
    }

    try {
      if (!silent) {
        setLoading(true);
      }

      const store = usePlanStore.getState();
      let planStatus;
      let planData;
      const shouldCheckPlanStatus = isAiPlanMode(user?.training_mode);

      if (store.planStatus != null && store.hasPlan && store.plan != null && !store.planStatus?.generating) {
        planStatus = store.planStatus;
        planData = store.plan;
      }

      const [planStatusRes, planRes, allResults, workoutsSummaryRes, workoutsListRes] = await Promise.all([
        shouldCheckPlanStatus
          ? (planStatus != null ? Promise.resolve(planStatus) : api.checkPlanStatus().catch((error) => {
            console.error('Error checking plan status:', error);
            return null;
          }))
          : Promise.resolve(null),
        planData != null ? Promise.resolve(planData) : api.getPlan().catch((error) => {
          console.error('Error loading plan:', error);
          return null;
        }),
        api.getAllResults().catch(() => ({ results: [] })),
        api.getAllWorkoutsSummary().catch(() => ({})),
        api.getAllWorkoutsList(null, 500).catch(() => ({ workouts: [] })),
      ]);

      planStatus = planStatus ?? planStatusRes;
      planData = planData ?? planRes;

      if (shouldCheckPlanStatus && planStatus && (planStatus.error || (!planStatus.has_plan && planStatus.error))) {
        setPlanError(planStatus.error);
        setPlanExists(false);
        setShowPlanMessage(false);
        setPlanGenerating(false);
        setLoading(false);
        return;
      }

      const generating = Boolean(planStatus?.generating && shouldCheckPlanStatus);
      setPlanGenerating(generating);

      const weeksData = planData?.weeks_data;
      const summaryObj = getSummaryObject(workoutsSummaryRes);
      const workoutsList = buildWorkoutsList(workoutsListRes);

      if (!planData || !Array.isArray(weeksData) || weeksData.length === 0) {
        setPlanExists(false);
        setPlan(null);
        setHasAnyPlannedWorkout(false);
        setPlanError(null);
        setProgressDataMap({});
        setMetrics(buildMetrics(summaryObj, allResults, null));
        if (isNewRegistration || registrationMessage) {
          setShowPlanMessage(true);
        }
        return;
      }

      setPlanExists(true);
      setPlanError(null);
      setShowPlanMessage(false);
      setPlanGenerating(false);
      clearPlanMessage();
      setPlan(planData);
      setHasAnyPlannedWorkout(hasAnyPlannedWorkout(weeksData));
      usePlanStore.getState().setPlan(planData);

      const workoutsListByDate = buildWorkoutsListByDate(workoutsList);
      setProgressDataMap(buildProgressDataMap(planData, summaryObj, allResults, workoutsListByDate));

      const { todayWorkout: currentWorkout, nextWorkout: upcomingWorkout, currentWeek } = findDashboardWorkouts(planData, user);
      setTodayWorkout(currentWorkout);
      setNextWorkout(upcomingWorkout);
      setWeekProgress(calculateWeekProgress(currentWeek, workoutsList, allResults, summaryObj));
      setMetrics(buildMetrics(summaryObj, allResults, planData));
    } catch (error) {
      console.error('Error loading dashboard:', error);
      if (isNewRegistration || registrationMessage) {
        setShowPlanMessage(true);
        setPlanExists(false);
      }
    } finally {
      setLoading(false);
      if (typeof window !== 'undefined' && window.Capacitor?.isNativePlatform?.()) {
        usePreloadStore.getState().triggerPreload();
      }
    }
  }, [api, clearPlanMessage, isNewRegistration, registrationMessage, user]);

  useEffect(() => {
    if (!api) return;
    if (user && !user.onboarding_completed) {
      setLoading(false);
      return;
    }
    loadDashboardData();
  }, [api, user?.onboarding_completed, user?.timezone, loadDashboardData, user]);

  useEffect(() => {
    if (!isTabActive) return;
    const onVisibilityChange = () => {
      if (document.visibilityState === 'visible' && api) {
        loadDashboardData({ silent: true });
      }
    };
    document.addEventListener('visibilitychange', onVisibilityChange);
    return () => document.removeEventListener('visibilitychange', onVisibilityChange);
  }, [api, isTabActive, loadDashboardData]);

  useEffect(() => {
    if (workoutRefreshVersion <= 0 || !api) return undefined;
    const timeoutId = setTimeout(() => loadDashboardData({ silent: true }), 250);
    return () => clearTimeout(timeoutId);
  }, [workoutRefreshVersion, api, loadDashboardData]);

  useEffect(() => {
    if (isNewRegistration || registrationMessage) {
      setShowPlanMessage(true);
    }
  }, [isNewRegistration, registrationMessage]);

  useEffect(() => {
    if (!planGenerating || !isTabActive || !api) return undefined;
    const intervalId = setInterval(() => {
      loadDashboardData({ silent: true });
    }, 10000);
    return () => clearInterval(intervalId);
  }, [planGenerating, isTabActive, api, loadDashboardData]);

  const handleRegeneratePlan = useCallback(async () => {
    if (!api || regenerating) return;

    setRegenerating(true);
    setPlanError(null);
    setShowPlanMessage(true);
    setPlanExists(false);
    setPlanGenerating(true);
    usePlanStore.getState().clearPlan();

    try {
      const result = await api.regeneratePlan();
      if (result?.success) {
        useWorkoutRefreshStore.getState().triggerRefresh();
        setTimeout(() => {
          loadDashboardData();
        }, 5000);
      } else {
        setPlanError(result?.error || 'Ошибка при запуске генерации плана');
        setShowPlanMessage(false);
        setPlanGenerating(false);
        clearPlanMessage();
      }
    } catch (error) {
      setPlanError(error.message || 'Ошибка при запуске генерации плана');
      setShowPlanMessage(false);
      setPlanGenerating(false);
      clearPlanMessage();
    } finally {
      setRegenerating(false);
    }
  }, [api, clearPlanMessage, loadDashboardData, regenerating]);

  const progressPercentage = useMemo(() => (
    weekProgress.total > 0
      ? Math.round((weekProgress.completed / weekProgress.total) * 100)
      : 0
  ), [weekProgress]);

  return {
    hasAnyPlannedWorkout: hasAnyPlannedWorkoutState,
    handleRegeneratePlan,
    isAiTrainingMode,
    loadDashboardData,
    loading,
    metrics,
    nextWorkout,
    noPlanChecked: isAiTrainingMode && planStatusFromStore != null && planStatusFromStore.has_plan === false,
    plan,
    planError,
    planExists,
    planGenerating,
    progressDataMap,
    progressPercentage,
    regenerating,
    setShowPlanMessage,
    showPlanMessage,
    todayWorkout,
    weekProgress,
  };
}
