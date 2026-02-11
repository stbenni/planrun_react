/**
 * MonthlyCalendar - Обычный месячный календарь
 * Показывает дни месяца с тренировками, привязанными к конкретным датам
 */

import React, { useState } from 'react';
import DayModal from './DayModal';
import './MonthlyCalendar.css';

const MonthlyCalendar = ({ 
  workoutsData = {}, 
  resultsData = {}, 
  planData = null,
  api,
  onDateClick,
  canEdit = true,
  targetUserId = null
}) => {
  const [currentDate, setCurrentDate] = useState(new Date());
  const [selectedDate, setSelectedDate] = useState(null);
  const [isDayModalOpen, setIsDayModalOpen] = useState(false);

  const year = currentDate.getFullYear();
  const month = currentDate.getMonth();

  const firstDay = new Date(year, month, 1);
  const lastDay = new Date(year, month + 1, 0);
  const daysInMonth = lastDay.getDate();
  const startingDayOfWeek = firstDay.getDay();
  const startingDay = startingDayOfWeek === 0 ? 6 : startingDayOfWeek - 1;

  const dayNames = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
  const monthNames = [
    'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь',
    'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'
  ];

  function isToday(date) {
    const today = new Date();
    return date.getDate() === today.getDate() &&
           date.getMonth() === today.getMonth() &&
           date.getFullYear() === today.getFullYear();
  }

  function formatDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  }

  function getPlanDayForDate(dateStr, planData) {
    const weeksData = planData?.weeks_data;
    if (!planData || !Array.isArray(weeksData)) return null;
    
    const date = new Date(dateStr + 'T00:00:00');
    date.setHours(0, 0, 0, 0);
    
    const dayOfWeek = date.getDay();
    const dayKey = dayOfWeek === 0 ? 'sun' : ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'][dayOfWeek - 1];
    
    for (const week of weeksData) {
        if (!week.start_date) continue;
        
        const weekStart = new Date(week.start_date + 'T00:00:00');
        weekStart.setHours(0, 0, 0, 0);
        const weekEnd = new Date(weekStart);
        weekEnd.setDate(weekEnd.getDate() + 6);
        weekEnd.setHours(23, 59, 59, 999);
        
        if (date >= weekStart && date <= weekEnd) {
          const raw = week.days && week.days[dayKey];
          if (raw) {
            const items = Array.isArray(raw) ? raw : [raw];
            return {
              items,
              weekNumber: week.number,
              type: items[0]?.type,
              text: items.map((i) => i.text).filter(Boolean).join('\n')
            };
          }
        }
    }
    
    return null;
  }

  const days = [];
  
  for (let i = 0; i < startingDay; i++) {
    days.push(null);
  }
  
  for (let day = 1; day <= daysInMonth; day++) {
    const date = new Date(year, month, day);
    const dateStr = formatDate(date);
    
    const hasWorkout = workoutsData[dateStr] && (workoutsData[dateStr].distance || workoutsData[dateStr].duration);
    const hasResult = resultsData[dateStr] && Array.isArray(resultsData[dateStr]) && resultsData[dateStr].length > 0;
    const isCompleted = hasWorkout || hasResult;
    const planDay = getPlanDayForDate(dateStr, planData);
    
    days.push({
      day,
      date: dateStr,
      dateObj: date,
      isToday: isToday(date),
      isCompleted,
      hasWorkout,
      hasResult,
      planDay
    });
  }

  const handleDateClick = (day) => {
    if (!day) return;
    
    setSelectedDate(day.date);
    setIsDayModalOpen(true);
    
    if (onDateClick) {
      onDateClick(day.date);
    }
  };

  const handlePrevMonth = () => {
    setCurrentDate(new Date(year, month - 1, 1));
  };

  const handleNextMonth = () => {
    setCurrentDate(new Date(year, month + 1, 1));
  };

  return (
    <div className="monthly-calendar">
      <div className="monthly-calendar-header">
        <button className="month-nav-btn" onClick={handlePrevMonth} aria-label="Предыдущий месяц">
          ‹
        </button>
        
        <div className="month-title">
          <h2>{monthNames[month]} {year}</h2>
        </div>
        
        <button className="month-nav-btn" onClick={handleNextMonth} aria-label="Следующий месяц">
          ›
        </button>
      </div>

      <div className="monthly-calendar-grid">
        <div className="monthly-calendar-weekdays">
          {dayNames.map(dayName => (
            <div key={dayName} className="weekday-header">
              {dayName}
            </div>
          ))}
        </div>

        <div className="monthly-calendar-days">
          {days.map((day, index) => {
            if (!day) {
              return <div key={`empty-${index}`} className="month-day-cell empty" />;
            }

            const workout = workoutsData[day.date];
            const workoutDistance = workout?.distance;
            const workoutDuration = workout?.duration;
            const workoutPace = workout?.pace;

            const getWorkoutTypeName = (type) => {
              const typeNames = {
                'easy': 'Легкий',
                'long': 'Длительный',
                'long-run': 'Длительный',
                'tempo': 'Темп',
                'interval': 'Интервалы',
                'other': 'ОФП',
                'sbu': 'СБУ',
                'fartlek': 'Фартлек',
                'race': 'Соревнование',
                'free': '—',
                'rest': 'Отдых'
              };
              return typeNames[type] || type;
            };

            const extractDistanceFromPlan = (text) => {
              if (!text) return null;
              
              const cleanText = text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ');
              
              const patterns = [
                /(\d+(?:[.,]\d+)?)\s*(?:км|km|КМ|KM)\b/i,
                /(\d+(?:[.,]\d+)?)\s*КИЛОМЕТР/i,
                /(\d+(?:[.,]\d+)?)\s*КИЛОМЕТРОВ/i,
                /(\d+(?:[.,]\d+)?)\s*километр/i,
                /(\d+(?:[.,]\d+)?)\s*километров/i
              ];
              
              for (const pattern of patterns) {
                const match = cleanText.match(pattern);
                if (match) {
                  return parseFloat(match[1].replace(',', '.'));
                }
              }
              
              return null;
            };

            const planDistance = day.planDay?.text ? extractDistanceFromPlan(day.planDay.text) : null;
            const displayDistance = workoutDistance || planDistance;

            const formatDuration = (minutes) => {
              if (!minutes) return null;
              const hours = Math.floor(minutes / 60);
              const mins = Math.floor(minutes % 60);
              if (hours > 0) {
                return `${hours}ч ${mins}м`;
              }
              return `${mins}м`;
            };

            const formatPace = (pace) => {
              if (pace == null || pace === '') return null;
              if (typeof pace === 'string') {
                const trimmed = String(pace).trim();
                const match = trimmed.match(/^(\d+):(\d{1,2})$/);
                if (match) return `${match[1]}:${match[2].padStart(2, '0')}`;
                return trimmed;
              }
              if (typeof pace === 'number') {
                const minutes = Math.floor(pace);
                const seconds = Math.round((pace - minutes) * 60);
                return `${minutes}:${seconds.toString().padStart(2, '0')}`;
              }
              return null;
            };

            return (
              <div
                key={day.date}
                className={`month-day-cell ${day.isToday ? 'today' : ''} ${day.isCompleted ? 'completed' : ''} ${day.planDay ? 'has-plan' : ''}`}
                onClick={() => handleDateClick(day)}
              >
                <div className="day-number">{day.day}</div>
                
                {day.planDay && day.planDay.type !== 'rest' && day.planDay.type !== 'free' && (
                  <div className="plan-indicator" title={day.planDay.text || day.planDay.type}>
                    {day.planDay.type === 'long' || day.planDay.type === 'long-run' ? '🏃' :
                     day.planDay.type === 'interval' ? '⚡' :
                     day.planDay.type === 'tempo' ? '🔥' :
                     day.planDay.type === 'easy' ? '🏃' :
                     day.planDay.type === 'other' ? '💪' :
                     day.planDay.type === 'sbu' ? '🏋️' :
                     day.planDay.type === 'fartlek' ? '🎯' :
                     day.planDay.type === 'race' ? '🏁' :
                     '📋'}
                  </div>
                )}
                
                {day.isCompleted && (
                  <div className="completed-indicator">
                    {day.hasWorkout ? '✅' : '✓'}
                  </div>
                )}
                
                <div className="workout-info">
                  {day.planDay && day.planDay.type !== 'rest' && day.planDay.type !== 'free' && (
                    <div className="workout-type">
                      {getWorkoutTypeName(day.planDay.type)}
                    </div>
                  )}
                  
                  {displayDistance && (
                    <div className="workout-distance">
                      {displayDistance.toFixed(displayDistance < 1 ? 1 : 0)} км
                    </div>
                  )}
                  
                  {workoutDuration && (
                    <div className="workout-duration">
                      {formatDuration(workoutDuration)}
                    </div>
                  )}
                  
                  {workoutPace && (
                    <div className="workout-pace">
                      {formatPace(workoutPace)}/км
                    </div>
                  )}
                </div>
                
                {day.planDay && day.planDay.type === 'rest' && !day.isCompleted && (
                  <div className="rest-indicator" title="Отдых">😴</div>
                )}
              </div>
            );
          })}
        </div>
      </div>

      <div className="monthly-calendar-legend">
        <div className="legend-item">
          <span className="legend-swatch legend-swatch--today" aria-hidden />
          <span>Сегодня</span>
        </div>
        <div className="legend-item">
          <span className="legend-swatch legend-swatch--plan" aria-hidden />
          <span>План</span>
        </div>
        <div className="legend-item">
          <span className="legend-swatch legend-swatch--completed" aria-hidden />
          <span>Выполнено</span>
        </div>
        <div className="legend-item">
          <span className="legend-icon">😴</span>
          <span>Отдых</span>
        </div>
      </div>

      {selectedDate && !onDateClick && (
        <DayModal
          isOpen={isDayModalOpen}
          onClose={() => {
            setIsDayModalOpen(false);
            setSelectedDate(null);
          }}
          date={selectedDate}
          weekNumber={null}
          dayKey={null}
          api={api}
          canEdit={canEdit}
          targetUserId={targetUserId}
        />
      )}
    </div>
  );
};

export default MonthlyCalendar;
