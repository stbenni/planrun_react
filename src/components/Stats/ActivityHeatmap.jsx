/**
 * Компонент Heatmap календаря (для мобильных)
 */

import React, { useState, useEffect, useRef } from 'react';

const ActivityHeatmap = ({ data }) => {
  const [selectedDay, setSelectedDay] = useState(null);
  const [tooltipPosition, setTooltipPosition] = useState({ x: 0, y: 0 });
  const [currentMonthIndex, setCurrentMonthIndex] = useState(0);
  const [isSwiping, setIsSwiping] = useState(false);
  const swipeStartX = useRef(0);
  const swipeStartY = useRef(0);
  const containerRef = useRef(null);

  if (!data || !Array.isArray(data) || data.length === 0) {
    return <div className="chart-empty">Нет данных для графика</div>;
  }

  const maxDistance = Math.max(...data.map(d => (d && d.distance) || 0), 1);
  
  // Группируем данные по месяцам
  const monthsData = [];
  const dataMap = {}; // Карта для быстрого доступа к данным по дате
  
  data.forEach(day => {
    if (day && day.date) {
      dataMap[day.date] = day;
      const date = new Date(day.date + 'T00:00:00');
      if (!isNaN(date.getTime())) {
        const monthKey = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
        
        let monthData = monthsData.find(m => m.key === monthKey);
        if (!monthData) {
          monthData = {
            key: monthKey,
            year: date.getFullYear(),
            month: date.getMonth(),
            days: []
          };
          monthsData.push(monthData);
        }
        monthData.days.push(day);
      }
    }
  });

  // Сортируем месяцы
  monthsData.sort((a, b) => a.key.localeCompare(b.key));

  // Устанавливаем начальный месяц (последний месяц с данными)
  useEffect(() => {
    if (monthsData.length > 0 && currentMonthIndex >= monthsData.length) {
      setCurrentMonthIndex(monthsData.length - 1);
    }
  }, [monthsData.length, currentMonthIndex]);

  // Обработчики свайпа (смахивания)
  const handleTouchStart = (e) => {
    if (monthsData.length <= 1) return; // Не нужен свайп, если месяц один
    swipeStartX.current = e.touches[0].clientX;
    swipeStartY.current = e.touches[0].clientY;
    setIsSwiping(false);
  };

  const handleTouchMove = (e) => {
    if (monthsData.length <= 1) return;
    const deltaX = e.touches[0].clientX - swipeStartX.current;
    const deltaY = e.touches[0].clientY - swipeStartY.current;
    
    // Определяем, это горизонтальный или вертикальный свайп
    if (Math.abs(deltaX) > Math.abs(deltaY) && Math.abs(deltaX) > 10) {
      setIsSwiping(true);
      e.preventDefault(); // Предотвращаем скролл страницы
    }
  };

  const handleTouchEnd = (e) => {
    if (monthsData.length <= 1 || !isSwiping) return;
    
    const deltaX = e.changedTouches[0].clientX - swipeStartX.current;
    const threshold = 50; // Минимальное расстояние для свайпа
    
    if (Math.abs(deltaX) > threshold) {
      if (deltaX > 0 && currentMonthIndex > 0) {
        // Свайп вправо - предыдущий месяц
        setCurrentMonthIndex(currentMonthIndex - 1);
      } else if (deltaX < 0 && currentMonthIndex < monthsData.length - 1) {
        // Свайп влево - следующий месяц
        setCurrentMonthIndex(currentMonthIndex + 1);
      }
    }
    
    setIsSwiping(false);
  };

  const goToPreviousMonth = () => {
    if (currentMonthIndex > 0) {
      setCurrentMonthIndex(currentMonthIndex - 1);
    }
  };

  const goToNextMonth = () => {
    if (currentMonthIndex < monthsData.length - 1) {
      setCurrentMonthIndex(currentMonthIndex + 1);
    }
  };

  // Функция для получения календаря месяца
  const getMonthCalendar = (year, month) => {
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);
    const daysInMonth = lastDay.getDate();
    const startDayOfWeek = firstDay.getDay(); // 0 = воскресенье
    const offset = startDayOfWeek === 0 ? 6 : startDayOfWeek - 1; // Приводим к понедельнику = 0 (понедельник = 0)
    
    const days = [];
    
    // Пустые ячейки в начале
    for (let i = 0; i < offset; i++) {
      days.push(null);
    }
    
    // Дни месяца
    for (let day = 1; day <= daysInMonth; day++) {
      const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
      days.push({
        day,
        date: dateStr,
        data: dataMap[dateStr] || null
      });
    }
    
    return days;
  };

  const monthNames = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 
                      'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
  const dayNames = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];

  const handleDayClick = (e, day) => {
    const rect = e.currentTarget.getBoundingClientRect();
    setSelectedDay(day);
    setTooltipPosition({
      x: rect.left + rect.width / 2,
      y: rect.top - 10
    });
  };

  const handleCloseTooltip = () => {
    setSelectedDay(null);
  };

  const currentMonth = monthsData[currentMonthIndex];
  if (!currentMonth) {
    return <div className="chart-empty">Нет данных для графика</div>;
  }

  const calendarDays = getMonthCalendar(currentMonth.year, currentMonth.month);

  return (
    <div className="activity-heatmap">
      <div className="heatmap-header">
        <div className="chart-legend">
          <div className="legend-item">
            <div className="legend-color" style={{ background: 'var(--primary-500)' }}></div>
            <span>Дистанция (км)</span>
          </div>
        </div>
      </div>
      
      {monthsData.length > 1 && (
        <div className="heatmap-nav">
          <button 
            className="heatmap-nav-btn"
            onClick={goToPreviousMonth}
            disabled={currentMonthIndex === 0}
            aria-label="Предыдущий месяц"
          >
            ‹
          </button>
          <div className="heatmap-nav-indicator">
            {currentMonthIndex + 1} / {monthsData.length}
          </div>
          <button 
            className="heatmap-nav-btn"
            onClick={goToNextMonth}
            disabled={currentMonthIndex === monthsData.length - 1}
            aria-label="Следующий месяц"
          >
            ›
          </button>
        </div>
      )}
      
      <div 
        className="heatmap-months-container"
        ref={containerRef}
        onTouchStart={handleTouchStart}
        onTouchMove={handleTouchMove}
        onTouchEnd={handleTouchEnd}
      >
        <div className="heatmap-months-wrapper">
          <div 
            className="heatmap-months-slider"
            style={{
              transform: `translateX(-${currentMonthIndex * 100}%)`,
              transition: isSwiping ? 'none' : 'transform 300ms ease-out'
            }}
          >
            {monthsData.map((monthData, index) => {
              const monthCalendarDays = getMonthCalendar(monthData.year, monthData.month);
              
              return (
                <div key={monthData.key} className="heatmap-month">
                  <div className="heatmap-month-title">
                    {monthNames[monthData.month]} {monthData.year}
                  </div>
                  
                  <div className="heatmap-calendar">
                    {/* Заголовки дней недели */}
                    <div className="heatmap-weekdays">
                      {dayNames.map((dayName, i) => (
                        <div key={i} className="heatmap-weekday">{dayName}</div>
                      ))}
                    </div>
                    
                    {/* Календарная сетка */}
                    <div className="heatmap-days-grid">
                      {monthCalendarDays.map((dayInfo, dayIndex) => {
                        if (!dayInfo) {
                          return <div key={`empty-${index}-${dayIndex}`} className="heatmap-day empty" />;
                        }
                        
                        const dayData = dayInfo.data;
                        const hasActivity = dayData && (dayData.distance || 0) > 0;
                        const intensity = hasActivity && maxDistance > 0 
                          ? Math.min((dayData.distance || 0) / maxDistance, 1) 
                          : 0;
                        
                        const opacity = hasActivity 
                          ? Math.max(0.3, Math.min(0.3 + intensity * 0.7, 1)) 
                          : 0.05;
                        const backgroundColor = `rgba(255, 107, 53, ${opacity})`;
                        
                        return (
                          <div
                            key={dayInfo.date}
                            className={`heatmap-day ${hasActivity ? 'has-activity' : ''}`}
                            style={{ backgroundColor }}
                            onClick={(e) => dayData && handleDayClick(e, dayData)}
                          >
                            <div className="heatmap-day-number">{dayInfo.day}</div>
                            {hasActivity && dayData && (
                              <div className="heatmap-day-value">
                                {Math.round(dayData.distance || 0)} км
                              </div>
                            )}
                          </div>
                        );
                      })}
                    </div>
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      </div>
      
      {selectedDay && (
        <div 
          className="heatmap-tooltip"
          style={{
            left: `${tooltipPosition.x}px`,
            top: `${tooltipPosition.y}px`,
            transform: 'translateX(-50%) translateY(-100%)'
          }}
          onClick={handleCloseTooltip}
        >
          <div className="tooltip-header">
            <span className="tooltip-date">{selectedDay.dateLabel}</span>
            <button className="tooltip-close" onClick={handleCloseTooltip}>×</button>
          </div>
          <div className="tooltip-distance">
            {typeof selectedDay.distance === 'number' 
              ? selectedDay.distance.toFixed(1) 
              : parseFloat(selectedDay.distance || 0).toFixed(1)} км
          </div>
          {selectedDay.workouts > 0 && (
            <div className="tooltip-workouts">
              {selectedDay.workouts} {selectedDay.workouts === 1 ? 'тренировка' : selectedDay.workouts < 5 ? 'тренировки' : 'тренировок'}
            </div>
          )}
        </div>
      )}
      
      <div className="heatmap-legend">
        <span className="legend-text">Меньше</span>
        <div className="legend-gradient">
          <div className="gradient-step" style={{ opacity: 0.3 }}></div>
          <div className="gradient-step" style={{ opacity: 0.5 }}></div>
          <div className="gradient-step" style={{ opacity: 0.7 }}></div>
          <div className="gradient-step" style={{ opacity: 0.9 }}></div>
          <div className="gradient-step" style={{ opacity: 1.0 }}></div>
        </div>
        <span className="legend-text">Больше</span>
      </div>
      
      <div className="chart-summary">
        <div className="summary-item">
          <span className="summary-label">Средняя дистанция:</span>
          <span className="summary-value">
            {data.length > 0 
              ? (Math.round((data.reduce((sum, d) => sum + d.distance, 0) / data.length) * 10) / 10).toFixed(1)
              : '0.0'} км
          </span>
        </div>
        <div className="summary-item">
          <span className="summary-label">Всего тренировок:</span>
          <span className="summary-value">
            {data.reduce((sum, d) => sum + d.workouts, 0)}
          </span>
        </div>
      </div>
    </div>
  );
};

export default ActivityHeatmap;
