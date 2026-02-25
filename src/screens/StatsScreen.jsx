/**
 * StatsScreen - Экран статистики в стиле Strava
 * Графики, метрики, прогресс, достижения
 */

import React, { useState, useEffect, useCallback, useRef } from 'react';
import useAuthStore from '../stores/useAuthStore';
import { useIsTabActive } from '../hooks/useIsTabActive';
import {
  ActivityHeatmap,
  DistanceChart,
  WeeklyProgressChart,
  RecentWorkoutsList,
  AchievementCard,
  WorkoutDetailsModal,
  processStatsData,
  processProgressData,
  processAchievementsData
} from '../components/Stats';
import SkeletonScreen from '../components/common/SkeletonScreen';
import './StatsScreen.css';

const StatsScreen = () => {
  const isTabActive = useIsTabActive('/stats');
  const { api, user } = useAuthStore();
  const [stats, setStats] = useState(null);
  const [loading, setLoading] = useState(true);
  const [timeRange, setTimeRange] = useState('month'); // week, month, quarter, year - только для "Обзор"
  const [activeTab, setActiveTab] = useState('overview'); // overview, progress, achievements
  const [workoutModal, setWorkoutModal] = useState({ isOpen: false, date: null, dayData: null, loading: false });

  const loadStats = useCallback(async () => {
    if (!api) {
      console.warn('StatsScreen: API client is not available');
      setLoading(false);
      setStats(null);
      return;
    }

    if (typeof api.getAllWorkoutsSummary !== 'function' ||
        typeof api.getAllResults !== 'function' ||
        typeof api.getPlan !== 'function') {
      console.error('StatsScreen: API client missing required methods', {
        hasGetAllWorkoutsSummary: typeof api.getAllWorkoutsSummary === 'function',
        hasGetAllResults: typeof api.getAllResults === 'function',
        hasGetPlan: typeof api.getPlan === 'function'
      });
      setLoading(false);
      setStats(null);
      return;
    }

    try {
      setLoading(true);
      
      // Загружаем статистику тренировок
      let workoutsData = null;
      try {
        const response = await api.getAllWorkoutsSummary();
        if (response && typeof response === 'object') {
          if (response.workouts) {
            workoutsData = { workouts: response.workouts };
          } else if (response.success && response.workouts) {
            workoutsData = { workouts: response.workouts };
          } else {
            workoutsData = { workouts: response };
          }
        } else {
          workoutsData = { workouts: {} };
        }
      } catch (error) {
        console.error('Error loading workouts summary:', error);
        workoutsData = { workouts: {} };
      }
      
      // Загружаем все результаты
      let allResults = null;
      try {
        const response = await api.getAllResults();
        if (response && typeof response === 'object') {
          if (response.results) {
            allResults = { results: response.results };
          } else if (response.success && response.results) {
            allResults = { results: response.results };
          } else {
            allResults = { results: response };
          }
        } else {
          allResults = { results: [] };
        }
      } catch (error) {
        console.error('Error loading all results:', error);
        allResults = { results: [] };
      }
      
      // Загружаем план для расчета прогресса
      let plan = null;
      try {
        const response = await api.getPlan();
        if (response && typeof response === 'object') {
          if (response.plan) {
            plan = response.plan;
          } else if (response.success && response.plan) {
            plan = response.plan;
          } else {
            plan = response;
          }
        }
      } catch (error) {
        console.error('Error loading plan:', error);
      }
      
      // Обрабатываем данные в зависимости от активной вкладки
      let processedStats;
      if (activeTab === 'overview') {
        // Для "Обзор" используем выбранный период
        processedStats = processStatsData(workoutsData, allResults, plan, timeRange);
      } else if (activeTab === 'progress') {
        // Для "Прогресс" показываем только данные из плана (без фильтрации по периоду)
        processedStats = processProgressData(workoutsData, allResults, plan);
      } else {
        // Для "Достижения" показываем общие данные (все время)
        processedStats = processAchievementsData(workoutsData, allResults);
      }
      
      setStats(processedStats);
    } catch (error) {
      console.error('Error loading stats:', error);
    } finally {
      setLoading(false);
    }
  }, [api, activeTab, timeRange]);

  const hasLoadedRef = useRef(false);
  useEffect(() => {
    if (!isTabActive && !hasLoadedRef.current) return;
    if (api && typeof api.getAllWorkoutsSummary === 'function') {
      hasLoadedRef.current = true;
      loadStats();
    } else {
      setLoading(false);
    }
  }, [api, isTabActive, loadStats]);

  const handleWorkoutClick = async (date) => {
    if (!api || !date) return;
    
    try {
      setWorkoutModal({ isOpen: true, date, dayData: null, loading: true });
      
      const response = await api.getDay(date);
      let raw = response;
      if (response && typeof response === 'object' && (response.data != null)) {
        raw = response.data;
      }
      const dayData = raw && typeof raw === 'object' ? {
        ...raw,
        planDays: raw.planDays ?? raw.plan_days ?? [],
        dayExercises: raw.dayExercises ?? raw.day_exercises ?? [],
        workouts: raw.workouts ?? []
      } : null;
      setWorkoutModal({ isOpen: true, date, dayData, loading: false });
    } catch (error) {
      console.error('Error loading workout details:', error);
      setWorkoutModal({ isOpen: true, date, dayData: null, loading: false });
    }
  };

  const handleCloseWorkoutModal = () => {
    setWorkoutModal({ isOpen: false, date: null, dayData: null, loading: false });
  };

  if (!api) {
    return (
      <div className="stats-screen">
        <div className="stats-empty">
          <div className="empty-icon">⚠️</div>
          <div className="empty-text">Клиент API не инициализирован</div>
        </div>
      </div>
    );
  }

  if (loading) {
    return (
      <div className="stats-screen">
        <SkeletonScreen type="stats" />
      </div>
    );
  }

  if (!stats) {
    return (
      <div className="stats-screen">
        <div className="stats-empty">
          <div className="empty-icon">📊</div>
          <div className="empty-text">Нет данных для отображения</div>
        </div>
      </div>
    );
  }

  return (
    <div className="stats-screen">
      <div className="stats-tabs">
        <button 
          className={`stats-tab ${activeTab === 'overview' ? 'active' : ''}`}
          onClick={() => setActiveTab('overview')}
        >
          Обзор
        </button>
        <button 
          className={`stats-tab ${activeTab === 'progress' ? 'active' : ''}`}
          onClick={() => setActiveTab('progress')}
        >
          Прогресс
        </button>
        <button 
          className={`stats-tab ${activeTab === 'achievements' ? 'active' : ''}`}
          onClick={() => setActiveTab('achievements')}
        >
          Достижения
        </button>
      </div>

      {activeTab === 'overview' && (
        <div className="stats-content">
          {/* Выбор периода только для "Обзор" */}
          <div className="stats-time-range">
            <button 
              className={`time-range-btn ${timeRange === 'week' ? 'active' : ''}`}
              onClick={() => setTimeRange('week')}
            >
              Эта неделя
            </button>
            <button 
              className={`time-range-btn ${timeRange === 'month' ? 'active' : ''}`}
              onClick={() => setTimeRange('month')}
            >
              Месяц
            </button>
            <button 
              className={`time-range-btn ${timeRange === 'quarter' ? 'active' : ''}`}
              onClick={() => setTimeRange('quarter')}
            >
              3 мес
            </button>
            <button 
              className={`time-range-btn ${timeRange === 'year' ? 'active' : ''}`}
              onClick={() => setTimeRange('year')}
            >
              Год
            </button>
          </div>
          <div className="stats-metrics-grid">
            <div className="dashboard-stat-metric-card">
              <div className="dashboard-stat-metric-card__label">
                <span className="dashboard-stat-metric-card__icon" aria-hidden>🏃</span>
                Дистанция
              </div>
              <div className="dashboard-stat-metric-card__value">
                <span className="dashboard-stat-metric-card__number">{stats.totalDistance}</span>
                <span className="dashboard-stat-metric-card__unit">км</span>
              </div>
            </div>
            <div className="dashboard-stat-metric-card">
              <div className="dashboard-stat-metric-card__label">
                <span className="dashboard-stat-metric-card__icon" aria-hidden>⏱️</span>
                Время
              </div>
              <div className="dashboard-stat-metric-card__value">
                <span className="dashboard-stat-metric-card__number">{Math.round(stats.totalTime / 60)}</span>
                <span className="dashboard-stat-metric-card__unit">часов</span>
              </div>
            </div>
            <div className="dashboard-stat-metric-card">
              <div className="dashboard-stat-metric-card__label">
                <span className="dashboard-stat-metric-card__icon" aria-hidden>📅</span>
                Активность
              </div>
              <div className="dashboard-stat-metric-card__value">
                <span className="dashboard-stat-metric-card__number">{stats.totalWorkouts}</span>
                <span className="dashboard-stat-metric-card__unit">тренировок</span>
              </div>
            </div>
            <div className="dashboard-stat-metric-card">
              <div className="dashboard-stat-metric-card__label">
                <span className="dashboard-stat-metric-card__icon" aria-hidden>📍</span>
                Средний темп
              </div>
              <div className="dashboard-stat-metric-card__value">
                <span className="dashboard-stat-metric-card__number">{stats.avgPace}</span>
                <span className="dashboard-stat-metric-card__unit">/км</span>
              </div>
            </div>
          </div>

          <div className="stats-chart-section">
            <h2 className="section-title">График активности</h2>
            {/* Heatmap для мобильных, столбчатый график для десктопов */}
            <div className="chart-mobile">
              <ActivityHeatmap data={stats.chartData} />
            </div>
            <div className="chart-desktop">
              <DistanceChart data={stats.chartData} />
            </div>
          </div>

          <div className="stats-recent-workouts">
            <h2 className="section-title">Последние тренировки</h2>
            <RecentWorkoutsList 
              workouts={stats.workouts} 
              api={api}
              onWorkoutClick={handleWorkoutClick}
            />
          </div>
        </div>
      )}

      {activeTab === 'progress' && (
        <div className="stats-content">
          {stats.planProgress && (
            <div className="plan-progress-card">
              <h2 className="section-title">Прогресс по плану</h2>
              <div className="progress-info">
                <div className="progress-stats">
                  <span className="progress-value">{stats.planProgress.completed}</span>
                  <span className="progress-separator">/</span>
                  <span className="progress-total">{stats.planProgress.total}</span>
                </div>
                <div className="progress-percentage">{stats.planProgress.percentage}%</div>
              </div>
              <div className="progress-bar-large">
                <div 
                  className="progress-bar-fill-large"
                  style={{ width: `${stats.planProgress.percentage}%` }}
                />
              </div>
            </div>
          )}
          
          {stats.chartData && stats.chartData.length > 0 && (
            <div className="stats-chart-section">
              <h2 className="section-title">Прогресс по неделям</h2>
              <WeeklyProgressChart data={stats.chartData} />
            </div>
          )}
        </div>
      )}

      {activeTab === 'achievements' && (
        <div className="stats-content">
          <div className="achievements-grid">
            <AchievementCard 
              icon="🏆"
              title="Первая тренировка"
              description="Выполните первую тренировку"
              achieved={stats.totalWorkouts > 0}
            />
            <AchievementCard 
              icon="🎯"
              title="10 тренировок"
              description="Выполните 10 тренировок"
              achieved={stats.totalWorkouts >= 10}
            />
            <AchievementCard 
              icon="🔥"
              title="50 км"
              description="Пробегите 50 километров"
              achieved={stats.totalDistance >= 50}
            />
            <AchievementCard 
              icon="💪"
              title="100 км"
              description="Пробегите 100 километров"
              achieved={stats.totalDistance >= 100}
            />
          </div>
        </div>
      )}
      
      <WorkoutDetailsModal
        isOpen={workoutModal.isOpen}
        onClose={handleCloseWorkoutModal}
        date={workoutModal.date}
        dayData={workoutModal.dayData}
        loading={workoutModal.loading}
      />
    </div>
  );
};

export default StatsScreen;
