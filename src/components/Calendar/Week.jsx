/**
 * Компонент недели тренировок (веб-версия)
 * Адаптирован из оригинального календаря с полной функциональностью
 */

import React, { useState } from 'react';
import Day from './Day';
import { TrashIcon } from '../common/Icons';
import { formatDateShort } from '../../utils/calendarHelpers';
import '../../assets/css/calendar_v2.css';

const Week = ({ week, isCurrentWeek, progressData, workoutsData, resultsData, onDayPress, canEdit, isOwner, onDeleteWeek }) => {
  const [isExpanded, setIsExpanded] = useState(isCurrentWeek);

  const toggleWeek = () => setIsExpanded(!isExpanded);

  const days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
  const dayLabels = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];

  // Форматируем даты недели
  const startDate = week.start_date ? formatDateShort(week.start_date) : '';
  const endDate = week.start_date ? formatDateShort(new Date(new Date(week.start_date).getTime() + 6 * 24 * 60 * 60 * 1000).toISOString().split('T')[0]) : '';

  const handleDeleteWeek = (e) => {
    e.stopPropagation();
    if (window.confirm(`Удалить неделю ${week.number}?`)) {
      if (onDeleteWeek) {
        onDeleteWeek(week.number);
      }
    }
  };

  return (
    <div className={`week ${isCurrentWeek ? 'current-week' : ''}`}>
      <div className="week-header" onClick={toggleWeek} style={{ cursor: 'pointer' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: '15px', justifyContent: 'space-between', width: '100%' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '15px', flex: 1 }}>
            <span className="week-toggle" id={`toggle-week-${week.number}`}>
              {isExpanded ? '▼' : '▶'}
            </span>
            <div className="week-info">
              <div className="week-label">
                Неделя {week.number}{' '}
                {startDate && endDate && (
                  <span style={{ color: 'var(--gray-500)', fontWeight: 500, fontSize: '0.9em' }}>
                    ({startDate} - {endDate})
                  </span>
                )}
              </div>
              <div className="week-stats">
                {week.total_volume || week.key_session || ''}
              </div>
            </div>
          </div>
          {canEdit && isOwner && (
            <button
              type="button"
              onClick={handleDeleteWeek}
              className="btn-delete-week"
              style={{
                background: '#ef4444',
                color: 'white',
                border: 'none',
                padding: '6px 12px',
                borderRadius: '6px',
                fontSize: '0.85em',
                fontWeight: 500,
                cursor: 'pointer',
                transition: 'all 0.2s ease',
                boxShadow: '0 2px 4px rgba(239, 68, 68, 0.2)',
                marginRight: '10px',
              }}
              onMouseOver={(e) => {
                e.target.style.background = '#dc2626';
                e.target.style.boxShadow = '0 2px 6px rgba(239, 68, 68, 0.3)';
              }}
              onMouseOut={(e) => {
                e.target.style.background = '#ef4444';
                e.target.style.boxShadow = '0 2px 4px rgba(239, 68, 68, 0.2)';
              }}
              title="Удалить неделю"
            >
              <TrashIcon size={18} aria-hidden />
            </button>
          )}
        </div>
      </div>

      <div 
        className="week-content" 
        id={`week-content-${week.number}`}
        style={{ display: isExpanded ? 'block' : 'none' }}
      >
        <div className="days-header">
          {dayLabels.map(day => (
            <div key={day} className="day-header">
              {day}
            </div>
          ))}
        </div>

        <div className="days-grid">
          {days.map(dayKey => (
            <Day
              key={dayKey}
              dayData={week.days && week.days[dayKey]}
              dayKey={dayKey}
              weekNumber={week.number}
              weekStartDate={week.start_date}
              progressData={progressData}
              workoutsData={workoutsData}
              resultsData={resultsData}
              onPress={onDayPress}
            />
          ))}
        </div>
      </div>
    </div>
  );
};

export default Week;
