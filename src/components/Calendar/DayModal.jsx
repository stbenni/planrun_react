/**
 * Модальное окно просмотра дня тренировки
 * Полностью адаптировано из оригинального календаря
 * Использует planHtml из API напрямую, как в оригинале
 */

import React, { useState, useEffect, useRef } from 'react';
import '../../assets/css/calendar_v2.css';
import './DayModal.modern.css';
import RouteMap from './RouteMap';

const DayModal = ({ isOpen, onClose, date, weekNumber, dayKey, api, canEdit = false, onOpenResultModal }) => {
  const [dayData, setDayData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const modalBodyRef = useRef(null);

  useEffect(() => {
    if (isOpen && date) {
      loadDayData();
    } else {
      // Сбрасываем состояние при закрытии
      setDayData(null);
      setLoading(true);
      setError(null);
    }
  }, [isOpen, date, weekNumber, dayKey]);

  // Обработка кликов внутри модального окна после загрузки HTML
  useEffect(() => {
    if (!modalBodyRef.current || !dayData || !dayData.planHtml) return;

    const handleClick = (e) => {
      // Обработка кнопок удаления тренировки
      if (e.target.classList.contains('btn-delete-workout') || e.target.closest('.btn-delete-workout')) {
        e.preventDefault();
        e.stopPropagation();
        const btn = e.target.classList.contains('btn-delete-workout') ? e.target : e.target.closest('.btn-delete-workout');
        const workoutId = btn.getAttribute('onclick')?.match(/deleteWorkout\((\d+)/)?.[1] || 
                      btn.getAttribute('data-workout-id');
        const isManual = btn.getAttribute('onclick')?.includes('true') || 
                        btn.getAttribute('data-is-manual') === 'true';
        
        if (workoutId) {
          handleDeleteWorkout(parseInt(workoutId), isManual);
        }
        return;
      }

      // Обработка кнопок открытия модального окна результата
      if (e.target.onclick?.toString().includes('openResultModal') || 
          e.target.closest('button')?.onclick?.toString().includes('openResultModal') ||
          e.target.getAttribute('onclick')?.includes('openResultModal')) {
        e.preventDefault();
        e.stopPropagation();
        if (onOpenResultModal && date && weekNumber && dayKey) {
          onOpenResultModal(date, weekNumber, dayKey);
        }
        return;
      }

      // Обработка кнопок добавления тренировки
      if (e.target.onclick?.toString().includes('openAddTrainingModal') || 
          e.target.closest('button')?.onclick?.toString().includes('openAddTrainingModal')) {
        e.preventDefault();
        e.stopPropagation();
        // TODO: Реализовать модальное окно добавления тренировки
        alert('Функция добавления тренировки будет реализована');
        return;
      }

      // Обработка ссылок на детали тренировки
      if (e.target.closest('tr[onclick]')) {
        const onclick = e.target.closest('tr[onclick]').getAttribute('onclick');
        if (onclick && onclick.includes('workout_details.php')) {
          const url = onclick.match(/['"]([^'"]+)['"]/)?.[1];
          if (url) {
            window.open(url, '_blank');
          }
        }
      }
    };

    modalBodyRef.current.addEventListener('click', handleClick);
    return () => {
      if (modalBodyRef.current) {
        modalBodyRef.current.removeEventListener('click', handleClick);
      }
    };
  }, [dayData, date, weekNumber, dayKey, onOpenResultModal]);

  const loadDayData = async () => {
    try {
      setLoading(true);
      setError(null);
      
      // API get_day возвращает: planHtml, plan, workouts, dayExercises, planType, planDayId
      const response = await api.getDay(date);
      
      // Обрабатываем структуру ответа (может быть data.data || data)
      const data = response?.data || response;
      
      if (data?.error) {
        setError('Ошибка загрузки данных');
        setDayData(null);
        return;
      }
      
      console.log('Day data loaded:', {
        hasPlanHtml: !!data?.planHtml,
        hasDayExercises: !!data?.dayExercises && data.dayExercises.length > 0,
        exercisesCount: data?.dayExercises?.length || 0,
        workoutsCount: data?.workouts?.length || 0
      });
      
      setDayData(data);
    } catch (error) {
      console.error('Error loading day:', error);
      setError('Ошибка загрузки данных');
      setDayData(null);
    } finally {
      setLoading(false);
    }
  };

  const formatDate = (dateString) => {
    if (!dateString) return '';
    const dateObj = new Date(dateString + 'T00:00:00');
    const dayNames = ['Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота'];
    const dayName = dayNames[dateObj.getDay()];
    const formattedDate = dateObj.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
    return `${formattedDate} • ${dayName}`;
  };

  const handleDeleteWorkout = async (workoutId, isManual) => {
    if (!workoutId) {
      alert('Ошибка: не указан ID тренировки');
      return;
    }
    
    const confirmMessage = isManual 
      ? 'Удалить эту запись о тренировке?' 
      : 'Удалить эту тренировку?\n\nВнимание: будут удалены все данные тренировки, включая трек и точки маршрута.';
    
    if (!window.confirm(confirmMessage)) {
      return;
    }
    
    try {
      // Используем API клиент для удаления
      const response = await fetch(`${api.baseUrl}/api_wrapper.php?action=delete_workout&workout_id=${workoutId}`, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
        },
      });
      
      const result = await response.json();
      
      if (result.success) {
        // Перезагружаем данные дня
        await loadDayData();
      } else {
        alert('Ошибка удаления: ' + (result.error || 'Неизвестная ошибка'));
      }
    } catch (error) {
      console.error('Error deleting workout:', error);
      alert('Ошибка удаления тренировки');
    }
  };

  if (!isOpen) return null;

  // Извлекаем метрики из выполненных тренировок
  const getWorkoutMetrics = () => {
    if (!dayData || !dayData.workouts || dayData.workouts.length === 0) return null;
    
    let totalDistance = 0;
    let totalDuration = 0;
    let avgPace = null;
    
    dayData.workouts.forEach(workout => {
      if (workout.distance) totalDistance += parseFloat(workout.distance) || 0;
      if (workout.duration) totalDuration += parseInt(workout.duration) || 0;
    });
    
    if (totalDistance > 0 && totalDuration > 0) {
      const paceSeconds = Math.round((totalDuration * 60) / totalDistance);
      const minutes = Math.floor(paceSeconds / 60);
      const seconds = paceSeconds % 60;
      avgPace = `${minutes}:${seconds.toString().padStart(2, '0')}`;
    }
    
    return {
      distance: Math.round(totalDistance * 10) / 10,
      duration: totalDuration,
      pace: avgPace,
      count: dayData.workouts.length
    };
  };

  const metrics = getWorkoutMetrics();

  return (
    <div 
      id="dayModal" 
      className="modal modal-modern" 
      style={{ display: isOpen ? 'block' : 'none' }} 
      onClick={(e) => {
        if (e.target.id === 'dayModal') {
          onClose();
        }
      }}
    >
      <div className="modal-content modal-modern-content" onClick={(e) => e.stopPropagation()}>
        <div className="modal-header modal-modern-header">
          <div className="modal-header-content">
            <h2 id="dayModalTitle" className="modal-title-modern">📅 {formatDate(date)}</h2>
            {metrics && (
              <div className="modal-metrics-preview">
                {metrics.distance > 0 && (
                  <span className="metric-badge">🏃 {metrics.distance} км</span>
                )}
                {metrics.duration > 0 && (
                  <span className="metric-badge">⏱️ {Math.round(metrics.duration / 60)} мин</span>
                )}
              </div>
            )}
          </div>
          <button className="close close-modern" onClick={onClose} aria-label="Закрыть">
            &times;
          </button>
        </div>
        <div className="modal-body modal-modern-body" id="dayModalBody" ref={modalBodyRef}>
          {loading ? (
            <div className="loading loading-modern">
              <div className="spinner-modern"></div>
              <div>Загрузка...</div>
            </div>
          ) : error ? (
            <div className="no-workouts-msg no-workouts-modern">
              <div className="icon">❌</div>
              <div>{error}</div>
            </div>
          ) : dayData && dayData.planHtml ? (
            // Используем готовый HTML из API, как в оригинале
            <div className="day-modal-content">
              <div dangerouslySetInnerHTML={{ __html: dayData.planHtml }} />
              
              {/* Показываем упражнения отдельно, если они есть, даже если есть planHtml */}
              {dayData.dayExercises && dayData.dayExercises.length > 0 && (
                <div className="day-exercises-card day-exercises-card-modern" style={{ marginTop: '20px' }}>
                  <div className="day-exercises-title">💪 Упражнения</div>
                  <div className="day-exercises-list">
                    {dayData.dayExercises.map((exercise, index) => (
                      <div key={exercise.id || index} className="exercise-item">
                        <div className="exercise-header">
                          <span className="exercise-name">{exercise.name || 'Упражнение'}</span>
                          {exercise.category && (
                            <span className="exercise-category">{exercise.category}</span>
                          )}
                        </div>
                        <div className="exercise-details">
                          {exercise.sets && (
                            <span className="exercise-detail">Подходов: {exercise.sets}</span>
                          )}
                          {exercise.reps && (
                            <span className="exercise-detail">Повторений: {exercise.reps}</span>
                          )}
                          {exercise.distance_m && (
                            <span className="exercise-detail">Дистанция: {exercise.distance_m} м</span>
                          )}
                          {exercise.duration_sec && (
                            <span className="exercise-detail">Время: {Math.round(exercise.duration_sec / 60)} мин</span>
                          )}
                          {exercise.weight_kg && (
                            <span className="exercise-detail">Вес: {exercise.weight_kg} кг</span>
                          )}
                          {exercise.pace && (
                            <span className="exercise-detail">Темп: {exercise.pace}</span>
                          )}
                        </div>
                        {exercise.notes && (
                          <div className="exercise-notes">{exercise.notes}</div>
                        )}
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>
          ) : dayData ? (
            // Fallback если нет planHtml, но есть структурированные данные
            <div className="day-modal-structured">
              {dayData.plan && (
                <div className="day-plan-card day-plan-card-modern">
                  <div className="day-plan-title">📋 План на этот день</div>
                  <div className="day-plan-text" dangerouslySetInnerHTML={{ __html: dayData.plan.replace(/\n/g, '<br>') }} />
                </div>
              )}
              
              {/* Отображаем упражнения дня, если они есть */}
              {dayData.dayExercises && dayData.dayExercises.length > 0 && (
                <div className="day-exercises-card day-exercises-card-modern">
                  <div className="day-exercises-title">💪 Упражнения</div>
                  <div className="day-exercises-list">
                    {dayData.dayExercises.map((exercise, index) => (
                      <div key={exercise.id || index} className="exercise-item">
                        <div className="exercise-header">
                          <span className="exercise-name">{exercise.name || 'Упражнение'}</span>
                          {exercise.category && (
                            <span className="exercise-category">{exercise.category}</span>
                          )}
                        </div>
                        <div className="exercise-details">
                          {exercise.sets && (
                            <span className="exercise-detail">Подходов: {exercise.sets}</span>
                          )}
                          {exercise.reps && (
                            <span className="exercise-detail">Повторений: {exercise.reps}</span>
                          )}
                          {exercise.distance_m && (
                            <span className="exercise-detail">Дистанция: {exercise.distance_m} м</span>
                          )}
                          {exercise.duration_sec && (
                            <span className="exercise-detail">Время: {Math.round(exercise.duration_sec / 60)} мин</span>
                          )}
                          {exercise.weight_kg && (
                            <span className="exercise-detail">Вес: {exercise.weight_kg} кг</span>
                          )}
                          {exercise.pace && (
                            <span className="exercise-detail">Темп: {exercise.pace}</span>
                          )}
                        </div>
                        {exercise.notes && (
                          <div className="exercise-notes">{exercise.notes}</div>
                        )}
                      </div>
                    ))}
                  </div>
                </div>
              )}
              
              {metrics && (
                <div className="workout-metrics-card">
                  <div className="metrics-title">🏃 Выполненные тренировки</div>
                  <div className="metrics-grid">
                    {metrics.distance > 0 && (
                      <div className="metric-item">
                        <div className="metric-item-value">{metrics.distance}</div>
                        <div className="metric-item-label">км</div>
                      </div>
                    )}
                    {metrics.duration > 0 && (
                      <div className="metric-item">
                        <div className="metric-item-value">{Math.round(metrics.duration / 60)}</div>
                        <div className="metric-item-label">минут</div>
                      </div>
                    )}
                    {metrics.pace && (
                      <div className="metric-item">
                        <div className="metric-item-value">{metrics.pace}</div>
                        <div className="metric-item-label">/км</div>
                      </div>
                    )}
                    <div className="metric-item">
                      <div className="metric-item-value">{metrics.count}</div>
                      <div className="metric-item-label">тренировок</div>
                    </div>
                  </div>
                </div>
              )}
              {dayData.workouts && dayData.workouts.length > 0 && (
                <div className="workouts-list">
                  <div className="section-subtitle">📊 Детали тренировок</div>
                  {dayData.workouts.map((workout, index) => {
                    // Проверяем, есть ли GPS данные для отображения карты
                    const hasGPSData = workout.gpx_data || workout.coordinates || workout.track_points;
                    
                    return (
                      <div key={index}>
                        <div className="workout-item-card">
                          {(workout.distance_km || workout.distance) && (
                            <div className="workout-item-metric">
                              <span className="workout-item-label">Дистанция:</span>
                              <span className="workout-item-value">{workout.distance_km || workout.distance} км</span>
                            </div>
                          )}
                          {(workout.duration_minutes || workout.duration) && (
                            <div className="workout-item-metric">
                              <span className="workout-item-label">Время:</span>
                              <span className="workout-item-value">{Math.round((workout.duration_minutes || workout.duration) / 60)} мин</span>
                            </div>
                          )}
                          {workout.avg_pace && (
                            <div className="workout-item-metric">
                              <span className="workout-item-label">Темп:</span>
                              <span className="workout-item-value">{workout.avg_pace}</span>
                            </div>
                          )}
                          {workout.elevation_gain && (
                            <div className="workout-item-metric">
                              <span className="workout-item-label">Набор высоты:</span>
                              <span className="workout-item-value">{Math.round(workout.elevation_gain)} м</span>
                            </div>
                          )}
                        </div>
                        
                        {/* Показываем карту маршрута, если есть GPS данные */}
                        {hasGPSData && (
                          <RouteMap 
                            workout={workout}
                            gpxData={workout.gpx_data}
                            coordinates={workout.coordinates || workout.track_points}
                          />
                        )}
                      </div>
                    );
                  })}
                </div>
              )}
            </div>
          ) : (
            <div className="no-workouts-msg no-workouts-modern">
              <div className="icon">📅</div>
              <div>Нет данных для этого дня</div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
};

export default DayModal;

