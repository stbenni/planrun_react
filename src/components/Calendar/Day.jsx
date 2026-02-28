/**
 * Компонент дня тренировки (веб-версия)
 * Адаптирован из оригинального календаря с полной функциональностью
 */

import React from 'react';
import { getDateForDay, getTrainingClass, getShortDescription, formatDateShort, getDayName } from '../../utils/calendarHelpers';
import { DistanceIcon, TimeIcon, PaceIcon } from '../common/Icons';
import '../../assets/css/calendar_v2.css';
import '../../assets/css/short-desc.css';

const Day = ({ dayData, dayKey, weekNumber, weekStartDate, progressData, workoutsData, resultsData, onPress }) => {
  const date = getDateForDay(weekStartDate, dayKey);
  const isRest = !dayData || dayData.type === 'rest' || dayData.type === 'free';
  const dayClass = isRest ? 'rest-day' : getTrainingClass(dayData.type, dayData.key);
  const isCompleted = progressData[date] || false;

  const handleClick = () => {
    if (onPress) {
      onPress(date, weekNumber, dayKey);
    }
  };

  const shortDescription = getShortDescription(
    dayData?.text || '',
    dayData?.type || 'rest'
  );

  const dayName = getDayName(dayKey);
  const formattedDate = formatDateShort(date);
  const workout = workoutsData?.[date];
  const results = resultsData?.[date] ? (Array.isArray(resultsData[date]) ? resultsData[date] : [resultsData[date]]) : [];

  return (
    <div
      className={`training-cell ${dayClass} ${isCompleted ? 'completed' : ''}`}
      onClick={handleClick}
      data-date={date}
      data-week={weekNumber}
      data-day={dayKey}
      title="Нажмите для полного описания"
    >
      <div className="date-cell" data-day-name={dayName}>
        {formattedDate}
      </div>
      <div 
        className="training-content"
        dangerouslySetInnerHTML={{ __html: shortDescription }}
      />
      {shortDescription && dayData?.text && dayData.text.trim() && (
        <div className="more-info">подробнее...</div>
      )}
      <div className="result-display" id={`result-${date}-${weekNumber}-${dayKey}`}>
        {workout && (workout.distance || workout.duration) && (
          <div className="workout-summary">
            {workout.distance && (
              <span className="workout-metric"><DistanceIcon size={14} className="day-metric-icon" aria-hidden /> {workout.distance.toFixed(1)} км</span>
            )}
            {workout.duration && (
              <span className="workout-metric"><TimeIcon size={14} className="day-metric-icon" aria-hidden /> {Math.floor(workout.duration / 60) > 0 ? Math.floor(workout.duration / 60) + 'ч ' : ''}{workout.duration % 60}м</span>
            )}
            {workout.pace && (
              <span className="workout-metric"><PaceIcon size={14} className="day-metric-icon" aria-hidden /> {workout.pace}</span>
            )}
            {workout.count > 1 && (
              <span className="workout-metric">({workout.count})</span>
            )}
          </div>
        )}
        {results.map((result, idx) => {
          if (!result || (!result.result_time && !result.result_distance && !result.result_pace && !result.notes)) return null;
          return (
            <div key={idx} className="result-info">
              {result.result_time && (
                <div className="result-info-item"><TimeIcon size={14} className="day-metric-icon" aria-hidden /> {result.result_time}</div>
              )}
              {result.result_distance && (
                <div className="result-info-item"><DistanceIcon size={14} className="day-metric-icon" aria-hidden /> {result.result_distance} км</div>
              )}
              {result.result_pace && (
                <div className="result-info-item"><PaceIcon size={14} className="day-metric-icon" aria-hidden /> {result.result_pace}/км</div>
              )}
              {result.notes && <div className="result-notes">{result.notes}</div>}
            </div>
          );
        })}
      </div>
    </div>
  );
};

export default Day;
