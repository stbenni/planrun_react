/**
 * MonthlyCalendar - Обычный месячный календарь
 * Показывает дни месяца с тренировками, привязанными к конкретным датам
 */

import React, { useState, useEffect, useRef } from 'react';
import { useLocation } from 'react-router-dom';
import DayModal from './DayModal';
import { RunningIcon, ZapIcon, FlameIcon, OtherIcon, SbuIcon, TargetIcon, BarChartIcon, FlagIcon, ClipboardListIcon, RestIcon, CompletedIcon, WalkingIcon, HikingIcon, CyclingIcon, SwimmingIcon } from '../common/Icons';
import { getPlanDayForDate, getDayCompletionStatus } from '../../utils/calendarHelpers';
import './MonthlyCalendar.css';

/** Названия типов для строки: «Легкий бег 5 км» или «ОФП 30 мин» */
const TYPE_NAMES_ROW = {
  easy: 'Легкий бег',
  long: 'Длительный бег',
  'long-run': 'Длительный бег',
  tempo: 'Темповый бег',
  interval: 'Интервалы',
  other: 'ОФП',
  sbu: 'СБУ',
  fartlek: 'Фартлек',
  control: 'Контрольный забег',
  race: 'Соревнование',
  rest: 'Отдых',
  free: '—',
  walking: 'Ходьба',
  hiking: 'Поход',
  cycling: 'Велосипед',
  swimming: 'Плавание',
};

/** Легенда типов тренировок (цвета как в дашборде и полосках календаря) */
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

/** Класс полоски по типу (те же цвета, что в WorkoutCard) */
function getWorkoutStripClass(type) {
  if (!type) return null;
  const map = {
    easy: 'easy', tempo: 'tempo', interval: 'interval', fartlek: 'interval',
    long: 'long', 'long-run': 'long', control: 'control', race: 'race',
    other: 'other', sbu: 'sbu', rest: 'rest', walking: 'walking', hiking: 'hiking',
    cycling: 'run', swimming: 'run',
  };
  return map[type] || (type === 'free' ? null : 'run');
}

const MonthlyCalendar = ({ 
  workoutsData = {}, 
  workoutsListByDate = {},
  resultsData = {}, 
  planData = null,
  api,
  onDateClick,
  canEdit = true,
  targetUserId = null
}) => {
  const location = useLocation();
  const [currentDate, setCurrentDate] = useState(new Date());
  const [selectedDate, setSelectedDate] = useState(null);
  const [isDayModalOpen, setIsDayModalOpen] = useState(false);
  const [isWeekdaysStuck, setIsWeekdaysStuck] = useState(false);

  useEffect(() => {
    const isCalendar = location.pathname === '/calendar' || location.pathname.startsWith('/calendar');
    if (!isCalendar) setIsDayModalOpen(false);
  }, [location.pathname]);
  const weekdaysRef = useRef(null);
  const weekdaysHeightRef = useRef(0);
  const sentinelRef = useRef(null);

  useEffect(() => {
    const sentinel = sentinelRef.current;
    if (!sentinel) return;

    const getHeaderTop = () => 64;

    const setupObserver = () => {
      const top = getHeaderTop();
      const rootMargin = `${-top}px 0px 0px 0px`;
      const observer = new IntersectionObserver(
        ([entry]) => {
          if (!entry) return;
          if (weekdaysRef.current) {
            weekdaysHeightRef.current = weekdaysRef.current.offsetHeight;
          }
          setIsWeekdaysStuck(!entry.isIntersecting);
        },
        { root: null, rootMargin, threshold: 0 }
      );
      observer.observe(sentinel);
      return observer;
    };

    let observer = setupObserver();
    const handleResize = () => {
      observer.disconnect();
      observer = setupObserver();
    };
    window.addEventListener('resize', handleResize);
    return () => {
      observer.disconnect();
      window.removeEventListener('resize', handleResize);
    };
  }, []);

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

  const days = [];
  
  for (let i = 0; i < startingDay; i++) {
    days.push(null);
  }
  
  for (let day = 1; day <= daysInMonth; day++) {
    const date = new Date(year, month, day);
    const dateStr = formatDate(date);
    
    const planDay = getPlanDayForDate(dateStr, planData);
    const completion = getDayCompletionStatus(dateStr, planDay, workoutsData, resultsData, workoutsListByDate);
    const isCompleted = completion.status === 'completed';
    const isRestExtra = completion.status === 'rest_extra';
    const restExtraType = completion.extraWorkoutType;
    
    days.push({
      day,
      date: dateStr,
      dateObj: date,
      isToday: isToday(date),
      isCompleted,
      isRestExtra,
      restExtraType,
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

      <div ref={sentinelRef} className="monthly-calendar-weekdays-sentinel" aria-hidden />
      {isWeekdaysStuck && (
        <div
          className="monthly-calendar-sticky-spacer"
          style={{ height: weekdaysHeightRef.current || 48 }}
          aria-hidden
        />
      )}
      <div
        ref={weekdaysRef}
        className={`monthly-calendar-weekdays-sticky${isWeekdaysStuck ? ' monthly-calendar-weekdays-sticky--stuck' : ''}`}
      >
        <div className="monthly-calendar-weekdays">
          {dayNames.map(dayName => (
            <div key={dayName} className="weekday-header">
              {dayName}
            </div>
          ))}
        </div>
      </div>

      <div className="monthly-calendar-grid">
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
                'control': 'Контроль',
                'race': 'Соревнование',
                'free': '—',
                'rest': 'Отдых'
              };
              return typeNames[type] || type;
            };

            const cleanPlanText = (t) => (t || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ');

            const extractDistanceFromPlan = (text) => {
              const cleanText = cleanPlanText(text);
              const patterns = [
                /(\d+(?:[.,]\d+)?)\s*(?:км|km|КМ|KM)\b/i,
                /(\d+(?:[.,]\d+)?)\s*КИЛОМЕТР/i,
                /(\d+(?:[.,]\d+)?)\s*КИЛОМЕТРОВ/i,
                /(\d+(?:[.,]\d+)?)\s*километр/i,
                /(\d+(?:[.,]\d+)?)\s*километров/i
              ];
              for (const pattern of patterns) {
                const match = cleanText.match(pattern);
                if (match) return parseFloat(match[1].replace(',', '.'));
              }
              const intervalMatch = cleanText.match(/(\d+)\s*[×x]\s*(\d+)\s*м/i);
              if (intervalMatch) return (parseInt(intervalMatch[1], 10) * parseInt(intervalMatch[2], 10)) / 1000;
              return null;
            };

            const extractDurationFromPlan = (text) => {
              const cleanText = cleanPlanText(text);
              const orTimeMatch = cleanText.match(/или\s*(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?/i);
              if (orTimeMatch) {
                const h = parseInt(orTimeMatch[1], 10) || 0;
                const m = parseInt(orTimeMatch[2], 10) || 0;
                const s = parseInt(orTimeMatch[3], 10) || 0;
                const totalMin = h * 60 + m + s / 60;
                if (totalMin > 0) return { h: Math.floor(totalMin / 60), m: Math.round(totalMin % 60) };
              }
              const hMatch = cleanText.match(/(\d+)\s*ч/i);
              const mMatch = cleanText.match(/(\d+)\s*(?:м|мин|минут)/i);
              const h = hMatch ? parseInt(hMatch[1], 10) : 0;
              const m = mMatch ? parseInt(mMatch[1], 10) : 0;
              if (h > 0 || m > 0) return { h, m };
              return null;
            };

            const extractOfpSbuFromPlan = (text, type) => {
              const cleanText = cleanPlanText(text);
              if (type === 'sbu') {
                const mMatch = cleanText.match(/(\d+)\s*м(?!и)/i);
                if (mMatch) return `${mMatch[1]} м`;
                const kmMatch = cleanText.match(/([\d.,]+)\s*км/i);
                if (kmMatch) return `${parseFloat(kmMatch[1].replace(',', '.')).toFixed(1)} км`;
              }
              const setsRepsMatch = cleanText.match(/(\d+)\s*[×x]\s*(\d+)/i);
              if (setsRepsMatch) {
                const weightMatch = cleanText.match(/(\d+(?:[.,]\d+)?)\s*кг/i);
                return weightMatch ? `${setsRepsMatch[1]}×${setsRepsMatch[2]}, ${weightMatch[1]} кг` : `${setsRepsMatch[1]}×${setsRepsMatch[2]}`;
              }
              const minMatch = cleanText.match(/(\d+)\s*мин/i);
              if (minMatch) return `${minMatch[1]} мин`;
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

            /* Строки — только запланированные тренировки (из плана), формат как в попапе */
            const runningTypes = ['easy', 'long', 'long-run', 'tempo', 'interval', 'fartlek', 'race', 'control', 'walking', 'hiking', 'cycling', 'swimming'];
            const buildExtra = (type, text) => {
              if (type === 'other' || type === 'sbu') {
                const ofpSbu = extractOfpSbuFromPlan(text, type);
                if (ofpSbu) return ofpSbu;
              }
              const dist = extractDistanceFromPlan(text);
              if (runningTypes.includes(type)) {
                if (dist != null) return `${dist < 1 ? dist.toFixed(1) : Math.round(dist)} км`;
                return '';
              }
              const dur = extractDurationFromPlan(text);
              if (dur) return dur.h > 0 ? `${dur.h}ч ${dur.m}м` : `${dur.m} мин`;
              return '';
            };

            const items = day.planDay?.items ?? [];
            const trainingRows = items.length > 0
              ? items.map((item, idx) => {
                  const type = item.type ?? item.activity_type ?? day.planDay.type;
                  const text = item.text ?? item.description ?? day.planDay?.text;
                  const extra = buildExtra(type, text);
                  return { type, key: `${day.date}-${idx}`, label: TYPE_NAMES_ROW[type] ?? type, extra };
                })
              : day.planDay
                ? (() => {
                    const type = day.planDay.type;
                    const text = day.planDay.text;
                    const extra = buildExtra(type, text);
                    return [{ type, key: day.date, label: TYPE_NAMES_ROW[type] ?? type, extra }];
                  })()
                : [];

            return (
              <div
                key={day.date}
                className={`month-day-cell ${day.isToday ? 'today' : ''} ${day.isCompleted ? 'completed' : ''} ${day.isRestExtra ? 'rest-extra' : ''} ${day.planDay ? 'has-plan' : ''}`}
                onClick={() => handleDateClick(day)}
              >
                <div className="day-number">{day.day}</div>
                
                {day.isCompleted && (
                  <div className="completed-indicator">
                    <CompletedIcon size={14} aria-hidden />
                  </div>
                )}
                {day.isRestExtra && !day.isCompleted && (
                  <div className={`rest-extra-dot legend-dot legend-dot--${getWorkoutStripClass(day.restExtraType) || 'run'}`} title="Тренировка в день отдыха" aria-hidden />
                )}
                
                {/* Классический вид (десктоп): строки с полоской + короткое название */}
                {trainingRows.length > 0 && (
                  <div className="month-day-training-rows">
                    {trainingRows.map(({ type, key, label, extra }) => {
                      const stripClass = getWorkoutStripClass(type);
                      const text = extra ? `${label} ${extra}` : label;
                      return (
                        <div
                          key={key}
                          className={`month-day-training-row${stripClass ? ` month-day-training-row--${stripClass}` : ''}`}
                          title={day.planDay?.text}
                        >
                          <span className="month-day-training-label">{text}</span>
                        </div>
                      );
                    })}
                  </div>
                )}
                
                {/* Мобильный: только цветные иконки (без полоски и текста) */}
                {trainingRows.length > 0 && (
                  <div className="month-day-icons">
                    {trainingRows.map(({ type, key }) => {
                      const stripClass = getWorkoutStripClass(type);
                      const Icon = type === 'long' || type === 'long-run' || type === 'easy' ? RunningIcon :
                        type === 'walking' ? WalkingIcon : type === 'hiking' ? HikingIcon :
                        type === 'cycling' ? CyclingIcon : type === 'swimming' ? SwimmingIcon :
                        type === 'interval' ? ZapIcon : type === 'tempo' ? FlameIcon :
                        type === 'other' ? OtherIcon : type === 'sbu' ? SbuIcon :
                        type === 'fartlek' ? TargetIcon : type === 'control' ? BarChartIcon :
                        type === 'race' ? FlagIcon : type === 'rest' ? RestIcon : ClipboardListIcon;
                      return (
                        <div key={key} className={`month-day-icon${stripClass ? ` month-day-icon--${stripClass}` : ''}`} title={day.planDay?.text}>
                          <Icon size={14} aria-hidden />
                        </div>
                      );
                    })}
                  </div>
                )}
                
                <div className="workout-info">
                  {day.planDay && day.planDay.type !== 'rest' && day.planDay.type !== 'free' && (
                    <div className="workout-type">
                      {getWorkoutTypeName(day.planDay.type)}
                      {day.planDay.is_key_workout && <span className="key-workout-dot" title="Ключевая тренировка" />}
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
                
                {day.planDay && day.planDay.type === 'rest' && !day.isCompleted && !day.isRestExtra && (
                  <div className="rest-indicator" title="Отдых"><RestIcon size={16} aria-hidden /></div>
                )}
              </div>
            );
          })}
        </div>
      </div>

      <div className="monthly-calendar-legend" aria-label="Легенда типов тренировок">
        <div className="legend-item">
          <span className="legend-dot legend-dot--today" aria-hidden />
          <span>Сегодня</span>
        </div>
        <div className="legend-item">
          <span className="legend-dot legend-dot--completed" aria-hidden />
          <span>Выполнено</span>
        </div>
        {LEGEND_ITEMS.map(({ type, label }) => (
          <div key={type} className="legend-item">
            <span className={`legend-dot legend-dot--${type}`} aria-hidden />
            <span>{label}</span>
          </div>
        ))}
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
