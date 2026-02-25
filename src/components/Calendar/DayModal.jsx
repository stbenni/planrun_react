/**
 * Модальное окно просмотра дня тренировки
 * Полностью адаптировано из оригинального календаря
 * При наличии dayExercises показываем единый вид карточек (план + упражнения), иначе — planHtml
 */

import React, { useState, useEffect, useRef } from 'react';
import '../../assets/css/calendar_v2.css';
import './DayModal.modern.css';
import './AddTrainingModal.css';
import AddTrainingModal from './AddTrainingModal';
import WorkoutDetailsModal from '../Stats/WorkoutDetailsModal';

const PLAN_DAY_TYPE_LABELS = {
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
const CATEGORY_LABELS = { run: 'Бег', running: 'Бег', ofp: 'ОФП' };
const getCategoryLabel = (cat) => (cat ? (CATEGORY_LABELS[String(cat).toLowerCase()] || cat) : '');
const getPlanDayTypeLabel = (type) => (type ? (PLAN_DAY_TYPE_LABELS[type] || type) : '');
const stripHtml = (s) => (s || '').replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim();

const DayModal = ({ isOpen, onClose, date, weekNumber, dayKey, api, canEdit = false, onOpenResultModal, onTrainingAdded, onEditTraining, refreshKey, openWorkoutDetailsInitially = false }) => {
  const [dayData, setDayData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [addTrainingModalOpen, setAddTrainingModalOpen] = useState(false);
  const [workoutDetailsOpen, setWorkoutDetailsOpen] = useState(false);
  const modalBodyRef = useRef(null);
  const didAutoOpenDetailsRef = useRef(false);

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

  const handleDeletePlanDay = async (dayId) => {
    if (!dayId || !api?.deleteTrainingDay) return;
    if (!window.confirm('Удалить эту тренировку из плана?')) return;
    try {
      await api.deleteTrainingDay(dayId);
      await loadDayData();
      onTrainingAdded?.();
    } catch (err) {
      console.error('Error deleting plan day:', err);
      alert('Ошибка удаления: ' + (err?.message || 'Не удалось удалить тренировку'));
    }
  };

  const handleTrainingAdded = () => {
    loadDayData();
    onTrainingAdded?.();
  };

  useEffect(() => {
    if (isOpen && date) {
      loadDayData();
    } else {
      setDayData(null);
      setLoading(true);
      setError(null);
      if (!isOpen) didAutoOpenDetailsRef.current = false;
    }
  }, [isOpen, date, weekNumber, dayKey, refreshKey]);

  // По «Детали» с календаря: после загрузки дня сразу открыть блок «Подробнее о тренировке»
  useEffect(() => {
    if (isOpen && openWorkoutDetailsInitially && !loading && dayData && !didAutoOpenDetailsRef.current) {
      didAutoOpenDetailsRef.current = true;
      setWorkoutDetailsOpen(true);
    }
  }, [isOpen, openWorkoutDetailsInitially, loading, dayData]);

  // Обработка кликов внутри модального окна (planHtml или карточки плана по dayExercises)
  useEffect(() => {
    if (!modalBodyRef.current || !dayData) return;
    const hasPlanContent = dayData.planHtml || (dayData.planDays && dayData.planDays.length > 0);
    if (!hasPlanContent) return;

    const handleClick = (e) => {
      if (e.target.classList.contains('btn-edit-plan-day') || e.target.closest('.btn-edit-plan-day')) {
        e.preventDefault();
        e.stopPropagation();
        const btn = e.target.classList.contains('btn-edit-plan-day') ? e.target : e.target.closest('.btn-edit-plan-day');
        const dayId = btn?.getAttribute('data-plan-day-id');
        if (dayId && date && onEditTraining && dayData?.planDays) {
          const planDay = dayData.planDays.find((d) => String(d.id) === String(dayId));
          if (planDay) {
            const exercises = dayData.dayExercises?.filter(ex => String(ex.plan_day_id) === String(planDay.id)) || [];
            onEditTraining({ id: planDay.id, type: planDay.type, description: planDay.description, is_key_workout: planDay.is_key_workout, exercises }, date);
          }
        }
        return;
      }
      if (e.target.classList.contains('btn-delete-plan-day') || e.target.closest('.btn-delete-plan-day')) {
        e.preventDefault();
        e.stopPropagation();
        const btn = e.target.classList.contains('btn-delete-plan-day') ? e.target : e.target.closest('.btn-delete-plan-day');
        const dayId = btn?.getAttribute('data-plan-day-id');
        if (dayId && api?.deleteTrainingDay) {
          handleDeletePlanDay(parseInt(dayId, 10));
        }
        return;
      }
      if (e.target.classList.contains('btn-delete-workout') || e.target.closest('.btn-delete-workout')) {
        e.preventDefault();
        e.stopPropagation();
        const btn = e.target.classList.contains('btn-delete-workout') ? e.target : e.target.closest('.btn-delete-workout');
        const workoutId = btn.getAttribute('onclick')?.match(/deleteWorkout\((\d+)/)?.[1] || btn.getAttribute('data-workout-id');
        const isManual = btn.getAttribute('onclick')?.includes('true') || btn.getAttribute('data-is-manual') === 'true';
        if (workoutId) {
          handleDeleteWorkout(parseInt(workoutId), isManual);
        }
        return;
      }
      if (e.target.onclick?.toString().includes('openResultModal') || e.target.closest('button')?.onclick?.toString().includes('openResultModal') || e.target.getAttribute('onclick')?.includes('openResultModal')) {
        e.preventDefault();
        e.stopPropagation();
        if (onOpenResultModal && date && weekNumber && dayKey) {
          onOpenResultModal(date, weekNumber, dayKey);
        }
        return;
      }
      if (e.target.onclick?.toString().includes('openAddTrainingModal') || e.target.closest('button')?.onclick?.toString().includes('openAddTrainingModal') || e.target.classList.contains('btn-add-training') || e.target.closest('.btn-add-training')) {
        e.preventDefault();
        e.stopPropagation();
        if (date) setAddTrainingModalOpen(true);
        return;
      }
      if (e.target.closest('tr[onclick]')) {
        const onclick = e.target.closest('tr[onclick]').getAttribute('onclick');
        if (onclick && onclick.includes('workout_details.php')) {
          const url = onclick.match(/['"]([^'"]+)['"]/)?.[1];
          if (url) window.open(url, '_blank');
        }
      }
    };

    modalBodyRef.current.addEventListener('click', handleClick);
    return () => {
      if (modalBodyRef.current) modalBodyRef.current.removeEventListener('click', handleClick);
    };
  }, [dayData, date, weekNumber, dayKey, onOpenResultModal, api, handleDeletePlanDay]);

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
    <>
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
          ) : dayData && (dayData.planHtml || dayData.dayExercises?.length > 0) ? (
            <div className="day-modal-content">
              {/* Единый вид карточками: когда есть структурированные упражнения — не дублируем planHtml */}
              {dayData.dayExercises && dayData.dayExercises.length > 0 ? (
                <>
                  {/* Только упражнения — без дублирования блока «Тренировки плана» */}
                  <div className="day-exercises-card day-exercises-card-modern">
                    <div className="day-exercises-title">💪 Упражнения</div>
                    <div className="day-exercises-list">
                      {dayData.dayExercises.map((exercise, index) => (
                        <div key={exercise.id || index} className="exercise-item">
                          <div className="exercise-header">
                            <span className="exercise-name">{exercise.name || 'Упражнение'}</span>
                            {(exercise.category && getCategoryLabel(exercise.category)) && (
                              <span className="exercise-category">{getCategoryLabel(exercise.category)}</span>
                            )}
                          </div>
                          <div className="exercise-details">
                            {exercise.sets != null && exercise.sets !== '' && (
                              <span className="exercise-detail">Подходов: {exercise.sets}</span>
                            )}
                            {exercise.reps != null && exercise.reps !== '' && (
                              <span className="exercise-detail">Повторений: {exercise.reps}</span>
                            )}
                            {exercise.distance_m != null && (
                              <span className="exercise-detail">Дистанция: {exercise.distance_m} м</span>
                            )}
                            {exercise.duration_sec != null && (
                              <span className="exercise-detail">Время: {Math.round(Number(exercise.duration_sec) / 60)} мин</span>
                            )}
                            {exercise.weight_kg != null && (
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
                </>
              ) : (
                <>
                  <div dangerouslySetInnerHTML={{ __html: dayData.planHtml }} />
                </>
              )}

              {/* Краткий вывод выполненной тренировки + ссылка на полный просмотр (как в Статистике) */}
              {dayData.workouts && dayData.workouts.length > 0 && (
                <div className="day-modal-workout-summary" style={{ marginTop: '20px' }}>
                  <div className="day-modal-workout-summary-title">
                    {dayData.workouts.length === 1 ? '🏃 Выполненная тренировка' : '🏃 Выполненные тренировки'}
                  </div>
                  {dayData.workouts.map((workout, idx) => {
                    const dist = workout.distance_km ?? workout.distance;
                    const durMin = workout.duration_minutes ?? (workout.duration != null ? Math.round(Number(workout.duration) / 60) : null);
                    const pace = workout.avg_pace ?? workout.pace;
                    const parts = [];
                    if (dist != null) parts.push(`${Number(dist).toFixed(1)} км`);
                    if (durMin != null) {
                      const h = Math.floor(durMin / 60);
                      const m = durMin % 60;
                      parts.push(h > 0 ? `${h}ч ${m}м` : `${m} мин`);
                    }
                    if (pace) parts.push(`${pace} /км`);
                    return (
                      <div key={idx} className="day-modal-workout-summary-card">
                        <div className="day-modal-workout-summary-metrics">
                          {parts.join(' · ')}
                        </div>
                        <button
                          type="button"
                          className="day-modal-workout-summary-link"
                          onClick={() => setWorkoutDetailsOpen(true)}
                        >
                          Подробнее о тренировке →
                        </button>
                      </div>
                    );
                  })}
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
                          {exercise.category && getCategoryLabel(exercise.category) && (
                            <span className="exercise-category">{getCategoryLabel(exercise.category)}</span>
                          )}
                        </div>
                        <div className="exercise-details">
                          {exercise.sets != null && exercise.sets !== '' && (
                            <span className="exercise-detail">Подходов: {exercise.sets}</span>
                          )}
                          {exercise.reps != null && exercise.reps !== '' && (
                            <span className="exercise-detail">Повторений: {exercise.reps}</span>
                          )}
                          {exercise.distance_m != null && (
                            <span className="exercise-detail">Дистанция: {exercise.distance_m} м</span>
                          )}
                          {exercise.duration_sec != null && (
                            <span className="exercise-detail">Время: {Math.round(Number(exercise.duration_sec) / 60)} мин</span>
                          )}
                          {exercise.weight_kg != null && (
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
                <div className="day-modal-workout-summary">
                  <div className="day-modal-workout-summary-title">
                    {dayData.workouts.length === 1 ? '🏃 Выполненная тренировка' : '🏃 Выполненные тренировки'}
                  </div>
                  {dayData.workouts.map((workout, idx) => {
                    const dist = workout.distance_km ?? workout.distance;
                    const durMin = workout.duration_minutes ?? (workout.duration != null ? Math.round(Number(workout.duration) / 60) : null);
                    const pace = workout.avg_pace ?? workout.pace;
                    const parts = [];
                    if (dist != null) parts.push(`${Number(dist).toFixed(1)} км`);
                    if (durMin != null) {
                      const h = Math.floor(durMin / 60);
                      const m = durMin % 60;
                      parts.push(h > 0 ? `${h}ч ${m}м` : `${m} мин`);
                    }
                    if (pace) parts.push(`${pace} /км`);
                    return (
                      <div key={idx} className="day-modal-workout-summary-card">
                        <div className="day-modal-workout-summary-metrics">
                          {parts.join(' · ')}
                        </div>
                        <button
                          type="button"
                          className="day-modal-workout-summary-link"
                          onClick={() => setWorkoutDetailsOpen(true)}
                        >
                          Подробнее о тренировке →
                        </button>
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
          {canEdit && !loading && !error && date && (
            <div className="day-modal-add-training day-modal-actions-row">
              <button
                type="button"
                className="btn btn-secondary"
                onClick={() => setAddTrainingModalOpen(true)}
              >
                Запланировать тренировку
              </button>
              {onOpenResultModal && (dayData?.planDays?.length > 0 || dayData?.dayExercises?.length > 0 || dayData?.planHtml) && (
                <button
                  type="button"
                  className="btn btn-primary"
                  onClick={() => {
                    const w = weekNumber ?? dayData?.week_number ?? 1;
                    const d = dayKey ?? dayData?.day_name ?? ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'][new Date(date + 'T12:00:00').getDay()];
                    onOpenResultModal?.(date, w, d);
                  }}
                >
                  Отметить выполненной
                </button>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
    <AddTrainingModal
      isOpen={addTrainingModalOpen}
      onClose={() => setAddTrainingModalOpen(false)}
      date={date}
      api={api}
      onSuccess={handleTrainingAdded}
    />
    <WorkoutDetailsModal
      isOpen={workoutDetailsOpen}
      onClose={() => setWorkoutDetailsOpen(false)}
      date={date}
      dayData={dayData}
      loading={false}
    />
    </>
  );
};

export default DayModal;

