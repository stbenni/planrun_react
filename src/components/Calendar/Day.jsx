/**
 * Компонент дня тренировки (веб-версия)
 * Адаптирован из оригинального календаря с полной функциональностью
 */

import React, { useEffect, useRef } from 'react';
import { getDateForDay, getTrainingClass, getShortDescription, formatDateShort, getDayName } from '../../utils/calendarHelpers';
import '../../assets/css/calendar_v2.css';
import '../../assets/css/short-desc.css';

const Day = ({ dayData, dayKey, weekNumber, weekStartDate, progressData, workoutsData, resultsData, onPress }) => {
  const date = getDateForDay(weekStartDate, dayKey);
  const isRest = !dayData || dayData.type === 'rest' || dayData.type === 'free';
  const dayClass = isRest ? 'rest-day' : getTrainingClass(dayData.type, dayData.key);
  const isCompleted = progressData[date] || false;
  const resultDisplayRef = useRef(null);

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

  // Отображаем тренировки и результаты в result-display
  useEffect(() => {
    if (!resultDisplayRef.current) return;
    
    let html = '';
    
    // Сначала показываем тренировки (из GPX/TCX)
    // getAllWorkoutsSummary возвращает объект: {date: {count, distance, duration, pace, hr, workout_url}}
    if (workoutsData && workoutsData[date]) {
      const workout = workoutsData[date];
      
      if (workout && (workout.distance || workout.duration)) {
        html += '<div class="workout-summary">';
        if (workout.distance) {
          html += `<span class="workout-metric">📏 ${workout.distance.toFixed(1)} км</span>`;
        }
        if (workout.duration) {
          const hours = Math.floor(workout.duration / 60);
          const mins = workout.duration % 60;
          html += `<span class="workout-metric">⏱️ ${hours > 0 ? hours + 'ч ' : ''}${mins}м</span>`;
        }
        if (workout.pace) {
          html += `<span class="workout-metric">⚡ ${escapeHtml(workout.pace)}</span>`;
        }
        if (workout.count > 1) {
          html += `<span class="workout-metric">(${workout.count})</span>`;
        }
        html += '</div>';
      }
    }
    
    // Затем показываем результаты (из workout_log)
    if (resultsData && resultsData[date]) {
      const results = Array.isArray(resultsData[date]) ? resultsData[date] : [resultsData[date]];
      
      results.forEach(result => {
        if (!result) return;
        
        const hasData = result.result_time || result.result_distance || result.result_pace || result.notes;
        if (!hasData) return;
        
        html += '<div class="result-info">';
        if (result.result_time) {
          html += `<div class="result-info-item"><strong>⏱️</strong> ${escapeHtml(result.result_time)}</div>`;
        }
        if (result.result_distance) {
          html += `<div class="result-info-item"><strong>📏</strong> ${result.result_distance} км</div>`;
        }
        if (result.result_pace) {
          html += `<div class="result-info-item"><strong>⚡</strong> ${escapeHtml(result.result_pace)}/км</div>`;
        }
        if (result.notes) {
          html += `<div class="result-notes">${escapeHtml(result.notes)}</div>`;
        }
        html += '</div>';
      });
    }
    
    resultDisplayRef.current.innerHTML = html;
  }, [date, workoutsData, resultsData]);

  // Функция для экранирования HTML
  const escapeHtml = (text) => {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  };

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
      <div 
        className="result-display" 
        id={`result-${date}-${weekNumber}-${dayKey}`}
        ref={resultDisplayRef}
      ></div>
    </div>
  );
};

export default Day;
