/**
 * WeekCalendar - Недельный календарь в стиле OMY! Sports
 * Показывает неделю с цветовыми индикаторами и карточками тренировок
 * Поддерживает swipe-жесты для навигации между неделями
 * При пустом плане всегда показывается виртуальная текущая неделя (пустая сетка).
 */

import React, { useState, useEffect, useRef, useCallback } from 'react';
import WorkoutCard from './WorkoutCard';
import './WeekCalendar.css';

const EMPTY_DAYS = { mon: null, tue: null, wed: null, thu: null, fri: null, sat: null, sun: null };

/** Добавить дни к дате YYYY-MM-DD, вернуть новую YYYY-MM-DD */
function addDays(dateStr, delta) {
  const d = new Date(dateStr + 'T00:00:00');
  d.setDate(d.getDate() + delta);
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

/** Понедельник текущей недели в формате YYYY-MM-DD */
function getMondayOfToday() {
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const dayOfWeek = today.getDay();
  const diff = dayOfWeek === 0 ? -6 : 1 - dayOfWeek;
  const monday = new Date(today);
  monday.setDate(today.getDate() + diff);
  const y = monday.getFullYear();
  const m = String(monday.getMonth() + 1).padStart(2, '0');
  const d = String(monday.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

/** Неделя для отображения: понедельник + пустая сетка дней (календарь не привязан к плану) */
function getVirtualWeekForStartDate(startDateStr) {
  return {
    number: 0,
    start_date: startDateStr,
    total_volume: '',
    days: { ...EMPTY_DAYS },
  };
}

/** Неделя для отображения: из плана, если есть на эту дату, иначе просто календарная неделя */
function getWeekForStartDate(plan, startDateStr) {
  const weeksData = plan?.weeks_data;
  if (Array.isArray(weeksData)) {
    const found = weeksData.find((w) => w.start_date === startDateStr);
    if (found) return { ...found };
  }
  return getVirtualWeekForStartDate(startDateStr);
}

function getVirtualCurrentWeek() {
  return getVirtualWeekForStartDate(getMondayOfToday());
}

const WeekCalendar = ({ plan, progressData, workoutsData, resultsData, api, canEdit = false, onDayPress, onOpenResultModal, onAddTraining, onEditTraining, onTrainingAdded, currentWeekNumber }) => {
  const [currentWeek, setCurrentWeek] = useState(getVirtualCurrentWeek);
  const [selectedDate, setSelectedDate] = useState(() => {
    const t = new Date();
    t.setHours(0, 0, 0, 0);
    return `${t.getFullYear()}-${String(t.getMonth() + 1).padStart(2, '0')}-${String(t.getDate()).padStart(2, '0')}`;
  });
  const [dayDetails, setDayDetails] = useState({});
  const [loadingDays, setLoadingDays] = useState(false);
  const [isSwiping, setIsSwiping] = useState(false);
  const swipeStartX = useRef(0);
  const swipeStartY = useRef(0);
  const containerRef = useRef(null);

  useEffect(() => {
    const todayStr = (() => {
      const t = new Date();
      t.setHours(0, 0, 0, 0);
      return `${t.getFullYear()}-${String(t.getMonth() + 1).padStart(2, '0')}-${String(t.getDate()).padStart(2, '0')}`;
    })();
    const mondayStr = getMondayOfToday();
    const week = getWeekForStartDate(plan, mondayStr);
    setCurrentWeek(week);
    setSelectedDate(todayStr);
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
      const rawDay = week.days && week.days[dayKey];
      const dayData = Array.isArray(rawDay)
        ? rawDay.find((d) => d && d.type !== 'rest' && d.type !== 'free') || null
        : rawDay && rawDay.type !== 'rest' && rawDay.type !== 'free'
          ? rawDay
          : null;
      
      const isToday = (() => {
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        return date.getTime() === today.getTime();
      })();
      
      const isCompleted = progressData[dateStr] || false;
      const status = isCompleted ? 'completed' : (dayData ? 'planned' : 'rest');
      
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

  const loadDayDataForDate = useCallback(async (date) => {
    if (!date || !api?.getDay) return;
    setLoadingDays(true);
    try {
      const response = await api.getDay(date);
      const data = response?.data || response;
      if (data && !data.error) {
        setDayDetails(prev => ({
          ...prev,
          [date]: {
            plan: data.plan || data.planHtml || '',
            planHtml: data.planHtml || null,
            planDays: data.planDays || [],
            dayExercises: data.dayExercises || [],
            workouts: data.workouts || []
          }
        }));
      }
    } catch (error) {
      console.error(`Error loading day ${date}:`, error);
    } finally {
      setLoadingDays(false);
    }
  }, [api]);

  // Загрузка/обновление дня при смене даты или при обновлении плана (после добавления/удаления тренировки)
  useEffect(() => {
    if (!selectedDate) return;
    loadDayDataForDate(selectedDate);
  }, [plan, selectedDate, loadDayDataForDate]);

  const handleDeletePlanDay = async (dayId) => {
    if (!dayId || !api?.deleteTrainingDay) return;
    if (!window.confirm('Удалить эту тренировку из плана?')) return;
    try {
      await api.deleteTrainingDay(dayId);
      onTrainingAdded?.();
      await loadDayDataForDate(selectedDate);
    } catch (err) {
      console.error('Error deleting plan day:', err);
      alert('Ошибка удаления: ' + (err?.message || 'Не удалось удалить тренировку'));
    }
  };

  const goToPreviousWeek = () => {
    if (!currentWeek?.start_date) return;
    const prevStart = addDays(currentWeek.start_date, -7);
    setCurrentWeek(getWeekForStartDate(plan, prevStart));
    setSelectedDate(prevStart);
  };

  const goToNextWeek = () => {
    if (!currentWeek?.start_date) return;
    const nextStart = addDays(currentWeek.start_date, 7);
    setCurrentWeek(getWeekForStartDate(plan, nextStart));
    setSelectedDate(nextStart);
  };

  const goToCurrentWeek = () => {
    const mondayStr = getMondayOfToday();
    const t = new Date();
    t.setHours(0, 0, 0, 0);
    const todayStr = `${t.getFullYear()}-${String(t.getMonth() + 1).padStart(2, '0')}-${String(t.getDate()).padStart(2, '0')}`;
    setCurrentWeek(getWeekForStartDate(plan, mondayStr));
    setSelectedDate(todayStr);
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
  }, [currentWeek]);

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
          {weekDays[0] && weekDays[6] && (
            <div className="week-title-main">
              {weekDays[0].dateObj.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' })} – {weekDays[6].dateObj.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' })}
            </div>
          )}
        </div>
        
        <div className="week-calendar-nav">
          <button
            type="button"
            className="week-nav-btn"
            onClick={goToPreviousWeek}
            aria-label="Предыдущая неделя"
          />
          <button
            type="button"
            className="week-current-btn"
            onClick={goToCurrentWeek}
            title="Перейти к текущей неделе"
          >
            Сегодня
          </button>
          <button
            type="button"
            className="week-nav-btn week-nav-btn-next"
            onClick={goToNextWeek}
            aria-label="Следующая неделя"
          />
        </div>
      </div>

      <div className="week-days-grid">
        {weekDays.map((day, index) => (
          <div
            key={day.date}
            className={`week-day-cell ${day.isToday ? 'today' : ''} ${day.status} ${selectedDate === day.date ? 'selected active' : ''}`}
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
            
            {day.dayData && day.dayData.type !== 'rest' && day.dayData.type !== 'free' && (
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
                   day.dayData.text || 'Тренировка'}
                </div>
              </div>
            )}
            
            {day.dayData && day.dayData.type === 'rest' && (
              <div className="week-day-rest">
                <span className="rest-text">Отдых</span>
              </div>
            )}
            
            {(!day.dayData || day.dayData.type === 'free') && (
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
              dayDetail={dayDetail}
              workoutMetrics={workout ? {
                distance: workout.distance,
                duration: workout.duration,
                pace: workout.pace
              } : null}
              results={results}
              planDays={dayDetail.planDays || []}
              onDeletePlanDay={canEdit ? handleDeletePlanDay : undefined}
              onEditPlanDay={canEdit && onEditTraining ? (planDay) => onEditTraining(planDay, selectedDay.date) : undefined}
              onPress={canEdit && onOpenResultModal && selectedDay.status === 'missed' ? () => onOpenResultModal(selectedDay.date, selectedDay.weekNumber ?? 1, selectedDay.dayKey) : undefined}
              canEdit={!!canEdit}
            />
            {canEdit && (onAddTraining || (onOpenResultModal && (selectedDay.dayData || (dayDetail.planDays && dayDetail.planDays.length > 0)))) && (
              <div className="week-selected-day-actions">
                {onAddTraining && (
                  <button
                    type="button"
                    className="btn btn-secondary"
                    onClick={() => onAddTraining(selectedDay.date)}
                  >
                    <span className="week-add-training-btn-icon" aria-hidden>+</span>
                    Запланировать тренировку
                  </button>
                )}
                {onOpenResultModal && (selectedDay.dayData || (dayDetail.planDays && dayDetail.planDays.length > 0)) && (
                  <button
                    type="button"
                    className="btn btn-primary"
                    onClick={() => onOpenResultModal(selectedDay.date, selectedDay.weekNumber ?? 1, selectedDay.dayKey)}
                  >
                    Отметить выполненной
                  </button>
                )}
              </div>
            )}
          </div>
        );
      })()}
    </div>
  );
};

export default WeekCalendar;
