/**
 * Компонент графика прогресса по неделям
 */

import React from 'react';

const WeeklyProgressChart = ({ data }) => {
  if (!data || data.length === 0) {
    return <div className="chart-empty">Нет данных для графика</div>;
  }

  // Группируем по неделям
  const weeklyData = [];
  for (let i = 0; i < data.length; i += 7) {
    const weekData = data.slice(i, i + 7);
    const weekDistance = weekData.reduce((sum, d) => sum + d.distance, 0);
    const weekWorkouts = weekData.reduce((sum, d) => sum + d.workouts, 0);
    weeklyData.push({
      week: Math.floor(i / 7) + 1,
      distance: Math.round(weekDistance * 10) / 10,
      workouts: weekWorkouts
    });
  }

  const maxDistance = Math.max(...weeklyData.map(d => d.distance), 1);

  return (
    <div className="weekly-progress-chart">
      {weeklyData.map((week, index) => (
        <div key={index} className="week-bar-container">
          <div className="week-bar-info">
            <div className="week-bar-label">Неделя {week.week}</div>
            <div className="week-bar-value">{week.distance} км</div>
          </div>
          <div className="week-bar">
            <div 
              className="week-bar-fill"
              style={{ width: `${(week.distance / maxDistance) * 100}%` }}
            />
          </div>
        </div>
      ))}
    </div>
  );
};

export default WeeklyProgressChart;
