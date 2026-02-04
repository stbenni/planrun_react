/**
 * WeekCalendar - Недельный календарь в стиле OMY! Sports
 * Показывает неделю с цветовыми индикаторами и карточками тренировок
 * Поддерживает swipe-жесты для навигации между неделями
 */

import React, { useState, useEffect, useRef } from 'react';
import WorkoutCard from './WorkoutCard';
import './WeekCalendar.css';

const WeekCalendar = ({ plan, progressData, workoutsData, resultsData, api, onDayPress, currentWeekNumber }) => {
  const [currentWeek, setCurrentWeek] = useState(null);
  const [weeks, setWeeks] = useState([]);
  const [selectedDate, setSelectedDate] = useState(null); // Выбранная дата, по умолчанию сегодня
  const [dayDetails, setDayDetails] = useState({}); // Данные о днях: {date: {plan, dayExercises, workouts}}
  const [loadingDays, setLoadingDays] = useState(false);
  const [isSwiping, setIsSwiping] = useState(false);
  const swipeStartX = useRef(0);
  const swipeStartY = useRef(0);
  const containerRef = useRef(null);

  useEffect(() => {
    if (plan && plan.phases) {
      const allWeeks = [];
      plan.phases.forEach(phase => {
        if (phase.weeks_data) {
          phase.weeks_data.forEach(week => {
            allWeeks.push({
              ...week,
              phaseName: phase.name
            });
          });
        }
      });
      setWeeks(allWeeks);
      
      // Находим текущую неделю
      const today = new Date();
      today.setHours(0, 0, 0, 0);
      
      const foundWeek = allWeeks.find(week => {
        if (!week.start_date) return false;
        const startDate = new Date(week.start_date);
        startDate.setHours(0, 0, 0, 0);
        const endDate = new Date(startDate);
        endDate.setDate(endDate.getDate() + 6);
        endDate.setHours(23, 59, 59, 999);
        return today >= startDate && today <= endDate;
      });
      
      if (foundWeek) {
        setCurrentWeek(foundWeek);
        // Устанавливаем выбранную дату на сегодня
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const year = today.getFullYear();
        const month = String(today.getMonth() + 1).padStart(2, '0');
        const day = String(today.getDate()).padStart(2, '0');
        setSelectedDate(`${year}-${month}-${day}`);
      } else if (allWeeks.length > 0) {
        setCurrentWeek(allWeeks[0]);
        // Если сегодняшний день не найден, выбираем первый день первой недели
        const firstWeek = allWeeks[0];
        if (firstWeek && firstWeek.start_date) {
          setSelectedDate(firstWeek.start_date);
        }
      }
    }
  }, [plan]);

  const getWeekDays = (week) => {
    if (!week || !week.start_date) return [];
    
    const days = [];
    // ВАЖНО: парсим дату правильно, чтобы избежать проблем с часовыми поясами
    const startDate = new Date(week.start_date + 'T00:00:00');
    startDate.setHours(0, 0, 0, 0);
    
    // ВАЖНО: start_date в БД ВСЕГДА понедельник недели (см. api.php:1507-1510)
    // Поэтому мы можем просто использовать индекс i для определения dayKey
    const dayKeys = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    const dayLabels = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
    
    for (let i = 0; i < 7; i++) {
      const date = new Date(startDate);
      date.setDate(startDate.getDate() + i);
      // Используем UTC для правильного форматирования даты
      const year = date.getFullYear();
      const month = String(date.getMonth() + 1).padStart(2, '0');
      const day = String(date.getDate()).padStart(2, '0');
      const dateStr = `${year}-${month}-${day}`;
      
      // i=0 → понедельник → 'mon'
      // i=1 → вторник → 'tue'
      // и т.д.
      const dayKey = dayKeys[i];
      const dayData = week.days && week.days[dayKey];
      
      const isToday = (() => {
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        return date.getTime() === today.getTime();
      })();
      
      const isCompleted = progressData[dateStr] || false;
      const status = isCompleted ? 'completed' : (dayData && dayData.type !== 'rest' ? 'planned' : 'rest');
      
      days.push({
        date: dateStr,
        dateObj: date,
        dayKey,
        dayLabel: dayLabels[i],
        dayData,
        isToday,
        status,
        weekNumber: week.number
      });
    }
    
    return days;
  };

  // Загружаем детальные данные для выбранной даты
  useEffect(() => {
    if (!selectedDate || !api) return;
    
    const loadDayData = async () => {
      // Проверяем, не загружены ли уже данные для этой даты
      if (dayDetails[selectedDate]) return;
      
      setLoadingDays(true);
      
      try {
        const response = await api.getDay(selectedDate);
        const data = response?.data || response;
        if (data && !data.error) {
          setDayDetails(prev => ({
            ...prev,
            [selectedDate]: {
              plan: data.plan || data.planHtml || '',
              dayExercises: data.dayExercises || [],
              workouts: data.workouts || []
            }
          }));
        }
      } catch (error) {
        console.error(`Error loading day ${selectedDate}:`, error);
      } finally {
        setLoadingDays(false);
      }
    };
    
    loadDayData();
  }, [selectedDate, api]);

  const goToPreviousWeek = () => {
    if (!currentWeek) return;
    const currentIndex = weeks.findIndex(w => w.number === currentWeek.number);
    if (currentIndex > 0) {
      const prevWeek = weeks[currentIndex - 1];
      setCurrentWeek(prevWeek);
      // Выбираем первый день предыдущей недели
      if (prevWeek && prevWeek.start_date) {
        setSelectedDate(prevWeek.start_date);
      }
    }
  };

  const goToNextWeek = () => {
    if (!currentWeek) return;
    const currentIndex = weeks.findIndex(w => w.number === currentWeek.number);
    if (currentIndex < weeks.length - 1) {
      const nextWeek = weeks[currentIndex + 1];
      setCurrentWeek(nextWeek);
      // Выбираем первый день следующей недели
      if (nextWeek && nextWeek.start_date) {
        setSelectedDate(nextWeek.start_date);
      }
    }
  };

  const goToCurrentWeek = () => {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    
    const foundWeek = weeks.find(week => {
      if (!week.start_date) return false;
      const startDate = new Date(week.start_date);
      startDate.setHours(0, 0, 0, 0);
      const endDate = new Date(startDate);
      endDate.setDate(endDate.getDate() + 6);
      endDate.setHours(23, 59, 59, 999);
      return today >= startDate && today <= endDate;
    });
    
    if (foundWeek) {
      setCurrentWeek(foundWeek);
      // Устанавливаем выбранную дату на сегодня
      const today = new Date();
      today.setHours(0, 0, 0, 0);
      const year = today.getFullYear();
      const month = String(today.getMonth() + 1).padStart(2, '0');
      const day = String(today.getDate()).padStart(2, '0');
      setSelectedDate(`${year}-${month}-${day}`);
    }
  };

  // Swipe жесты для мобильных устройств
  useEffect(() => {
    const container = containerRef.current;
    if (!container) return;

    const handleTouchStart = (e) => {
      swipeStartX.current = e.touches[0].clientX;
      swipeStartY.current = e.touches[0].clientY;
      setIsSwiping(false);
    };

    const handleTouchMove = (e) => {
      if (!swipeStartX.current || !swipeStartY.current) return;
      
      const deltaX = e.touches[0].clientX - swipeStartX.current;
      const deltaY = e.touches[0].clientY - swipeStartY.current;
      
      // Проверяем, что это горизонтальный swipe (не вертикальный скролл)
      if (Math.abs(deltaX) > Math.abs(deltaY) && Math.abs(deltaX) > 10) {
        setIsSwiping(true);
        e.preventDefault(); // Предотвращаем скролл при горизонтальном swipe
      }
    };

    const handleTouchEnd = (e) => {
      if (!swipeStartX.current || !swipeStartY.current) return;
      
      const deltaX = e.changedTouches[0].clientX - swipeStartX.current;
      const deltaY = e.changedTouches[0].clientY - swipeStartY.current;
      
      // Минимальное расстояние для swipe (50px)
      if (Math.abs(deltaX) > 50 && Math.abs(deltaX) > Math.abs(deltaY)) {
        if (deltaX > 0) {
          // Swipe вправо - предыдущая неделя
          goToPreviousWeek();
        } else {
          // Swipe влево - следующая неделя
          goToNextWeek();
        }
      }
      
      swipeStartX.current = 0;
      swipeStartY.current = 0;
      setIsSwiping(false);
    };

    container.addEventListener('touchstart', handleTouchStart, { passive: false });
    container.addEventListener('touchmove', handleTouchMove, { passive: false });
    container.addEventListener('touchend', handleTouchEnd, { passive: true });

    return () => {
      container.removeEventListener('touchstart', handleTouchStart);
      container.removeEventListener('touchmove', handleTouchMove);
      container.removeEventListener('touchend', handleTouchEnd);
    };
  }, [currentWeek, weeks]);

  if (!currentWeek) {
    return (
      <div className="week-calendar-empty">
        <p>Загрузка календаря...</p>
      </div>
    );
  }

  const weekDays = getWeekDays(currentWeek);
  const today = new Date();
  today.setHours(0, 0, 0, 0);

  return (
    <div className={`week-calendar-container ${isSwiping ? 'swiping' : ''}`} ref={containerRef}>
      <div className="week-calendar-header">
        <div className="week-calendar-title">
          <div className="week-title-main">
            Неделя {currentWeek.number}
            {currentWeek.phaseName && (
              <span className="week-phase-name"> • {currentWeek.phaseName}</span>
            )}
          </div>
          <div className="week-title-dates">
            {weekDays[0] && weekDays[6] && (
              <>
                {weekDays[0].dateObj.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' })} - 
                {weekDays[6].dateObj.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' })}
              </>
            )}
          </div>
        </div>
        
        <div className="week-calendar-nav">
          <button 
            className="week-nav-btn"
            onClick={goToPreviousWeek}
            disabled={weeks.findIndex(w => w.number === currentWeek.number) === 0}
            aria-label="Предыдущая неделя"
          />
          
          <button 
            className="week-current-btn"
            onClick={goToCurrentWeek}
            title="Перейти к текущей неделе"
          >
            Сегодня
          </button>
          
          <button 
            className="week-nav-btn"
            onClick={goToNextWeek}
            disabled={weeks.findIndex(w => w.number === currentWeek.number) === weeks.length - 1}
            aria-label="Следующая неделя"
          />
        </div>
      </div>

      <div className="week-days-grid">
        {weekDays.map((day, index) => (
          <div
            key={day.date}
            className={`week-day-cell ${day.isToday ? 'today' : ''} ${day.status} ${selectedDate === day.date ? 'selected' : ''}`}
            onClick={() => {
              setSelectedDate(day.date);
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
                   day.dayData.type === 'sbu' ? '🏋️' :
                   '🏃'}
                </div>
                <div className="workout-type-text">
                  {day.dayData.type === 'long' || day.dayData.type === 'long-run' ? 'Длительный' :
                   day.dayData.type === 'interval' ? 'Интервалы' :
                   day.dayData.type === 'tempo' ? 'Темп' :
                   day.dayData.type === 'easy' ? 'Легкий' :
                   day.dayData.type === 'other' ? 'ОФП' :
                   day.dayData.type === 'sbu' ? 'СБУ' :
                   day.dayData.type === 'fartlek' ? 'Фартлек' :
                   day.dayData.type === 'race' ? 'Соревнование' :
                   day.dayData.type === 'free' ? 'Свободная' :
                   day.dayData.text || 'Тренировка'}
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

      {selectedDate && (() => {
        const selectedDay = weekDays.find(d => d.date === selectedDate);
        if (!selectedDay) return null;
        
        const dayDetail = dayDetails[selectedDay.date] || {};
        const workout = workoutsData && workoutsData[selectedDay.date] ? workoutsData[selectedDay.date] : null;
        const results = resultsData && resultsData[selectedDay.date] ? (Array.isArray(resultsData[selectedDay.date]) ? resultsData[selectedDay.date] : [resultsData[selectedDay.date]]) : [];
        
        // Объединяем данные для WorkoutCard
        // Очищаем HTML из plan для отображения
        const planText = dayDetail.plan || selectedDay.dayData?.text || '';
        const planTextClean = planText
          .replace(/<[^>]*>/g, '') // Убираем HTML теги
          .replace(/&nbsp;/g, ' ')
          .replace(/&amp;/g, '&')
          .replace(/&lt;/g, '<')
          .replace(/&gt;/g, '>')
          .replace(/&quot;/g, '"')
          .trim();
        
        const workoutData = {
          ...selectedDay.dayData,
          // Добавляем полное описание из dayDetails
          text: planTextClean,
          // Добавляем упражнения
          dayExercises: dayDetail.dayExercises || []
        };
        
        return (
          <div className="week-selected-day">
            <WorkoutCard
              workout={workoutData}
              date={selectedDay.date}
              status={selectedDay.status}
              isToday={selectedDay.isToday}
              onPress={() => {
                if (onDayPress) {
                  onDayPress(selectedDay.date, selectedDay.weekNumber, selectedDay.dayKey);
                }
              }}
              dayDetail={dayDetail}
              workoutMetrics={workout ? {
                distance: workout.distance,
                duration: workout.duration,
                pace: workout.pace
              } : null}
              results={results}
            />
          </div>
        );
      })()}
    </div>
  );
};

export default WeekCalendar;
