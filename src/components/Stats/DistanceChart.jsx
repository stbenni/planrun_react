/**
 * Компонент графика дистанции (для десктопов)
 */

import React, { useState } from 'react';

const DistanceChart = ({ data }) => {
  const [tooltip, setTooltip] = useState(null);
  const [tooltipPosition, setTooltipPosition] = useState({ x: 0, y: 0 });

  if (!data || data.length === 0) {
    return <div className="chart-empty">Нет данных для графика</div>;
  }

  const maxDistance = Math.max(...data.map(d => d.distance), 1);
  const chartHeight = 200;
  
  // Вычисляем значения для Y-оси (5 делений)
  const yAxisValues = [];
  for (let i = 0; i <= 4; i++) {
    yAxisValues.push(Math.round((maxDistance / 4) * i));
  }

  // Определяем, какие метки показывать (только ключевые даты)
  // Показываем: начало, конец, и каждую неделю
  const showLabelIndexes = new Set();
  if (data.length > 0) {
    showLabelIndexes.add(0); // Первый день
    showLabelIndexes.add(data.length - 1); // Последний день
    
    // Каждую неделю (примерно каждые 7 дней)
    const step = Math.max(1, Math.floor(data.length / 6));
    for (let i = step; i < data.length - 1; i += step) {
      showLabelIndexes.add(i);
    }
  }

  const handleMouseEnter = (e, item) => {
    const rect = e.currentTarget.getBoundingClientRect();
    
    setTooltip({
      date: item.dateLabel,
      distance: Math.round(item.distance * 10) / 10, // Округляем до 1 знака после запятой
      workouts: item.workouts
    });
    
    // Позиционируем подсказку относительно столбца графика
    setTooltipPosition({
      x: rect.left + rect.width / 2,
      y: rect.top - 10
    });
  };

  const handleMouseLeave = () => {
    setTooltip(null);
  };

  return (
    <div className="distance-chart">
      <div className="chart-header">
        <div className="chart-legend">
          <div className="legend-item">
            <div className="legend-color" style={{ background: 'var(--primary-500)' }}></div>
            <span>Дистанция (км)</span>
          </div>
        </div>
      </div>
      
      <div className="chart-bars-container">
        <div className="chart-y-axis">
          {yAxisValues.reverse().map((value, i) => (
            <div key={i} className="y-axis-label">
              {value}
            </div>
          ))}
        </div>
        
        <div className="chart-bars-wrapper">
          <div className="chart-bars">
            {data.map((item, index) => {
              const barHeight = maxDistance > 0 ? (item.distance / maxDistance) * chartHeight : 0;
              const date = new Date(item.date + 'T00:00:00');
              const dayOfWeek = date.getDay();
              const isWeekend = dayOfWeek === 0 || dayOfWeek === 6; // Выходной день
              const showLabel = showLabelIndexes.has(index);
              
              return (
                <div 
                  key={index} 
                  className={`chart-bar-container ${isWeekend ? 'weekend' : ''}`}
                  onMouseEnter={(e) => handleMouseEnter(e, item)}
                  onMouseLeave={handleMouseLeave}
                >
                  <div className="chart-bar-wrapper">
                    {item.distance > 0 ? (
                      <>
                        <div 
                          className="chart-bar"
                          style={{ 
                            height: `${barHeight}px`,
                            minHeight: barHeight > 0 ? '4px' : '0'
                          }}
                        />
                        {item.workouts > 0 && (
                          <div className="chart-bar-dots">
                            {Array.from({ length: Math.min(item.workouts, 3) }).map((_, i) => (
                              <div key={i} className="chart-dot" />
                            ))}
                          </div>
                        )}
                      </>
                    ) : (
                      <div className="chart-bar-empty" />
                    )}
                  </div>
                  {showLabel && (
                    <div className="chart-label">
                      {item.dateLabel}
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        </div>
      </div>
      
      {tooltip && (
        <div 
          className="chart-tooltip"
          style={{
            left: `${tooltipPosition.x}px`,
            top: `${tooltipPosition.y}px`,
            transform: 'translateX(-50%) translateY(-100%)'
          }}
        >
          <div className="tooltip-date">{tooltip.date}</div>
          <div className="tooltip-distance">{tooltip.distance.toFixed(1)} км</div>
          {tooltip.workouts > 0 && (
            <div className="tooltip-workouts">{tooltip.workouts} {tooltip.workouts === 1 ? 'тренировка' : tooltip.workouts < 5 ? 'тренировки' : 'тренировок'}</div>
          )}
        </div>
      )}
      
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

export default DistanceChart;
