/**
 * StatsScreen - Экран статистики в стиле Strava
 * Графики, метрики, прогресс, достижения
 */

import React, { useState, useEffect, useCallback, useRef } from 'react';
import { useLocation } from 'react-router-dom';
import useAuthStore from '../stores/useAuthStore';
import usePreloadStore from '../stores/usePreloadStore';
import useWorkoutRefreshStore from '../stores/useWorkoutRefreshStore';
import { useIsTabActive } from '../hooks/useIsTabActive';
import { isNativeCapacitor } from '../services/TokenStorageService';
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
import { MetricDistanceIcon, MetricActivityIcon, MetricTimeIcon, MetricPaceIcon } from '../components/Dashboard/DashboardMetricIcons';
import { BarChartIcon, TrophyIcon, TargetIcon, FlameIcon, OtherIcon } from '../components/common/Icons';
import '../components/Dashboard/Dashboard.css';
import './StatsScreen.css';

const StatsScreen = () => {
  const location = useLocation();
  const isTabActive = useIsTabActive('/stats');
  const preloadTriggered = usePreloadStore((s) => s.preloadTriggered);
  const workoutRefreshVersion = useWorkoutRefreshStore((s) => s.version);
  const { api, user } = useAuthStore();
  const [rawData, setRawData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [timeRange, setTimeRange] = useState('month');
  const [activeTab, setActiveTab] = useState('overview');
  const [workoutModal, setWorkoutModal] = useState({ isOpen: false, date: null, dayData: null, loading: false, selectedWorkoutId: null });

  useEffect(() => {
    const isStats = location.pathname === '/stats' || location.pathname.startsWith('/stats');
    if (!isStats) setWorkoutModal((prev) => (prev.isOpen ? { ...prev, isOpen: false } : prev));
  }, [location.pathname]);

  const loadRawData = useCallback(async (options = {}) => {
    const silent = options.silent === true;
    if (!api || typeof api.getAllWorkoutsSummary !== 'function') {
      setLoading(false);
      return;
    }

    try {
      if (!silent) setLoading(true);
      
      const [summaryRes, listRes, resultsRes, planRes] = await Promise.allSettled([
        api.getAllWorkoutsSummary(),
        api.getAllWorkoutsList(null, 500),
        api.getAllResults(),
        api.getPlan(),
      ]);

      let workoutsData = { workouts: {} };
      if (summaryRes.status === 'fulfilled' && summaryRes.value && typeof summaryRes.value === 'object') {
        const raw = summaryRes.value.data ?? summaryRes.value;
        workoutsData = raw?.workouts != null ? { workouts: raw.workouts } : { workouts: typeof raw === 'object' && !Array.isArray(raw) ? raw : {} };
      }

      let workoutsList = [];
      if (listRes.status === 'fulfilled' && listRes.value && typeof listRes.value === 'object') {
        const raw = listRes.value.data ?? listRes.value;
        workoutsList = Array.isArray(raw?.workouts) ? raw.workouts : [];
      }

      let allResults = { results: [] };
      if (resultsRes.status === 'fulfilled' && resultsRes.value && typeof resultsRes.value === 'object') {
        const r = resultsRes.value;
        allResults = { results: r.results ?? r };
      }

      let plan = null;
      if (planRes.status === 'fulfilled' && planRes.value && typeof planRes.value === 'object') {
        const r = planRes.value;
        plan = r.plan ?? r;
      }

      setRawData({ workoutsData, workoutsList, allResults, plan });
    } catch (error) {
      console.error('Error loading stats:', error);
    } finally {
      setLoading(false);
    }
  }, [api]);

  const stats = React.useMemo(() => {
    if (!rawData) return null;
    const { workoutsData, workoutsList, allResults, plan } = rawData;
    if (activeTab === 'overview') {
      return processStatsData(workoutsData, allResults, plan, timeRange, workoutsList);
    }
    if (activeTab === 'progress') {
      return processProgressData(workoutsData, allResults, plan);
    }
    return processAchievementsData(workoutsData, allResults);
  }, [rawData, activeTab, timeRange]);

  const hasLoadedRef = useRef(false);
  useEffect(() => {
    const isNative = isNativeCapacitor();
    const shouldPreload = isNative && preloadTriggered;
    if (!isTabActive && !hasLoadedRef.current && !shouldPreload) return;
    if (api && typeof api.getAllWorkoutsSummary === 'function') {
      hasLoadedRef.current = true;
      const silent = (shouldPreload && !isTabActive) || !!rawData;
      loadRawData({ silent });
    } else {
      setLoading(false);
    }
  }, [api, isTabActive, preloadTriggered, loadRawData]);

  useEffect(() => {
    if (workoutRefreshVersion <= 0 || !api) return;
    const t = setTimeout(() => loadRawData({ silent: true }), 250);
    return () => clearTimeout(t);
  }, [workoutRefreshVersion, api, loadRawData]);

  const handleWorkoutClick = async (workout) => {
    const date = workout?.start_time ? workout.start_time.split('T')[0] : workout?.date;
    if (!api || !date) return;

    const selectedWorkoutId = workout?.id ?? null;

    const immediateDayData = {
      planDays: [],
      dayExercises: [],
      workouts: [{ ...workout, start_time: workout.start_time || (date + 'T12:00:00') }],
    };
    setWorkoutModal({ isOpen: true, date, dayData: immediateDayData, loading: false, selectedWorkoutId });

    try {
      const response = await api.getDay(date);
      let raw = response;
      if (response && typeof response === 'object' && (response.data != null)) {
        raw = response.data;
      }
      if (raw && typeof raw === 'object') {
        const fullDayData = {
          ...raw,
          planDays: raw.planDays ?? raw.plan_days ?? [],
          dayExercises: raw.dayExercises ?? raw.day_exercises ?? [],
          workouts: raw.workouts ?? [],
        };
        setWorkoutModal((prev) => prev.isOpen && prev.date === date ? { ...prev, dayData: fullDayData } : prev);
      }
    } catch {
      // Мгновенные данные уже отображены — ошибка enrichment не критична
    }
  };

  const handleCloseWorkoutModal = () => {
    setWorkoutModal({ isOpen: false, date: null, dayData: null, loading: false, selectedWorkoutId: null });
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
          <div className="empty-icon" aria-hidden><BarChartIcon size={48} /></div>
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
          <div className="dashboard-module-card stats-metrics-module">
            <div className="dashboard-stats-widget">
              <div className="dashboard-stats-metrics-grid">
                <div className="metric-card">
                  <div className="metric-card__label">
                    <MetricDistanceIcon className="metric-card__icon" />
                    <span>Дистанция</span>
                  </div>
                  <div className="metric-card__value">
                    <span className="metric-card__number">{stats.totalDistance}</span>
                    <span className="metric-card__unit">км</span>
                  </div>
                </div>
                <div className="metric-card">
                  <div className="metric-card__label">
                    <MetricTimeIcon className="metric-card__icon" />
                    <span>Время</span>
                  </div>
                  <div className="metric-card__value">
                    <span className="metric-card__number">{Math.round(stats.totalTime / 60)}</span>
                    <span className="metric-card__unit">часов</span>
                  </div>
                </div>
                <div className="metric-card">
                  <div className="metric-card__label">
                    <MetricActivityIcon className="metric-card__icon" />
                    <span>Активность</span>
                  </div>
                  <div className="metric-card__value">
                    <span className="metric-card__number">{stats.totalWorkouts}</span>
                    <span className="metric-card__unit">тренировок</span>
                  </div>
                </div>
                <div className="metric-card">
                  <div className="metric-card__label">
                    <MetricPaceIcon className="metric-card__icon" />
                    <span>Средний темп</span>
                  </div>
                  <div className="metric-card__value">
                    <span className="metric-card__number">{stats.avgPace}</span>
                    <span className="metric-card__unit">/км</span>
                  </div>
                </div>
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
            <h2 className="section-title">
              <span className="section-title--mobile">Тренировки</span>
              <span className="section-title--desktop">Последние тренировки</span>
            </h2>
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
              Icon={TrophyIcon}
              title="Первая тренировка"
              description="Выполните первую тренировку"
              achieved={stats.totalWorkouts > 0}
            />
            <AchievementCard 
              Icon={TargetIcon}
              title="10 тренировок"
              description="Выполните 10 тренировок"
              achieved={stats.totalWorkouts >= 10}
            />
            <AchievementCard 
              Icon={FlameIcon}
              title="50 км"
              description="Пробегите 50 километров"
              achieved={stats.totalDistance >= 50}
            />
            <AchievementCard 
              Icon={OtherIcon}
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
        selectedWorkoutId={workoutModal.selectedWorkoutId}
        onDelete={() => { handleCloseWorkoutModal(); loadRawData({ silent: true }); }}
      />
    </div>
  );
};

export default StatsScreen;
