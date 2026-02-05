/**
 * Виджет статистики для дашборда: период + 4 карточки (дистанция, время, тренировки, темп)
 * Как в экране Статистика, только без графиков и списка тренировок
 */

import React, { useState, useEffect, useCallback } from 'react';
import { processStatsData } from '../Stats/StatsUtils';
import './Dashboard.css';

const DashboardStatsWidget = ({ api, onNavigate }) => {
  const [timeRange, setTimeRange] = useState('quarter'); // week, month, quarter, year
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
      try {
        const w = await api.getAllWorkoutsSummary();
        if (w && typeof w === 'object') {
          workoutsData = w.workouts != null ? { workouts: w.workouts } : { workouts: typeof w === 'object' && !Array.isArray(w) ? w : {} };
        }
      } catch (e) { /* ignore */ }
      try {
        const r = await api.getAllResults();
        if (r && typeof r === 'object') {
          const list = Array.isArray(r) ? r : r.results;
          allResults = { results: Array.isArray(list) ? list : [] };
        }
      } catch (e) { /* ignore */ }
      try {
        plan = await api.getPlan();
      } catch (e) { /* ignore */ }

      const processed = processStatsData(workoutsData, allResults, plan, timeRange);
      setStats(processed);
    } catch (e) {
      console.error('DashboardStatsWidget load error', e);
      setStats(null);
    } finally {
      setLoading(false);
    }
  }, [api, timeRange]);

  useEffect(() => {
    loadStats();
  }, [loadStats]);

  if (loading && !stats) {
    return (
      <div className="dashboard-stats-widget">
        <div className="dashboard-stats-widget-loading">Загрузка...</div>
      </div>
    );
  }

  const s = stats || {
    totalDistance: 0,
    totalTime: 0,
    totalWorkouts: 0,
    avgPace: '—',
  };

  return (
    <div className="dashboard-stats-widget">
      <div className="dashboard-stats-time-range">
        <button
          type="button"
          className={`dashboard-time-range-btn ${timeRange === 'week' ? 'active' : ''}`}
          onClick={() => setTimeRange('week')}
        >
          Эта неделя
        </button>
        <button
          type="button"
          className={`dashboard-time-range-btn ${timeRange === 'month' ? 'active' : ''}`}
          onClick={() => setTimeRange('month')}
        >
          Месяц
        </button>
        <button
          type="button"
          className={`dashboard-time-range-btn ${timeRange === 'quarter' ? 'active' : ''}`}
          onClick={() => setTimeRange('quarter')}
        >
          3 мес
        </button>
        <button
          type="button"
          className={`dashboard-time-range-btn ${timeRange === 'year' ? 'active' : ''}`}
          onClick={() => setTimeRange('year')}
        >
          Год
        </button>
      </div>
      <div className="dashboard-stats-metrics-grid">
        <div className="dashboard-stat-metric-card">
          <div className="metric-icon">🏃</div>
          <div className="metric-content">
            <div className="metric-value">{s.totalDistance}</div>
            <div className="metric-unit">км</div>
            <div className="metric-label">Дистанция</div>
          </div>
        </div>
        <div className="dashboard-stat-metric-card">
          <div className="metric-icon">⏱️</div>
          <div className="metric-content">
            <div className="metric-value">{Math.round(s.totalTime / 60)}</div>
            <div className="metric-unit">часов</div>
            <div className="metric-label">Время</div>
          </div>
        </div>
        <div className="dashboard-stat-metric-card">
          <div className="metric-icon">📅</div>
          <div className="metric-content">
            <div className="metric-value">{s.totalWorkouts}</div>
            <div className="metric-unit">тренировок</div>
            <div className="metric-label">Активность</div>
          </div>
        </div>
        <div className="dashboard-stat-metric-card">
          <div className="metric-icon">📍</div>
          <div className="metric-content">
            <div className="metric-value">{s.avgPace}</div>
            <div className="metric-unit">/км</div>
            <div className="metric-label">Средний темп</div>
          </div>
        </div>
      </div>
      {onNavigate && (
        <button type="button" className="dashboard-module-link-btn" onClick={() => onNavigate('stats')}>
          Вся статистика →
        </button>
      )}
    </div>
  );
};

export default DashboardStatsWidget;
