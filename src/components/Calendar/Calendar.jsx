/**
 * Компонент календаря тренировок (веб-версия)
 * Адаптирован из оригинального календаря с полной функциональностью
 */

import React from 'react';
import Week from './Week';
import '../../assets/css/calendar_v2.css';

const Calendar = ({ plan, progressData, workoutsData, resultsData, api, onDayPress, canEdit = false, isOwner = false }) => {
  if (!plan || !plan.phases || plan.phases.length === 0) {
    return (
      <div className="calendar-empty">
        <p>План тренировок не найден</p>
      </div>
    );
  }

  const currentWeekNumber = getCurrentWeekNumber(plan);
  const showPhaseHeaders = plan.phases.length > 1;

  const handleDeleteWeek = async (weekNumber) => {
    if (!api) return;
    try {
      await api.deleteWeek(weekNumber);
      // Перезагрузить план после удаления
      window.location.reload();
    } catch (error) {
      console.error('Error deleting week:', error);
      alert('Ошибка при удалении недели: ' + (error.message || 'Неизвестная ошибка'));
    }
  };

  return (
    <div className="calendar">
      {plan.phases.map((phase, phaseIndex) => (
        <div key={phase.id || phaseIndex} className="phase" data-phase-id={phase.id}>
          {showPhaseHeaders && (
            <div className={`phase-header phase${phaseIndex}`}>
              <div style={{ display: 'flex', alignItems: 'center', gap: '15px' }}>
                <div>
                  <div className="phase-title">{phase.name || `Фаза ${phaseIndex + 1}`}</div>
                  <div className="phase-goal">
                    {phase.period || ''} | {phase.weeks || 0} недель | {phase.goal || ''}
                  </div>
                </div>
              </div>
            </div>
          )}

          <div className="phase-content">
            {phase.weeks_data && phase.weeks_data.map(week => (
              <Week
                key={week.number}
                week={week}
                isCurrentWeek={week.number === currentWeekNumber}
                progressData={progressData}
                workoutsData={workoutsData}
                resultsData={resultsData}
                onDayPress={onDayPress}
                canEdit={canEdit}
                isOwner={isOwner}
                onDeleteWeek={handleDeleteWeek}
              />
            ))}
          </div>
        </div>
      ))}
    </div>
  );
};

function getCurrentWeekNumber(plan) {
  if (!plan || !plan.phases) return null;

  const today = new Date();
  today.setHours(0, 0, 0, 0);

  for (const phase of plan.phases) {
    if (!phase.weeks_data) continue;

    for (const week of phase.weeks_data) {
      if (!week.start_date) continue;

      const startDate = new Date(week.start_date);
      startDate.setHours(0, 0, 0, 0);

      const endDate = new Date(startDate);
      endDate.setDate(endDate.getDate() + 7);
      endDate.setHours(23, 59, 59, 999);

      if (today >= startDate && today <= endDate) {
        return week.number;
      }
    }
  }

  return null;
}

export default Calendar;
