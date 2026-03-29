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
  pointerWithin,
  rectIntersection,
  useSensor,
  useSensors,
} from '@dnd-kit/core';
import useAuthStore from '../../stores/useAuthStore';
import usePlanStore from '../../stores/usePlanStore';
import WorkoutCard from '../Calendar/WorkoutCard';
import DashboardWeekStrip from './DashboardWeekStrip';
import DashboardStatsWidget from './DashboardStatsWidget';
import { MetricDistanceIcon, MetricActivityIcon, MetricTimeIcon } from './DashboardMetricIcons';
import { DASHBOARD_MODULE_IDS, DASHBOARD_MODULE_LABELS } from './dashboardConfig';
import { toLocalDateString } from './dashboardDateUtils';
import { expandLayoutForMobile, getDefaultLayout, getStoredLayout, layoutExpandSlot, layoutInsertRow, layoutMergeIntoRow, layoutRemoveId, layoutToOrder, orderToLayout, saveLayout } from './dashboardLayout';
import { useDashboardPullToRefresh } from './useDashboardPullToRefresh';
import { useDashboardData } from './useDashboardData';
import SkeletonScreen from '../common/SkeletonScreen';
import { RunningIcon, BotIcon, AlertTriangleIcon, CalendarIcon, SkipForwardIcon, CloseIcon } from '../common/Icons';
import RacePredictionWidget from './RacePredictionWidget';
import TrainingLoadWidget from './TrainingLoadWidget';
import './Dashboard.css';

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
function CustomizerDraggableItem({ rowIndex, slotIndex, moduleId, onRemove, mergeActive = false }) {
  const id = `slot-${rowIndex}-${slotIndex}`;
  const { attributes, listeners, setNodeRef, isDragging } = useDraggable({ id });
  return (
    <div
      ref={setNodeRef}
      className={`dashboard-customizer-item ${isDragging ? 'dragging' : ''} ${mergeActive ? 'dashboard-customizer-merge-active' : ''}`}
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
          <CloseIcon className="modal-close-icon" />
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
            mergeActive={showMerge}
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

function isAiPlanMode(trainingMode) {
  return trainingMode === 'ai' || trainingMode === 'both';
}

const Dashboard = ({ api, user, isTabActive = true, onNavigate, registrationMessage, isNewRegistration }) => {
  const setShowOnboardingModal = useAuthStore((s) => s.setShowOnboardingModal);
  const setPlanGenerationMessage = useAuthStore((s) => s.setPlanGenerationMessage);
  const needsOnboarding = !!(user && !user.onboarding_completed);

  const clearPlanMessage = useCallback(() => {
    setPlanGenerationMessage(null);
  }, [setPlanGenerationMessage]);

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

  const customizerCollisionDetection = useCallback((args) => {
    const pointerCollisions = pointerWithin(args);
    if (pointerCollisions.length > 0) return pointerCollisions;
    return rectIntersection(args);
  }, []);

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
        const mergeAt = fromRow < targetRow && currentLayout[fromRow]?.length === 1 ? targetRow - 1 : targetRow;
        const next = layoutMergeIntoRow(without, mergeAt, id);
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

  useEffect(() => {
    if (customizerOpen) return;
    setActiveDragId(null);
  }, [customizerOpen]);

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
  const {
    hasAnyPlannedWorkout,
    handleRegeneratePlan,
    isAiTrainingMode,
    loadDashboardData,
    loading,
    metrics,
    nextWorkout,
    noPlanChecked,
    plan,
    planError,
    planExists,
    planGenerating,
    progressDataMap,
    progressPercentage,
    regenerating,
    showPlanMessage,
    todayWorkout,
    weekProgress,
  } = useDashboardData({
    api,
    clearPlanMessage,
    isNewRegistration,
    isTabActive,
    registrationMessage,
    user,
  });
  const { refreshing, pullDistance } = useDashboardPullToRefresh(dashboardRef, loadDashboardData);
  const generationLabel = usePlanStore((s) => s.generationLabel);
  const supportsAiPlan = isAiTrainingMode || isAiPlanMode(user?.training_mode);
  const hasPendingPlanNotice = Boolean((showPlanMessage || registrationMessage) && !planExists && !planError);
  const showAiEmptyState = supportsAiPlan && noPlanChecked && !planGenerating && !planError && !planExists && !loading;
  const showAiGenerationNotice = supportsAiPlan && (planGenerating || hasPendingPlanNotice);
  const showManualModeNotice = !supportsAiPlan && hasPendingPlanNotice;

  const handleWorkoutPress = useCallback((workout) => {
    if (onNavigate) {
      onNavigate('calendar', { date: workout.date, week: workout.weekNumber, day: workout.dayKey });
    }
  }, [onNavigate]);

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
          <div className="dashboard-empty-onboarding-icon" aria-hidden><RunningIcon size={64} /></div>
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

  if (loading && !noPlanChecked) {
    return (
      <div className="dashboard">
        <SkeletonScreen type="dashboard" />
      </div>
    );
  }

  if (showAiEmptyState) {
    return (
      <div className="dashboard dashboard-empty-no-plan">
        <div className="dashboard-empty-onboarding-inner">
          <div className="dashboard-empty-onboarding-icon" aria-hidden><CalendarIcon size={64} /></div>
          <h1 className="dashboard-empty-onboarding-title">Создайте план тренировок</h1>
          <p className="dashboard-empty-onboarding-text">
            У вас пока нет плана. Настройте цели и режим тренировок — AI-тренер составит персональный план.
          </p>
          <button
            type="button"
            className="btn btn-primary dashboard-empty-onboarding-btn"
            onClick={() => (needsOnboarding ? setShowOnboardingModal(true) : handleRegeneratePlan())}
            disabled={regenerating}
          >
            {regenerating ? 'Генерация...' : 'Создать план'}
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="dashboard" ref={dashboardRef}>
      {/* Уведомление об ошибке генерации плана */}
      {planError && (
        <div className="plan-generation-notice plan-generation-notice--error">
          <div className="plan-generation-notice__icon" aria-hidden><AlertTriangleIcon size={32} /></div>
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

      {/* Компактная плашка генерации — как в календаре */}
      {showAiGenerationNotice && !planError && (
        <div className="plan-generating-banner">
          <span className="btn-spinner" />
          <span>{generationLabel || 'Генерация плана...'} Это займёт 3-5 минут.</span>
        </div>
      )}

      {showManualModeNotice && !planError && (
        <div className="plan-generation-notice plan-generation-notice--info">
          <div className="plan-generation-notice__icon" aria-hidden><CalendarIcon size={32} /></div>
          <h3 className="plan-generation-notice__title">Календарь готов</h3>
          <p className="plan-generation-notice__message">
            {registrationMessage || 'Добавляйте тренировки на нужные даты, отмечайте выполнение и следите за прогрессом на дашборде.'}
          </p>
          {onNavigate && (
            <button
              type="button"
              className="plan-generation-notice__btn"
              onClick={() => onNavigate('calendar')}
            >
              Открыть календарь
            </button>
          )}
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
              Привет{user?.name ? `, ${user.name}` : ''}!
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
                      <div className="empty-icon" aria-hidden><CalendarIcon size={48} /></div>
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
                      <div className="empty-icon" aria-hidden><CalendarIcon size={48} /></div>
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
                      <span>{metrics.hasWalking ? 'Дистанция (бег + ходьба)' : 'Дистанция'}</span>
                    </div>
                    <div className="metric-card__value">
                      <span className="metric-card__number">{metrics.distance}</span>
                      <span className="metric-card__unit">км</span>
                    </div>
                  </div>
                  <div className="metric-card">
                    <div className="metric-card__label">
                      <MetricActivityIcon className="metric-card__icon" />
                      <span>{metrics.hasWalking ? 'Активности' : 'Тренировки'}</span>
                    </div>
                    <div className="metric-card__value">
                      <span className="metric-card__number">{metrics.workouts}</span>
                      <span className="metric-card__unit">{metrics.hasWalking ? 'активностей' : 'тренировок'}</span>
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
                    <div className="empty-icon" aria-hidden><SkipForwardIcon size={48} /></div>
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
          if (moduleId === 'race_prediction') {
            return (
              <div key="race_prediction" className={sectionClass}>
                <h2 className="section-title">Прогноз на забег</h2>
                <div className="dashboard-module-card">
                  <RacePredictionWidget api={api} compact={row.type === 'double'} />
                </div>
              </div>
            );
          }
          if (moduleId === 'training_load') {
            return (
              <div key="training_load" className={sectionClass}>
                <h2 className="section-title">Тренировочная нагрузка</h2>
                <div className="dashboard-module-card">
                  <TrainingLoadWidget api={api} compact={row.type === 'double'} />
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
              <button type="button" className="dashboard-customizer-close" onClick={() => setCustomizerOpen(false)} aria-label="Закрыть">
                <CloseIcon className="modal-close-icon" />
              </button>
            </div>
            <p className="dashboard-customizer-hint">
              {isMobileView
                ? 'Удерживайте блок ~0.3 сек, затем перетащите. По одному в строку.'
                : 'Перетаскивайте для порядка. Бросьте на блок — в одну строку; на полоску — на всю ширину.'}
            </p>
            <DndContext
              collisionDetection={customizerCollisionDetection}
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
