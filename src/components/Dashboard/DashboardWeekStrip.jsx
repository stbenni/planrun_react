/**
 * Компактная полоска недели для блока «Календарь» на дашборде
 * Использует те же стили и разметку, что и страница недельного календаря (WeekCalendar)
 */

import React, { useMemo } from 'react';
import '../Calendar/WeekCalendar.css';

function getWeekDaysFromPlan(plan, progressDataMap) {
  if (!plan?.phases) return [];
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  let currentWeek = null;
  for (const phase of plan.phases) {
    if (!phase.weeks_data) continue;
    for (const week of phase.weeks_data) {
      if (!week.start_date || !week.days) continue;
      const startDate = new Date(week.start_date + 'T00:00:00');
      startDate.setHours(0, 0, 0, 0);
      const endDate = new Date(startDate);
      endDate.setDate(endDate.getDate() + 6);
      endDate.setHours(23, 59, 59, 999);
      if (today >= startDate && today <= endDate) {
        currentWeek = week;
        break;
      }
    }
    if (currentWeek) break;
  }
  if (!currentWeek) return [];

  const dayKeys = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
  const dayLabels = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
  const startDate = new Date(currentWeek.start_date + 'T00:00:00');
  startDate.setHours(0, 0, 0, 0);
  const days = [];
  for (let i = 0; i < 7; i++) {
    const date = new Date(startDate);
    date.setDate(startDate.getDate() + i);
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const dateStr = `${year}-${month}-${day}`;
    const dayKey = dayKeys[i];
    const dayData = currentWeek.days && currentWeek.days[dayKey];
    const isToday = date.getTime() === today.getTime();
    const isCompleted = progressDataMap[dateStr];
    const status = isCompleted ? 'completed' : (dayData && dayData.type !== 'rest' ? 'planned' : 'rest');
    days.push({
      date: dateStr,
      dateObj: date,
      dayLabel: dayLabels[i],
      dayKey,
      dayData,
      isToday,
      status,
      weekNumber: currentWeek.number,
    });
  }
  return days;
}

function getDayTypeLabel(dayData, status) {
  if (!dayData) return '—';
  if (dayData.type === 'rest') return 'Отдых';
  if (status === 'completed') return 'Выполнено';
  const labels = {
    long: 'Длительный',
    'long-run': 'Длительный',
    easy: 'Легкий',
    interval: 'Интервалы',
    tempo: 'Темп',
    fartlek: 'Фартлек',
    race: 'Соревнование',
    free: 'Свободная',
    other: 'ОФП',
    sbu: 'СБУ',
  };
  return labels[dayData.type] || dayData.text || 'Тренировка';
}

const DashboardWeekStrip = ({ plan, progressDataMap, onNavigate }) => {
  const weekDays = useMemo(
    () => getWeekDaysFromPlan(plan, progressDataMap || {}),
    [plan, progressDataMap]
  );

  if (!weekDays.length) {
    return (
      <div className="dashboard-week-strip dashboard-week-strip-empty">
        <p>Нет плана на текущую неделю</p>
        {onNavigate && (
          <button type="button" className="dashboard-module-link-btn" onClick={() => onNavigate('calendar')}>
            Открыть календарь →
          </button>
        )}
      </div>
    );
  }

  return (
    <div className="dashboard-week-strip">
      <div className="week-calendar-container dashboard-week-calendar-wrap">
        <div className="week-days-grid">
          {weekDays.map((day) => (
            <div
              key={day.date}
              role="button"
              tabIndex={0}
              className={`week-day-cell ${day.isToday ? 'today' : ''} ${day.status}`}
              onClick={() => onNavigate && onNavigate('calendar', { date: day.date, week: day.weekNumber, day: day.dayKey })}
              onKeyDown={(e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                  e.preventDefault();
                  onNavigate && onNavigate('calendar', { date: day.date, week: day.weekNumber, day: day.dayKey });
                }
              }}
            >
              <div className="week-day-header">
                <div className="week-day-label">{day.dayLabel}</div>
                <div className={`week-day-number ${day.isToday ? 'today-number' : ''}`}>
                  {day.dateObj.getDate()}
                </div>
              </div>

              {day.dayData && day.dayData.type !== 'rest' && (
                <div className="week-day-workout">
                  <div className="workout-type-icon">
                    {day.status === 'completed' ? '✅' :
                     day.dayData.type === 'other' ? '💪' :
                     day.dayData.type === 'sbu' ? '🏋️' : '🏃'}
                  </div>
                  <div className="workout-type-text">
                    {getDayTypeLabel(day.dayData, day.status)}
                  </div>
                </div>
              )}

              {day.dayData && day.dayData.type === 'rest' && (
                <div className="week-day-rest">
                  <span className="rest-icon">😴</span>
                  <span className="rest-text">Отдых</span>
                </div>
              )}

              {!day.dayData && (
                <div className="week-day-empty">—</div>
              )}
            </div>
          ))}
        </div>
      </div>
      {onNavigate && (
        <button type="button" className="dashboard-module-link-btn" onClick={() => onNavigate('calendar')}>
          Открыть календарь →
        </button>
      )}
    </div>
  );
};

export default DashboardWeekStrip;
