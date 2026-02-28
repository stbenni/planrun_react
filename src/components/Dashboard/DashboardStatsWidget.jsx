/**
 * Виджет статистики для дашборда: период + 4 карточки (дистанция, время, тренировки, темп)
 * Внешний вид как у быстрых метрик: те же SVG-иконки и стиль карточек.
 */

import React, { useState, useEffect, useCallback } from 'react';
import { processStatsData } from '../Stats/StatsUtils';
import { MetricDistanceIcon, MetricActivityIcon, MetricTimeIcon, MetricPaceIcon } from './DashboardMetricIcons';
import './Dashboard.css';

const DashboardStatsWidget = ({ api, onNavigate, viewContext = null }) => {
  const [timeRange, setTimeRange] = useState('month'); // month, quarter, year
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
        const raw = plan?.data ?? plan;
        plan = raw?.weeks_data ? raw : (typeof raw === 'object' && !Array.isArray(raw) ? raw : null);
      } catch (e) { /* ignore */ }

      const processed = processStatsData(workoutsData, allResults, plan, timeRange);
      setStats(processed);
    } catch (e) {
      console.error('DashboardStatsWidget load error', e);
      setStats(null);
    } finally {
      setLoading(false);
    }
  }, [api, timeRange, viewContext]);

  useEffect(() => {
    loadStats();
  }, [loadStats]);

  if (loading && !stats) {
    return (
      <div
        className="dashboard-stats-widget"
        role={onNavigate ? 'button' : undefined}
        tabIndex={onNavigate ? 0 : undefined}
        onClick={onNavigate ? () => onNavigate('stats') : undefined}
        onKeyDown={onNavigate ? (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onNavigate('stats'); } } : undefined}
      >
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
    <div
      className="dashboard-stats-widget"
      role={onNavigate ? 'button' : undefined}
      tabIndex={onNavigate ? 0 : undefined}
      onClick={onNavigate ? () => onNavigate('stats') : undefined}
      onKeyDown={onNavigate ? (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onNavigate('stats'); } } : undefined}
    >
      <div className="dashboard-stats-time-range" onClick={(e) => e.stopPropagation()}>
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
        <div className="metric-card">
          <div className="metric-card__label">
            <MetricDistanceIcon className="metric-card__icon" />
            <span>Дистанция</span>
          </div>
          <div className="metric-card__value">
            <span className="metric-card__number">{s.totalDistance}</span>
            <span className="metric-card__unit">км</span>
          </div>
        </div>
        <div className="metric-card">
          <div className="metric-card__label">
            <MetricTimeIcon className="metric-card__icon" />
            <span>Время</span>
          </div>
          <div className="metric-card__value">
            <span className="metric-card__number">{Math.round(s.totalTime / 60)}</span>
            <span className="metric-card__unit">часов</span>
          </div>
        </div>
        <div className="metric-card">
          <div className="metric-card__label">
            <MetricActivityIcon className="metric-card__icon" />
            <span>Активность</span>
          </div>
          <div className="metric-card__value">
            <span className="metric-card__number">{s.totalWorkouts}</span>
            <span className="metric-card__unit">тренировок</span>
          </div>
        </div>
        <div className="metric-card">
          <div className="metric-card__label">
            <MetricPaceIcon className="metric-card__icon" />
            <span>Средний темп</span>
          </div>
          <div className="metric-card__value">
            <span className="metric-card__number">{s.avgPace}</span>
            <span className="metric-card__unit">/км</span>
          </div>
        </div>
      </div>
    </div>
  );
};

export default DashboardStatsWidget;
