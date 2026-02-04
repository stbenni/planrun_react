/**
 * Dashboard - Главный экран в стиле OMY! Sports
 * Показывает сегодняшнюю тренировку, прогресс недели и быстрые метрики
 * Поддерживает pull-to-refresh для обновления данных
 */

import React, { useState, useEffect, useRef, useCallback, useMemo } from 'react';
import WorkoutCard from '../Calendar/WorkoutCard';
import Notifications from '../common/Notifications';
import './Dashboard.css';

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
  const [showPlanMessage, setShowPlanMessage] = useState(false);
  const [planError, setPlanError] = useState(null);
  const [regenerating, setRegenerating] = useState(false);
  const dashboardRef = useRef(null);
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
        console.log('Plan status:', planStatus);
        
        // API может вернуть success: true с error в ответе (это нормально для check_plan_status)
        if (planStatus && (planStatus.error || (!planStatus.has_plan && planStatus.error))) {
          console.log('Plan error found:', planStatus.error);
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

      <div className="dashboard-top-section">
        {todayWorkout ? (
          <div className="dashboard-section">
            <h2 className="section-title">📅 Сегодняшняя тренировка</h2>
            <WorkoutCard
              workout={todayWorkout}
              date={todayWorkout.date}
              status={progressDataMap[todayWorkout.date] ? 'completed' : 'planned'}
              isToday={true}
              onPress={() => handleWorkoutPress(todayWorkout)}
            />
          </div>
        ) : (
          <div className="dashboard-section">
            <div className="dashboard-empty">
              <div className="empty-icon">📅</div>
              <div className="empty-text">Сегодня день отдыха</div>
              <div className="empty-subtext">Отдых — важная часть тренировочного процесса</div>
            </div>
          </div>
        )}

        <div className="dashboard-section">
          <h2 className="section-title">📊 Прогресс недели</h2>
          <div className="progress-card">
            <div className="progress-header">
              <span className="progress-label">Выполнено тренировок</span>
              <span className="progress-value">{weekProgress.completed} / {weekProgress.total}</span>
            </div>
            <div className="progress-bar">
              <div 
                className="progress-bar-fill"
                style={{ width: `${progressPercentage}%` }}
              />
            </div>
            <div className="progress-percentage">{progressPercentage}%</div>
          </div>
        </div>
      </div>

      <div className="dashboard-section">
        <h2 className="section-title">⚡ Быстрые метрики</h2>
        <div className="metrics-grid">
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

      {nextWorkout && (
        <div className="dashboard-section">
          <h2 className="section-title">⏭️ Следующая тренировка</h2>
          <WorkoutCard
            workout={nextWorkout}
            date={nextWorkout.date}
            status="planned"
            compact={true}
            onPress={() => handleWorkoutPress(nextWorkout)}
          />
        </div>
      )}

    </div>
  );
};

export default Dashboard;
