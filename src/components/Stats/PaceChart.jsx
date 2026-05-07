/**
 * График темпа по времени (мин/км).
 * Выше = быстрее (меньше мин/км), ниже = медленнее.
 */

import { useMemo, useState, useRef } from 'react';
import { useMediaQuery } from '../../hooks/useMediaQuery';
import { ZapIcon } from '../common/Icons';

const formatPaceFromSeconds = (seconds) => {
  const m = Math.floor(seconds / 60);
  const s = Math.round(seconds % 60);
  return `${m}:${String(s).padStart(2, '0')}`;
};

const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

const percentile = (sortedValues, ratio) => {
  if (!sortedValues.length) return null;
  const index = (sortedValues.length - 1) * ratio;
  const lower = Math.floor(index);
  const upper = Math.ceil(index);
  if (lower === upper) return sortedValues[lower];
  const weight = index - lower;
  return sortedValues[lower] * (1 - weight) + sortedValues[upper] * weight;
};

const getPaceDomain = (paceValues) => {
  const sortedValues = [...paceValues].sort((a, b) => a - b);
  const minPace = sortedValues[0];
  const maxPace = sortedValues[sortedValues.length - 1];
  const median = percentile(sortedValues, 0.5) ?? minPace;
  let displayMin = minPace;
  let displayMax = maxPace;

  if (sortedValues.length >= 8) {
    const q1 = percentile(sortedValues, 0.25) ?? minPace;
    const q3 = percentile(sortedValues, 0.75) ?? maxPace;
    const p10 = percentile(sortedValues, 0.1) ?? minPace;
    const p90 = percentile(sortedValues, 0.9) ?? maxPace;
    const iqr = Math.max(20, q3 - q1);
    const fastFence = q1 - iqr * 1.5;
    const slowFence = q3 + iqr * 1.5;
    const fastCap = Math.max(60, median - Math.max(120, median * 0.35));
    const slowCap = median + Math.max(300, median * 0.75);

    displayMin = clamp(Math.min(p10, fastFence), minPace, maxPace);
    displayMax = clamp(Math.max(p90, slowFence), minPace, maxPace);
    displayMin = Math.max(displayMin, fastCap);
    displayMax = Math.min(displayMax, slowCap);

    if (displayMin >= displayMax) {
      displayMin = Math.max(60, median - 30);
      displayMax = median + 30;
    }
  }

  let displayRange = displayMax - displayMin;
  const minRange = 60;
  if (displayRange < minRange) {
    const center = (displayMin + displayMax) / 2;
    displayMin = Math.max(60, center - minRange / 2);
    displayMax = displayMin + minRange;
    displayRange = displayMax - displayMin;
  }

  const padding = Math.max(15, displayRange * 0.08);
  const adjustedMinPace = Math.max(60, displayMin - padding);
  const adjustedMaxPace = displayMax + padding;

  return {
    minPace,
    maxPace,
    adjustedMinPace,
    adjustedMaxPace,
    adjustedRange: adjustedMaxPace - adjustedMinPace || 1,
  };
};

const getPaceSmoothingWindowMs = (durationMinutes) => {
  if (durationMinutes <= 45) return 15 * 1000;
  if (durationMinutes <= 90) return 20 * 1000;
  if (durationMinutes <= 150) return 30 * 1000;
  return 45 * 1000;
};

const getTrimmedMean = (values) => {
  if (!values.length) return null;
  const sortedValues = [...values].sort((a, b) => a - b);
  const trimCount = sortedValues.length >= 5 ? Math.floor(sortedValues.length * 0.2) : 0;
  const trimmedValues = sortedValues.slice(trimCount, sortedValues.length - trimCount);
  const usableValues = trimmedValues.length ? trimmedValues : sortedValues;
  return usableValues.reduce((sum, value) => sum + value, 0) / usableValues.length;
};

const smoothPaceData = (points, durationMinutes) => {
  const windowMs = getPaceSmoothingWindowMs(durationMinutes);

  return points.map((point, index) => {
    const centerTime = new Date(point.timestamp).getTime();
    const minTime = centerTime - windowMs / 2;
    const maxTime = centerTime + windowMs / 2;
    const values = [];

    for (let i = index; i >= 0; i -= 1) {
      const candidate = points[i];
      const candidateTime = new Date(candidate.timestamp).getTime();
      if (candidateTime < minTime) break;
      values.push(candidate.paceSeconds);
    }

    for (let i = index + 1; i < points.length; i += 1) {
      const candidate = points[i];
      const candidateTime = new Date(candidate.timestamp).getTime();
      if (candidateTime > maxTime) break;
      values.push(candidate.paceSeconds);
    }

    return {
      ...point,
      smoothedPaceSeconds: getTrimmedMean(values) ?? point.paceSeconds,
    };
  });
};

const PaceChart = ({ timeline, onHoverIndex }) => {
  const isMobile = useMediaQuery('(max-width: 768px)');
  const [tooltip, setTooltip] = useState(null);
  const svgRef = useRef(null);

  const chartData = useMemo(() => {
    if (!timeline || timeline.length === 0) {
      return null;
    }

    // Темп (мин/км) — храним в секундах для расчётов
    const dataPoints = timeline
      .map((point, index) => {
        if (!point.pace) return null;
        const paceParts = point.pace.split(':');
        if (paceParts.length !== 2) return null;
        const mins = parseInt(paceParts[0], 10);
        const secs = parseInt(paceParts[1], 10);
        if (isNaN(mins) || isNaN(secs)) return null;
        const paceSeconds = mins * 60 + secs;
        if (paceSeconds <= 0) return null;
        return {
          index,
          timestamp: point.timestamp,
          paceSeconds,
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
    const startTime = new Date(dataPoints[0].timestamp);
    const endTime = new Date(dataPoints[dataPoints.length - 1].timestamp);
    const durationMinutes = (endTime - startTime) / (1000 * 60);
    const smoothedData = smoothPaceData(sampledData, durationMinutes);

    const paceDomain = getPaceDomain(smoothedData.map(d => d.smoothedPaceSeconds));

    return {
      data: smoothedData,
      ...paceDomain,
      startTime,
      endTime
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

  // Ось Y: темп (сек/км). Выше на графике = быстрее (меньше мин/км)
  const paceScale = (paceSeconds) => {
    const visiblePace = clamp(paceSeconds, chartData.adjustedMinPace, chartData.adjustedMaxPace);
    return margin.top + ((visiblePace - chartData.adjustedMinPace) / chartData.adjustedRange) * chartHeight;
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
    const y = paceScale(point.smoothedPaceSeconds) * (rect.height / viewBoxHeight);
    
    const time = new Date(point.timestamp);
    const hours = String(time.getHours()).padStart(2, '0');
    const minutes = String(time.getMinutes()).padStart(2, '0');
    const seconds = String(time.getSeconds()).padStart(2, '0');

    setTooltip({
      x: rect.left + x,
      y: rect.top + y,
      value: formatPaceFromSeconds(point.smoothedPaceSeconds),
      rawValue: Math.abs(point.paceSeconds - point.smoothedPaceSeconds) >= 15
        ? formatPaceFromSeconds(point.paceSeconds)
        : null,
      time: `${hours}:${minutes}:${seconds}`,
      point: point
    });
    if (onHoverIndex) onHoverIndex(point.index);
  };

  const handleMouseLeave = () => {
    setTooltip(null);
    if (onHoverIndex) onHoverIndex(null);
  };

  // Формируем путь для графика (линия продлевается до правого края, чтобы не было пустого отступа)
  const chartRight = margin.left + chartWidth;
  const lastPoint = chartData.data[chartData.data.length - 1];
  const lastY = lastPoint ? paceScale(lastPoint.smoothedPaceSeconds) : margin.top + chartHeight / 2;
  const pathData = chartData.data
    .map((point, index) => {
      const x = timeScale(point.timestamp);
      const y = paceScale(point.smoothedPaceSeconds);
      return `${index === 0 ? 'M' : 'L'} ${x} ${y}`;
    })
    .join(' ') + (lastPoint ? ` L ${chartRight} ${lastY}` : '');

  // Метки оси Y — темп (мин/км)
  const yAxisLabels = [];
  const yAxisSteps = 5;
  for (let i = 0; i <= yAxisSteps; i++) {
    const paceValue = chartData.adjustedMinPace + (chartData.adjustedRange / yAxisSteps) * i;
    const y = paceScale(paceValue);
    yAxisLabels.push({ value: formatPaceFromSeconds(paceValue), y });
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

  const avgPaceSeconds = chartData.data.reduce((sum, d) => sum + d.smoothedPaceSeconds, 0) / chartData.data.length;

  return (
    <div className="workout-chart-container">
      <div className="workout-chart-title"><ZapIcon size={20} className="workout-chart-title-icon" aria-hidden /> Темп по времени</div>
      <div className="workout-chart-wrapper">
        <svg 
          ref={svgRef}
          viewBox={`0 0 ${viewBoxWidth} ${viewBoxHeight}`}
          className="workout-chart-svg"
          preserveAspectRatio={isMobile ? 'none' : 'xMidYMid meet'}
          onMouseMove={handleMouseMove}
          onMouseLeave={handleMouseLeave}
          onTouchMove={(e) => { e.preventDefault(); handleMouseMove(e.touches[0]); }}
          onTouchEnd={handleMouseLeave}
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
                cy={paceScale(tooltip.point.smoothedPaceSeconds)}
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
            <div className="tooltip-value">{tooltip.value} /км</div>
            {tooltip.rawValue && <div className="tooltip-time">точка {tooltip.rawValue} /км</div>}
          </div>
        )}
      </div>
      <div className="workout-chart-legend">
        <span>Мин: {formatPaceFromSeconds(chartData.minPace)} /км</span>
        <span>Макс: {formatPaceFromSeconds(chartData.maxPace)} /км</span>
        <span>Средний: {formatPaceFromSeconds(avgPaceSeconds)} /км</span>
      </div>
    </div>
  );
};

export default PaceChart;
