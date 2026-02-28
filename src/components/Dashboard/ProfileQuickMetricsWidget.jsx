/**
 * Виджет быстрых метрик для профиля пользователя
 * Показывает дистанцию, активность и время за последние 7 дней
 * Структура как на дашборде: прогресс «X из Y» + прогресс-бар + 3 карточки
 */

import React, { useState, useEffect, useCallback } from 'react';
import { processStatsData } from '../Stats/StatsUtils';
import { MetricDistanceIcon, MetricActivityIcon, MetricTimeIcon } from './DashboardMetricIcons';
import '../Dashboard/Dashboard.css';

const ProfileQuickMetricsWidget = ({ api, viewContext = null, plan = null, progressDataMap = null, weekProgress = { completed: 0, total: 0 } }) => {
  const [stats, setStats] = useState(null);
  const [loading, setLoading] = useState(true);

  const loadStats = useCallback(async () => {
    if (!api) {
      setLoading(false);
      return;
    }
    try {
      setLoading(true);
      let workoutsData = { workouts: {} };
      let allResults = { results: [] };
      let plan = null;
      const vc = viewContext || undefined;
      try {
        const w = await api.getAllWorkoutsSummary(vc);
        if (w && typeof w === 'object') {
          const raw = w.data ?? w;
          workoutsData = raw?.workouts != null ? { workouts: raw.workouts } : { workouts: typeof raw === 'object' && !Array.isArray(raw) ? raw : {} };
        }
      } catch (e) { /* ignore */ }
      try {
        const r = await api.getAllResults(vc);
        if (r && typeof r === 'object') {
          const raw = r.data ?? r;
          const list = Array.isArray(raw) ? raw : raw?.results;
          allResults = { results: Array.isArray(list) ? list : [] };
        }
      } catch (e) { /* ignore */ }
      try {
        plan = await api.getPlan(null, vc);
      } catch (e) { /* ignore */ }

      const processed = processStatsData(workoutsData, allResults, plan, 'last7days');
      setStats(processed);
    } catch (e) {
      console.error('ProfileQuickMetricsWidget load error', e);
      setStats(null);
    } finally {
      setLoading(false);
    }
  }, [api, viewContext]);

  useEffect(() => {
    loadStats();
  }, [loadStats]);

  if (loading && !stats) {
    return (
      <div className="dashboard-module-card dashboard-module-card--metrics">
        <div className="profile-quick-metrics-loading">Загрузка...</div>
      </div>
    );
  }

  const s = stats || {
    totalDistance: 0,
    totalTime: 0,
    totalWorkouts: 0,
  };

  const metrics = {
    distance: s.totalDistance ?? 0,
    workouts: s.totalWorkouts ?? 0,
    time: Math.round((s.totalTime ?? 0) / 60),
  };

  const hasAnyPlannedWorkout = (weekProgress?.total ?? 0) > 0;
  const progressPercentage = hasAnyPlannedWorkout
    ? Math.round(((weekProgress?.completed ?? 0) / (weekProgress?.total ?? 1)) * 100)
    : 0;

  return (
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
  );
};

export default ProfileQuickMetricsWidget;
