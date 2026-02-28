/**
 * Компактная полоска недели для блока «Календарь» на дашборде
 * Два квадрата: дата + минималистичная SVG (бег / ОФП / СБУ)
 */

import React, { useMemo, useState, useEffect } from 'react';
import { RunIcon, OFPIcon, SbuIcon, CompletedIcon } from '../Calendar/WeekCalendarIcons';
import '../Calendar/WeekCalendar.css';

const MOBILE_BREAKPOINT = '(max-width: 640px)';

/** API может вернуть day как массив { type, text, id } или один объект. Возвращаем массив активностей дня для отображения всех иконок. */
function normalizeDayActivities(rawDayData) {
  if (!rawDayData) return [];
  const list = Array.isArray(rawDayData) ? rawDayData : [rawDayData];
  return list.filter((d) => d && typeof d.type === 'string').map((d) => ({ type: d.type }));
}

/** Первый не-rest тип дня (для класса ячейки и точки на мобилке). */
function firstNonRestType(activities) {
  const a = activities.find((d) => d.type !== 'rest' && d.type !== 'free');
  return a ? a.type : null;
}

function getWeekDaysFromPlan(plan, progressDataMap) {
  const weeksData = plan?.weeks_data;
  if (!plan || !Array.isArray(weeksData)) return [];
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  let currentWeek = null;
  for (const week of weeksData) {
    if (!week.start_date || !week.days) continue;
    const [wy, wm, wd] = week.start_date.split('-').map(Number);
    const weekStart = new Date(wy, wm - 1, wd);
    weekStart.setHours(0, 0, 0, 0);
    const weekEnd = new Date(weekStart);
    weekEnd.setDate(weekEnd.getDate() + 6);
    weekEnd.setHours(23, 59, 59, 999);
    if (today >= weekStart && today <= weekEnd) {
      currentWeek = week;
      break;
    }
  }
  if (!currentWeek) return [];

  const dayKeys = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
  const dayLabels = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
  /* Парсим start_date как локальную дату (YYYY-MM-DD в части браузеров иначе даёт UTC и сдвиг дня) */
  const [sy, sm, sd] = currentWeek.start_date.split('-').map(Number);
  const startDate = new Date(sy, sm - 1, sd);
  startDate.setHours(0, 0, 0, 0);
  const days = [];
  for (let i = 0; i < 7; i++) {
    const date = new Date(startDate.getFullYear(), startDate.getMonth(), startDate.getDate() + i);
    date.setHours(0, 0, 0, 0);
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const dateStr = `${year}-${month}-${day}`;
    const dayKey = dayKeys[i];
    const rawDayData = currentWeek.days && currentWeek.days[dayKey];
    const dayActivities = normalizeDayActivities(rawDayData);
    const isToday = date.getTime() === today.getTime();
    const isCompleted = progressDataMap[dateStr];
    const hasPlanned = dayActivities.some((a) => a.type !== 'rest' && a.type !== 'free');
    const status = isCompleted ? 'completed' : (hasPlanned ? 'planned' : 'rest');
    const cellType = firstNonRestType(dayActivities);
    days.push({
      date: dateStr,
      dateObj: date,
      dayLabel: dayLabels[i],
      dayKey,
      dayActivities,
      isToday,
      status,
      cellType,
      weekNumber: currentWeek.number,
    });
  }
  return days;
}

function getDayTypeLabel(dayData, status) {
  if (!dayData) return '—';
  if (dayData.type === 'rest') return 'Отдых';
  if (status === 'completed') return 'Выполнено';
  if (dayData.type === 'free') return '—';
  const labels = {
    long: 'Длительный',
    'long-run': 'Длительный',
    easy: 'Легкий',
    interval: 'Интервалы',
    tempo: 'Темп',
    fartlek: 'Фартлек',
    race: 'Соревнование',
    other: 'ОФП',
    sbu: 'СБУ',
    walking: 'Ходьба',
    hiking: 'Поход',
  };
  return labels[dayData.type] || dayData.text || 'Тренировка';
}

/** Порядок и подписи для легенды типов тренировок (цвета из sports-colors.css / WeekCalendar.css) */
const LEGEND_ITEMS = [
  { type: 'easy', label: 'Легкий' },
  { type: 'tempo', label: 'Темп' },
  { type: 'interval', label: 'Интервалы' },
  { type: 'long', label: 'Длительный' },
  { type: 'race', label: 'Соревнование' },
  { type: 'other', label: 'ОФП' },
  { type: 'sbu', label: 'СБУ' },
  { type: 'rest', label: 'Отдых' },
];

const DashboardWeekStrip = ({ plan, progressDataMap, onNavigate, onDayClick }) => {
  const [isMobile, setIsMobile] = useState(
    () => (typeof window !== 'undefined' && window.matchMedia ? window.matchMedia(MOBILE_BREAKPOINT).matches : false)
  );
  useEffect(() => {
    if (typeof window === 'undefined' || !window.matchMedia) return;
    const m = window.matchMedia(MOBILE_BREAKPOINT);
    const fn = () => setIsMobile(m.matches);
    m.addEventListener('change', fn);
    fn();
    return () => m.removeEventListener('change', fn);
  }, []);

  const weekDays = useMemo(
    () => getWeekDaysFromPlan(plan, progressDataMap || {}),
    [plan, progressDataMap]
  );

  if (!weekDays.length) {
    return (
      <div
        className="dashboard-week-strip dashboard-week-strip-empty"
        role={onNavigate ? 'button' : undefined}
        tabIndex={onNavigate ? 0 : undefined}
        onClick={onNavigate ? () => onNavigate('calendar') : undefined}
        onKeyDown={onNavigate ? (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onNavigate('calendar'); } } : undefined}
      >
        <p>Нет плана на текущую неделю</p>
      </div>
    );
  }

  return (
    <div className="dashboard-week-strip">
      <div className="week-calendar-container dashboard-week-calendar-wrap">
        <div className="dashboard-week-strip-content">
        <div className="week-days-grid">
          {weekDays.map((day) => (
            <div
              key={day.date}
              role="button"
              tabIndex={0}
              className={`week-day-cell ${day.isToday ? 'today' : ''} ${day.status} ${day.cellType ? `type-${day.cellType}` : ''}`}
              onClick={() => {
                if (onDayClick) onDayClick(day.date, day.weekNumber, day.dayKey);
                else if (onNavigate) onNavigate('calendar', { date: day.date, week: day.weekNumber, day: day.dayKey });
              }}
              onKeyDown={(e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                  e.preventDefault();
                  if (onDayClick) onDayClick(day.date, day.weekNumber, day.dayKey);
                  else if (onNavigate) onNavigate('calendar', { date: day.date, week: day.weekNumber, day: day.dayKey });
                }
              }}
            >
              <div className="week-day-date-square">
                <span className={`week-day-number ${day.isToday ? 'today-number' : ''}`}>
                  {day.dateObj.getDate()}
                </span>
                <span className="week-day-date-sep">/</span>
                <span className="week-day-label">{day.dayLabel}</span>
              </div>

              <div className="week-day-icons-grid">
                {day.status === 'completed' && (
                  <div className="week-day-icon-square">
                    <CompletedIcon className="week-day-svg-icon week-day-svg-icon--completed" aria-hidden />
                  </div>
                )}
                {day.status !== 'completed' && day.dayActivities.length === 0 && (
                  <div className="week-day-icon-square">
                    <span className="week-day-empty-dash">—</span>
                  </div>
                )}
                {day.status !== 'completed' && day.dayActivities.length > 0 && (() => {
                  const activities = day.dayActivities;
                  const mobileShowTwo = isMobile && activities.length > 2;
                  const hasMore = isMobile ? activities.length > 2 : activities.length > 4;
                  const show = isMobile
                    ? (mobileShowTwo ? [activities[0], { type: '_more' }] : activities.slice(0, 2))
                    : (hasMore ? activities.slice(0, 3) : activities);
                  return (
                    <>
                      {show.map((activity, idx) => (
                        <div key={idx} className={`week-day-icon-square${activity.type === '_more' ? ' week-day-icon-square--more' : ''}${activity.type !== '_more' && activity.type ? ` week-day-icon-square--${activity.type}` : ''}`} aria-label={activity.type === '_more' ? `Ещё ${activities.length - 1} тренировок` : undefined}>
                          {activity.type === '_more' && <span className="week-day-more-dots">…</span>}
                          {activity.type !== '_more' && activity.type === 'rest' && <span className="week-day-empty-dash">—</span>}
                          {activity.type !== '_more' && activity.type === 'free' && <span className="week-day-empty-dash">—</span>}
                          {activity.type !== '_more' && activity.type === 'other' && <OFPIcon className="week-day-svg-icon" aria-hidden />}
                          {activity.type !== '_more' && activity.type === 'sbu' && <SbuIcon className="week-day-svg-icon" aria-hidden />}
                          {activity.type !== '_more' && activity.type !== 'rest' && activity.type !== 'free' && activity.type !== 'other' && activity.type !== 'sbu' && (
                            <RunIcon className="week-day-svg-icon" aria-hidden />
                          )}
                        </div>
                      ))}
                      {!isMobile && hasMore && (
                        <div className="week-day-icon-square week-day-icon-square--more" aria-label={`Ещё ${day.dayActivities.length - 3} тренировок`}>
                          <span className="week-day-more-dots">…</span>
                        </div>
                      )}
                    </>
                  );
                })()}
              </div>
            </div>
          ))}
        </div>
        <div className="dashboard-week-strip-legend" aria-label="Легенда типов тренировок">
          {LEGEND_ITEMS.map(({ type, label }) => (
            <span key={type} className="dashboard-week-strip-legend-item">
              <span className={`dashboard-week-strip-legend-dot dashboard-week-strip-legend-dot--${type}`} aria-hidden />
              <span className="dashboard-week-strip-legend-label">{label}</span>
            </span>
          ))}
        </div>
        </div>
      </div>
    </div>
  );
};

export default DashboardWeekStrip;
