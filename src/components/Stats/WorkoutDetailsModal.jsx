/**
 * Модальное окно деталей тренировки
 */

import React, { useState, useEffect, useRef } from 'react';
import { HeartRateChart, PaceChart } from './index';
import useAuthStore from '../../stores/useAuthStore';

const WorkoutDetailsModal = ({ isOpen, onClose, date, dayData, loading }) => {
  const { api } = useAuthStore();
  const [timelineData, setTimelineData] = useState({});
  const [loadingTimeline, setLoadingTimeline] = useState({});
  const loadedWorkoutsRef = useRef(new Set());

  // Загружаем timeline данные для каждой тренировки с ID (не ручной)
  useEffect(() => {
    if (!isOpen || !dayData || !dayData.workouts || !api) {
      // Сбрасываем загруженные тренировки при закрытии модального окна
      if (!isOpen) {
        loadedWorkoutsRef.current.clear();
        setTimelineData({});
        setLoadingTimeline({});
      }
      return;
    }

    const loadTimeline = async (workoutId) => {
      // Проверяем, не загружаем ли мы уже эту тренировку
      if (loadedWorkoutsRef.current.has(workoutId) || loadingTimeline[workoutId]) return;

      setLoadingTimeline(prev => ({ ...prev, [workoutId]: true }));
      try {
        const response = await api.getWorkoutTimeline(workoutId);
        let timeline = null;
        
        if (response && typeof response === 'object') {
          if (response.timeline) {
            timeline = response.timeline;
          } else if (response.data && response.data.timeline) {
            timeline = response.data.timeline;
          } else if (response.success && response.data && response.data.timeline) {
            timeline = response.data.timeline;
          } else if (Array.isArray(response)) {
            timeline = response;
          }
        }
        
        if (timeline && Array.isArray(timeline) && timeline.length > 0) {
          setTimelineData(prev => ({ ...prev, [workoutId]: timeline }));
          loadedWorkoutsRef.current.add(workoutId);
        }
      } catch (error) {
        console.error('Error loading workout timeline:', error);
      } finally {
        setLoadingTimeline(prev => ({ ...prev, [workoutId]: false }));
      }
    };

    dayData.workouts.forEach(workout => {
      // Загружаем timeline только для автоматических тренировок (не ручных)
      if (workout.id && !workout.is_manual) {
        loadTimeline(workout.id);
      }
    });
  }, [isOpen, dayData, api]);

  if (!isOpen) return null;

  return (
    <div className="workout-details-modal-overlay" onClick={onClose}>
      <div className="workout-details-modal" onClick={(e) => e.stopPropagation()}>
        <div className="workout-details-modal-header">
          <h2 className="workout-details-modal-title">
            Тренировка {date ? new Date(date + 'T00:00:00').toLocaleDateString('ru-RU', {
              day: 'numeric',
              month: 'long',
              year: 'numeric'
            }) : ''}
          </h2>
          <button className="workout-details-modal-close" onClick={onClose}>×</button>
        </div>
        
        <div className="workout-details-modal-body">
          {loading ? (
            <div className="workout-details-loading">Загрузка...</div>
          ) : dayData && dayData.workouts && dayData.workouts.length > 0 ? (
            <div className="workout-details-list">
              {dayData.workouts.map((workout, index) => {
                const workoutDate = workout.start_time ? new Date(workout.start_time) : new Date(date + 'T12:00:00');
                const hours = Math.floor((workout.duration_minutes || 0) / 60);
                const mins = Math.floor((workout.duration_minutes || 0) % 60);
                
                // Форматируем время начала тренировки
                const startTimeStr = workout.start_time 
                  ? workoutDate.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
                  : '—';
                
                return (
                  <div key={index} className="workout-details-item">
                    <div className="workout-details-item-header">
                      <div className="workout-details-item-time">
                        {startTimeStr}
                      </div>
                      {workout.activity_type && (
                        <div className="workout-details-item-type">
                          {workout.activity_type}
                        </div>
                      )}
                    </div>
                    
                    <div className="workout-details-item-metrics">
                      {workout.distance_km && (
                        <div className="workout-details-metric">
                          <span className="workout-details-metric-label">Дистанция:</span>
                          <span className="workout-details-metric-value">{workout.distance_km} км</span>
                        </div>
                      )}
                      {workout.duration_minutes && (
                        <div className="workout-details-metric">
                          <span className="workout-details-metric-label">Время:</span>
                          <span className="workout-details-metric-value">
                            {hours > 0 ? `${hours}ч ` : ''}{mins}м
                          </span>
                        </div>
                      )}
                      {workout.avg_pace && (
                        <div className="workout-details-metric">
                          <span className="workout-details-metric-label">Средний темп:</span>
                          <span className="workout-details-metric-value">{workout.avg_pace} /км</span>
                        </div>
                      )}
                      {workout.avg_heart_rate && (
                        <div className="workout-details-metric">
                          <span className="workout-details-metric-label">Средний пульс:</span>
                          <span className="workout-details-metric-value">{workout.avg_heart_rate} уд/мин</span>
                        </div>
                      )}
                      {workout.max_heart_rate && (
                        <div className="workout-details-metric">
                          <span className="workout-details-metric-label">Макс. пульс:</span>
                          <span className="workout-details-metric-value">{workout.max_heart_rate} уд/мин</span>
                        </div>
                      )}
                      {workout.elevation_gain && (
                        <div className="workout-details-metric">
                          <span className="workout-details-metric-label">Набор высоты:</span>
                          <span className="workout-details-metric-value">{Math.round(workout.elevation_gain)} м</span>
                        </div>
                      )}
                      {workout.id && (
                        <div className="workout-details-metric">
                          <span className="workout-details-metric-label">Номер тренировки:</span>
                          <span className="workout-details-metric-value">#{workout.id}</span>
                        </div>
                      )}
                      {workout.is_manual && (
                        <div className="workout-details-metric">
                          <span className="workout-details-metric-label">Тип:</span>
                          <span className="workout-details-metric-value">Ручная запись</span>
                        </div>
                      )}
                    </div>
                    
                    {workout.notes && (
                      <div className="workout-details-notes">
                        <span className="workout-details-notes-label">Заметки:</span>
                        <span className="workout-details-notes-text">{workout.notes}</span>
                      </div>
                    )}
                    
                    {/* Графики пульса и темпа (только для автоматических тренировок с timeline) */}
                    {workout.id && !workout.is_manual && timelineData[workout.id] && (
                      <div className="workout-details-charts">
                        <HeartRateChart timeline={timelineData[workout.id]} />
                        <PaceChart timeline={timelineData[workout.id]} />
                      </div>
                    )}
                    
                    {/* Показываем упражнения дня, если есть */}
                    {dayData.dayExercises && dayData.dayExercises.length > 0 && index === 0 && (
                      <div className="workout-details-exercises">
                        <span className="workout-details-exercises-label">Упражнения:</span>
                        <div className="workout-details-exercises-list">
                          {dayData.dayExercises.map((exercise, exIndex) => (
                            <div key={exIndex} className="workout-details-exercise-item">
                              <div className="workout-details-exercise-name">
                                {exercise.name}
                                {exercise.category && (
                                  <span className="workout-details-exercise-category"> ({exercise.category})</span>
                                )}
                              </div>
                              <div className="workout-details-exercise-details">
                                {exercise.sets && <span>Подходов: {exercise.sets}</span>}
                                {exercise.reps && <span>Повторений: {exercise.reps}</span>}
                                {exercise.distance_m && <span>Дистанция: {exercise.distance_m} м</span>}
                                {exercise.duration_sec && <span>Время: {Math.round(exercise.duration_sec / 60)} мин</span>}
                                {exercise.weight_kg && <span>Вес: {exercise.weight_kg} кг</span>}
                                {exercise.pace && <span>Темп: {exercise.pace}</span>}
                              </div>
                              {exercise.notes && (
                                <div className="workout-details-exercise-notes">{exercise.notes}</div>
                              )}
                            </div>
                          ))}
                        </div>
                      </div>
                    )}
                  </div>
                );
              })}
            </div>
          ) : (
            <div className="workout-details-empty">Нет данных о тренировке</div>
          )}
        </div>
      </div>
    </div>
  );
};

export default WorkoutDetailsModal;
