/**
 * График скорости по времени (км/ч).
 * Понятнее темпа: выше = быстрее, ниже = медленнее.
 */

import React, { useMemo, useState, useRef } from 'react';
import { useMediaQuery } from '../../hooks/useMediaQuery';
import { ZapIcon } from '../common/Icons';

const PaceChart = ({ timeline }) => {
  const isMobile = useMediaQuery('(max-width: 768px)');
  const [tooltip, setTooltip] = useState(null);
  const svgRef = useRef(null);

  const chartData = useMemo(() => {
    if (!timeline || timeline.length === 0) {
      return null;
    }

    // Темп (мин/км) → скорость (км/ч): speed = 60 / pace
    const dataPoints = timeline
      .map((point, index) => {
        if (!point.pace) return null;
        const paceParts = point.pace.split(':');
        if (paceParts.length !== 2) return null;
        const mins = parseInt(paceParts[0], 10);
        const secs = parseInt(paceParts[1], 10);
        if (isNaN(mins) || isNaN(secs)) return null;
        const paceInMinutes = mins + secs / 60;
        if (paceInMinutes <= 0) return null;
        const speedKmh = 60 / paceInMinutes;
        return {
          index,
          timestamp: point.timestamp,
          speed: Math.min(speedKmh, 25),
          paceString: point.pace
        };
      })
      .filter(point => point !== null);

    if (dataPoints.length === 0) return null;

    const step = Math.max(1, Math.floor(dataPoints.length / 500));
    const sampledIndices = new Set();
    for (let i = 0; i < dataPoints.length; i += step) sampledIndices.add(i);
    if (dataPoints.length > 1) sampledIndices.add(dataPoints.length - 1);
    const sampledData = dataPoints.filter((_, i) => sampledIndices.has(i));

    const minSpeed = Math.min(...sampledData.map(d => d.speed));
    const maxSpeed = Math.max(...sampledData.map(d => d.speed));
    const speedRange = maxSpeed - minSpeed || 0.5;
    const paddingPercent = 0.05;
    const adjustedMinSpeed = Math.max(0, minSpeed - speedRange * paddingPercent);
    const adjustedMaxSpeed = maxSpeed + speedRange * paddingPercent;
    const adjustedRange = adjustedMaxSpeed - adjustedMinSpeed;

    return {
      data: sampledData,
      minSpeed,
      maxSpeed,
      adjustedMinSpeed,
      adjustedMaxSpeed,
      adjustedRange,
      startTime: new Date(dataPoints[0].timestamp),
      endTime: new Date(dataPoints[dataPoints.length - 1].timestamp)
    };
  }, [timeline]);

  if (!chartData) {
    return null;
  }

  // Размеры графика (используем viewBox для адаптивности)
  const viewBoxWidth = 800;
  const viewBoxHeight = 250;
  const margin = { top: 20, right: 5, bottom: 35, left: 60 };
  const chartWidth = viewBoxWidth - margin.left - margin.right;
  const chartHeight = viewBoxHeight - margin.top - margin.bottom;

  // Масштабирование для оси X (время)
  const timeScale = (timestamp) => {
    const time = new Date(timestamp).getTime();
    const start = chartData.startTime.getTime();
    const end = chartData.endTime.getTime();
    const range = end - start || 1;
    return margin.left + ((time - start) / range) * chartWidth;
  };

  // Ось Y: скорость (км/ч). Выше на графике = быстрее
  const speedScale = (speed) => {
    return margin.top + ((chartData.adjustedMaxSpeed - speed) / chartData.adjustedRange) * chartHeight;
  };

  // Обратная функция для получения значения по X координате
  const getValueFromX = (x) => {
    const svg = svgRef.current;
    if (!svg) return null;
    
    const rect = svg.getBoundingClientRect();
    const scaleX = rect.width / viewBoxWidth;
    const localX = (x - rect.left) / scaleX;
    
    const chartX = localX - margin.left;
    if (chartX < 0 || chartX > chartWidth) return null;
    
    const start = chartData.startTime.getTime();
    const end = chartData.endTime.getTime();
    const range = end - start || 1;
    const timestamp = start + (chartX / chartWidth) * range;
    
    // Находим ближайшую точку данных
    let closestPoint = chartData.data[0];
    let minDistance = Infinity;
    
    chartData.data.forEach(point => {
      const pointTime = new Date(point.timestamp).getTime();
      const distance = Math.abs(pointTime - timestamp);
      if (distance < minDistance) {
        minDistance = distance;
        closestPoint = point;
      }
    });
    
    return closestPoint;
  };

  // Обработчик движения мыши
  const handleMouseMove = (e) => {
    const point = getValueFromX(e.clientX);
    if (!point) {
      setTooltip(null);
      return;
    }

    const svg = svgRef.current;
    if (!svg) return;

    const rect = svg.getBoundingClientRect();
    const scaleX = rect.width / viewBoxWidth;
    
    const x = timeScale(point.timestamp) * scaleX;
    const y = speedScale(point.speed) * (rect.height / viewBoxHeight);
    
    const time = new Date(point.timestamp);
    const hours = String(time.getHours()).padStart(2, '0');
    const minutes = String(time.getMinutes()).padStart(2, '0');
    const seconds = String(time.getSeconds()).padStart(2, '0');

    setTooltip({
      x: rect.left + x,
      y: rect.top + y,
      value: point.speed.toFixed(1),
      time: `${hours}:${minutes}:${seconds}`,
      point: point
    });
  };

  const handleMouseLeave = () => {
    setTooltip(null);
  };

  // Формируем путь для графика (линия продлевается до правого края, чтобы не было пустого отступа)
  const chartRight = margin.left + chartWidth;
  const lastPoint = chartData.data[chartData.data.length - 1];
  const lastY = lastPoint ? speedScale(lastPoint.speed) : margin.top + chartHeight / 2;
  const pathData = chartData.data
    .map((point, index) => {
      const x = timeScale(point.timestamp);
      const y = speedScale(point.speed);
      return `${index === 0 ? 'M' : 'L'} ${x} ${y}`;
    })
    .join(' ') + (lastPoint ? ` L ${chartRight} ${lastY}` : '');

  // Метки оси Y — скорость (км/ч)
  const yAxisLabels = [];
  const yAxisSteps = 5;
  for (let i = 0; i <= yAxisSteps; i++) {
    const speedValue = chartData.adjustedMinSpeed + (chartData.adjustedRange / yAxisSteps) * i;
    const y = speedScale(speedValue);
    yAxisLabels.push({ value: speedValue.toFixed(1), y });
  }

  // Формируем метки для оси X (время) - умное распределение
  const xAxisLabels = [];
  const duration = chartData.endTime - chartData.startTime;
  const durationMinutes = duration / (1000 * 60);
  
  // Определяем интервал в зависимости от длительности
  let intervalMinutes;
  if (durationMinutes <= 30) {
    intervalMinutes = 5;
  } else if (durationMinutes <= 60) {
    intervalMinutes = 10;
  } else if (durationMinutes <= 120) {
    intervalMinutes = 15;
  } else {
    intervalMinutes = 30;
  }

  const startMinutes = Math.floor(chartData.startTime.getMinutes() / intervalMinutes) * intervalMinutes;
  let currentTime = new Date(chartData.startTime);
  currentTime.setMinutes(startMinutes, 0, 0);

  while (currentTime <= chartData.endTime) {
    const x = timeScale(currentTime.getTime());
    const hours = String(currentTime.getHours()).padStart(2, '0');
    const minutes = String(currentTime.getMinutes()).padStart(2, '0');
    const seconds = String(currentTime.getSeconds()).padStart(2, '0');
    xAxisLabels.push({
      time: `${hours}:${minutes}:${seconds}`,
      x
    });
    currentTime = new Date(currentTime.getTime() + intervalMinutes * 60 * 1000);
  }

  // Убираем метки, которые слишком близко друг к другу (минимум 80px между ними)
  const filteredXLabels = [];
  let lastX = -Infinity;
  xAxisLabels.forEach(label => {
    if (label.x - lastX >= 80 || filteredXLabels.length === 0) {
      filteredXLabels.push(label);
      lastX = label.x;
    }
  });

  const avgSpeed = chartData.data.reduce((sum, d) => sum + d.speed, 0) / chartData.data.length;

  return (
    <div className="workout-chart-container">
      <div className="workout-chart-title"><ZapIcon size={20} className="workout-chart-title-icon" aria-hidden /> Скорость по времени</div>
      <div className="workout-chart-wrapper">
        <svg 
          ref={svgRef}
          viewBox={`0 0 ${viewBoxWidth} ${viewBoxHeight}`}
          className="workout-chart-svg"
          preserveAspectRatio={isMobile ? 'none' : 'xMidYMid meet'}
          onMouseMove={handleMouseMove}
          onMouseLeave={handleMouseLeave}
        >
          {/* Сетка */}
          {yAxisLabels.map((label, i) => (
            <line
              key={`grid-y-${i}`}
              x1={margin.left}
              y1={label.y}
              x2={viewBoxWidth - margin.right}
              y2={label.y}
              stroke="var(--gray-200)"
              strokeWidth="1"
            />
          ))}
          
          {/* Ось Y (темп) */}
          <line
            x1={margin.left}
            y1={margin.top}
            x2={margin.left}
            y2={viewBoxHeight - margin.bottom}
            stroke="var(--gray-300)"
            strokeWidth="1"
          />
          {yAxisLabels.map((label, i) => (
            <g key={`y-label-${i}`}>
              <text
                x={margin.left - 10}
                y={label.y + 4}
                textAnchor="end"
                fontSize="11"
                fill="var(--gray-600)"
                className="workout-chart-axis-label"
              >
                {label.value}
              </text>
            </g>
          ))}
          
          {/* Ось X (время) */}
          <line
            x1={margin.left}
            y1={viewBoxHeight - margin.bottom}
            x2={viewBoxWidth - margin.right}
            y2={viewBoxHeight - margin.bottom}
            stroke="var(--gray-300)"
            strokeWidth="1"
          />
          {filteredXLabels.map((label, i) => (
            <g key={`x-label-${i}`}>
              <line
                x1={label.x}
                y1={viewBoxHeight - margin.bottom}
                x2={label.x}
                y2={viewBoxHeight - margin.bottom + 5}
                stroke="var(--gray-400)"
                strokeWidth="1"
              />
              <text
                x={label.x}
                y={viewBoxHeight - margin.bottom + 18}
                textAnchor="middle"
                fontSize="10"
                fill="var(--gray-600)"
                className="workout-chart-axis-label"
              >
                {label.time}
              </text>
            </g>
          ))}
          
          {/* Заливка под графиком (цвет через CSS для светлой/тёмной темы) */}
          <path
            className="workout-chart-area workout-chart-area--pace"
            d={`${pathData} L ${chartRight} ${viewBoxHeight - margin.bottom} L ${margin.left} ${viewBoxHeight - margin.bottom} Z`}
          />
          
          {/* График */}
          <path
            d={pathData}
            fill="none"
            stroke="var(--primary-500)"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
          />
          
          {/* Невидимая область для отслеживания мыши */}
          <rect
            x={margin.left}
            y={margin.top}
            width={chartWidth}
            height={chartHeight}
            fill="transparent"
            style={{ cursor: 'crosshair' }}
          />
          
          {/* Вертикальная линия и точка при наведении */}
          {tooltip && tooltip.point && (
            <g>
              <line
                x1={timeScale(tooltip.point.timestamp)}
                y1={margin.top}
                x2={timeScale(tooltip.point.timestamp)}
                y2={viewBoxHeight - margin.bottom}
                stroke="var(--gray-400)"
                strokeWidth="1"
                strokeDasharray="4 4"
                opacity="0.6"
              />
              <circle
                className="workout-chart-marker"
                cx={timeScale(tooltip.point.timestamp)}
                cy={speedScale(tooltip.point.speed)}
                r="4"
                fill="var(--primary-500)"
                strokeWidth="2"
              />
            </g>
          )}
        </svg>
        
        {/* Tooltip */}
        {tooltip && (
          <div 
            className="workout-chart-tooltip"
            style={{
              left: `${tooltip.x}px`,
              top: `${tooltip.y - 60}px`,
              transform: 'translateX(-50%)'
            }}
          >
            <div className="tooltip-time">{tooltip.time}</div>
            <div className="tooltip-value">{tooltip.value} км/ч</div>
          </div>
        )}
      </div>
      <div className="workout-chart-legend">
        <span>Мин: {chartData.minSpeed.toFixed(1)} км/ч</span>
        <span>Макс: {chartData.maxSpeed.toFixed(1)} км/ч</span>
        <span>Средняя: {avgSpeed.toFixed(1)} км/ч</span>
      </div>
    </div>
  );
};

export default PaceChart;
