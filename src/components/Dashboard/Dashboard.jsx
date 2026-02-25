/**
 * Dashboard - Главный экран в стиле OMY! Sports
 * Показывает сегодняшнюю тренировку, прогресс недели и быстрые метрики
 * Поддерживает pull-to-refresh и настраиваемые блоки (добавить/удалить/порядок)
 */

import React, { useState, useEffect, useRef, useCallback, useMemo } from 'react';
import {
  DndContext,
  DragOverlay,
  useDraggable,
  useDroppable,
  PointerSensor,
  TouchSensor,
  KeyboardSensor,
  useSensor,
  useSensors,
} from '@dnd-kit/core';
import useAuthStore from '../../stores/useAuthStore';
import WorkoutCard from '../Calendar/WorkoutCard';
import DashboardWeekStrip from './DashboardWeekStrip';
import DashboardStatsWidget from './DashboardStatsWidget';
import { MetricDistanceIcon, MetricActivityIcon, MetricTimeIcon } from './DashboardMetricIcons';
import SkeletonScreen from '../common/SkeletonScreen';
import './Dashboard.css';

const DASHBOARD_MODULE_IDS = ['today_workout', 'quick_metrics', 'next_workout', 'calendar', 'stats'];
const DASHBOARD_MODULE_LABELS = {
  today_workout: 'Сегодняшняя тренировка',
  quick_metrics: 'Быстрые метрики',
  next_workout: 'Следующая тренировка',
  calendar: 'Календарь',
  stats: 'Статистика',
};
const STORAGE_KEY = 'planrun_dashboard_modules';

/** Layout: массив строк; каждая строка — 1 или 2 id (на всю ширину или в одну линию) */
function getStoredLayout() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return getDefaultLayout();
    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed) || parsed.length === 0) return getDefaultLayout();
    const first = parsed[0];
    if (typeof first === 'string') {
      return orderToLayout(parsed.filter((id) => DASHBOARD_MODULE_IDS.includes(id)));
    }
    const layout = parsed.filter((row) => Array.isArray(row) && row.length >= 1 && row.length <= 2);
    const valid = layout.flat().filter((id) => DASHBOARD_MODULE_IDS.includes(id));
    const seen = new Set();
    const deduped = valid.filter((id) => !seen.has(id) && seen.add(id));
    const missing = DASHBOARD_MODULE_IDS.filter((id) => !deduped.includes(id));
    const fixed = layout
      .map((row) => row.filter((id) => DASHBOARD_MODULE_IDS.includes(id)).slice(0, 2))
      .filter((row) => row.length > 0);
    if (fixed.length === 0) return getDefaultLayout();
    const used = new Set(fixed.flat());
    for (const id of missing) {
      if (!used.has(id)) fixed.push([id]);
    }
    return fixed;
  } catch {
    return getDefaultLayout();
  }
}

const PAIRABLE_MODULE_IDS = new Set(['today_workout', 'next_workout', 'stats']);

/** API возвращает week.days[dayKey] как массив { type, text, id } или один объект. Нормализуем в массив. */
function getDayItems(dayData) {
  if (!dayData) return [];
  const arr = Array.isArray(dayData) ? dayData : [dayData];
  return arr.filter((d) => d && d.type !== 'rest' && d.type !== 'free');
}

/** Дата в формате YYYY-MM-DD по локальной таймзоне (не UTC). */
function toLocalDateString(date) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

/** Сегодня в формате YYYY-MM-DD в заданной IANA-таймзоне (Europe/Moscow и т.д.). */
function getTodayInTimezone(ianaTimezone) {
  try {
    const formatter = new Intl.DateTimeFormat('en-CA', { timeZone: ianaTimezone, year: 'numeric', month: '2-digit', day: '2-digit' });
    const parts = formatter.formatToParts(new Date());
    const y = parts.find((p) => p.type === 'year').value;
    const m = parts.find((p) => p.type === 'month').value;
    const d = parts.find((p) => p.type === 'day').value;
    return `${y}-${m}-${d}`;
  } catch {
    return toLocalDateString(new Date());
  }
}

/** Добавить дни к строке даты YYYY-MM-DD (без сдвига по таймзоне). */
function addDaysToDateStr(dateStr, days) {
  const [y, m, d] = dateStr.split('-').map(Number);
  const date = new Date(Date.UTC(y, m - 1, d + days));
  return date.toISOString().split('T')[0];
}

/** Из массива дня плана: первый элемент для workout, все — для planDays в WorkoutCard. */
function dayItemsToWorkoutAndPlanDays(items, date, weekNumber, dayKey) {
  if (!items || items.length === 0) return null;
  const first = items[0];
  const workout = {
    type: first.type,
    text: first.text,
    date,
    weekNumber,
    dayKey,
  };
  const planDays = items.map((d) => ({
    id: d.id,
    type: d.type,
    description: d.text || '',
  }));
  return { workout, planDays };
}

function orderToLayout(order) {
  const rows = [];
  let i = 0;
  while (i < order.length) {
    const id = order[i];
    const nextId = order[i + 1];
    const canPair = PAIRABLE_MODULE_IDS.has(id) && nextId != null && PAIRABLE_MODULE_IDS.has(nextId);
    if (canPair) {
      rows.push([id, nextId]);
      i += 2;
      continue;
    }
    rows.push([id]);
    i += 1;
  }
  return rows;
}

function getDefaultLayout() {
  /* По умолчанию на десктопе: сегодня + следующая в одну строку, календарь и статистика во всю ширину; быстрые метрики только если пользователь добавит через «Виджеты». */
  return [
    ['today_workout', 'next_workout'],
    ['calendar'],
    ['stats'],
  ];
}

function layoutToOrder(layout) {
  return layout.flat();
}

function saveLayout(layout) {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(layout));
  } catch (e) {
    console.warn('Dashboard: could not save layout', e);
  }
}

/** Удалить блок id из layout и вернуть новый layout */
function layoutRemoveId(layout, id) {
  const next = [];
  for (const row of layout) {
    const filtered = row.filter((x) => x !== id);
    if (filtered.length > 0) next.push(filtered);
  }
  return next;
}

/** Вставить новую строку [id] перед rowIndex; id уже должен быть удалён из layout */
function layoutInsertRow(layout, rowIndex, id) {
  const out = layout.slice(0, rowIndex).concat([[id]], layout.slice(rowIndex));
  return out;
}

/** Добавить id в строку targetRowIndex (строка должна быть из одного блока); id уже удалён из layout */
function layoutMergeIntoRow(layout, targetRowIndex, id) {
  const row = layout[targetRowIndex];
  if (!row || row.length !== 1) return layout;
  const out = layout.slice();
  out[targetRowIndex] = [row[0], id];
  return out;
}

/** Развернуть один слот в отдельную строку: [a,b] -> [a], [b] при slotIndex 1 */
function layoutExpandSlot(layout, rowIndex, slotIndex) {
  const row = layout[rowIndex];
  if (!row || row.length !== 2) return layout;
  const id = row[slotIndex];
  const other = row[1 - slotIndex];
  const out = layout.slice(0, rowIndex).concat([[other], [id]], layout.slice(rowIndex + 1));
  return out;
}

/** На мобильных: развернуть все строки в по одному блоку — [[a,b],[c]] → [[a],[b],[c]] */
function expandLayoutForMobile(layout) {
  const result = [];
  for (const row of layout) {
    for (const id of row) result.push([id]);
  }
  return result;
}

/** Полоска-зона сброса «вставить перед строкой N» (для @dnd-kit) */
function CustomizerStripZone({ rowIndex, children }) {
  const { setNodeRef, isOver } = useDroppable({ id: `insert-${rowIndex}` });
  return (
    <div
      ref={setNodeRef}
      className={`dashboard-customizer-strip-zone ${isOver ? 'dashboard-customizer-strip-zone-active' : ''}`}
    >
      {isOver && <div className="dashboard-customizer-drop-strip dashboard-customizer-drop-strip--full" aria-hidden />}
      {children}
    </div>
  );
}

/** Карточка для DragOverlay — та же вёрстка, что и в списке, без кнопки и без useDraggable */
function CustomizerItemPreview({ moduleId }) {
  return (
    <div className="dashboard-customizer-item dashboard-customizer-item--overlay">
      <span className="dashboard-customizer-drag-handle" aria-hidden>⋮⋮</span>
      <span className="dashboard-customizer-label">{DASHBOARD_MODULE_LABELS[moduleId]}</span>
    </div>
  );
}

/** Блок «+ в одну строку» — только оформление; зоной сброса является вся строка (см. CustomizerRow). */
function CustomizerMergeZone({ active }) {
  return (
    <div className={`dashboard-customizer-merge-zone ${active ? 'dashboard-customizer-merge-zone-active' : ''}`}>
      <span className="dashboard-customizer-merge-label">+ в одну строку</span>
    </div>
  );
}

/** Элемент списка — перетаскиваемый блок (для @dnd-kit). Тянуть можно за всю карточку, кнопка «Убрать» не запускает drag. */
function CustomizerDraggableItem({ rowIndex, slotIndex, moduleId, onRemove }) {
  const id = `slot-${rowIndex}-${slotIndex}`;
  const { attributes, listeners, setNodeRef, isDragging } = useDraggable({ id });
  return (
    <div
      ref={setNodeRef}
      className={`dashboard-customizer-item ${isDragging ? 'dragging' : ''}`}
      {...attributes}
      {...listeners}
    >
      <span className="dashboard-customizer-drag-handle" aria-hidden title="Перетащите">⋮⋮</span>
      <span className="dashboard-customizer-label">{DASHBOARD_MODULE_LABELS[moduleId]}</span>
      <div className="dashboard-customizer-actions" onPointerDown={(e) => e.stopPropagation()}>
        <button
          type="button"
          className="dashboard-customizer-remove"
          onClick={(e) => { e.stopPropagation(); onRemove(); }}
          aria-label="Убрать"
        >
          ✕
        </button>
      </div>
    </div>
  );
}

/** Строка кастомайзера: слоты + зона «в одну строку» (десктоп). Вся строка — зона сброса для merge, когда в ней один блок. */
function CustomizerRow({ row, rowIndex, layout, setLayout, saveLayout, isMobileView }) {
  const { setNodeRef: setMergeRef, isOver: isMergeOver } = useDroppable({
    id: `merge-${rowIndex}`,
  });
  const showMerge = row.length === 1 && !isMobileView && isMergeOver;
  const isMergeDroppable = row.length === 1 && !isMobileView;
  return (
    <div
      ref={isMergeDroppable ? setMergeRef : undefined}
      className={`dashboard-customizer-row ${row.length === 2 ? 'dashboard-customizer-row-double' : ''} ${showMerge ? 'dashboard-customizer-row-show-merge' : ''}`}
    >
      {row.map((id, slotIndex) => (
        <div key={`${rowIndex}-${slotIndex}-${id}`} className="dashboard-customizer-slot-wrap">
          <CustomizerDraggableItem
            rowIndex={rowIndex}
            slotIndex={slotIndex}
            moduleId={id}
            onRemove={() => {
              const next = layoutRemoveId(layout, id);
              setLayout(next);
              saveLayout(next);
            }}
          />
        </div>
      ))}
      {row.length === 1 && !isMobileView && (
        <CustomizerMergeZone active={isMergeOver} />
      )}
    </div>
  );
}

const Dashboard = ({ api, user, isTabActive = true, onNavigate, registrationMessage, isNewRegistration }) => {
  const setShowOnboardingModal = useAuthStore((s) => s.setShowOnboardingModal);
  const setPlanGenerationMessage = useAuthStore((s) => s.setPlanGenerationMessage);
  const needsOnboarding = !!(user && !user.onboarding_completed);

  const clearPlanMessage = useCallback(() => {
    setPlanGenerationMessage(null);
  }, [setPlanGenerationMessage]);

  const [todayWorkout, setTodayWorkout] = useState(null);
  const [weekProgress, setWeekProgress] = useState({ completed: 0, total: 0 });
  const [metrics, setMetrics] = useState({
    distance: 0,
    workouts: 0,
    time: 0
  });
  const [loading, setLoading] = useState(true);
  const [nextWorkout, setNextWorkout] = useState(null);
  const [refreshing, setRefreshing] = useState(false);
  const [pullDistance, setPullDistance] = useState(0);
  const [progressDataMap, setProgressDataMap] = useState({});
  const [planExists, setPlanExists] = useState(false);
  const [plan, setPlan] = useState(null);
  const [hasAnyPlannedWorkout, setHasAnyPlannedWorkout] = useState(false);
  const [showPlanMessage, setShowPlanMessage] = useState(false);
  const [planError, setPlanError] = useState(null);
  const [regenerating, setRegenerating] = useState(false);
  const [layout, setLayout] = useState(getStoredLayout);
  const [customizerOpen, setCustomizerOpen] = useState(false);
  const [activeDragId, setActiveDragId] = useState(null); // id перетаскиваемого слота для DragOverlay
  const [expandedWorkoutCard, setExpandedWorkoutCard] = useState(null); // 'today' | 'next' | null
  const dashboardRef = useRef(null);
  const [isMobileView, setIsMobileView] = useState(() =>
    typeof window !== 'undefined' ? window.matchMedia('(max-width: 640px)').matches : false
  );

  useEffect(() => {
    if (typeof window === 'undefined') return;
    const m = window.matchMedia('(max-width: 640px)');
    const fn = () => setIsMobileView(m.matches);
    fn();
    m.addEventListener('change', fn);
    return () => m.removeEventListener('change', fn);
  }, []);

  /* На мобильных в layout не должно быть сдвоенных строк — нормализуем и сохраняем */
  useEffect(() => {
    if (!isMobileView) return;
    const hasDoubles = layout.some((row) => row.length > 1);
    if (!hasDoubles) return;
    const expanded = expandLayoutForMobile(layout);
    setLayout(expanded);
    saveLayout(expanded);
  }, [isMobileView, layout]);

  /** На мобильных — развёрнутый layout (по одному блоку в строку), на десктопе — как сохранён */
  const displayLayout = useMemo(
    () => (isMobileView ? expandLayoutForMobile(layout) : layout),
    [layout, isMobileView]
  );

  const customizerSensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 5 } }),
    useSensor(TouchSensor, {
      activationConstraint: { delay: 200, tolerance: 8 },
    }),
    useSensor(KeyboardSensor)
  );

  const handleDndDragEnd = useCallback((event) => {
    const { active, over } = event;
    if (!over?.id || typeof active.id !== 'string') return;
    const slotMatch = String(active.id).match(/^slot-(\d+)-(\d+)$/);
    if (!slotMatch) return;
    const fromRow = parseInt(slotMatch[1], 10);
    const fromSlot = parseInt(slotMatch[2], 10);
    const currentLayout = isMobileView ? displayLayout : layout;
    const id = currentLayout[fromRow]?.[fromSlot];
    if (!id) return;

    const overId = String(over.id);
    if (overId.startsWith('insert-')) {
      const targetRow = parseInt(overId.slice(7), 10);
      const without = layoutRemoveId(currentLayout, id);
      const insertAt = fromRow < targetRow && currentLayout[fromRow]?.length === 1 ? targetRow - 1 : targetRow;
      const next = layoutInsertRow(without, insertAt, id);
      setLayout(next);
      saveLayout(next);
    } else if (overId.startsWith('merge-') && !isMobileView) {
      const targetRow = parseInt(overId.slice(6), 10);
      if (currentLayout[targetRow]?.length === 1 && targetRow !== fromRow) {
        const without = layoutRemoveId(currentLayout, id);
        const next = layoutMergeIntoRow(without, targetRow, id);
        setLayout(next);
        saveLayout(next);
      }
    }
  }, [layout, displayLayout, isMobileView]);

  const handleDndDragStart = useCallback((event) => {
    setActiveDragId(String(event.active.id));
  }, []);

  const handleDndDragEndWithCleanup = useCallback((event) => {
    handleDndDragEnd(event);
    setActiveDragId(null);
  }, [handleDndDragEnd]);

  /* На тач-устройствах при перетаскивании блокируем скролл фона и списка */
  useEffect(() => {
    if (!activeDragId) return;
    document.body.classList.add('dashboard-customizer-dragging');
    return () => document.body.classList.remove('dashboard-customizer-dragging');
  }, [activeDragId]);

  const draggedModuleId = useMemo(() => {
    if (!activeDragId || typeof activeDragId !== 'string') return null;
    const m = activeDragId.match(/^slot-(\d+)-(\d+)$/);
    if (!m) return null;
    const rowIndex = parseInt(m[1], 10);
    const slotIndex = parseInt(m[2], 10);
    const currentLayout = isMobileView ? displayLayout : layout;
    return currentLayout[rowIndex]?.[slotIndex] ?? null;
  }, [activeDragId, layout, displayLayout, isMobileView]);

  const handleExpandSlot = (rowIndex, slotIndex) => {
    const next = layoutExpandSlot(layout, rowIndex, slotIndex);
    setLayout(next);
    saveLayout(next);
  };
  const pullStartY = useRef(0);
  const isPulling = useRef(false);

  const loadDashboardData = useCallback(async (options = {}) => {
    const silent = options.silent === true;
    if (!api) {
      setLoading(false);
      return;
    }
    try {
      if (!silent) setLoading(true);

      // Все три запроса параллельно — время загрузки = max, а не сумма
      const [planStatus, plan, allResults] = await Promise.all([
        api.checkPlanStatus().catch((error) => {
          console.error('Error checking plan status:', error);
          return null;
        }),
        api.getPlan().catch((error) => {
          console.error('Error loading plan:', error);
          return null;
        }),
        api.getAllResults().catch((error) => {
          console.error('Error loading results:', error);
          return { results: [] };
        }),
      ]);

      // API может вернуть success: true с error в ответе (это нормально для check_plan_status)
      if (planStatus && (planStatus.error || (!planStatus.has_plan && planStatus.error))) {
        setPlanError(planStatus.error);
        setPlanExists(false);
        setShowPlanMessage(false);
        setLoading(false);
        return;
      }

      const weeksData = plan?.weeks_data;
      const hasNoPlan = !plan || !Array.isArray(weeksData) || weeksData.length === 0;
      if (hasNoPlan) {
        setPlanExists(false);
        setPlan(null);
        setHasAnyPlannedWorkout(false);
        setPlanError(null);
        setLoading(false);
        if (isNewRegistration || registrationMessage) {
          setShowPlanMessage(true);
        }
        return;
      }

      setPlanExists(true);
      setPlanError(null);
      setShowPlanMessage(false);
      clearPlanMessage();
      setPlan(plan);

      // Есть ли в плане хотя бы одна запланированная тренировка (дни могут быть массивом)
      let anyWorkout = false;
      for (const week of weeksData) {
        if (!week.days) continue;
        for (const dayData of Object.values(week.days)) {
          if (getDayItems(dayData).length > 0) {
            anyWorkout = true;
            break;
          }
        }
        if (anyWorkout) break;
      }
      setHasAnyPlannedWorkout(anyWorkout);

      // Загружаем прогресс для определения статусов
      let progressDataMap = {};
      if (allResults && allResults.results && Array.isArray(allResults.results)) {
        allResults.results.forEach(result => {
          if (result.training_date) {
            progressDataMap[result.training_date] = true;
          }
        });
      }
      
      setProgressDataMap(progressDataMap);

      // Сегодня в таймзоне пользователя: профиль (Europe/Moscow) → браузер (Intl) → по умолчанию Europe/Moscow
      const ianaTimezone = (user && user.timezone) || (typeof Intl !== 'undefined' && Intl.DateTimeFormat && Intl.DateTimeFormat().resolvedOptions().timeZone) || 'Europe/Moscow';
      const todayStr = getTodayInTimezone(ianaTimezone);

      // Находим тренировку на сегодня и следующую после сегодня
      let foundTodayWorkout = null;
      let foundTodayPlanDays = null;
      let foundNextWorkout = null;
      let foundNextPlanDays = null;
      let weekStart = null;
      let weekEnd = null;
      const dayKeys = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

      for (const week of weeksData) {
        if (!week.start_date || !week.days) continue;
        const endDateStr = addDaysToDateStr(week.start_date, 6);
        const inThisWeek = todayStr >= week.start_date && todayStr <= endDateStr;
        if (inThisWeek) {
          const startDate = new Date(week.start_date + 'T12:00:00');
          const todayDate = new Date(todayStr + 'T12:00:00');
          weekStart = new Date(week.start_date + 'T00:00:00');
          weekEnd = new Date(endDateStr + 'T23:59:59');
          const dayIndex = Math.round((todayDate - startDate) / (24 * 60 * 60 * 1000));
          const dayKey = dayKeys[dayIndex >= 0 && dayIndex <= 6 ? dayIndex : 0];
          const items = getDayItems(week.days && week.days[dayKey]);
          const todayPayload = dayItemsToWorkoutAndPlanDays(items, todayStr, week.number, dayKey);
          if (todayPayload) {
            foundTodayWorkout = todayPayload.workout;
            foundTodayPlanDays = todayPayload.planDays;
          }
        }
      }

      // Следующая тренировка — первый день с тренировкой строго после сегодня (текущая неделя и дальше)
      if (!foundNextWorkout) {
        for (const week of weeksData) {
          if (!week.start_date || !week.days) continue;
          for (let i = 0; i < 7; i++) {
            const workoutDateStr = addDaysToDateStr(week.start_date, i);
            if (workoutDateStr <= todayStr) continue;
            const dayKey = dayKeys[i];
            const items = getDayItems(week.days && week.days[dayKey]);
            const nextPayload = dayItemsToWorkoutAndPlanDays(items, workoutDateStr, week.number, dayKey);
            if (nextPayload) {
              foundNextWorkout = nextPayload.workout;
              foundNextPlanDays = nextPayload.planDays;
              break;
            }
          }
          if (foundNextWorkout) break;
        }
      }

      setTodayWorkout(foundTodayWorkout ? { ...foundTodayWorkout, planDays: foundTodayPlanDays } : null);
      setNextWorkout(foundNextWorkout ? { ...foundNextWorkout, planDays: foundNextPlanDays } : null);

      // Загружаем прогресс недели (используем уже загруженные allResults)
      if (weekStart && weekEnd) {
        let completed = 0;
        let total = 0;

        if (allResults && allResults.results && Array.isArray(allResults.results)) {
          for (const result of allResults.results) {
            if (result.training_date) {
              const resultDate = new Date(result.training_date);
              if (resultDate >= weekStart && resultDate <= weekEnd) {
                completed++;
              }
            }
          }
        }

        // Подсчитываем общее количество тренировок в текущей неделе (дни — массив элементов)
        for (const week of weeksData) {
          if (!week.days) continue;
          const endDateStr = addDaysToDateStr(week.start_date, 6);
          if (todayStr >= week.start_date && todayStr <= endDateStr) {
            for (const dayData of Object.values(week.days)) {
              total += getDayItems(dayData).length;
            }
            break;
          }
        }

        setWeekProgress({ completed, total });
      }

      // Загружаем метрики (используем уже загруженные allResults)
      if (allResults && allResults.results && Array.isArray(allResults.results)) {
        let totalDistance = 0;
        let totalTime = 0;
        let workoutCount = 0;

        // Берем данные за последние 7 дней (относительно «сегодня» в таймзоне пользователя)
        const todayDate = new Date(todayStr + 'T12:00:00');
        const weekAgo = new Date(todayDate);
        weekAgo.setDate(weekAgo.getDate() - 7);

        for (const result of allResults.results) {
          if (result.training_date) {
            const resultDate = new Date(result.training_date);
            if (resultDate >= weekAgo) {
              workoutCount++;
              if (result.distance) totalDistance += parseFloat(result.distance) || 0;
              if (result.duration) totalTime += parseInt(result.duration) || 0;
            }
          }
        }

        setMetrics({
          distance: Math.round(totalDistance * 10) / 10,
          workouts: workoutCount,
          time: Math.round(totalTime / 60) // в часах
        });
      }

    } catch (error) {
      console.error('Error loading dashboard:', error);
      // Если план не загрузился и это новая регистрация, показываем сообщение
      if (isNewRegistration || registrationMessage) {
        setShowPlanMessage(true);
        setPlanExists(false);
      }
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, [api, user, isNewRegistration, registrationMessage]);

  // Загружаем данные при монтировании и при появлении timezone (после getCurrentUser)
  useEffect(() => {
    if (!api) {
      setLoading(false);
      return;
    }
    if (user && !user.onboarding_completed) {
      setLoading(false);
      return;
    }
    loadDashboardData();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [api, user?.onboarding_completed, user?.timezone]);

  // Обновление при возврате на вкладку (тихо, без спиннера)
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
  
  // Показываем сообщение о генерации плана при новой регистрации
  useEffect(() => {
    if (isNewRegistration || registrationMessage) {
      setShowPlanMessage(true);
    }
  }, [isNewRegistration, registrationMessage]);

  // Pull-to-refresh обработчики
  useEffect(() => {
    const dashboard = dashboardRef.current;
    if (!dashboard) return;

    const handleTouchStart = (e) => {
      // Проверяем, что скролл в самом верху
      if (dashboard.scrollTop === 0) {
        pullStartY.current = e.touches[0].clientY;
        isPulling.current = true;
      }
    };

    const handleTouchMove = (e) => {
      if (!isPulling.current || !pullStartY.current) return;
      
      const currentY = e.touches[0].clientY;
      const deltaY = currentY - pullStartY.current;
      
      if (deltaY > 0 && dashboard.scrollTop === 0) {
        // Ограничиваем максимальное расстояние
        const maxPull = 100;
        const distance = Math.min(deltaY, maxPull);
        setPullDistance(distance);
        
        // Предотвращаем скролл страницы при pull-to-refresh
        if (distance > 10) {
          e.preventDefault();
        }
      } else {
        setPullDistance(0);
        isPulling.current = false;
      }
    };

    const handleTouchEnd = async () => {
      if (pullDistance > 50) {
        // Запускаем обновление
        setRefreshing(true);
        try {
          await loadDashboardData();
        } finally {
          setRefreshing(false);
          setPullDistance(0);
        }
      } else {
        setPullDistance(0);
      }
      
      pullStartY.current = 0;
      isPulling.current = false;
    };

    dashboard.addEventListener('touchstart', handleTouchStart, { passive: true });
    dashboard.addEventListener('touchmove', handleTouchMove, { passive: false });
    dashboard.addEventListener('touchend', handleTouchEnd, { passive: true });

    return () => {
      dashboard.removeEventListener('touchstart', handleTouchStart);
      dashboard.removeEventListener('touchmove', handleTouchMove);
      dashboard.removeEventListener('touchend', handleTouchEnd);
    };
  }, [pullDistance, loadDashboardData]);

  const handleWorkoutPress = useCallback((workout) => {
    if (onNavigate) {
      onNavigate('calendar', { date: workout.date, week: workout.weekNumber, day: workout.dayKey });
    }
  }, [onNavigate]);

  const handleRegeneratePlan = useCallback(async () => {
    if (!api || regenerating) return;
    
    setRegenerating(true);
    setPlanError(null);
    setShowPlanMessage(true);
    
    try {
      const result = await api.regeneratePlan();
      if (result && result.success) {
        // План начал генерироваться, обновляем данные через несколько секунд
        setTimeout(() => {
          loadDashboardData();
        }, 5000);
      } else {
        setPlanError(result?.error || 'Ошибка при запуске генерации плана');
        setShowPlanMessage(false);
        clearPlanMessage();
      }
    } catch (error) {
      setPlanError(error.message || 'Ошибка при запуске генерации плана');
      setShowPlanMessage(false);
      clearPlanMessage();
    } finally {
      setRegenerating(false);
    }
  }, [api, regenerating, loadDashboardData]);

  const progressPercentage = useMemo(() => {
    return weekProgress.total > 0 
      ? Math.round((weekProgress.completed / weekProgress.total) * 100) 
      : 0;
  }, [weekProgress]);

  /** Строки дашборда из displayLayout (на мобильных всегда по одному блоку в строку) */
  const dashboardRows = useMemo(
    () => displayLayout.map((row) => ({
      type: row.length === 2 ? 'double' : 'single',
      ids: row,
    })),
    [displayLayout]
  );

  const moduleOrder = useMemo(() => layoutToOrder(layout), [layout]);

  if (needsOnboarding) {
    return (
      <div className="dashboard dashboard-empty-onboarding">
        <div className="dashboard-empty-onboarding-inner">
          <div className="dashboard-empty-onboarding-icon">🏃</div>
          <h1 className="dashboard-empty-onboarding-title">Добро пожаловать в PlanRun</h1>
          <p className="dashboard-empty-onboarding-text">
            Выберите режим тренировок, цель и заполните профиль — после этого здесь появится ваш план и прогресс.
          </p>
          <button
            type="button"
            className="dashboard-empty-onboarding-btn"
            onClick={() => setShowOnboardingModal(true)}
          >
            Настроить план
          </button>
        </div>
      </div>
    );
  }

  if (loading) {
    return (
      <div className="dashboard">
        <SkeletonScreen type="dashboard" />
      </div>
    );
  }

  return (
    <div className="dashboard" ref={dashboardRef}>
      {/* Уведомление об ошибке генерации плана */}
      {planError && (
        <div className="plan-generation-notice plan-generation-notice--error">
          <div className="plan-generation-notice__icon">⚠️</div>
          <h3 className="plan-generation-notice__title">Ошибка генерации плана</h3>
          <p className="plan-generation-notice__message">{planError}</p>
          <button
            type="button"
            className="plan-generation-notice__btn"
            onClick={handleRegeneratePlan}
            disabled={regenerating}
          >
            {regenerating ? 'Генерируется...' : 'Сгенерировать план заново'}
          </button>
        </div>
      )}

      {/* Уведомление о генерации плана */}
      {(showPlanMessage || registrationMessage) && !planExists && !planError && (
        <div className="plan-generation-notice plan-generation-notice--generating">
          <div className="plan-generation-notice__icon">🤖</div>
          <h3 className="plan-generation-notice__title">План тренировок генерируется</h3>
          <p className="plan-generation-notice__message">
            {registrationMessage || 'План тренировок генерируется через PlanRun AI. Это займет 3-5 минут.'}
          </p>
          <div className="plan-generation-notice__spinner-row">
            <div className="spinner-dash" />
            <span>Ожидайте...</span>
          </div>
          <button
            type="button"
            className="plan-generation-notice__btn"
            onClick={() => loadDashboardData()}
          >
            Проверить готовность
          </button>
        </div>
      )}
      
      {pullDistance > 0 && (
        <div 
          className="pull-to-refresh-indicator"
          style={{ 
            transform: `translateY(${Math.min(pullDistance, 100)}px)`,
            opacity: Math.min(pullDistance / 50, 1)
          }}
        >
          {pullDistance > 50 ? (
            <span>Отпустите для обновления</span>
          ) : (
            <span>Потяните для обновления</span>
          )}
        </div>
      )}
      
      {refreshing && (
        <div className="refreshing-indicator">
          <div className="spinner"></div>
          <span>Обновление...</span>
        </div>
      )}

      <div className="dashboard-header">
        <div className="dashboard-header-row">
          <div>
            <h1 className="dashboard-greeting">
              Привет{user?.name ? `, ${user.name}` : ''}! 👋
            </h1>
            <p className="dashboard-date">
              {new Date().toLocaleDateString('ru-RU', {
                weekday: 'long',
                day: 'numeric',
                month: 'long'
              })}
            </p>
          </div>
          <button
            type="button"
            className="dashboard-customize-btn"
            onClick={() => setCustomizerOpen(true)}
            aria-label="Настроить виджеты дашборда"
          >
            Виджеты
          </button>
        </div>
      </div>

      {dashboardRows.map((row, rowIndex) => {
        const renderSection = (moduleId) => {
          const sectionClass = row.type === 'double' ? 'dashboard-section dashboard-section-inline' : 'dashboard-section';
          if (moduleId === 'today_workout') {
            return (
              <div key="today_workout" className={sectionClass}>
                <h2 className="section-title">Сегодняшняя тренировка</h2>
                <div className={`dashboard-module-card ${todayWorkout ? 'dashboard-module-card--workout' : ''} ${todayWorkout && expandedWorkoutCard === 'today' ? 'dashboard-module-card--expanded' : ''}`}>
                  {!hasAnyPlannedWorkout ? (
                    <div className="dashboard-top-card dashboard-empty">
                      <div className="empty-icon">📅</div>
                      <div className="empty-text">Кажется, у вас нет ни одной тренировки</div>
                      <div className="empty-subtext">Перейдите в календарь и запланируйте тренировку</div>
                      {onNavigate && (
                        <button
                          type="button"
                          className="btn btn-primary dashboard-empty-btn"
                          style={{ marginTop: '12px' }}
                          onClick={() => onNavigate('calendar')}
                        >
                          Открыть календарь
                        </button>
                      )}
                    </div>
                  ) : todayWorkout ? (
                    <div
                      className="dashboard-workout-card-wrapper"
                      role="button"
                      tabIndex={0}
                      onClick={() => handleWorkoutPress(todayWorkout)}
                      onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); handleWorkoutPress(todayWorkout); } }}
                    >
                      <div className="dashboard-top-card">
                        <WorkoutCard
                          workout={todayWorkout}
                          date={todayWorkout.date}
                          status={progressDataMap[todayWorkout.date] ? 'completed' : 'planned'}
                          isToday={true}
                          compact={row.type === 'double' ? (expandedWorkoutCard !== 'today') : false}
                          planDays={row.type === 'single' ? (todayWorkout.planDays || []) : (expandedWorkoutCard === 'today' ? (todayWorkout.planDays || []) : ((todayWorkout.planDays?.length > 1) ? (todayWorkout.planDays.slice(0, 1)) : (todayWorkout.planDays || [])))}
                          maxDescriptionItems={row.type === 'double' && expandedWorkoutCard !== 'today' ? 3 : null}
                          extraActions={
                            <>
                              {!progressDataMap[todayWorkout.date] && (row.type === 'single' || expandedWorkoutCard !== 'today') && (
                                <button
                                  type="button"
                                  className="btn btn-primary dashboard-workout-mark-done dashboard-workout-open-calendar"
                                  onClick={(e) => { e.stopPropagation(); handleWorkoutPress(todayWorkout); }}
                                >
                                  Отметить выполнение
                                </button>
                              )}
                              {((row.type === 'single' && progressDataMap[todayWorkout.date]) || (row.type === 'double' && expandedWorkoutCard === 'today')) && (
                                <button
                                  type="button"
                                  className="btn btn-primary dashboard-workout-open-calendar"
                                  onClick={(e) => { e.stopPropagation(); handleWorkoutPress(todayWorkout); }}
                                >
                                  {progressDataMap[todayWorkout.date] ? 'Открыть в календаре' : 'Отметить выполнение'}
                                </button>
                              )}
                              {row.type === 'double' && (todayWorkout.planDays?.length > 1 || expandedWorkoutCard === 'today') && (
                                <button
                                  type="button"
                                  className="dashboard-workout-expand-arrow"
                                  onClick={(e) => { e.stopPropagation(); setExpandedWorkoutCard((p) => (p === 'today' ? null : 'today')); }}
                                  aria-label={expandedWorkoutCard === 'today' ? 'Свернуть' : 'Развернуть'}
                                >
                                  <span className="dashboard-workout-expand-arrow-icon">▼</span>
                                  {(todayWorkout.planDays?.length > 1) && expandedWorkoutCard !== 'today' && (
                                    <span className="dashboard-workout-expand-hint">Ещё {todayWorkout.planDays.length - 1}</span>
                                  )}
                                </button>
                              )}
                            </>
                          }
                        />
                      </div>
                    </div>
                  ) : (
                    <div className="dashboard-top-card dashboard-empty">
                      <div className="empty-icon">📅</div>
                      <div className="empty-text">Сегодня день отдыха</div>
                      <div className="empty-subtext">Отдых — важная часть тренировочного процесса</div>
                    </div>
                  )}
                </div>
              </div>
            );
          }
          if (moduleId === 'quick_metrics') {
            return (
              <div key="quick_metrics" className={sectionClass}>
                <h2 className="section-title">Быстрые метрики</h2>
                <div className="dashboard-module-card dashboard-module-card--metrics">
                <div className={`dashboard-metrics-grid ${hasAnyPlannedWorkout ? 'dashboard-metrics-grid--with-progress' : ''}`}>
                {hasAnyPlannedWorkout ? (
                  <div className="metric-card metric-card--progress">
                    <div className="metric-card__value metric-card__value--progress">
                      <div className="progress-card-head">
                        <p className="progress-value" aria-label={`Выполнено ${weekProgress.completed} из ${weekProgress.total} тренировок`}>
                          <span className="progress-value-current">{weekProgress.completed}</span>
                          <span className="progress-value-sep"> из </span>
                          <span className="progress-value-total">{weekProgress.total}</span>
                        </p>
                        <p className="progress-subtitle">тренировок за неделю</p>
                      </div>
                      <div className="progress-bar-wrap">
                        <div className="progress-bar" role="progressbar" aria-valuenow={progressPercentage} aria-valuemin={0} aria-valuemax={100} title={`${progressPercentage}%`}>
                          <div className="progress-bar-fill" style={{ width: `${progressPercentage}%` }} />
                        </div>
                        <span className="progress-percentage">{progressPercentage}%</span>
                      </div>
                    </div>
                  </div>
                ) : null}
                  <div className="metric-card">
                    <div className="metric-card__label">
                      <MetricDistanceIcon className="metric-card__icon" />
                      <span>Дистанция</span>
                    </div>
                    <div className="metric-card__value">
                      <span className="metric-card__number">{metrics.distance}</span>
                      <span className="metric-card__unit">км</span>
                    </div>
                  </div>
                  <div className="metric-card">
                    <div className="metric-card__label">
                      <MetricActivityIcon className="metric-card__icon" />
                      <span>Активность</span>
                    </div>
                    <div className="metric-card__value">
                      <span className="metric-card__number">{metrics.workouts}</span>
                      <span className="metric-card__unit">тренировок</span>
                    </div>
                  </div>
                  <div className="metric-card">
                    <div className="metric-card__label">
                      <MetricTimeIcon className="metric-card__icon" />
                      <span>Время</span>
                    </div>
                    <div className="metric-card__value">
                      <span className="metric-card__number">{metrics.time}</span>
                      <span className="metric-card__unit">часов</span>
                    </div>
                  </div>
                </div>
                </div>
              </div>
            );
          }
          if (moduleId === 'next_workout') {
            return (
              <div key="next_workout" className={sectionClass}>
                <h2 className="section-title">Следующая тренировка</h2>
                <div className={`dashboard-module-card ${nextWorkout ? 'dashboard-module-card--workout' : ''} ${nextWorkout && expandedWorkoutCard === 'next' ? 'dashboard-module-card--expanded' : ''}`}>
                {nextWorkout ? (
                  <div
                    className="dashboard-workout-card-wrapper"
                    role="button"
                    tabIndex={0}
                    onClick={() => handleWorkoutPress(nextWorkout)}
                    onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); handleWorkoutPress(nextWorkout); } }}
                  >
                    <div className="dashboard-top-card">
                      <WorkoutCard
                        workout={nextWorkout}
                        date={nextWorkout.date}
                        status="planned"
                        compact={row.type === 'double' ? (expandedWorkoutCard !== 'next') : false}
                        planDays={row.type === 'single' ? (nextWorkout.planDays || []) : (expandedWorkoutCard === 'next' ? (nextWorkout.planDays || []) : ((nextWorkout.planDays?.length > 1) ? (nextWorkout.planDays.slice(0, 1)) : (nextWorkout.planDays || [])))}
                        maxDescriptionItems={row.type === 'double' && expandedWorkoutCard !== 'next' ? 3 : null}
                        extraActions={
                          <>
                            {(row.type === 'single' || expandedWorkoutCard !== 'next') && (
                              <button
                                type="button"
                                className="btn btn-primary dashboard-workout-mark-done dashboard-workout-open-calendar"
                                onClick={(e) => { e.stopPropagation(); handleWorkoutPress(nextWorkout); }}
                              >
                                Открыть в календаре
                              </button>
                            )}
                            {row.type === 'double' && (nextWorkout.planDays?.length > 1 || expandedWorkoutCard === 'next') && (
                              <button
                                type="button"
                                className="dashboard-workout-expand-arrow"
                                onClick={(e) => { e.stopPropagation(); setExpandedWorkoutCard((p) => (p === 'next' ? null : 'next')); }}
                                aria-label={expandedWorkoutCard === 'next' ? 'Свернуть' : 'Развернуть'}
                              >
                                <span className="dashboard-workout-expand-arrow-icon">▼</span>
                                {(nextWorkout.planDays?.length > 1) && expandedWorkoutCard !== 'next' && (
                                  <span className="dashboard-workout-expand-hint">Ещё {nextWorkout.planDays.length - 1}</span>
                                )}
                              </button>
                            )}
                          </>
                        }
                      />
                    </div>
                  </div>
                ) : (
                  <div className="dashboard-top-card dashboard-empty">
                    <div className="empty-icon">⏭️</div>
                    <div className="empty-text">Нет запланированных тренировок</div>
                    <div className="empty-subtext">Добавьте план или откройте календарь</div>
                  </div>
                )}
                </div>
              </div>
            );
          }
          if (moduleId === 'calendar') {
            return (
              <div key="calendar" className={sectionClass}>
                <h2 className="section-title">Календарь</h2>
                <div className="dashboard-module-card">
                <DashboardWeekStrip
                  plan={plan}
                  progressDataMap={progressDataMap}
                  onNavigate={onNavigate}
                />
                </div>
              </div>
            );
          }
          if (moduleId === 'stats') {
            return (
              <div key="stats" className={sectionClass}>
                <h2 className="section-title">Статистика</h2>
                <div className="dashboard-module-card">
                <DashboardStatsWidget api={api} onNavigate={onNavigate} />
                </div>
              </div>
            );
          }
          return null;
        };

        if (row.type === 'double') {
          return (
            <div key={`row-${rowIndex}`} className="dashboard-row-two">
              {row.ids.map((id) => renderSection(id))}
            </div>
          );
        }
        return (
          <React.Fragment key={`row-${rowIndex}`}>
            {row.ids.map((id) => renderSection(id))}
          </React.Fragment>
        );
      })}

      {customizerOpen && (
        <div className="dashboard-customizer-overlay" onClick={() => setCustomizerOpen(false)} role="presentation">
          <div
            className={`dashboard-customizer ${activeDragId ? 'dashboard-customizer--dragging' : ''}`}
            onClick={(e) => e.stopPropagation()}
          >
            <div className="dashboard-customizer-header">
              <h3>Блоки дашборда</h3>
              <button type="button" className="dashboard-customizer-close" onClick={() => setCustomizerOpen(false)} aria-label="Закрыть">×</button>
            </div>
            <p className="dashboard-customizer-hint">
              {isMobileView
                ? 'Удерживайте блок ~0.3 сек, затем перетащите. По одному в строку.'
                : 'Перетаскивайте для порядка. Бросьте на блок — в одну строку; на полоску — на всю ширину.'}
            </p>
            <DndContext
              sensors={customizerSensors}
              onDragStart={handleDndDragStart}
              onDragEnd={handleDndDragEndWithCleanup}
            >
              <div className="dashboard-customizer-list">
                {displayLayout.map((row, rowIndex) => (
                  <React.Fragment key={`row-${rowIndex}`}>
                    <CustomizerStripZone rowIndex={rowIndex} />
                    <CustomizerRow
                      row={row}
                      rowIndex={rowIndex}
                      layout={displayLayout}
                      setLayout={setLayout}
                      saveLayout={saveLayout}
                      isMobileView={isMobileView}
                    />
                  </React.Fragment>
                ))}
                {displayLayout.length > 0 && <CustomizerStripZone rowIndex={displayLayout.length} />}
              </div>
              <DragOverlay dropAnimation={null}>
                {draggedModuleId ? (
                  <CustomizerItemPreview moduleId={draggedModuleId} />
                ) : null}
              </DragOverlay>
            </DndContext>
            {moduleOrder.length < DASHBOARD_MODULE_IDS.length && (
              <div className="dashboard-customizer-add">
                <label htmlFor="dashboard-add-select">Добавить блок:</label>
                <select
                  id="dashboard-add-select"
                  value=""
                  onChange={(e) => {
                    const id = e.target.value;
                    if (!id) return;
                    const next = layout.concat([[id]]);
                    setLayout(next);
                    saveLayout(next);
                    e.target.value = '';
                  }}
                >
                  <option value="">— выберите —</option>
                  {DASHBOARD_MODULE_IDS.filter((id) => !moduleOrder.includes(id)).map((id) => (
                    <option key={id} value={id}>{DASHBOARD_MODULE_LABELS[id]}</option>
                  ))}
                </select>
              </div>
            )}
          </div>
        </div>
      )}

    </div>
  );
};

export default Dashboard;
