/**
 * Виджет прогноза результатов на забег (VDOT-based + Riegel).
 * Показывает VDOT, прогнозы на стандартные дистанции и тренировочные зоны.
 * В compact-режиме (два виджета в строку): две страницы — прогнозы и зоны, свайп/стрелка.
 */

import React, { useState, useEffect, useCallback, useRef } from 'react';
import LogoLoading from '../common/LogoLoading';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import useWorkoutRefreshStore from '../../stores/useWorkoutRefreshStore';
import { useMediaQuery } from '../../hooks/useMediaQuery';
import './RacePredictionWidget.css';

const DISTANCE_LABELS_FULL = {
  '5k': '5 км',
  '10k': '10 км',
  'half': 'Полумарафон',
  '21.1k': 'Полумарафон',
  'marathon': 'Марафон',
  '42.2k': 'Марафон',
};

const DISTANCE_LABELS_SHORT = {
  '5k': '5 км',
  '10k': '10 км',
  'half': '21.1k',
  '21.1k': '21.1k',
  'marathon': '42k',
  '42.2k': '42k',
};

const PACE_ZONE_LABELS = {
  easy: 'Легкий',
  marathon: 'Марафонский',
  threshold: 'Пороговый',
  interval: 'Интервальный',
  repetition: 'Повторный',
};

const PACE_ZONE_COLORS = {
  easy: 'var(--workout-easy)',
  marathon: 'var(--workout-long)',
  threshold: 'var(--workout-tempo)',
  interval: 'var(--workout-interval)',
  repetition: 'var(--workout-control)',
};

const COMPACT_TABLE_KEY_GROUPS = [
  ['5k'],
  ['10k'],
  ['half', '21.1k'],
  ['marathon', '42.2k'],
];

const SWIPE_THRESHOLD = 50;

const RacePredictionWidget = ({ api, viewContext = null, compact = false }) => {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [page, setPage] = useState(0);
  const touchStartRef = useRef(null);
  const isMobile = useMediaQuery('(max-width: 1023px)');
  const useShortLabels = compact || isMobile;
  const distLabels = useShortLabels ? DISTANCE_LABELS_SHORT : DISTANCE_LABELS_FULL;
  const isCompactCard = compact && !isMobile;
  const showRiegel = !isCompactCard;

  const load = useCallback(async () => {
    if (!api) return;
    try {
      setLoading(true);
      setError(null);
      const res = await api.getRacePrediction(viewContext);
      const d = res?.data ?? res;
      setData(d);
    } catch (e) {
      setError(e.message || 'Ошибка загрузки');
    } finally {
      setLoading(false);
    }
  }, [api, viewContext]);

  useEffect(() => { load(); }, [load]);

  // Обновление при сохранении тренировок, pull-to-refresh и т.п.
  const workoutRefreshVersion = useWorkoutRefreshStore((s) => s.version);
  useEffect(() => {
    if (workoutRefreshVersion > 0 && api) load();
  }, [workoutRefreshVersion, api, load]);

  if (loading) {
    return <div className="race-prediction-loading"><LogoLoading size={32} /></div>;
  }

  if (error) {
    return <div className="race-prediction-empty">{error}</div>;
  }

  if (!data?.available) {
    return (
      <div className="race-prediction-empty">
        {data?.message || 'Недостаточно данных для прогноза'}
      </div>
    );
  }

  const { vdot, vdot_source, vdot_source_detail, predictions, riegel_predictions, training_paces, goal } = data;

  const vdotSourceLabel = {
    last_race: 'по результату забега',
    best_result: vdot_source_detail || 'по тренировкам',
    easy_pace: 'по легкому темпу',
    target_time: 'по целевому времени',
  }[vdot_source] || '';

  const handleSwipeStart = (e) => {
    touchStartRef.current = e.touches?.[0]?.clientX ?? e.clientX;
  };
  const handleSwipeEnd = (e) => {
    if (touchStartRef.current == null) return;
    const x = e.changedTouches?.[0]?.clientX ?? e.clientX;
    const delta = touchStartRef.current - x;
    if (delta > SWIPE_THRESHOLD) setPage((p) => Math.min(1, p + 1));
    else if (delta < -SWIPE_THRESHOLD) setPage((p) => Math.max(0, p - 1));
    touchStartRef.current = null;
  };

  const showPagination = isCompactCard && training_paces;
  const goalDistanceKey = goal?.race_distance || null;
  const compactTableKeys = COMPACT_TABLE_KEY_GROUPS
    .map((group) => group.find((key) => predictions?.[key]))
    .filter(Boolean);
  const getCompactPageState = (pageIndex) => {
    if (pageIndex === page) return 'race-prediction__page--active';
    return pageIndex < page ? 'race-prediction__page--before' : 'race-prediction__page--after';
  };
  const isCompactTargetKey = (key) => {
    if (!goalDistanceKey) return false;
    if (goalDistanceKey === key) return true;
    return (
      (goalDistanceKey === '42.2k' && key === 'marathon') ||
      (goalDistanceKey === 'marathon' && key === '42.2k') ||
      (goalDistanceKey === '21.1k' && key === 'half') ||
      (goalDistanceKey === 'half' && key === '21.1k')
    );
  };

  const headerRow = (
    <div className="race-prediction__header-row">
      <div className="race-prediction__vdot">
        <div className="race-prediction__vdot-value">{vdot}</div>
        <div className="race-prediction__vdot-label">
          VDOT
          {vdotSourceLabel && <span className="race-prediction__vdot-source">{vdotSourceLabel}</span>}
        </div>
      </div>
      {goal && goal.days_to_race > 0 && (
        <div className="race-prediction__goal">
          <span className="race-prediction__goal-dist">{distLabels[goal.race_distance] || goal.race_distance}</span>
          <span className="race-prediction__goal-days">
            {goal.weeks_to_race > 0 ? `${goal.weeks_to_race} нед.` : `${goal.days_to_race} дн.`} до старта
          </span>
        </div>
      )}
    </div>
  );

  const tableBlock = (
    <div className={`race-prediction__table ${compact && !isMobile ? 'race-prediction__table--compact' : ''}`}>
        <div className="race-prediction__table-header">
          <span>Дистанция</span>
          <span>VDOT</span>
          {riegel_predictions && showRiegel && <span>Riegel</span>}
          <span>Темп</span>
        </div>
        {Object.entries(predictions).map(([key, pred]) => (
          <div
            key={key}
            className={`race-prediction__row ${goal?.race_distance === key ? 'race-prediction__row--target' : ''}`}
          >
            <span className="race-prediction__dist">{distLabels[key] || key}</span>
            <span className="race-prediction__time">{pred.formatted}</span>
            {riegel_predictions && showRiegel && (
              <span className="race-prediction__time race-prediction__time--riegel">
                {riegel_predictions[key]?.formatted || '—'}
              </span>
            )}
            <span className="race-prediction__pace">{pred.pace_formatted}/км</span>
          </div>
        ))}
      </div>
  );

  const compactMiniPage = (
    <>
      <div className="race-prediction__compact-section-head">
        <span className="race-prediction__compact-section-title">Прогнозы</span>
        {showPagination && (
          <button
            type="button"
            className="race-prediction__compact-toggle"
            onClick={() => setPage(1)}
            aria-label="Показать тренировочные зоны"
          >
            <span>Зоны</span>
            <ChevronRight size={14} className="race-prediction__compact-toggle-icon" />
          </button>
        )}
      </div>
      <div className="race-prediction__compact-grid">
        {compactTableKeys.map((key) => (
          <div
            key={key}
            className={`race-prediction__compact-cell ${isCompactTargetKey(key) ? 'race-prediction__compact-cell--target' : ''}`}
          >
            <span className="race-prediction__compact-cell-dist">{distLabels[key] || key}</span>
            <span className="race-prediction__compact-cell-time">{predictions[key].formatted}</span>
            <span className="race-prediction__compact-cell-pace">{predictions[key].pace_formatted}/км</span>
          </div>
        ))}
      </div>
    </>
  );

  const page2 = training_paces && (
    <div className="race-prediction__paces">
      <div className="race-prediction__paces-title">Тренировочные зоны</div>
      <div className="race-prediction__paces-grid">
        {Object.entries(training_paces).map(([zone, pace]) => (
          <div key={zone} className="race-prediction__pace-item">
            <span
              className="race-prediction__pace-dot"
              style={{ background: PACE_ZONE_COLORS[zone] }}
            />
            <span className="race-prediction__pace-label">{PACE_ZONE_LABELS[zone] || zone}</span>
            <span className="race-prediction__pace-value">{pace}/км</span>
          </div>
        ))}
      </div>
    </div>
  );

  const compactZonesPage = training_paces && (
    <>
      <div className="race-prediction__compact-section-head">
        <span className="race-prediction__compact-section-title">Тренировочные зоны</span>
        <button
          type="button"
          className="race-prediction__compact-toggle race-prediction__compact-toggle--back"
          onClick={() => setPage(0)}
          aria-label="Вернуться к прогнозам"
        >
          <ChevronLeft size={14} className="race-prediction__compact-toggle-icon" />
          <span>Прогноз</span>
        </button>
      </div>
      <div className="race-prediction__compact-zones-list">
        {Object.entries(training_paces).map(([zone, pace]) => (
          <div key={zone} className="race-prediction__compact-zone-item">
            <span
              className="race-prediction__pace-dot"
              style={{ background: PACE_ZONE_COLORS[zone] }}
            />
            <span className="race-prediction__compact-zone-label">{PACE_ZONE_LABELS[zone] || zone}</span>
            <span className="race-prediction__compact-zone-value">{pace}/км</span>
          </div>
        ))}
      </div>
    </>
  );

  if (isCompactCard) {
    return (
      <div
        className="race-prediction race-prediction--compact-layout"
        onTouchStart={handleSwipeStart}
        onTouchEnd={handleSwipeEnd}
      >
        {headerRow}
        <div className="race-prediction__pages">
          <div
            className={`race-prediction__page ${getCompactPageState(0)}`}
            aria-hidden={page !== 0}
          >
            {compactMiniPage}
          </div>
          {showPagination && (
            <div
              className={`race-prediction__page ${getCompactPageState(1)}`}
              aria-hidden={page !== 1}
            >
              {compactZonesPage}
            </div>
          )}
        </div>
      </div>
    );
  }

  return (
    <div className="race-prediction race-prediction--desktop-cols">
      <div className="race-prediction__header-row">
        <div className="race-prediction__col race-prediction__col--table">
          <div className="race-prediction__vdot">
            <div className="race-prediction__vdot-value">{vdot}</div>
            <div className="race-prediction__vdot-label">
              VDOT
              {vdotSourceLabel && <span className="race-prediction__vdot-source">{vdotSourceLabel}</span>}
            </div>
          </div>
        </div>
        {goal && goal.days_to_race > 0 && (
          <div className="race-prediction__col race-prediction__col--zones">
            <div className="race-prediction__goal">
              <span className="race-prediction__goal-dist">{distLabels[goal.race_distance] || goal.race_distance}</span>
              <span className="race-prediction__goal-days">
                {goal.weeks_to_race > 0 ? `${goal.weeks_to_race} нед.` : `${goal.days_to_race} дн.`} до старта
              </span>
            </div>
          </div>
        )}
      </div>
      <div className="race-prediction__cols">
        <div className="race-prediction__col race-prediction__col--table">{tableBlock}</div>
        {page2 && <div className="race-prediction__col race-prediction__col--zones">{page2}</div>}
      </div>
    </div>
  );
};

export default RacePredictionWidget;
