/**
 * Dashboard - Главный экран в стиле OMY! Sports
 * Показывает сегодняшнюю тренировку, прогресс недели и быстрые метрики
 * Поддерживает pull-to-refresh и настраиваемые блоки (добавить/удалить/порядок)
 */

import React, { useState, useEffect, useRef, useCallback, useMemo } from 'react';
import { flushSync } from 'react-dom';
import WorkoutCard from '../Calendar/WorkoutCard';
import Notifications from '../common/Notifications';
import DashboardWeekStrip from './DashboardWeekStrip';
import DashboardStatsWidget from './DashboardStatsWidget';
import './Dashboard.css';

const DASHBOARD_MODULE_IDS = ['today_workout', 'week_progress', 'quick_metrics', 'next_workout', 'calendar', 'stats'];
const DASHBOARD_MODULE_LABELS = {
  today_workout: 'Сегодняшняя тренировка',
  week_progress: 'Прогресс недели',
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

const PAIRABLE_MODULE_IDS = new Set(['today_workout', 'next_workout', 'week_progress', 'stats']);

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
  return orderToLayout(DASHBOARD_MODULE_IDS.slice());
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

const Dashboard = ({ api, user, onNavigate, registrationMessage, isNewRegistration }) => {
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
  const [showPlanMessage, setShowPlanMessage] = useState(false);
  const [planError, setPlanError] = useState(null);
  const [regenerating, setRegenerating] = useState(false);
  const [layout, setLayout] = useState(getStoredLayout);
  const [customizerOpen, setCustomizerOpen] = useState(false);
  const [draggingSlot, setDraggingSlot] = useState(null);
  const [dropTarget, setDropTarget] = useState(null);
  const dashboardRef = useRef(null);

  const handleModuleDragStart = (e, rowIndex, slotIndex) => {
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('application/json', JSON.stringify({ rowIndex, slotIndex }));
    setDraggingSlot({ rowIndex, slotIndex });
  };

  const handleModuleDragEnd = () => {
    setDraggingSlot(null);
    setDropTarget(null);
  };

  const setDropTargetSync = useCallback((target) => {
    flushSync(() => setDropTarget(target));
  }, []);

  const handleModuleDragOver = (e, target) => {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    if (draggingSlot && target.type === 'insert' && target.rowIndex === draggingSlot.rowIndex && layout[draggingSlot.rowIndex]?.length === 1) return;
    setDropTargetSync(target);
  };

  const handleModuleDragLeave = () => {
    setDropTarget(null);
  };

  const handleModuleDrop = (e, target) => {
    e.preventDefault();
    setDropTarget(null);
    let data;
    try {
      data = JSON.parse(e.dataTransfer.getData('application/json'));
    } catch {
      setDraggingSlot(null);
      return;
    }
    const { rowIndex: fromRow, slotIndex: fromSlot } = data;
    const id = layout[fromRow]?.[fromSlot];
    if (!id) {
      setDraggingSlot(null);
      return;
    }
    if (target.type === 'insert') {
      const without = layoutRemoveId(layout, id);
      const insertAt = fromRow < target.rowIndex && layout[fromRow]?.length === 1 ? target.rowIndex - 1 : target.rowIndex;
      const next = layoutInsertRow(without, insertAt, id);
      setLayout(next);
      saveLayout(next);
    } else if (target.type === 'merge' && layout[target.rowIndex]?.length === 1 && target.rowIndex !== fromRow) {
      const without = layoutRemoveId(layout, id);
      const next = layoutMergeIntoRow(without, target.rowIndex, id);
      setLayout(next);
      saveLayout(next);
    }
    setDraggingSlot(null);
  };

  const handleExpandSlot = (rowIndex, slotIndex) => {
    const next = layoutExpandSlot(layout, rowIndex, slotIndex);
    setLayout(next);
    saveLayout(next);
  };
  const pullStartY = useRef(0);
  const isPulling = useRef(false);

  const loadDashboardData = useCallback(async () => {
    if (!api) {
      setLoading(false);
      return;
    }
    try {
      setLoading(true);
      
      // Проверяем статус плана (включая ошибки)
      try {
        const planStatus = await api.checkPlanStatus();
        // API может вернуть success: true с error в ответе (это нормально для check_plan_status)
        if (planStatus && (planStatus.error || (!planStatus.has_plan && planStatus.error))) {
          setPlanError(planStatus.error);
          setPlanExists(false);
          setShowPlanMessage(false);
          setLoading(false);
          return;
        }
      } catch (error) {
        console.error('Error checking plan status:', error);
        // Продолжаем загрузку плана даже если проверка статуса не удалась
      }
      
      // Загружаем план
      const plan = await api.getPlan();
      if (!plan || !plan.phases) {
        setPlanExists(false);
        setPlanError(null);
        setLoading(false);
        // Если это новая регистрация, показываем сообщение о генерации
        if (isNewRegistration || registrationMessage) {
          setShowPlanMessage(true);
        }
        return;
      }
      
      setPlanExists(true);
      setPlanError(null);
      setShowPlanMessage(false);
      setPlan(plan);

      // Загружаем все результаты ОДИН РАЗ для всех целей
      let allResults = null;
      try {
        allResults = await api.getAllResults();
      } catch (error) {
        console.error('Error loading results:', error);
        allResults = { results: [] };
      }

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

      // Находим сегодняшнюю дату
      const today = new Date();
      today.setHours(0, 0, 0, 0);
      const todayStr = today.toISOString().split('T')[0];

      // Находим тренировку на сегодня
      let foundTodayWorkout = null;
      let foundNextWorkout = null;
      let weekStart = null;
      let weekEnd = null;

      for (const phase of plan.phases) {
        if (!phase.weeks_data) continue;
        
        for (const week of phase.weeks_data) {
          if (!week.start_date || !week.days) continue;
          
          const startDate = new Date(week.start_date);
          startDate.setHours(0, 0, 0, 0);
          
          const endDate = new Date(startDate);
          endDate.setDate(endDate.getDate() + 6);
          endDate.setHours(23, 59, 59, 999);

          // Проверяем, попадает ли сегодня в эту неделю
          if (today >= startDate && today <= endDate) {
            weekStart = startDate;
            weekEnd = endDate;
            
            // Используем ISO-8601 формат дня недели (1=понедельник, 7=воскресенье), как в PHP
            // Это соответствует формату, используемому в day_workouts.php
            const dayOfWeekISO = today.getDay() === 0 ? 7 : today.getDay(); // Преобразуем 0 (воскресенье) в 7
            const dayNamesISO = { 1: 'mon', 2: 'tue', 3: 'wed', 4: 'thu', 5: 'fri', 6: 'sat', 7: 'sun' };
            const dayKey = dayNamesISO[dayOfWeekISO];
            
            const dayData = week.days && week.days[dayKey];
            if (dayData && dayData.type !== 'rest') {
              foundTodayWorkout = {
                ...dayData,
                date: todayStr,
                weekNumber: week.number,
                dayKey
              };
            }
          }

          // Ищем следующую тренировку
          if (!foundNextWorkout && startDate > today) {
            const dayKeys = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
            for (let i = 0; i < 7; i++) {
              const dayKey = dayKeys[i];
              const dayData = week.days && week.days[dayKey];
              if (dayData && dayData.type !== 'rest') {
                const workoutDate = new Date(startDate);
                workoutDate.setDate(startDate.getDate() + i);
                
                foundNextWorkout = {
                  ...dayData,
                  date: workoutDate.toISOString().split('T')[0],
                  weekNumber: week.number,
                  dayKey
                };
                break;
              }
            }
            if (foundNextWorkout) break;
          }
        }
        
        if (foundTodayWorkout && foundNextWorkout) break;
      }

      setTodayWorkout(foundTodayWorkout);
      setNextWorkout(foundNextWorkout);

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

        // Подсчитываем общее количество тренировок в неделе
        for (const phase of plan.phases) {
          if (!phase.weeks_data) continue;
          for (const week of phase.weeks_data) {
            if (!week.days) continue;
            const startDate = new Date(week.start_date);
            startDate.setHours(0, 0, 0, 0);
            const endDate = new Date(startDate);
            endDate.setDate(endDate.getDate() + 6);
            
            if (today >= startDate && today <= endDate) {
              for (const dayData of Object.values(week.days)) {
                if (dayData && dayData.type !== 'rest') {
                  total++;
                }
              }
              break;
            }
          }
        }

        setWeekProgress({ completed, total });
      }

      // Загружаем метрики (используем уже загруженные allResults)
      if (allResults && allResults.results && Array.isArray(allResults.results)) {
        let totalDistance = 0;
        let totalTime = 0;
        let workoutCount = 0;

        // Берем данные за последние 7 дней
        const weekAgo = new Date(today);
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
  }, [api, isNewRegistration, registrationMessage]);

  // Загружаем данные при монтировании
  useEffect(() => {
    if (api) {
      loadDashboardData();
    } else {
      // Если api еще не загружен, устанавливаем loading в false чтобы не показывать вечную загрузку
      setLoading(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [api]); // Запускаем при монтировании и изменении api
  
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
      }
    } catch (error) {
      setPlanError(error.message || 'Ошибка при запуске генерации плана');
      setShowPlanMessage(false);
    } finally {
      setRegenerating(false);
    }
  }, [api, regenerating, loadDashboardData]);

  const progressPercentage = useMemo(() => {
    return weekProgress.total > 0 
      ? Math.round((weekProgress.completed / weekProgress.total) * 100) 
      : 0;
  }, [weekProgress]);

  /** Строки дашборда из layout (каждая строка — 1 или 2 блока) */
  const dashboardRows = useMemo(() => layout.map((row) => ({
    type: row.length === 2 ? 'double' : 'single',
    ids: row,
  })), [layout]);

  const moduleOrder = useMemo(() => layoutToOrder(layout), [layout]);
  const draggedId = draggingSlot != null ? layout[draggingSlot.rowIndex]?.[draggingSlot.slotIndex] : null;

  if (loading) {
    return (
      <div className="dashboard">
        <div className="dashboard-loading">Загрузка...</div>
      </div>
    );
  }

  return (
    <div className="dashboard" ref={dashboardRef}>
      <Notifications api={api} onWorkoutPress={handleWorkoutPress} />
      
      {/* Уведомление об ошибке генерации плана */}
      {planError && (
        <div className="plan-generation-notice" style={{
          margin: '20px',
          padding: '20px',
          backgroundColor: '#fef2f2',
          border: '2px solid #ef4444',
          borderRadius: '12px',
          textAlign: 'center'
        }}>
          <div style={{ fontSize: '48px', marginBottom: '10px' }}>⚠️</div>
          <h3 style={{ margin: '0 0 10px', color: '#dc2626', fontSize: '18px' }}>
            Ошибка генерации плана
          </h3>
          <p style={{ margin: '0 0 15px', color: '#64748b', fontSize: '14px' }}>
            {planError}
          </p>
          <button 
            onClick={handleRegeneratePlan}
            disabled={regenerating}
            style={{
              padding: '12px 24px',
              backgroundColor: '#3b82f6',
              color: 'white',
              border: 'none',
              borderRadius: '8px',
              cursor: regenerating ? 'not-allowed' : 'pointer',
              fontSize: '15px',
              fontWeight: '600',
              opacity: regenerating ? 0.6 : 1
            }}
          >
            {regenerating ? 'Генерируется...' : 'Сгенерировать план заново'}
          </button>
        </div>
      )}

      {/* Уведомление о генерации плана */}
      {(showPlanMessage || registrationMessage) && !planExists && !planError && (
        <div className="plan-generation-notice" style={{
          margin: '20px',
          padding: '20px',
          backgroundColor: '#f0f9ff',
          border: '2px solid #3b82f6',
          borderRadius: '12px',
          textAlign: 'center'
        }}>
          <div style={{ fontSize: '48px', marginBottom: '10px' }}>🤖</div>
          <h3 style={{ margin: '0 0 10px', color: '#1e40af', fontSize: '18px' }}>
            План тренировок генерируется
          </h3>
          <p style={{ margin: '0 0 15px', color: '#64748b', fontSize: '14px' }}>
            {registrationMessage || 'План тренировок генерируется через PlanRun AI. Это займет 3-5 минут.'}
          </p>
          <div style={{ 
            display: 'flex', 
            alignItems: 'center', 
            justifyContent: 'center',
            gap: '10px',
            color: '#64748b',
            fontSize: '13px'
          }}>
            <div className="spinner" style={{ 
              width: '16px', 
              height: '16px', 
              border: '2px solid #e2e8f0',
              borderTop: '2px solid #3b82f6',
              borderRadius: '50%',
              animation: 'spin 1s linear infinite'
            }}></div>
            <span>Ожидайте...</span>
          </div>
          <button 
            onClick={() => {
              setShowPlanMessage(false);
              loadDashboardData();
            }}
            style={{
              marginTop: '15px',
              padding: '8px 16px',
              backgroundColor: '#3b82f6',
              color: 'white',
              border: 'none',
              borderRadius: '8px',
              cursor: 'pointer',
              fontSize: '14px'
            }}
          >
            Обновить
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
            aria-label="Настроить блоки дашборда"
          >
            ⚙️ Настроить
          </button>
        </div>
      </div>

      {dashboardRows.map((row, rowIndex) => {
        const renderSection = (moduleId) => {
          const sectionClass = row.type === 'double' ? 'dashboard-section dashboard-section-inline' : 'dashboard-section';
          if (moduleId === 'today_workout') {
            return (
              <div key="today_workout" className={sectionClass}>
                <h2 className="section-title">📅 Сегодняшняя тренировка</h2>
                {todayWorkout ? (
                  <div className="dashboard-top-card">
                    <WorkoutCard
                      workout={todayWorkout}
                      date={todayWorkout.date}
                      status={progressDataMap[todayWorkout.date] ? 'completed' : 'planned'}
                      isToday={true}
                      compact={true}
                      onPress={() => handleWorkoutPress(todayWorkout)}
                    />
                  </div>
                ) : (
                  <div className="dashboard-top-card dashboard-empty">
                    <div className="empty-icon">📅</div>
                    <div className="empty-text">Сегодня день отдыха</div>
                    <div className="empty-subtext">Отдых — важная часть тренировочного процесса</div>
                  </div>
                )}
              </div>
            );
          }
          if (moduleId === 'week_progress') {
            return (
              <div key="week_progress" className={sectionClass}>
                <h2 className="section-title">📊 Прогресс недели</h2>
                <div className="dashboard-top-card progress-card">
                  <p className="progress-card-desc">Тренировок выполнено из плана на неделю</p>
                  <div className="progress-stat">
                    <span className="progress-label">Выполнено</span>
                    <span className="progress-value">{weekProgress.completed} из {weekProgress.total}</span>
                  </div>
                  <div className="progress-bar-row">
                    <div className="progress-bar" role="progressbar" aria-valuenow={progressPercentage} aria-valuemin={0} aria-valuemax={100} title={`${progressPercentage}%`}>
                      <div className="progress-bar-fill" style={{ width: `${progressPercentage}%` }} />
                    </div>
                    <span className="progress-percentage">{progressPercentage}% плана</span>
                  </div>
                </div>
              </div>
            );
          }
          if (moduleId === 'quick_metrics') {
            return (
              <div key="quick_metrics" className={sectionClass}>
                <h2 className="section-title">⚡ Быстрые метрики</h2>
                <div className="dashboard-metrics-grid">
                  <div className="metric-card">
                    <div className="metric-icon">🏃</div>
                    <div className="metric-content">
                      <div className="metric-value">{metrics.distance}</div>
                      <div className="metric-unit">км</div>
                      <div className="metric-label">За неделю</div>
                    </div>
                  </div>
                  <div className="metric-card">
                    <div className="metric-icon">📅</div>
                    <div className="metric-content">
                      <div className="metric-value">{metrics.workouts}</div>
                      <div className="metric-unit">тренировок</div>
                      <div className="metric-label">За неделю</div>
                    </div>
                  </div>
                  <div className="metric-card">
                    <div className="metric-icon">⏱️</div>
                    <div className="metric-content">
                      <div className="metric-value">{metrics.time}</div>
                      <div className="metric-unit">часов</div>
                      <div className="metric-label">За неделю</div>
                    </div>
                  </div>
                </div>
              </div>
            );
          }
          if (moduleId === 'next_workout') {
            return (
              <div key="next_workout" className={sectionClass}>
                <h2 className="section-title">⏭️ Следующая тренировка</h2>
                {nextWorkout ? (
                  <WorkoutCard
                    workout={nextWorkout}
                    date={nextWorkout.date}
                    status="planned"
                    compact={true}
                    onPress={() => handleWorkoutPress(nextWorkout)}
                  />
                ) : (
                  <div className="dashboard-top-card dashboard-empty">
                    <div className="empty-icon">⏭️</div>
                    <div className="empty-text">Нет запланированных тренировок</div>
                    <div className="empty-subtext">Добавьте план или откройте календарь</div>
                  </div>
                )}
              </div>
            );
          }
          if (moduleId === 'calendar') {
            return (
              <div key="calendar" className={sectionClass}>
                <h2 className="section-title">📅 Календарь</h2>
                <DashboardWeekStrip
                  plan={plan}
                  progressDataMap={progressDataMap}
                  onNavigate={onNavigate}
                />
              </div>
            );
          }
          if (moduleId === 'stats') {
            return (
              <div key="stats" className={sectionClass}>
                <h2 className="section-title">📊 Статистика</h2>
                <DashboardStatsWidget api={api} onNavigate={onNavigate} />
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
          <div className="dashboard-customizer" onClick={(e) => e.stopPropagation()}>
            <div className="dashboard-customizer-header">
              <h3>Блоки дашборда</h3>
              <button type="button" className="dashboard-customizer-close" onClick={() => setCustomizerOpen(false)} aria-label="Закрыть">×</button>
            </div>
            <p className="dashboard-customizer-hint">Перетаскивайте для порядка. Бросьте на блок — в одну строку; на полоску — на всю ширину.</p>
            <div className="dashboard-customizer-list">
              {layout.map((row, rowIndex) => {
                const insertBeforeTarget = { type: 'insert', rowIndex };
                const showStripBefore = dropTarget?.type === 'insert' && dropTarget.rowIndex === rowIndex;
                return (
                  <React.Fragment key={`row-${rowIndex}`}>
                    <div
                      className={`dashboard-customizer-strip-zone ${showStripBefore ? 'dashboard-customizer-strip-zone-active' : ''}`}
                      onDragEnter={(e) => { e.preventDefault(); setDropTargetSync(insertBeforeTarget); }}
                      onDragOver={(e) => handleModuleDragOver(e, insertBeforeTarget)}
                      onDragLeave={handleModuleDragLeave}
                      onDrop={(e) => handleModuleDrop(e, insertBeforeTarget)}
                    >
                      {showStripBefore && <div className="dashboard-customizer-drop-strip dashboard-customizer-drop-strip--full" aria-hidden />}
                    </div>
                    <div
                      className={`dashboard-customizer-row ${row.length === 2 ? 'dashboard-customizer-row-double' : ''} ${row.length === 1 && dropTarget?.type === 'merge' && dropTarget.rowIndex === rowIndex ? 'dashboard-customizer-row-show-merge' : ''}`}
                      onDragEnter={row.length === 1 ? (e) => { e.preventDefault(); setDropTargetSync({ type: 'merge', rowIndex }); } : undefined}
                      onDragOver={row.length === 1 ? (e) => handleModuleDragOver(e, { type: 'merge', rowIndex }) : undefined}
                    >
                      {row.map((id, slotIndex) => {
                        const isDragging = draggingSlot?.rowIndex === rowIndex && draggingSlot?.slotIndex === slotIndex;
                        const mergeTarget = { type: 'merge', rowIndex };
                        const showMerge = row.length === 1 && dropTarget?.type === 'merge' && dropTarget.rowIndex === rowIndex;
                        return (
                          <div key={`${rowIndex}-${slotIndex}-${id}`} className="dashboard-customizer-slot-wrap">
                            <div
                              className={`dashboard-customizer-item ${isDragging ? 'dragging' : ''} ${showMerge ? 'dashboard-customizer-merge-active' : ''}`}
                              draggable
                              onDragStart={(e) => handleModuleDragStart(e, rowIndex, slotIndex)}
                              onDragEnd={handleModuleDragEnd}
                              onDragOver={(e) => row.length === 1 && handleModuleDragOver(e, mergeTarget)}
                              onDrop={(e) => row.length === 1 && handleModuleDrop(e, mergeTarget)}
                            >
                              <span className="dashboard-customizer-drag-handle" aria-hidden title="Перетащите">⋮⋮</span>
                              <span className="dashboard-customizer-label">{DASHBOARD_MODULE_LABELS[id]}</span>
                              <div className="dashboard-customizer-actions">
                                <button type="button" className="dashboard-customizer-remove" onClick={() => {
                                  const next = layoutRemoveId(layout, id);
                                  setLayout(next);
                                  saveLayout(next);
                                }} aria-label="Убрать">✕</button>
                              </div>
                            </div>
                          </div>
                        );
                      })}
                      {row.length === 1 && (
                        <div
                          className={`dashboard-customizer-merge-zone ${dropTarget?.type === 'merge' && dropTarget.rowIndex === rowIndex ? 'dashboard-customizer-merge-zone-active' : ''}`}
                          onDragEnter={(e) => { e.preventDefault(); setDropTargetSync({ type: 'merge', rowIndex }); }}
                          onDragOver={(e) => { e.preventDefault(); handleModuleDragOver(e, { type: 'merge', rowIndex }); }}
                          onDragLeave={handleModuleDragLeave}
                          onDrop={(e) => handleModuleDrop(e, { type: 'merge', rowIndex })}
                        >
                          <span className="dashboard-customizer-merge-label">+ в одну строку</span>
                        </div>
                      )}
                    </div>
                  </React.Fragment>
                );
              })}
              {layout.length > 0 && (() => {
                const insertEndTarget = { type: 'insert', rowIndex: layout.length };
                const showStripEnd = dropTarget?.type === 'insert' && dropTarget.rowIndex === layout.length;
                return (
                  <div
                    className={`dashboard-customizer-strip-zone ${showStripEnd ? 'dashboard-customizer-strip-zone-active' : ''}`}
                    onDragEnter={(e) => { e.preventDefault(); setDropTargetSync(insertEndTarget); }}
                    onDragOver={(e) => handleModuleDragOver(e, insertEndTarget)}
                    onDragLeave={handleModuleDragLeave}
                    onDrop={(e) => handleModuleDrop(e, insertEndTarget)}
                  >
                    {showStripEnd && <div className="dashboard-customizer-drop-strip dashboard-customizer-drop-strip--full" aria-hidden />}
                  </div>
                );
              })()}
            </div>
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
