/**
 * Компонент списка последних тренировок
 * Разметка: [тип тренировки] [дата + параметры], SVG-иконки вместо эмодзи
 */

import React, { useState } from 'react';
import { ActivityTypeIcon, DistanceIcon, TimeIcon, PaceIcon } from './RecentWorkoutIcons';

const TYPE_NAMES = {
  running: 'Бег',
  walking: 'Ходьба',
  hiking: 'Поход',
  cycling: 'Велосипед',
  swimming: 'Плавание',
  other: 'ОФП',
  easy: 'Бег',
  long: 'Бег',
  tempo: 'Бег',
  interval: 'Бег',
  sbu: 'СБУ',
  fartlek: 'Бег',
  rest: 'Отдых',
};

const RecentWorkoutsList = ({ workouts, api, onWorkoutClick }) => {
  const [showAll, setShowAll] = useState(false);
  
  if (!workouts || workouts.length === 0) {
    return <div className="workouts-empty">Нет тренировок</div>;
  }

  const displayedWorkouts = showAll ? workouts : workouts.slice(0, 10);
  const hasMore = workouts.length > 10;

  return (
    <div className="recent-workouts-list">
      {displayedWorkouts.map((workout, index) => {
        const workoutDate = workout.start_time ? workout.start_time.split('T')[0] : workout.date;
        const activityType = (workout.activity_type || 'running').toLowerCase().trim();
        const typeLabel = TYPE_NAMES[activityType] || 'Бег';
        const key = workout.id ?? `${workoutDate}-${index}`;
        
        return (
          <div 
            key={key} 
            className="workout-item"
            onClick={() => onWorkoutClick && onWorkoutClick(workout)}
            style={{ cursor: onWorkoutClick ? 'pointer' : 'default' }}
          >
            <div className="workout-item-type" data-type={activityType}>
              <ActivityTypeIcon type={activityType} className="workout-item-type__icon" />
              <span className="workout-item-type__label">{typeLabel}</span>
            </div>
            <div className="workout-item-main">
              <div className="workout-item-date">
                {new Date(workout.start_time || workout.date + 'T00:00:00').toLocaleDateString('ru-RU', { 
                  day: 'numeric', 
                  month: 'short',
                  year: 'numeric'
                })}
              </div>
              <div className="workout-item-metrics">
                {(workout.distance_km != null && parseFloat(workout.distance_km) > 0) && (
                  <span className="workout-metric">
                    <DistanceIcon className="workout-metric__icon" aria-hidden />
                    {workout.distance_km} км
                  </span>
                )}
                {((workout.duration_seconds != null && workout.duration_seconds > 0) || (workout.duration_minutes != null && workout.duration_minutes > 0)) && (
                  <span className="workout-metric">
                    <TimeIcon className="workout-metric__icon" aria-hidden />
                    {workout.duration_seconds != null && workout.duration_seconds > 0
                      ? (() => {
                          const h = Math.floor(workout.duration_seconds / 3600);
                          const m = Math.floor((workout.duration_seconds % 3600) / 60);
                          const s = workout.duration_seconds % 60;
                          return (h > 0 ? `${h} ч ` : '') + `${m} мин ${s} сек`;
                        })()
                      : Math.floor(workout.duration_minutes / 60) > 0
                        ? `${Math.floor(workout.duration_minutes / 60)} ч ${workout.duration_minutes % 60} мин`
                        : `${workout.duration_minutes} мин`}
                  </span>
                )}
                {workout.avg_pace && (
                  <span className="workout-metric">
                    <PaceIcon className="workout-metric__icon" aria-hidden />
                    {workout.avg_pace} /км
                  </span>
                )}
              </div>
            </div>
          </div>
        );
      })}
      {hasMore && !showAll && (
        <button 
          className="workouts-show-all-btn"
          onClick={(e) => {
            e.stopPropagation();
            setShowAll(true);
          }}
        >
          Показать все ({workouts.length})
        </button>
      )}
      {showAll && hasMore && (
        <button 
          className="workouts-show-all-btn"
          onClick={(e) => {
            e.stopPropagation();
            setShowAll(false);
          }}
        >
          Свернуть
        </button>
      )}
    </div>
  );
};

export default RecentWorkoutsList;
