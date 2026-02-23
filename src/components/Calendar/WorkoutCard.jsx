/**
 * Карточка тренировки в стиле Strava/Nike Run Club
 * Современный дизайн с крупными метриками и цветовой индикацией
 */

import React, { useMemo } from 'react';
import './WorkoutCard.css';

const TYPE_NAMES = {
  easy: 'Легкий бег',
  long: 'Длительный бег',
  'long-run': 'Длительный бег',
  tempo: 'Темповый бег',
  interval: 'Интервалы',
  other: 'ОФП',
  sbu: 'СБУ',
  fartlek: 'Фартлек',
  race: 'Соревнование',
  rest: 'День отдыха',
  free: 'Пустой день',
};

/** Цветовая группа для полоски — те же классы, что в легенде дашборда (easy, tempo, interval, long, race, other, sbu, rest). */
function getWorkoutStripColorClass(type) {
  if (!type) return null;
  const stripByType = {
    easy: 'easy',
    tempo: 'tempo',
    interval: 'interval',
    fartlek: 'interval',
    long: 'long',
    'long-run': 'long',
    race: 'race',
    other: 'other',
    sbu: 'sbu',
    rest: 'rest',
  };
  return stripByType[type] || (type === 'free' ? null : 'run');
}

/** Ограничить описание до maxItems пунктов (по <li> или по строкам), вернуть { html, hasMore } */
function limitDescription(description, maxItems) {
  if (!description || !maxItems || typeof document === 'undefined') {
    return { html: description || '', hasMore: false };
  }
  const div = document.createElement('div');
  div.innerHTML = description;
  const lis = div.querySelectorAll('li');
  if (lis.length > maxItems) {
    const ul = document.createElement('ul');
    for (let i = 0; i < maxItems; i++) ul.appendChild(lis[i].cloneNode(true));
    return { html: ul.outerHTML, hasMore: true };
  }
  const text = (div.textContent || '').trim();
  const lines = text.split(/\r?\n|<br\s*\/?>/i).map((s) => s.trim()).filter(Boolean);
  if (lines.length > maxItems) {
    const limited = lines.slice(0, maxItems).join('<br/>');
    return { html: limited, hasMore: true };
  }
  return { html: description, hasMore: false };
}

const WorkoutCard = ({ 
  workout, 
  date, 
  status = 'planned', // 'completed', 'planned', 'missed', 'rest'
  onPress,
  isToday = false,
  compact = false,
  dayDetail = null, // {plan, planDays, dayExercises, workouts}
  workoutMetrics = null, // {distance, duration, pace} из workoutsData
  results = [], // Результаты из resultsData
  planDays = [], // [{ id, type, description, is_key_workout? }] — все тренировки дня
  onDeletePlanDay, // (dayId) => void
  onEditPlanDay, // (planDay) => void — открыть модалку редактирования
  canEdit = false,
  extraActions = null, // React node — кнопки дашборда (развернуть, «Отметить выполнение») рендерятся внутри карточки
  maxDescriptionItems = null, // при числе (напр. 3) — показывать только первые N пунктов + «и др.» (для двух виджетов в строку)
}) => {
  const items = (planDays && planDays.length > 0) ? planDays : null;
  const statusConfig = {
    completed: { 
      border: 'var(--success-500)', 
      icon: '✅',
      label: 'Выполнено'
    },
    planned: { 
      border: 'var(--primary-500)', 
      icon: '📅',
      label: 'Запланировано'
    },
    missed: { 
      border: 'var(--accent-500)', 
      icon: '❌',
      label: 'Пропущено'
    },
    rest: {
      border: 'var(--gray-300)',
      icon: '😴',
      label: 'Отдых'
    }
  };

  const config = statusConfig[status] || statusConfig.planned;
  const isRest = status === 'rest' || !workout || workout?.type === 'free';
  const hasPlanDays = items && items.length > 0;

  const formatDate = (dateString) => {
    if (!dateString) return '';
    // Исправляем: используем UTC для правильного определения дня недели
    const dateObj = new Date(dateString + 'T00:00:00Z');
    const dayNames = ['Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота'];
    const dayName = dayNames[dateObj.getUTCDay()];
    const formattedDate = dateObj.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', timeZone: 'UTC' });
    return `${formattedDate} • ${dayName}`;
  };

  const extractMetrics = (text) => {
    if (!text) return {};
    
    const metrics = {};
    
    // Дистанция (км)
    const distanceMatch = text.match(/(\d+(?:[.,]\d+)?)\s*(?:км|km)/i);
    if (distanceMatch) {
      metrics.distance = parseFloat(distanceMatch[1].replace(',', '.'));
    }
    
    // Время (минуты)
    const timeMatch = text.match(/(\d+)\s*(?:мин|min|минут)/i);
    if (timeMatch) {
      metrics.duration = parseInt(timeMatch[1]);
    }
    
    // Темп (мин/км)
    const paceMatch = text.match(/(\d+):(\d+)\s*(?:\/км|\/km)/i);
    if (paceMatch) {
      metrics.pace = `${paceMatch[1]}:${paceMatch[2]}`;
    }
    
    return metrics;
  };

  const metrics = useMemo(() => {
    // Сначала проверяем workoutMetrics (из GPX/TCX)
    if (workoutMetrics && (workoutMetrics.distance || workoutMetrics.duration || workoutMetrics.pace)) {
      return {
        distance: workoutMetrics.distance,
        duration: workoutMetrics.duration ? Math.round(workoutMetrics.duration / 60) : null,
        pace: workoutMetrics.pace
      };
    }
    
    // Затем проверяем results (из workout_log)
    if (results && results.length > 0) {
      const firstResult = results[0];
      return {
        distance: firstResult.result_distance ? parseFloat(firstResult.result_distance) : null,
        duration: firstResult.result_time ? firstResult.result_time : null,
        pace: firstResult.result_pace || null
      };
    }
    
    // В конце извлекаем из текста
    return workout?.text ? extractMetrics(workout.text) : {};
  }, [workout?.text, workoutMetrics, results]);

  const workoutTitle = useMemo(() => {
    if (workout?.type === 'rest') {
      return 'День отдыха';
    }
    if (workout?.type === 'free') {
      return 'Пустой день';
    }
    
    const typeName = TYPE_NAMES[workout?.type];
    if (typeName) {
      return typeName;
    }
    
    // Если тип не определен, используем текст из описания
    return workout?.text?.split('\n')[0] || workout?.text || 'Тренировка';
  }, [workout?.type, workout?.text]);

  // Добавляем класс для статуса, чтобы CSS мог управлять фоном
  return (
    <div 
      className={`workout-card workout-card-${status} ${compact ? 'workout-card-compact' : ''} ${isToday ? 'workout-card-today' : ''}`}
      style={{ 
        borderLeft: `4px solid ${config.border}`
      }}
      onClick={onPress}
    >
      <div className="workout-card-content">
        <div className="workout-card-header">
          <div className="workout-date-wrapper">
            <span className="workout-date">{formatDate(date)}</span>
            {isToday && <span className="workout-badge-today">Сегодня</span>}
          </div>
        </div>

        {/* Список всех тренировок дня с возможностью удаления */}
        {hasPlanDays && (
          <div className="workout-card-plan-days">
          {items.map((planDay) => {
            const stripClass = getWorkoutStripColorClass(planDay.type);
            return (
            <div
              key={planDay.id}
              className={`workout-card-plan-day-block${stripClass ? ` workout-card-plan-day-block--${stripClass}` : ''}`}
            >
              <div className="workout-card-plan-day-head">
                <span className="workout-card-plan-day-type">{TYPE_NAMES[planDay.type] || planDay.type || 'Тренировка'}</span>
                {canEdit && (
                  <div className="workout-card-plan-day-actions">
                    {onEditPlanDay && (
                      <button
                        type="button"
                        className="workout-card-btn-edit-plan-day"
                        onClick={(e) => { e.stopPropagation(); onEditPlanDay(planDay); }}
                        title="Редактировать тренировку"
                      >
                        Изменить
                      </button>
                    )}
                    {onDeletePlanDay && (
                      <button
                        type="button"
                        className="workout-card-btn-delete-plan-day"
                        onClick={(e) => { e.stopPropagation(); onDeletePlanDay(planDay.id); }}
                        title="Удалить тренировку"
                      >
                        Удалить
                      </button>
                    )}
                  </div>
                )}
              </div>
              {planDay.description && (() => {
                const { html, hasMore } = maxDescriptionItems
                  ? limitDescription(planDay.description, maxDescriptionItems)
                  : { html: planDay.description, hasMore: false };
                return (
                  <>
                    <div className="workout-card-plan-day-text" dangerouslySetInnerHTML={{ __html: html }} />
                    {hasMore && <span className="workout-card-plan-day-more">и др.</span>}
                  </>
                );
              })()}
            </div>
          );
          })}
        </div>
      )}
      
      {!isRest && !hasPlanDays && (
        <>
          <div className="workout-title">{workoutTitle}</div>
          
          {(metrics.distance || metrics.duration || metrics.pace) && (
            <div className="workout-metrics">
              {metrics.distance && (
                <div className="metric">
                  <span className="metric-icon">🏃</span>
                  <div className="metric-content">
                    <span className="metric-value">{metrics.distance}</span>
                    <span className="metric-unit">км</span>
                  </div>
                </div>
              )}
              {metrics.duration && (
                <div className="metric">
                  <span className="metric-icon">⏱️</span>
                  <div className="metric-content">
                    <span className="metric-value">{metrics.duration}</span>
                    <span className="metric-unit">мин</span>
                  </div>
                </div>
              )}
              {metrics.pace && (
                <div className="metric">
                  <span className="metric-icon">📍</span>
                  <div className="metric-content">
                    <span className="metric-value">{metrics.pace}</span>
                    <span className="metric-unit">/км</span>
                  </div>
                </div>
              )}
            </div>
          )}

          {/* Полное описание тренировки */}
          {workout?.text && (
            <div className="workout-description">
              {workout.text.replace(/<[^>]*>/g, '').trim()}
            </div>
          )}
          
          {/* Упражнения */}
          {workout?.dayExercises && workout.dayExercises.length > 0 && (
            <div className="workout-exercises">
              <div className="workout-exercises-title">💪 Упражнения ({workout.dayExercises.length})</div>
              <div className="workout-exercises-list">
                {workout.dayExercises.slice(0, 5).map((exercise, idx) => (
                  <div key={exercise.id || idx} className="workout-exercise-item">
                    <span className="exercise-name">{exercise.name || 'Упражнение'}</span>
                    {exercise.sets && exercise.reps && (
                      <span className="exercise-sets">({exercise.sets}×{exercise.reps})</span>
                    )}
                    {exercise.distance_m && (
                      <span className="exercise-detail">{exercise.distance_m} м</span>
                    )}
                    {exercise.duration_sec && (
                      <span className="exercise-detail">{Math.round(exercise.duration_sec / 60)} мин</span>
                    )}
                    {exercise.weight_kg && (
                      <span className="exercise-detail">{exercise.weight_kg} кг</span>
                    )}
                  </div>
                ))}
                {workout.dayExercises.length > 5 && (
                  <div className="workout-exercises-more">+{workout.dayExercises.length - 5} еще</div>
                )}
              </div>
            </div>
          )}
          
          {/* Результаты из workout_log (если есть несколько) */}
          {results && results.length > 1 && (
            <div className="workout-results">
              <div className="workout-results-title">📊 Результаты ({results.length})</div>
              {results.map((result, idx) => (
                <div key={idx} className="workout-result-item">
                  {result.result_distance && <span>📏 {result.result_distance} км</span>}
                  {result.result_time && <span>⏱️ {result.result_time}</span>}
                  {result.result_pace && <span>⚡ {result.result_pace}</span>}
                  {result.notes && <div className="result-notes">{result.notes}</div>}
                </div>
              ))}
            </div>
          )}
        </>
      )}

      {isRest && !hasPlanDays && (
        <div className="workout-rest">
          <span className="rest-text">
            {isToday ? 'На сегодня тренировок не запланировано' : (workout?.type === 'free' ? 'Пустой день' : 'День отдыха')}
          </span>
        </div>
      )}
      </div>

      <div className={`workout-actions${extraActions ? ' workout-card-extra-actions' : ''}`}>
        {extraActions ?? (
          <>
            {status === 'completed' && (
              <button className="btn-workout btn-details" onClick={(e) => { e.stopPropagation(); onPress?.(); }}>
                Детали
              </button>
            )}
            {status === 'missed' && (
              <button className="btn-workout btn-missed btn btn-primary" onClick={(e) => { e.stopPropagation(); onPress?.(); }}>
                Отметить выполненной
              </button>
            )}
          </>
        )}
      </div>
    </div>
  );
};

export default WorkoutCard;
