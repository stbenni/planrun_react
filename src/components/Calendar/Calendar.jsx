/**
 * Компонент календаря тренировок (веб-версия)
 * Адаптирован из оригинального календаря с полной функциональностью
 */

import React from 'react';
import Week from './Week';
import '../../assets/css/calendar_v2.css';

const Calendar = ({ plan, progressData, workoutsData, resultsData, api, onDayPress, canEdit = false, isOwner = false }) => {
  const weeksData = plan?.weeks_data;
  if (!plan || !Array.isArray(weeksData) || weeksData.length === 0) {
    return (
      <div className="calendar-empty">
        <p>План тренировок не найден</p>
      </div>
    );
  }

  const currentWeekNumber = getCurrentWeekNumber(plan);

  const handleDeleteWeek = async (weekNumber) => {
    if (!api) return;
    try {
      await api.deleteWeek(weekNumber);
      window.location.reload();
    } catch (error) {
      console.error('Error deleting week:', error);
      alert('Ошибка при удалении недели: ' + (error.message || 'Неизвестная ошибка'));
    }
  };

  return (
    <div className="calendar">
      <div className="phase-content">
        {weeksData.map(week => (
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
  );
};

function getCurrentWeekNumber(plan) {
  const weeksData = plan?.weeks_data;
  if (!plan || !Array.isArray(weeksData)) return null;

  const today = new Date();
  today.setHours(0, 0, 0, 0);

  for (const week of weeksData) {
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

  return null;
}

export default Calendar;
