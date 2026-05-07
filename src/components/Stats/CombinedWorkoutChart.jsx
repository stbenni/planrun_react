/**
 * Единый график темпа и пульса по времени.
 * Темп рисуется по левой оси: выше = быстрее.
 * Пульс рисуется по правой оси.
 */

import { useMemo, useRef, useState } from 'react';
import { useMediaQuery } from '../../hooks/useMediaQuery';
import { HeartIcon, ZapIcon } from '../common/Icons';

const parsePaceToSeconds = (pace) => {
  if (!pace || typeof pace !== 'string') return null;
  const parts = pace.split(':');
  if (parts.length !== 2) return null;
  const minutes = parseInt(parts[0], 10);
  const seconds = parseInt(parts[1], 10);
  if (!Number.isFinite(minutes) || !Number.isFinite(seconds) || seconds < 0 || seconds >= 60) return null;
  const totalSeconds = minutes * 60 + seconds;
  return totalSeconds > 0 ? totalSeconds : null;
};

const formatPaceFromSeconds = (seconds) => {
  if (!Number.isFinite(seconds) || seconds <= 0) return '—';
  const rounded = Math.round(seconds);
  return `${Math.floor(rounded / 60)}:${String(rounded % 60).padStart(2, '0')}`;
};

const formatSpeedFromPace = (paceSeconds) => {
  if (!Number.isFinite(paceSeconds) || paceSeconds <= 0) return '—';
  return (3600 / paceSeconds).toFixed(1);
};

const formatTime = (timestamp) => {
  const time = new Date(timestamp);
  const hours = String(time.getHours()).padStart(2, '0');
  const minutes = String(time.getMinutes()).padStart(2, '0');
  const seconds = String(time.getSeconds()).padStart(2, '0');
  return `${hours}:${minutes}:${seconds}`;
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
  if (!paceValues.length) {
    return {
      minPace: null,
      maxPace: null,
      adjustedMinPace: 60,
      adjustedMaxPace: 600,
      adjustedPaceRange: 540,
      hasPaceOutliers: false,
    };
  }

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
    adjustedPaceRange: adjustedMaxPace - adjustedMinPace || 1,
    hasPaceOutliers: minPace < adjustedMinPace || maxPace > adjustedMaxPace,
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
    if (!Number.isFinite(point.paceSeconds)) {
      return { ...point, smoothedPaceSeconds: null };
    }

    const centerTime = new Date(point.timestamp).getTime();
    const minTime = centerTime - windowMs / 2;
    const maxTime = centerTime + windowMs / 2;
    const values = [];

    for (let i = index; i >= 0; i -= 1) {
      const candidate = points[i];
      const candidateTime = new Date(candidate.timestamp).getTime();
      if (candidateTime < minTime) break;
      if (Number.isFinite(candidate.paceSeconds)) values.push(candidate.paceSeconds);
    }

    for (let i = index + 1; i < points.length; i += 1) {
      const candidate = points[i];
      const candidateTime = new Date(candidate.timestamp).getTime();
      if (candidateTime > maxTime) break;
      if (Number.isFinite(candidate.paceSeconds)) values.push(candidate.paceSeconds);
    }

    return {
      ...point,
      smoothedPaceSeconds: getTrimmedMean(values) ?? point.paceSeconds,
    };
  });
};

const buildLinePath = (points, xScale, yScale, valueKey) => points
  .filter((point) => Number.isFinite(point[valueKey]))
  .map((point, index) => {
    const x = xScale(point.timestamp);
    const y = yScale(point[valueKey]);
    return `${index === 0 ? 'M' : 'L'} ${x} ${y}`;
  })
  .join(' ');

const CombinedWorkoutChart = ({ timeline, onHoverIndex }) => {
  const isMobile = useMediaQuery('(max-width: 768px)');
  const [tooltip, setTooltip] = useState(null);
  const svgRef = useRef(null);
  const wrapperRef = useRef(null);

  const chartData = useMemo(() => {
    if (!Array.isArray(timeline) || timeline.length === 0) return null;

    const dataPoints = timeline
      .map((point, index) => {
        const paceSeconds = parsePaceToSeconds(point?.pace);
        const heartRate = Number(point?.heart_rate);
        const hasHeartRate = Number.isFinite(heartRate) && heartRate > 0;
        if (!point?.timestamp || (!paceSeconds && !hasHeartRate)) return null;

        return {
          index,
          timestamp: point.timestamp,
          paceSeconds,
          heartRate: hasHeartRate ? heartRate : null,
        };
      })
      .filter(Boolean);

    if (dataPoints.length === 0) return null;

    const step = Math.max(1, Math.floor(dataPoints.length / 500));
    const sampledIndices = new Set();
    for (let i = 0; i < dataPoints.length; i += step) sampledIndices.add(i);
    if (dataPoints.length > 1) sampledIndices.add(dataPoints.length - 1);
    const sampledData = dataPoints.filter((_, index) => sampledIndices.has(index));
    const startTime = new Date(dataPoints[0].timestamp);
    const endTime = new Date(dataPoints[dataPoints.length - 1].timestamp);
    const durationMinutes = (endTime - startTime) / (1000 * 60);
    const smoothedData = smoothPaceData(sampledData, durationMinutes);

    const paceData = smoothedData.filter((point) => Number.isFinite(point.smoothedPaceSeconds));
    const heartRateData = smoothedData.filter((point) => Number.isFinite(point.heartRate));

    const paceDomain = getPaceDomain(paceData.map((point) => point.smoothedPaceSeconds));

    const minHR = heartRateData.length ? Math.min(...heartRateData.map((point) => point.heartRate)) : null;
    const maxHR = heartRateData.length ? Math.max(...heartRateData.map((point) => point.heartRate)) : null;
    const hrRange = minHR !== null && maxHR !== null ? maxHR - minHR || 1 : 1;
    const adjustedMinHR = minHR !== null ? minHR - hrRange * 0.05 : 80;
    const adjustedMaxHR = maxHR !== null ? maxHR + hrRange * 0.05 : 180;

    return {
      data: smoothedData,
      paceData,
      heartRateData,
      ...paceDomain,
      minHR,
      maxHR,
      adjustedMinHR,
      adjustedMaxHR,
      adjustedHRRange: adjustedMaxHR - adjustedMinHR || 1,
      startTime,
      endTime,
    };
  }, [timeline]);

  if (!chartData) return null;

  const viewBoxWidth = 800;
  const viewBoxHeight = 280;
  const margin = { top: 22, right: 54, bottom: 38, left: 62 };
  const chartWidth = viewBoxWidth - margin.left - margin.right;
  const chartHeight = viewBoxHeight - margin.top - margin.bottom;
  const chartBottom = viewBoxHeight - margin.bottom;

  const timeScale = (timestamp) => {
    const time = new Date(timestamp).getTime();
    const start = chartData.startTime.getTime();
    const end = chartData.endTime.getTime();
    return margin.left + ((time - start) / (end - start || 1)) * chartWidth;
  };

  const paceScale = (paceSeconds) => (
    margin.top
      + ((clamp(paceSeconds, chartData.adjustedMinPace, chartData.adjustedMaxPace) - chartData.adjustedMinPace)
        / chartData.adjustedPaceRange) * chartHeight
  );

  const hrScale = (heartRate) => (
    margin.top + chartHeight - ((heartRate - chartData.adjustedMinHR) / chartData.adjustedHRRange) * chartHeight
  );

  const getPointFromX = (clientX) => {
    const svg = svgRef.current;
    if (!svg) return null;

    const rect = svg.getBoundingClientRect();
    const localX = (clientX - rect.left) / (rect.width / viewBoxWidth);
    const chartX = localX - margin.left;
    if (chartX < 0 || chartX > chartWidth) return null;

    const start = chartData.startTime.getTime();
    const end = chartData.endTime.getTime();
    const timestamp = start + (chartX / chartWidth) * (end - start || 1);

    let closestPoint = chartData.data[0];
    let minDistance = Infinity;
    chartData.data.forEach((point) => {
      const distance = Math.abs(new Date(point.timestamp).getTime() - timestamp);
      if (distance < minDistance) {
        minDistance = distance;
        closestPoint = point;
      }
    });
    return closestPoint;
  };

  const handlePointerMove = (event) => {
    const clientX = event.clientX ?? event.touches?.[0]?.clientX;
    if (clientX == null) return;

    const point = getPointFromX(clientX);
    if (!point) {
      setTooltip(null);
      onHoverIndex?.(null);
      return;
    }

    const svg = svgRef.current;
    const wrapper = wrapperRef.current;
    if (!svg || !wrapper) return;

    const rect = svg.getBoundingClientRect();
    const wrapperRect = wrapper.getBoundingClientRect();
    const scaleX = rect.width / viewBoxWidth;
    const scaleY = rect.height / viewBoxHeight;
    const x = timeScale(point.timestamp) * scaleX;
    const yValues = [
      Number.isFinite(point.smoothedPaceSeconds) ? paceScale(point.smoothedPaceSeconds) * scaleY : null,
      Number.isFinite(point.heartRate) ? hrScale(point.heartRate) * scaleY : null,
    ].filter(Number.isFinite);
    const y = yValues.length ? Math.min(...yValues) : margin.top * scaleY;
    const rawLeft = rect.left - wrapperRect.left + x;
    const safeLeft = Math.max(92, Math.min(wrapperRect.width - 92, rawLeft));

    setTooltip({
      left: safeLeft,
      top: rect.top - wrapperRect.top + y - 12,
      time: formatTime(point.timestamp),
      pace: formatPaceFromSeconds(point.smoothedPaceSeconds),
      speed: formatSpeedFromPace(point.smoothedPaceSeconds),
      rawPace: formatPaceFromSeconds(point.paceSeconds),
      hasRawPaceDiff: Number.isFinite(point.paceSeconds)
        && Number.isFinite(point.smoothedPaceSeconds)
        && Math.abs(point.paceSeconds - point.smoothedPaceSeconds) >= 15,
      heartRate: Number.isFinite(point.heartRate) ? Math.round(point.heartRate) : null,
      point,
    });
    onHoverIndex?.(point.index);
  };

  const handlePointerLeave = () => {
    setTooltip(null);
    onHoverIndex?.(null);
  };

  const pacePath = buildLinePath(chartData.paceData, timeScale, paceScale, 'smoothedPaceSeconds');
  const heartRatePath = buildLinePath(chartData.heartRateData, timeScale, hrScale, 'heartRate');
  const hasPaceData = chartData.paceData.length > 0;
  const hasHeartRateData = chartData.heartRateData.length > 0;
  const paceLastPoint = chartData.paceData[chartData.paceData.length - 1];
  const paceAreaPath = pacePath && paceLastPoint
    ? `${pacePath} L ${timeScale(paceLastPoint.timestamp)} ${chartBottom} L ${margin.left} ${chartBottom} Z`
    : '';

  const yAxisSteps = 5;
  const paceLabels = Array.from({ length: yAxisSteps + 1 }, (_, index) => {
    const value = chartData.adjustedMinPace + (chartData.adjustedPaceRange / yAxisSteps) * index;
    return { value: formatPaceFromSeconds(value), y: paceScale(value) };
  });
  const heartRateLabels = Array.from({ length: yAxisSteps + 1 }, (_, index) => {
    const value = Math.round(chartData.adjustedMinHR + (chartData.adjustedHRRange / yAxisSteps) * index);
    return { value, y: hrScale(value) };
  });
  const gridLabels = hasPaceData ? paceLabels : heartRateLabels;

  const xAxisLabels = [];
  const durationMinutes = (chartData.endTime - chartData.startTime) / (1000 * 60);
  const intervalMinutes = durationMinutes <= 30 ? 5 : durationMinutes <= 60 ? 10 : durationMinutes <= 120 ? 15 : 30;
  const currentTime = new Date(chartData.startTime);
  currentTime.setMinutes(Math.floor(currentTime.getMinutes() / intervalMinutes) * intervalMinutes, 0, 0);

  while (currentTime <= chartData.endTime) {
    xAxisLabels.push({ time: formatTime(currentTime), x: timeScale(currentTime.getTime()) });
    currentTime.setTime(currentTime.getTime() + intervalMinutes * 60 * 1000);
  }

  const filteredXLabels = [];
  let lastX = -Infinity;
  xAxisLabels.forEach((label) => {
    if (label.x - lastX >= 80 || filteredXLabels.length === 0) {
      filteredXLabels.push(label);
      lastX = label.x;
    }
  });

  const avgPaceSeconds = chartData.paceData.length
    ? chartData.paceData.reduce((sum, point) => sum + point.smoothedPaceSeconds, 0) / chartData.paceData.length
    : null;
  const avgHR = chartData.heartRateData.length
    ? Math.round(chartData.heartRateData.reduce((sum, point) => sum + point.heartRate, 0) / chartData.heartRateData.length)
    : null;
  const chartTitle = hasPaceData && hasHeartRateData ? 'Темп и пульс' : hasPaceData ? 'Темп по времени' : 'Пульс по времени';

  return (
    <div className="workout-chart-container workout-combined-chart">
      <div className="workout-chart-title">
        {hasPaceData && <ZapIcon size={20} className="workout-chart-title-icon workout-chart-title-icon--pace" aria-hidden />}
        {chartTitle}
        {hasHeartRateData && <HeartIcon size={20} className="workout-chart-title-icon workout-chart-title-icon--hr" aria-hidden />}
      </div>
      <div className="workout-chart-wrapper" ref={wrapperRef}>
        <svg
          ref={svgRef}
          viewBox={`0 0 ${viewBoxWidth} ${viewBoxHeight}`}
          className="workout-chart-svg workout-combined-chart-svg"
          preserveAspectRatio={isMobile ? 'none' : 'xMidYMid meet'}
          onMouseMove={handlePointerMove}
          onMouseLeave={handlePointerLeave}
          onTouchMove={(event) => { event.preventDefault(); handlePointerMove(event); }}
          onTouchEnd={handlePointerLeave}
        >
          {gridLabels.map((label, index) => (
            <line
              key={`combined-grid-y-${index}`}
              x1={margin.left}
              y1={label.y}
              x2={viewBoxWidth - margin.right}
              y2={label.y}
              stroke="var(--gray-200)"
              strokeWidth="1"
            />
          ))}

          {hasPaceData && <line x1={margin.left} y1={margin.top} x2={margin.left} y2={chartBottom} stroke="var(--gray-300)" strokeWidth="1" />}
          {hasHeartRateData && <line x1={viewBoxWidth - margin.right} y1={margin.top} x2={viewBoxWidth - margin.right} y2={chartBottom} stroke="var(--gray-300)" strokeWidth="1" />}

          {hasPaceData && paceLabels.map((label, index) => (
            <text
              key={`combined-pace-label-${index}`}
              x={margin.left - 10}
              y={label.y + 4}
              textAnchor="end"
              fontSize="11"
              fill="var(--gray-600)"
              className="workout-chart-axis-label workout-chart-axis-label--pace"
            >
              {label.value}
            </text>
          ))}

          {hasHeartRateData && heartRateLabels.map((label, index) => (
            <text
              key={`combined-hr-label-${index}`}
              x={viewBoxWidth - margin.right + 10}
              y={label.y + 4}
              textAnchor="start"
              fontSize="11"
              fill="var(--danger-500)"
              className="workout-chart-axis-label workout-chart-axis-label--hr"
            >
              {label.value}
            </text>
          ))}

          {hasPaceData && <text x={margin.left} y={margin.top - 8} textAnchor="start" fontSize="11" fill="var(--primary-500)" className="workout-chart-axis-label workout-chart-axis-label--pace">
            /км
          </text>}
          {hasHeartRateData && <text x={viewBoxWidth - margin.right} y={margin.top - 8} textAnchor="end" fontSize="11" fill="var(--danger-500)" className="workout-chart-axis-label workout-chart-axis-label--hr">
            уд/мин
          </text>}

          <line x1={margin.left} y1={chartBottom} x2={viewBoxWidth - margin.right} y2={chartBottom} stroke="var(--gray-300)" strokeWidth="1" />
          {filteredXLabels.map((label, index) => (
            <g key={`combined-x-label-${index}`}>
              <line x1={label.x} y1={chartBottom} x2={label.x} y2={chartBottom + 5} stroke="var(--gray-400)" strokeWidth="1" />
              <text
                x={label.x}
                y={chartBottom + 18}
                textAnchor="middle"
                fontSize="10"
                fill="var(--gray-600)"
                className="workout-chart-axis-label"
              >
                {label.time}
              </text>
            </g>
          ))}

          {paceAreaPath && <path className="workout-chart-area workout-chart-area--pace" d={paceAreaPath} />}
          {pacePath && (
            <path
              d={pacePath}
              fill="none"
              stroke="var(--primary-500)"
              strokeWidth="2.5"
              strokeLinecap="round"
              strokeLinejoin="round"
            />
          )}
          {heartRatePath && (
            <path
              d={heartRatePath}
              fill="none"
              stroke="var(--danger-500)"
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
              opacity="0.9"
            />
          )}

          <rect
            x={margin.left}
            y={margin.top}
            width={chartWidth}
            height={chartHeight}
            fill="transparent"
            style={{ cursor: 'crosshair' }}
          />

          {tooltip?.point && (
            <g>
              <line
                x1={timeScale(tooltip.point.timestamp)}
                y1={margin.top}
                x2={timeScale(tooltip.point.timestamp)}
                y2={chartBottom}
                stroke="var(--gray-500)"
                strokeWidth="1"
                strokeDasharray="4 4"
                opacity="0.65"
              />
              {Number.isFinite(tooltip.point.smoothedPaceSeconds) && (
                <circle
                  className="workout-chart-marker"
                  cx={timeScale(tooltip.point.timestamp)}
                  cy={paceScale(tooltip.point.smoothedPaceSeconds)}
                  r="4"
                  fill="var(--primary-500)"
                  strokeWidth="2"
                />
              )}
              {Number.isFinite(tooltip.point.heartRate) && (
                <circle
                  className="workout-chart-marker"
                  cx={timeScale(tooltip.point.timestamp)}
                  cy={hrScale(tooltip.point.heartRate)}
                  r="4"
                  fill="var(--danger-500)"
                  strokeWidth="2"
                />
              )}
            </g>
          )}
        </svg>

        {tooltip && (
          <div
            className="workout-chart-tooltip workout-chart-tooltip--combined"
            style={{
              left: `${tooltip.left}px`,
              top: `${tooltip.top}px`,
              transform: 'translateX(-50%) translateY(-100%)',
            }}
          >
            <div className="tooltip-time">{tooltip.time}</div>
            {Number.isFinite(tooltip.point.smoothedPaceSeconds) && (
              <>
                <div className="tooltip-value tooltip-value--speed">{tooltip.speed} км/ч</div>
                <div className="tooltip-row">
                  <span>Темп</span>
                  <strong>{tooltip.pace} /км</strong>
                </div>
                {tooltip.hasRawPaceDiff && (
                  <div className="tooltip-row tooltip-row--muted">
                    <span>Точка</span>
                    <strong>{tooltip.rawPace} /км</strong>
                  </div>
                )}
              </>
            )}
            {Number.isFinite(tooltip.point.heartRate) && (
              <div className="tooltip-row">
                <span>Пульс</span>
                <strong>{tooltip.heartRate} уд/мин</strong>
              </div>
            )}
          </div>
        )}
      </div>
      <div className="workout-chart-legend workout-chart-legend--combined">
        {chartData.paceData.length > 0 && (
          <>
            <span className="workout-chart-legend-item workout-chart-legend-item--pace">Темп: {formatPaceFromSeconds(chartData.minPace)} – {formatPaceFromSeconds(chartData.maxPace)} /км</span>
            <span>Средний: {formatPaceFromSeconds(avgPaceSeconds)} /км</span>
          </>
        )}
        {chartData.heartRateData.length > 0 && (
          <>
            <span className="workout-chart-legend-item workout-chart-legend-item--hr">Пульс: {chartData.minHR} – {chartData.maxHR} уд/мин</span>
            <span>Средний: {avgHR} уд/мин</span>
          </>
        )}
      </div>
    </div>
  );
};

export default CombinedWorkoutChart;
