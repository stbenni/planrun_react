/**
 * Карточка тренировки для генерации изображения при «Поделиться»
 * Рендерится скрыто для захвата html2canvas
 */

import { HeartIcon } from '../common/Icons';

const CARD_WIDTH = 420;
const CONTENT_WIDTH = CARD_WIDTH - 56;

const ACTIVITY_TYPE_LABELS = {
  run: 'Бег',
  running: 'Бег',
  walking: 'Ходьба',
  hiking: 'Поход',
  cycling: 'Велосипед',
  swimming: 'Плавание',
  ofp: 'ОФП',
  sbu: 'СБУ',
  easy: 'Легкий бег',
  long: 'Длительный бег',
  'long-run': 'Длительный бег',
  tempo: 'Темповый бег',
  interval: 'Интервалы',
  fartlek: 'Фартлек',
  race: 'Соревнование',
  control: 'Контрольный забег',
  other: 'ОФП',
  rest: 'Отдых',
  free: 'Пустой день',
};

const SOURCE_LABELS = {
  strava: 'Strava',
  huawei: 'Huawei Health',
  polar: 'Polar',
  garmin: 'Garmin',
  coros: 'COROS',
  gpx: 'GPX-файл',
  fit: 'FIT-файл',
};

const getActivityTypeLabel = (type) => {
  if (!type) return '';
  const key = String(type).toLowerCase().trim();
  return ACTIVITY_TYPE_LABELS[key] || type;
};

const getWorkoutDisplayType = (workout) => {
  if (!workout) return null;
  const planType = workout.type;
  const activityType = workout.activity_type;
  if (planType && ACTIVITY_TYPE_LABELS[String(planType).toLowerCase().trim()]) {
    return planType;
  }
  return activityType || planType;
};

const getSourceLabel = (source) => {
  if (!source) return null;
  const key = String(source).toLowerCase();
  return SOURCE_LABELS[key] || source;
};

const formatDistanceValue = (distanceKm) => {
  const value = Number(distanceKm);
  if (!Number.isFinite(value) || value <= 0) return null;
  return {
    value: value.toFixed(2).replace('.', ','),
    unit: 'км',
  };
};

const formatDurationValue = (workout) => {
  if (workout.duration_seconds != null && workout.duration_seconds > 0) {
    const totalSeconds = Number(workout.duration_seconds);
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;
    return hours > 0
      ? `${hours}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`
      : `${minutes}:${String(seconds).padStart(2, '0')}`;
  }
  if (workout.duration_minutes != null && workout.duration_minutes > 0) {
    const totalMinutes = Number(workout.duration_minutes);
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;
    return hours > 0 ? `${hours}ч ${minutes}м` : `${minutes}м`;
  }
  return null;
};

const formatDurationText = (workout) => {
  if (workout.duration_seconds != null && workout.duration_seconds > 0) {
    const totalSeconds = Number(workout.duration_seconds);
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;
    return `${hours > 0 ? `${hours} ч ` : ''}${minutes} мин ${seconds} сек`;
  }
  if (workout.duration_minutes != null && workout.duration_minutes > 0) {
    const totalMinutes = Number(workout.duration_minutes);
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;
    return hours > 0 ? `${hours}ч ${minutes}м` : `${minutes}м`;
  }
  return null;
};

const truncateText = (value, maxLength = 140) => {
  if (!value || typeof value !== 'string') return null;
  const normalized = value.trim().replace(/\s+/g, ' ');
  if (!normalized) return null;
  if (normalized.length <= maxLength) return normalized;
  return `${normalized.slice(0, maxLength - 1).trimEnd()}…`;
};

const getRoutePoints = (timeline) => (
  Array.isArray(timeline)
    ? timeline
      .map((point) => ({
        latitude: Number(point?.latitude),
        longitude: Number(point?.longitude),
      }))
      .filter((point) => Number.isFinite(point.latitude) && Number.isFinite(point.longitude))
    : []
);

const ShareBadge = ({ children, muted = false }) => (
  <div
    style={{
      padding: '7px 12px',
      borderRadius: 999,
      background: muted ? 'rgba(15,23,42,0.06)' : 'rgba(252,76,2,0.1)',
      color: muted ? '#475569' : '#EA580C',
      fontSize: 11,
      fontWeight: muted ? 600 : 700,
      letterSpacing: muted ? '0.02em' : '0.08em',
      textTransform: muted ? 'none' : 'uppercase',
    }}
  >
    {children}
  </div>
);

const BrandWordmark = ({ size = 24, marginBottom = 10 }) => (
  <div
    className="top-header-logo"
    style={{
      cursor: 'default',
      transform: 'none',
      marginBottom,
      pointerEvents: 'none',
    }}
  >
    <span className="logo-text" style={{ fontSize: size }}>
      <span className="logo-plan">plan</span>
      <span className="logo-run">RUN</span>
    </span>
  </div>
);

const splitMetricValue = (value) => {
  if (value == null || value === '') return { primary: null, secondary: null };
  const normalized = String(value).trim();
  const patterns = [
    /^(.+?)\s*(мин\/км)$/i,
    /^(.+?)\s*(\/км)$/i,
    /^(\d+)\s*(уд\/мин)$/i,
    /^(\d+)\s*(ккал)$/i,
    /^(\d+)\s*(м)$/i,
  ];

  for (const pattern of patterns) {
    const match = normalized.match(pattern);
    if (match) {
      return {
        primary: match[1],
        secondary: match[2],
      };
    }
  }

  return {
    primary: normalized,
    secondary: null,
  };
};

const ShareMetricTile = ({
  label,
  value,
  accent = false,
  primaryAlign = 'left',
  secondaryAlign = null,
}) => {
  if (!value) return null;
  const { primary, secondary } = splitMetricValue(value);
  const resolvedSecondaryAlign = secondaryAlign || primaryAlign;
  return (
    <div
      style={{
        padding: '14px 16px',
        borderRadius: 18,
        border: accent ? '1px solid rgba(252, 76, 2, 0.16)' : '1px solid rgba(148, 163, 184, 0.16)',
        background: accent ? 'rgba(255, 249, 245, 0.98)' : 'rgba(255,255,255,0.94)',
        boxShadow: 'inset 0 1px 0 rgba(255,255,255,0.82)',
      }}
    >
      <div
        style={{
          fontSize: 10,
          fontWeight: 700,
          letterSpacing: '0.08em',
          textTransform: 'uppercase',
          color: accent ? '#F97316' : '#94A3B8',
          marginBottom: 6,
        }}
      >
        {label}
      </div>
      <div style={{ display: 'flex', flexDirection: 'column', gap: secondary ? 3 : 0 }}>
        <div
          style={{
            fontSize: secondary ? 17 : 18,
            fontWeight: 700,
            lineHeight: 1.05,
            color: '#0F172A',
            textAlign: primaryAlign,
            alignSelf: primaryAlign === 'right' ? 'flex-end' : 'flex-start',
          }}
        >
          {primary}
        </div>
        {secondary ? (
          <div
            style={{
              width: resolvedSecondaryAlign === 'right' ? '100%' : 'auto',
              fontSize: 12,
              fontWeight: 700,
              lineHeight: 1,
              color: '#475569',
              textTransform: 'uppercase',
              textAlign: resolvedSecondaryAlign,
              alignSelf: resolvedSecondaryAlign === 'right' ? 'stretch' : 'flex-start',
            }}
          >
            {secondary}
          </div>
        ) : null}
      </div>
    </div>
  );
};

const ShareRoutePreview = ({
  timeline,
  staticMapUrl = null,
  marginTop = 20,
  height = 216,
  elevated = false,
}) => {
  const points = getRoutePoints(timeline);
  if (points.length < 2) return null;

  const shellStyle = {
    marginTop,
    borderRadius: 26,
    padding: 12,
    border: '1px solid rgba(252, 76, 2, 0.14)',
    background: elevated
      ? 'linear-gradient(180deg, #151A23 0%, #1A2230 56%, #141923 100%)'
      : 'linear-gradient(180deg, #171C25 0%, #1B2330 100%)',
    boxShadow: elevated
      ? '0 20px 44px rgba(15, 23, 42, 0.16), inset 0 1px 0 rgba(255,255,255,0.05)'
      : '0 16px 36px rgba(15, 23, 42, 0.14), inset 0 1px 0 rgba(255,255,255,0.05)',
    position: 'relative',
    overflow: 'hidden',
  };

  const mapViewportStyle = {
    height,
    borderRadius: 20,
    overflow: 'hidden',
    position: 'relative',
    background: '#0F172A',
    border: '1px solid rgba(255,255,255,0.06)',
  };

  const chipStyle = {
    display: 'inline-flex',
    alignItems: 'center',
    minHeight: 28,
    padding: '0 11px',
    borderRadius: 999,
    fontSize: 10,
    fontWeight: 700,
    letterSpacing: '0.12em',
    textTransform: 'uppercase',
    backdropFilter: 'blur(10px)',
    WebkitBackdropFilter: 'blur(10px)',
  };

  if (staticMapUrl) {
    return (
      <div
        style={{
          marginTop,
          position: 'relative',
        }}
      >
        <div
          style={{
            ...mapViewportStyle,
            border: 'none',
            boxShadow: elevated
              ? '0 18px 40px rgba(15, 23, 42, 0.16)'
              : '0 14px 32px rgba(15, 23, 42, 0.12)',
          }}
        >
          <img
            data-share-static-map="true"
            src={staticMapUrl}
            alt="Маршрут тренировки на карте"
            style={{
              display: 'block',
              width: '100%',
              height: '100%',
              objectFit: 'cover',
              transform: 'scale(1.015)',
            }}
          />
          <div
            style={{
              position: 'absolute',
              inset: 0,
              background: [
                'linear-gradient(180deg, rgba(15,23,42,0.02) 0%, rgba(15,23,42,0) 20%, rgba(15,23,42,0.10) 100%)',
                'linear-gradient(0deg, rgba(15,23,42,0.48) 0%, rgba(15,23,42,0.10) 18%, rgba(15,23,42,0) 36%)',
                'radial-gradient(circle at top right, rgba(252,76,2,0.18) 0%, rgba(252,76,2,0.06) 18%, rgba(252,76,2,0) 42%)',
              ].join(', '),
              pointerEvents: 'none',
            }}
          />
          <div style={{ position: 'absolute', top: 12, left: 12, right: 12, display: 'flex', justifyContent: 'space-between', gap: 10 }}>
            <div
              style={{
                ...chipStyle,
                background: 'rgba(255,255,255,0.82)',
                border: '1px solid rgba(255,255,255,0.56)',
                color: '#EA580C',
                boxShadow: '0 10px 24px rgba(15, 23, 42, 0.12)',
              }}
            >
              Маршрут
            </div>
            <div
              style={{
                ...chipStyle,
                background: 'rgba(15,23,42,0.56)',
                border: '1px solid rgba(255,255,255,0.12)',
                color: '#E2E8F0',
              }}
            >
              GPS
            </div>
          </div>
        </div>
      </div>
    );
  }

  const sampleStep = Math.max(1, Math.floor(points.length / 180));
  const sampled = points.filter((_, index) => index % sampleStep === 0 || index === points.length - 1);
  const latitudes = sampled.map((point) => point.latitude);
  const longitudes = sampled.map((point) => point.longitude);
  const minLat = Math.min(...latitudes);
  const maxLat = Math.max(...latitudes);
  const minLng = Math.min(...longitudes);
  const maxLng = Math.max(...longitudes);
  const latRange = Math.max(0.0001, maxLat - minLat);
  const lngRange = Math.max(0.0001, maxLng - minLng);

  const width = CONTENT_WIDTH;
  const padding = 18;
  const drawableWidth = width - padding * 2;
  const drawableHeight = height - padding * 2;

  const projected = sampled.map((point) => ({
    x: padding + ((point.longitude - minLng) / lngRange) * drawableWidth,
    y: padding + drawableHeight - ((point.latitude - minLat) / latRange) * drawableHeight,
  }));

  const pathData = projected
    .map((point, index) => `${index === 0 ? 'M' : 'L'} ${point.x.toFixed(2)} ${point.y.toFixed(2)}`)
    .join(' ');

  const startPoint = projected[0];
  const endPoint = projected[projected.length - 1];

  return (
    <div style={shellStyle}>
      <div
        style={{
          position: 'absolute',
          inset: 0,
          background: 'radial-gradient(circle at top right, rgba(252,76,2,0.20) 0%, rgba(252,76,2,0.07) 18%, rgba(252,76,2,0) 44%)',
          pointerEvents: 'none',
        }}
      />
      <div style={mapViewportStyle}>
        <div style={{ position: 'absolute', top: 12, left: 12, right: 12, display: 'flex', justifyContent: 'space-between', gap: 10, zIndex: 2 }}>
          <div
            style={{
              ...chipStyle,
              background: 'rgba(15,23,42,0.54)',
              border: '1px solid rgba(255,255,255,0.12)',
              color: '#FDBA74',
            }}
          >
            Маршрут
          </div>
          <div
            style={{
              ...chipStyle,
              background: 'rgba(15,23,42,0.46)',
              border: '1px solid rgba(255,255,255,0.10)',
              color: '#CBD5E1',
            }}
          >
            GPS
          </div>
        </div>
        <svg viewBox={`0 0 ${width} ${height}`} width="100%" height="auto" role="img" aria-label="Маршрут тренировки">
        <defs>
          <linearGradient id="share-route-line" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stopColor="#FDBA74" />
            <stop offset="52%" stopColor="#FB923C" />
            <stop offset="100%" stopColor="#F97316" />
          </linearGradient>
        </defs>
        {[0.2, 0.5, 0.8].map((factor) => {
          const y = padding + drawableHeight * factor;
          return (
            <line
              key={`route-grid-${factor}`}
              x1={padding}
              y1={y}
              x2={width - padding}
              y2={y}
              stroke="rgba(148, 163, 184, 0.14)"
              strokeWidth="1"
            />
          );
        })}
        {[0.25, 0.5, 0.75].map((factor) => {
          const x = padding + drawableWidth * factor;
          return (
            <line
              key={`route-grid-x-${factor}`}
              x1={x}
              y1={padding}
              x2={x}
              y2={height - padding}
              stroke="rgba(148, 163, 184, 0.08)"
              strokeWidth="1"
            />
          );
        })}
        {[
          `M ${padding} ${padding + 28} C ${padding + 46} ${padding + 6}, ${padding + 102} ${padding + 44}, ${width - padding} ${padding + 18}`,
          `M ${padding + 18} ${height * 0.56} C ${width * 0.28} ${height * 0.42}, ${width * 0.58} ${height * 0.74}, ${width - padding} ${height * 0.58}`,
          `M ${width * 0.18} ${height - padding - 18} C ${width * 0.22} ${height * 0.62}, ${width * 0.44} ${height * 0.76}, ${width * 0.66} ${height - padding - 12}`,
        ].map((backgroundPath, index) => (
          <path
            key={`road-${index}`}
            d={backgroundPath}
            fill="none"
            stroke="rgba(226, 232, 240, 0.12)"
            strokeWidth={index === 1 ? 1.25 : 1}
            strokeLinecap="round"
            strokeLinejoin="round"
          />
        ))}
        <path d={pathData} fill="none" stroke="rgba(249, 115, 22, 0.34)" strokeWidth="14" strokeLinecap="round" strokeLinejoin="round" />
        <path d={pathData} fill="none" stroke="rgba(255,255,255,0.22)" strokeWidth="8" strokeLinecap="round" strokeLinejoin="round" opacity="0.28" />
        <path d={pathData} fill="none" stroke="url(#share-route-line)" strokeWidth="5.5" strokeLinecap="round" strokeLinejoin="round" />
        <circle cx={startPoint.x} cy={startPoint.y} r="7.5" fill="#FFFFFF" stroke="#F97316" strokeWidth="3" />
        <circle cx={endPoint.x} cy={endPoint.y} r="7.5" fill="#F97316" stroke="#111827" strokeWidth="3" />
        </svg>
        <div
          style={{
            position: 'absolute',
            inset: 'auto 0 0 0',
            height: 44,
            background: 'linear-gradient(180deg, rgba(15,23,42,0) 0%, rgba(15,23,42,0.44) 100%)',
            pointerEvents: 'none',
          }}
        />
      </div>
    </div>
  );
};

const ShareHeartRateChart = ({ timeline, marginTop = 20 }) => {
  const points = Array.isArray(timeline)
    ? timeline
      .map((point) => ({
        heartRate: Number(point?.heart_rate),
      }))
      .filter((point) => Number.isFinite(point.heartRate) && point.heartRate > 0)
    : [];

  if (points.length < 2) return null;

  const sampleStep = Math.max(1, Math.floor(points.length / 120));
  const sampled = points.filter((_, index) => index % sampleStep === 0 || index === points.length - 1);
  const minValue = Math.min(...sampled.map((point) => point.heartRate));
  const maxValue = Math.max(...sampled.map((point) => point.heartRate));
  const range = Math.max(1, maxValue - minValue);
  const paddedMin = Math.max(0, minValue - range * 0.08);
  const paddedMax = maxValue + range * 0.08;
  const paddedRange = Math.max(1, paddedMax - paddedMin);

  const width = CONTENT_WIDTH;
  const height = 144;
  const margin = { top: 10, right: 8, bottom: 18, left: 8 };
  const chartWidth = width - margin.left - margin.right;
  const chartHeight = height - margin.top - margin.bottom;

  const xScale = (index) => margin.left + (index / Math.max(1, sampled.length - 1)) * chartWidth;
  const yScale = (value) => margin.top + chartHeight - ((value - paddedMin) / paddedRange) * chartHeight;

  const linePath = sampled
    .map((point, index) => `${index === 0 ? 'M' : 'L'} ${xScale(index).toFixed(2)} ${yScale(point.heartRate).toFixed(2)}`)
    .join(' ');

  const areaPath = `${linePath} L ${xScale(sampled.length - 1).toFixed(2)} ${(margin.top + chartHeight).toFixed(2)} L ${xScale(0).toFixed(2)} ${(margin.top + chartHeight).toFixed(2)} Z`;
  const average = Math.round(sampled.reduce((sum, point) => sum + point.heartRate, 0) / sampled.length);
  const gridValues = [0, 0.5, 1].map((factor) => Math.round(paddedMin + paddedRange * factor));

  return (
    <div
      style={{
        marginTop,
        borderRadius: 22,
        padding: 14,
        border: '1px solid rgba(239, 68, 68, 0.12)',
        background: 'linear-gradient(180deg, rgba(255,255,255,0.94) 0%, rgba(255,244,244,0.96) 100%)',
        boxShadow: 'inset 0 1px 0 rgba(255,255,255,0.78)',
      }}
    >
      <div
        style={{
          fontSize: 12,
          fontWeight: 700,
          marginBottom: 8,
          color: '#0F172A',
          display: 'flex',
          alignItems: 'center',
          gap: 6,
          letterSpacing: '0.08em',
          textTransform: 'uppercase',
        }}
      >
        <HeartIcon size={18} aria-hidden /> Пульс по времени
      </div>
      <svg viewBox={`0 0 ${width} ${height}`} width="100%" height="auto" role="img" aria-label="График пульса">
        {gridValues.map((value, index) => {
          const y = yScale(value).toFixed(2);
          return (
            <line
              key={`grid-${index}`}
              x1={margin.left}
              y1={y}
              x2={width - margin.right}
              y2={y}
              stroke="#F1F5F9"
              strokeWidth="1"
            />
          );
        })}
        <path d={areaPath} fill="#FECACA" opacity="0.55" />
        <path d={linePath} fill="none" stroke="#EF4444" strokeWidth="3.5" strokeLinecap="round" strokeLinejoin="round" />
      </svg>
      <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', marginTop: 10, fontSize: 12, color: '#475569' }}>
        <span>Мин: {minValue} уд/мин</span>
        <span>Средний: {average} уд/мин</span>
        <span>Макс: {maxValue} уд/мин</span>
      </div>
    </div>
  );
};

const buildShareModel = ({ date, workout, timeline, staticMapUrl = null, staticMapAttribution = null }) => {
  const workoutDate = workout.start_time ? new Date(workout.start_time) : new Date(`${date}T12:00:00`);
  const startTimeStr = workout.start_time
    ? workoutDate.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
    : '—';

  const dateStr = date
    ? new Date(`${date}T00:00:00`).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' })
    : '';

  const typeLabel = getActivityTypeLabel(getWorkoutDisplayType(workout)) || 'Тренировка';
  const sourceLabel = workout.source && !workout.is_manual ? getSourceLabel(workout.source) : null;
  const primaryDistance = formatDistanceValue(workout.distance_km);
  const durationValue = formatDurationValue(workout);
  const durationText = formatDurationText(workout);
  const notePreview = truncateText(workout.notes);
  const hasRoute = getRoutePoints(timeline).length > 1;

  const metrics = [
    { label: 'Время', value: durationValue },
    { label: 'Темп', value: workout.avg_pace ? `${workout.avg_pace} /км` : null, accent: true },
    { label: 'Пульс', value: workout.avg_heart_rate != null ? `${workout.avg_heart_rate} уд/мин` : null },
    { label: 'Набор', value: workout.elevation_gain != null ? `${Math.round(workout.elevation_gain)} м` : null },
    { label: 'Калории', value: workout.calories != null ? `${Math.round(workout.calories)} ккал` : null },
  ].filter((metric) => metric.value);

  return {
    dateStr,
    durationText,
    durationValue,
    hasRoute,
    metrics,
    notePreview,
    primaryDistance,
    sourceLabel,
    startTimeStr,
    staticMapAttribution,
    staticMapUrl,
    timeline,
    typeLabel,
    workout,
  };
};

const PosterShareCard = ({ model }) => {
  const {
    dateStr,
    durationText,
    durationValue,
    hasRoute,
    metrics,
    notePreview,
    primaryDistance,
    sourceLabel,
    startTimeStr,
    staticMapAttribution,
    staticMapUrl,
    timeline,
    typeLabel,
    workout,
  } = model;

  return (
    <div
      style={{
        width: CARD_WIDTH,
        background: 'linear-gradient(180deg, #FFF9F5 0%, #FFFFFF 44%, #FFF8F4 100%)',
        color: '#0F172A',
        fontFamily: 'Jost, system-ui, sans-serif',
        padding: 28,
        borderRadius: 30,
        boxSizing: 'border-box',
        position: 'relative',
        overflow: 'hidden',
        border: '1px solid rgba(252, 76, 2, 0.12)',
        boxShadow: '0 24px 60px rgba(15, 23, 42, 0.12)',
      }}
    >
      <div
        style={{
          position: 'absolute',
          top: -82,
          right: -56,
          width: 220,
          height: 220,
          borderRadius: '50%',
          background: 'radial-gradient(circle, rgba(252,76,2,0.14) 0%, rgba(252,76,2,0.06) 45%, rgba(252,76,2,0) 72%)',
          pointerEvents: 'none',
        }}
      />
      <div
        style={{
          position: 'absolute',
          left: -48,
          bottom: -82,
          width: 180,
          height: 180,
          borderRadius: '50%',
          background: 'radial-gradient(circle, rgba(253,186,116,0.14) 0%, rgba(253,186,116,0.06) 48%, rgba(253,186,116,0) 72%)',
          pointerEvents: 'none',
        }}
      />

      <div
        style={{
          position: 'relative',
          zIndex: 1,
          display: 'flex',
          justifyContent: 'space-between',
          alignItems: 'flex-start',
          gap: 16,
          marginBottom: 24,
        }}
      >
        <div>
          <BrandWordmark />
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>
            <ShareBadge>{typeLabel}</ShareBadge>
            {sourceLabel ? <ShareBadge muted>{sourceLabel}</ShareBadge> : null}
          </div>
        </div>

        <div style={{ textAlign: 'right' }}>
          <div style={{ fontSize: 12, fontWeight: 700, letterSpacing: '0.08em', textTransform: 'uppercase', color: '#94A3B8', marginBottom: 6 }}>
            Выполнено
          </div>
          <div style={{ fontSize: 18, fontWeight: 700, color: '#0F172A', lineHeight: 1.1 }}>
            {dateStr}
          </div>
          <div style={{ fontSize: 13, color: '#64748B', marginTop: 4 }}>
            {startTimeStr}
          </div>
        </div>
      </div>

      <div
        style={{
          position: 'relative',
          zIndex: 1,
          display: 'grid',
          gridTemplateColumns: primaryDistance ? '1.1fr 0.9fr' : '1fr',
          gap: 20,
          alignItems: 'end',
          marginBottom: 24,
        }}
      >
        <div>
          <div style={{ fontSize: 12, fontWeight: 700, letterSpacing: '0.08em', textTransform: 'uppercase', color: '#94A3B8', marginBottom: 8 }}>
            Главный результат
          </div>
          {primaryDistance ? (
            <div style={{ display: 'flex', alignItems: 'flex-end', gap: 10, lineHeight: 0.9 }}>
              <span style={{ fontSize: 72, fontWeight: 800, letterSpacing: '-0.06em', color: '#0F172A' }}>
                {primaryDistance.value}
              </span>
              <span style={{ fontSize: 24, fontWeight: 700, color: '#F97316', paddingBottom: 10 }}>
                {primaryDistance.unit}
              </span>
            </div>
          ) : (
            <div style={{ fontSize: 44, fontWeight: 800, letterSpacing: '-0.05em', color: '#0F172A' }}>
              {durationValue || 'Тренировка'}
            </div>
          )}
          <div style={{ fontSize: 16, color: '#475569', marginTop: 10 }}>
            {workout.avg_pace ? `Средний темп ${workout.avg_pace} /км` : durationText}
          </div>
        </div>

        <div
          style={{
            justifySelf: primaryDistance ? 'end' : 'stretch',
            width: primaryDistance ? 150 : '100%',
            minHeight: 118,
            borderRadius: 26,
            background: 'linear-gradient(180deg, rgba(255,255,255,0.88) 0%, rgba(255,247,242,0.96) 100%)',
            border: '1px solid rgba(252,76,2,0.1)',
            boxShadow: 'inset 0 1px 0 rgba(255,255,255,0.74)',
            display: 'flex',
            flexDirection: 'column',
            justifyContent: 'center',
            padding: '18px 18px 16px',
          }}
        >
          <div style={{ fontSize: 11, fontWeight: 700, letterSpacing: '0.08em', textTransform: 'uppercase', color: '#94A3B8', marginBottom: 8 }}>
            Статус
          </div>
          <div style={{ fontSize: 24, fontWeight: 700, color: '#0F172A', lineHeight: 1.1, marginBottom: 8 }}>
            Завершено
          </div>
          <div style={{ fontSize: 14, color: '#64748B', lineHeight: 1.4 }}>
            {hasRoute ? 'Маршрут и метрики сохранены' : 'Метрики тренировки сохранены'}
          </div>
        </div>
      </div>

      {notePreview ? (
        <div
          style={{
            position: 'relative',
            zIndex: 1,
            marginBottom: 20,
            padding: '14px 16px',
            borderRadius: 18,
            background: 'rgba(255,255,255,0.74)',
            border: '1px solid rgba(148,163,184,0.12)',
            color: '#334155',
            fontSize: 15,
            lineHeight: 1.5,
          }}
        >
          {notePreview}
        </div>
      ) : null}

      {hasRoute ? (
        <ShareRoutePreview
          timeline={timeline}
          staticMapUrl={staticMapUrl}
          staticMapAttribution={staticMapAttribution}
        />
      ) : <ShareHeartRateChart timeline={timeline} />}

      <div
        style={{
          position: 'relative',
          zIndex: 1,
          display: 'grid',
          gridTemplateColumns: metrics.length >= 4 ? 'repeat(2, minmax(0, 1fr))' : 'repeat(auto-fit, minmax(140px, 1fr))',
          gap: 12,
          marginTop: 20,
        }}
      >
        {metrics.map((metric) => (
          <ShareMetricTile key={metric.label} label={metric.label} value={metric.value} accent={metric.accent} />
        ))}
      </div>

      <div
        style={{
          position: 'relative',
          zIndex: 1,
          display: 'flex',
          justifyContent: 'space-between',
          alignItems: 'center',
          marginTop: 20,
          paddingTop: 16,
          borderTop: '1px solid rgba(226,232,240,0.9)',
          fontSize: 12,
          color: '#94A3B8',
          letterSpacing: '0.04em',
        }}
      >
        <div style={{ display: 'flex', flexDirection: 'column', gap: staticMapUrl && staticMapAttribution ? 4 : 0 }}>
          <span>planrun.app</span>
          {staticMapUrl && staticMapAttribution ? (
            <span style={{ fontSize: 10, letterSpacing: 0, color: '#A3B1C6', lineHeight: 1.25 }}>
              {staticMapAttribution}
            </span>
          ) : null}
        </div>
        {workout.id ? <span>#{workout.id}</span> : <span>{typeLabel}</span>}
      </div>
    </div>
  );
};

const RouteShareCard = ({ model }) => {
  const {
    dateStr,
    durationValue,
    hasRoute,
    notePreview,
    primaryDistance,
    sourceLabel,
    startTimeStr,
    staticMapAttribution,
    staticMapUrl,
    timeline,
    typeLabel,
    workout,
  } = model;

  const heroMetric = {
    key: 'time',
    label: 'Время',
    value: durationValue,
    helper: null,
  };

  const routeMetrics = [
    { key: 'pace', label: 'Темп', value: workout.avg_pace ? `${workout.avg_pace} мин/км` : null, accent: true, primaryAlign: 'right', secondaryAlign: 'right' },
    { key: 'pulse', label: 'Пульс', value: workout.avg_heart_rate != null ? `${workout.avg_heart_rate} уд/мин` : null, primaryAlign: 'right', secondaryAlign: 'right' },
    { key: 'elevation', label: 'Высота', value: workout.elevation_gain != null ? `${Math.round(workout.elevation_gain)} м` : null, primaryAlign: 'right', secondaryAlign: 'right' },
  ]
    .filter((metric) => metric.value);

  return (
    <div
      style={{
        width: CARD_WIDTH,
        background: [
          'radial-gradient(circle at 100% 0%, rgba(252,76,2,0.20) 0%, rgba(252,76,2,0.13) 16%, rgba(252,76,2,0.06) 34%, rgba(252,76,2,0.02) 50%, rgba(252,76,2,0) 70%)',
          'radial-gradient(circle at 0% 100%, rgba(253,186,116,0.14) 0%, rgba(253,186,116,0.08) 16%, rgba(253,186,116,0.03) 32%, rgba(253,186,116,0) 58%)',
          'linear-gradient(145deg, #FFF4EC 0%, #FFF1E8 34%, #FFF3EB 70%, #FFF6F0 100%)',
        ].join(', '),
        color: '#0F172A',
        fontFamily: 'Jost, system-ui, sans-serif',
        padding: 28,
        borderRadius: 30,
        boxSizing: 'border-box',
        position: 'relative',
        overflow: 'hidden',
        border: '1px solid rgba(252, 76, 2, 0.14)',
        boxShadow: '0 28px 66px rgba(15, 23, 42, 0.14)',
      }}
    >
      <div
        style={{
          position: 'relative',
          zIndex: 1,
          display: 'flex',
          justifyContent: 'space-between',
          alignItems: 'flex-start',
          gap: 16,
          marginBottom: 18,
        }}
      >
        <div>
          <BrandWordmark />
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>
            <ShareBadge>{typeLabel}</ShareBadge>
            {sourceLabel ? <ShareBadge muted>{sourceLabel}</ShareBadge> : null}
          </div>
        </div>

        <div style={{ textAlign: 'right' }}>
          <div style={{ fontSize: 12, fontWeight: 700, letterSpacing: '0.08em', textTransform: 'uppercase', color: '#94A3B8', marginBottom: 6 }}>
            {dateStr}
          </div>
          <div style={{ fontSize: 14, color: '#64748B' }}>
            {startTimeStr}
          </div>
        </div>
      </div>

      <div
        style={{
          position: 'relative',
          zIndex: 1,
          display: 'grid',
          gridTemplateColumns: '1.15fr 0.85fr',
          gap: 16,
          alignItems: 'end',
          marginBottom: 8,
        }}
      >
        <div>
          {primaryDistance ? (
            <div style={{ display: 'flex', alignItems: 'flex-end', gap: 8, lineHeight: 0.92 }}>
              <span style={{ fontSize: 78, fontWeight: 800, fontStyle: 'italic', letterSpacing: '-0.07em', color: '#F97316' }}>
                {primaryDistance.value}
              </span>
              <span style={{ fontSize: 24, fontWeight: 800, color: '#0F172A', paddingBottom: 10 }}>
                {primaryDistance.unit}
              </span>
            </div>
          ) : (
            <div style={{ fontSize: 46, fontWeight: 800, letterSpacing: '-0.05em', color: '#0F172A' }}>
              {durationValue || 'Тренировка'}
            </div>
          )}
        </div>

        <div
          style={{
            borderRadius: 24,
            background: 'rgba(255,255,255,0.94)',
            border: '1px solid rgba(252,76,2,0.12)',
            padding: '16px 16px 15px',
            boxShadow: 'inset 0 1px 0 rgba(255,255,255,0.78)',
          }}
        >
          <div style={{ fontSize: 11, fontWeight: 700, letterSpacing: '0.08em', textTransform: 'uppercase', color: '#94A3B8', marginBottom: 8 }}>
            {heroMetric.label}
          </div>
          <div style={{ fontSize: 28, fontWeight: 700, lineHeight: 1, color: '#0F172A', marginBottom: 8 }}>
            {splitMetricValue(heroMetric.value).primary || '—'}
          </div>
          {splitMetricValue(heroMetric.value).secondary ? (
            <div style={{ fontSize: 13, color: '#64748B' }}>
              {splitMetricValue(heroMetric.value).secondary}
            </div>
          ) : null}
        </div>
      </div>

      {hasRoute ? (
        <ShareRoutePreview
          timeline={timeline}
          staticMapUrl={staticMapUrl}
          staticMapAttribution={staticMapAttribution}
          marginTop={16}
          height={236}
          elevated
        />
      ) : <ShareHeartRateChart timeline={timeline} marginTop={16} />}

      <div
        style={{
          position: 'relative',
          zIndex: 1,
          display: 'grid',
          gridTemplateColumns: 'repeat(3, minmax(0, 1fr))',
          gap: 12,
          marginTop: 16,
        }}
      >
        {routeMetrics.map((metric) => (
          <ShareMetricTile
            key={metric.label}
            label={metric.label}
            value={metric.value}
            accent={metric.accent}
            primaryAlign={metric.primaryAlign}
            secondaryAlign={metric.secondaryAlign}
          />
        ))}
      </div>

      {notePreview ? (
        <div
          style={{
            position: 'relative',
            zIndex: 1,
            marginTop: 16,
            padding: '14px 16px',
            borderRadius: 18,
            background: 'rgba(255,255,255,0.72)',
            border: '1px solid rgba(148,163,184,0.12)',
            color: '#334155',
            fontSize: 14,
            lineHeight: 1.5,
          }}
        >
          {notePreview}
        </div>
      ) : null}

      {staticMapUrl && staticMapAttribution ? (
        <div
          style={{
            position: 'relative',
            zIndex: 1,
            marginTop: 18,
            paddingTop: 16,
            borderTop: '1px solid rgba(226,232,240,0.9)',
            fontSize: 10,
            color: '#A3B1C6',
            lineHeight: 1.25,
          }}
        >
          {staticMapAttribution}
        </div>
      ) : null}
    </div>
  );
};

const MinimalShareCard = ({ model }) => {
  const {
    dateStr,
    durationText,
    durationValue,
    metrics,
    notePreview,
    primaryDistance,
    sourceLabel,
    startTimeStr,
    typeLabel,
    workout,
  } = model;

  const summaryRows = [
    { label: 'Тип', value: typeLabel },
    { label: 'Старт', value: `${dateStr}${startTimeStr !== '—' ? `, ${startTimeStr}` : ''}` },
    { label: 'Источник', value: sourceLabel || 'PlanRun' },
    ...metrics.map((metric) => ({ label: metric.label, value: metric.value })),
  ].filter((row) => row.value);

  return (
    <div
      style={{
        width: CARD_WIDTH,
        background: '#FFFFFF',
        color: '#0F172A',
        fontFamily: 'Jost, system-ui, sans-serif',
        padding: 28,
        borderRadius: 26,
        boxSizing: 'border-box',
        border: '1px solid rgba(226,232,240,0.95)',
        boxShadow: '0 18px 44px rgba(15, 23, 42, 0.08)',
      }}
    >
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 20, marginBottom: 24 }}>
        <div>
          <BrandWordmark size={22} marginBottom={0} />
        </div>

        <div style={{ textAlign: 'right' }}>
          <div style={{ fontSize: 15, fontWeight: 700, color: '#0F172A', lineHeight: 1.2 }}>
            {dateStr}
          </div>
          <div style={{ fontSize: 13, color: '#64748B', marginTop: 4 }}>
            {startTimeStr}
          </div>
        </div>
      </div>

      <div style={{ marginBottom: 24 }}>
        {primaryDistance ? (
          <div style={{ display: 'flex', alignItems: 'flex-end', gap: 8, lineHeight: 0.92, marginBottom: 8 }}>
            <span style={{ fontSize: 70, fontWeight: 800, letterSpacing: '-0.07em', color: '#0F172A' }}>
              {primaryDistance.value}
            </span>
            <span style={{ fontSize: 24, fontWeight: 700, color: '#F97316', paddingBottom: 10 }}>
              {primaryDistance.unit}
            </span>
          </div>
        ) : (
          <div style={{ fontSize: 44, fontWeight: 800, letterSpacing: '-0.05em', color: '#0F172A', marginBottom: 8 }}>
            {durationValue || 'Тренировка'}
          </div>
        )}
        <div style={{ fontSize: 15, color: '#475569' }}>
          {workout.avg_pace ? `Средний темп ${workout.avg_pace} /км` : durationText || typeLabel}
        </div>
      </div>

      <div style={{ borderTop: '1px solid #E2E8F0', borderBottom: '1px solid #E2E8F0', marginBottom: 18 }}>
        {summaryRows.slice(0, 6).map((row, index) => (
          <div
            key={`${row.label}-${index}`}
            style={{
              display: 'flex',
              justifyContent: 'space-between',
              gap: 16,
              padding: '12px 0',
              borderBottom: index === Math.min(summaryRows.length, 6) - 1 ? 'none' : '1px solid #F1F5F9',
              alignItems: 'baseline',
            }}
          >
            <span style={{ fontSize: 12, fontWeight: 700, letterSpacing: '0.08em', textTransform: 'uppercase', color: '#94A3B8' }}>
              {row.label}
            </span>
            <span style={{ fontSize: 16, fontWeight: 600, color: '#0F172A', textAlign: 'right' }}>
              {row.value}
            </span>
          </div>
        ))}
      </div>

      {notePreview ? (
        <div style={{ fontSize: 15, lineHeight: 1.55, color: '#334155', marginBottom: 16 }}>
          {notePreview}
        </div>
      ) : null}
    </div>
  );
};

const WorkoutShareCard = ({
  date,
  workout,
  timeline,
  staticMapUrl = null,
  staticMapAttribution = null,
  className = '',
  variant = 'poster',
}) => {
  if (!workout) return null;

  const model = buildShareModel({ date, workout, timeline, staticMapUrl, staticMapAttribution });

  if (variant === 'route') {
    return <RouteShareCard model={model} className={className} />;
  }

  if (variant === 'minimal') {
    return <MinimalShareCard model={model} className={className} />;
  }

  return <PosterShareCard model={model} className={className} />;
};

export default WorkoutShareCard;
