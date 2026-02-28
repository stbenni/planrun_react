/**
 * Карточка тренировки для генерации изображения при «Поделиться»
 * Рендерится скрыто для захвата html2canvas
 */

import React from 'react';
import { HeartIcon } from '../common/Icons';
import HeartRateChart from './HeartRateChart';

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

const getActivityTypeLabel = (t) => {
  if (!t) return '';
  const key = String(t).toLowerCase().trim();
  return ACTIVITY_TYPE_LABELS[key] || t;
};

/** Тип для отображения: plan type (easy, long, tempo) приоритетнее activity_type (running) */
const getWorkoutDisplayType = (workout) => {
  if (!workout) return null;
  const planType = workout.type;
  const activityType = workout.activity_type;
  if (planType && ACTIVITY_TYPE_LABELS[String(planType).toLowerCase().trim()]) {
    return planType;
  }
  return activityType || planType;
};

const SOURCE_LABELS = {
  strava: 'Strava',
  huawei: 'Huawei Health',
  polar: 'Polar',
  gpx: 'GPX-файл',
};

const getSourceLabel = (s) => {
  if (!s) return null;
  const key = String(s).toLowerCase();
  return SOURCE_LABELS[key] || s;
};

const WorkoutShareCard = ({ date, workout, timeline, className = '' }) => {
  if (!workout) return null;

  const workoutDate = workout.start_time ? new Date(workout.start_time) : new Date(date + 'T12:00:00');
  const startTimeStr = workout.start_time
    ? workoutDate.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
    : '—';
  let durationStr = '—';
  if (workout.duration_seconds != null && workout.duration_seconds > 0) {
    const h = Math.floor(workout.duration_seconds / 3600);
    const m = Math.floor((workout.duration_seconds % 3600) / 60);
    const s = workout.duration_seconds % 60;
    durationStr = (h > 0 ? `${h} ч ` : '') + `${m} мин ${s} сек`;
  } else if (workout.duration_minutes != null && workout.duration_minutes > 0) {
    const h = Math.floor(workout.duration_minutes / 60);
    const m = workout.duration_minutes % 60;
    durationStr = h > 0 ? `${h}ч ${m}м` : `${m}м`;
  }

  const dateStr = date
    ? new Date(date + 'T00:00:00').toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' })
    : '';
  const typeLabel = getActivityTypeLabel(getWorkoutDisplayType(workout));
  const sourceLabel = workout.source && !workout.is_manual ? getSourceLabel(workout.source) : null;

  const metrics = [
    workout.distance_km != null && { label: 'Дистанция:', value: `${workout.distance_km} км` },
    durationStr !== '—' && { label: 'Время:', value: durationStr },
    workout.avg_pace && { label: 'Средний темп:', value: `${workout.avg_pace} /км` },
    workout.avg_heart_rate != null && { label: 'Средний пульс:', value: `${workout.avg_heart_rate} уд/мин` },
    workout.max_heart_rate != null && { label: 'Макс. пульс:', value: `${workout.max_heart_rate} уд/мин` },
    workout.elevation_gain != null && { label: 'Набор высоты:', value: `${Math.round(workout.elevation_gain)} м` },
    workout.id && { label: 'Номер тренировки:', value: `#${workout.id}` },
  ].filter(Boolean);

  return (
    <div
      className={`workout-share-card ${className}`}
      style={{
        width: 400,
        background: '#ffffff',
        color: '#0F172A',
        fontFamily: 'Montserrat, system-ui, sans-serif',
        padding: 24,
        borderRadius: 16,
        boxSizing: 'border-box',
      }}
    >
      {/* Логотип */}
      <div
        className="workout-share-card__logo"
        style={{
          marginBottom: 8,
          fontSize: 24,
          fontWeight: 300,
          color: '#FC4C02',
          letterSpacing: '-0.5px',
        }}
      >
        <span style={{ fontWeight: 300 }}>plan</span>
        <span style={{ fontWeight: 800 }}>RUN</span>
      </div>
      {/* Оранжевая полоска как в хедере */}
      <div
        style={{
          height: 3,
          background: 'linear-gradient(135deg, #FC4C02 0%, #E03D00 100%)',
          borderRadius: '2px 2px 0 0',
          marginBottom: 16,
        }}
      />

      {/* Заголовок */}
      <div style={{ marginBottom: 4, fontSize: 22, fontWeight: 700, color: '#0F172A' }}>
        {dateStr} {typeLabel.toUpperCase()}
      </div>
      {sourceLabel && (
        <div style={{ marginBottom: 12, fontSize: 12, color: '#64748B' }}>
          Импортировано из: {sourceLabel}
        </div>
      )}

      {/* Время и тип — на одной линии */}
      <div style={{ marginBottom: 16, display: 'flex', gap: 8, alignItems: 'baseline' }}>
        <span style={{ fontSize: 18, fontWeight: 600 }}>{startTimeStr}</span>
        {typeLabel && (
          <span style={{ fontSize: 14, color: '#475569' }}>{typeLabel}</span>
        )}
      </div>

      {/* Метрики */}
      <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginBottom: 16 }}>
        {metrics.map((m, i) => (
          <div
            key={i}
            style={{
              display: 'flex',
              justifyContent: 'space-between',
              alignItems: 'center',
              padding: '6px 0',
              borderBottom: '1px solid #E2E8F0',
            }}
          >
            <span style={{ fontSize: 14, color: '#64748B' }}>{m.label}</span>
            <span style={{ fontSize: 16, fontWeight: 600, color: '#0F172A' }}>{m.value}</span>
          </div>
        ))}
      </div>

      {/* График пульса — заголовок здесь, в HeartRateChart скрыт */}
      {timeline && timeline.length > 0 && (
        <div className="workout-share-card__chart">
          <div style={{ fontSize: 14, fontWeight: 600, marginBottom: 4, color: '#0F172A', display: 'flex', alignItems: 'center', gap: 6 }}>
            <HeartIcon size={18} aria-hidden /> Пульс по времени
          </div>
          <HeartRateChart timeline={timeline} hideTitle />
        </div>
      )}
    </div>
  );
};

export default WorkoutShareCard;
