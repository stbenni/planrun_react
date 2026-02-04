/**
 * Компонент списка последних тренировок
 */

import React, { useState } from 'react';

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
        
        return (
          <div 
            key={index} 
            className="workout-item"
            onClick={() => onWorkoutClick && onWorkoutClick(workoutDate)}
            style={{ cursor: onWorkoutClick ? 'pointer' : 'default' }}
          >
            <div className="workout-item-date">
              {new Date(workout.start_time || workout.date + 'T00:00:00').toLocaleDateString('ru-RU', { 
                day: 'numeric', 
                month: 'short',
                year: 'numeric'
              })}
            </div>
            <div className="workout-item-metrics">
              <span className="workout-metric">🏃 {workout.distance_km || 0} км</span>
              {workout.duration_minutes && (
                <span className="workout-metric">⏱️ {Math.round(workout.duration_minutes / 60)} ч</span>
              )}
              {workout.avg_pace && (
                <span className="workout-metric">📍 {workout.avg_pace} /км</span>
              )}
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
