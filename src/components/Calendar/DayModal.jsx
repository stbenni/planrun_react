/**
 * Модальное окно просмотра дня тренировки
 * Полностью адаптировано из оригинального календаря
 * При наличии dayExercises показываем единый вид карточек (план + упражнения), иначе — planHtml
 */

import React, { useState, useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import { CalendarIcon, DistanceIcon, TimeIcon, PaceIcon, ActivityTypeIcon, XCircleIcon, TrashIcon } from '../common/Icons';
import '../../assets/css/calendar_v2.css';
import './DayModal.modern.css';
import './AddTrainingModal.css';
import './WorkoutCard.css';
import '../../screens/StatsScreen.css';
import AddTrainingModal from './AddTrainingModal';
import WorkoutCard from './WorkoutCard';
import WorkoutDetailsModal from '../Stats/WorkoutDetailsModal';

const stripHtml = (s) => (s || '').replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim();

const TYPE_NAMES = {
  easy: 'Легкий бег', long: 'Длительный бег', 'long-run': 'Длительный бег',
  tempo: 'Темповый бег', interval: 'Интервалы', fartlek: 'Фартлек',
  control: 'Контрольный забег', race: 'Соревнование', other: 'ОФП', sbu: 'СБУ',
  rest: 'День отдыха', free: 'Пустой день', walking: 'Ходьба', hiking: 'Поход',
  cycling: 'Велосипед', swimming: 'Плавание', run: 'Бег', running: 'Бег',
};

const formatDurationDisplay = (minutesOrSeconds, isSeconds = false) => {
  if (minutesOrSeconds == null) return null;
  const totalSec = isSeconds ? minutesOrSeconds : minutesOrSeconds * 60;
  const h = Math.floor(totalSec / 3600);
  const m = Math.floor((totalSec % 3600) / 60);
  const s = Math.round(totalSec % 60);
  if (h > 0) return `${h}ч ${m}м`;
  if (m > 0) return s > 0 ? `${m}м ${s}с` : `${m}м`;
  return s > 0 ? `${s}с` : null;
};

const DayModal = ({ isOpen, onClose, date, weekNumber, dayKey, api, canEdit = false, viewContext = null, onOpenResultModal, onTrainingAdded, onEditTraining, refreshKey, openWorkoutDetailsInitially = false }) => {
  const [dayData, setDayData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [addTrainingModalOpen, setAddTrainingModalOpen] = useState(false);
  const [workoutDetailsOpen, setWorkoutDetailsOpen] = useState(false);
  const [selectedWorkoutId, setSelectedWorkoutId] = useState(null);
  const modalBodyRef = useRef(null);
  const didAutoOpenDetailsRef = useRef(false);

  const loadDayData = async () => {
    try {
      setLoading(true);
      setError(null);
      
      // API get_day возвращает: planHtml, plan, workouts, dayExercises, planType, planDayId
      const response = await api.getDay(date, viewContext || undefined);
      
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


  const formatDate = (dateString) => {
    if (!dateString) return '';
    const dateObj = new Date(dateString + 'T00:00:00');
    const dayNames = ['Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота'];
    const dayName = dayNames[dateObj.getDay()];
    const formattedDate = dateObj.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
    return `${formattedDate} • ${dayName}`;
  };

  const handleWorkoutDeleted = async () => {
    await loadDayData();
    onTrainingAdded?.();
  };

  const handleDeleteWorkoutInline = async (e, workoutId, isManual) => {
    e.stopPropagation();
    if (!workoutId || !api?.deleteWorkout) return;
    const msg = isManual
      ? 'Удалить эту запись о тренировке?'
      : 'Удалить эту тренировку?\n\nВнимание: будут удалены все данные, включая трек.';
    if (!window.confirm(msg)) return;
    try {
      await api.deleteWorkout(workoutId, !!isManual);
      await handleWorkoutDeleted();
    } catch (err) {
      console.error('Delete workout error:', err);
      alert('Ошибка удаления: ' + (err?.message || 'Неизвестная ошибка'));
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

  // Блок «Выполненные тренировки» — только если есть хотя бы одна с реальными данными (дистанция, время и т.д.)
  const hasCompletedWorkouts = (() => {
    const w = dayData?.workouts;
    if (!w || !Array.isArray(w) || w.length === 0) return false;
    const hasMeaningfulData = (workout) => {
      const dist = workout.distance_km ?? workout.distance;
      const dur = workout.duration_minutes ?? workout.duration ?? workout.duration_seconds;
      return (dist != null && Number(dist) > 0) || (dur != null && Number(dur) > 0);
    };
    return w.some(hasMeaningfulData);
  })();

  const modalTarget = typeof document !== 'undefined' && (document.getElementById('modal-root') || document.body);
  const modalContent = (
    <div 
      id="dayModal" 
      className="modal modal-modern" 
      style={{ display: isOpen ? 'flex' : 'none' }} 
      onClick={(e) => {
        if (e.target.id === 'dayModal') {
          onClose();
        }
      }}
    >
      <div className="modal-content modal-modern-content" onClick={(e) => e.stopPropagation()}>
        <div className="modal-header modal-modern-header">
          <div className="modal-header-content">
            <h2 id="dayModalTitle" className="modal-title-modern"><CalendarIcon size={22} className="title-icon" aria-hidden /> {formatDate(date)}</h2>
            {hasCompletedWorkouts && metrics && (
              <div className="modal-metrics-preview">
                {metrics.distance > 0 && (
                  <span className="metric-badge"><DistanceIcon size={16} className="inline-icon" aria-hidden /> {metrics.distance} км</span>
                )}
                {metrics.duration > 0 && (
                  <span className="metric-badge"><TimeIcon size={16} className="inline-icon" aria-hidden /> {Math.round(metrics.duration / 60)} мин</span>
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
              <div className="icon" aria-hidden><XCircleIcon size={32} /></div>
              <div>{error}</div>
            </div>
          ) : dayData ? (
            <div className="day-modal-two-blocks">
              {/* Блок 1: Запланированная тренировка — только информация */}
              <div className="day-modal-workout-card-wrapper">
                <WorkoutCard
                  workout={{
                    ...(dayData.planDays?.[0] || {}),
                    text: stripHtml(dayData.planHtml || dayData.plan || ''),
                    dayExercises: dayData.dayExercises || [],
                  }}
                  date={date}
                  status={dayData.planDays?.length > 0 || dayData.dayExercises?.length > 0 ? 'planned' : 'rest'}
                  isToday={date === new Date().toISOString().slice(0, 10)}
                  dayDetail={{ plan: dayData.plan, planDays: dayData.planDays, dayExercises: dayData.dayExercises, workouts: dayData.workouts }}
                  workoutMetrics={null}
                  results={[]}
                  planDays={dayData.planDays || []}
                  canEdit={false}
                  extraActions={null}
                />
              </div>

              {/* Блок 2: Выполненные тренировки — стиль как в статистике (workout-item) */}
              {hasCompletedWorkouts && dayData.workouts?.length > 0 && (
                <div className="day-modal-completed-workouts stats-style">
                  <h2 className="section-title">Выполненные тренировки</h2>
                  <div className="recent-workouts-list">
                    {dayData.workouts.map((workout) => {
                      const dist = workout.distance_km ?? workout.distance;
                      const durSec = workout.duration_seconds;
                      const durMin = workout.duration_minutes ?? (workout.duration != null ? Number(workout.duration) / 60 : null);
                      const durationDisplay = durSec != null ? formatDurationDisplay(durSec, true) : (durMin != null ? formatDurationDisplay(durMin, false) : null);
                      const pace = workout.avg_pace ?? workout.pace;
                      const typeKey = (workout.activity_type ?? workout.type ?? 'run').toLowerCase().trim();
                      const typeName = TYPE_NAMES[typeKey] || typeKey;
                      const workoutId = workout.is_manual ? `log_${workout.id}` : (workout.id ?? workout.workout_id);
                      const dateObj = date ? new Date(date + 'T00:00:00') : null;
                      return (
                        <div
                          key={workout.id || workout.workout_id || Math.random()}
                          className="workout-item"
                          onClick={() => {
                            setSelectedWorkoutId(workoutId);
                            setWorkoutDetailsOpen(true);
                          }}
                          style={{ cursor: 'pointer' }}
                          role="button"
                          tabIndex={0}
                          onKeyDown={(e) => {
                            if (e.key === 'Enter' || e.key === ' ') {
                              e.preventDefault();
                              setSelectedWorkoutId(workoutId);
                              setWorkoutDetailsOpen(true);
                            }
                          }}
                        >
                          <div className="workout-item-type" data-type={typeKey}>
                            <ActivityTypeIcon type={typeKey} className="workout-item-type__icon" aria-hidden />
                            <span className="workout-item-type__label">{typeName}</span>
                          </div>
                          <div className="workout-item-main">
                            <div className="workout-item-date">
                              {dateObj ? dateObj.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short', year: 'numeric' }) : ''}
                            </div>
                            <div className="workout-item-metrics">
                              {dist != null && Number(dist) > 0 && (
                                <span className="workout-metric">
                                  <DistanceIcon className="workout-metric__icon" aria-hidden />
                                  {Number(dist).toFixed(1)} км
                                </span>
                              )}
                              {durationDisplay && (
                                <span className="workout-metric">
                                  <TimeIcon className="workout-metric__icon" aria-hidden />
                                  {durationDisplay}
                                </span>
                              )}
                              {pace && (
                                <span className="workout-metric">
                                  <PaceIcon className="workout-metric__icon" aria-hidden />
                                  {pace} /км
                                </span>
                              )}
                            </div>
                          </div>
                          {canEdit && (
                            <button
                              type="button"
                              className="workout-item-delete"
                              onClick={(e) => handleDeleteWorkoutInline(e, workout.is_manual ? workout.id : (workout.id ?? workout.workout_id), workout.is_manual)}
                              aria-label="Удалить тренировку"
                              title="Удалить"
                            >
                              <TrashIcon size={16} />
                            </button>
                          )}
                        </div>
                      );
                    })}
                  </div>
                </div>
              )}
            </div>
          ) : (
            <div className="no-workouts-msg no-workouts-modern">
              <div className="icon" aria-hidden><CalendarIcon size={32} /></div>
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
  );

  return (
    <>
    {modalTarget && createPortal(modalContent, modalTarget)}
    <AddTrainingModal
      isOpen={addTrainingModalOpen}
      onClose={() => setAddTrainingModalOpen(false)}
      date={date}
      api={api}
      onSuccess={handleTrainingAdded}
    />
    <WorkoutDetailsModal
      isOpen={workoutDetailsOpen}
      onClose={() => {
        setWorkoutDetailsOpen(false);
        setSelectedWorkoutId(null);
      }}
      date={date}
      dayData={dayData}
      loading={false}
      selectedWorkoutId={selectedWorkoutId}
      onDelete={canEdit ? handleWorkoutDeleted : undefined}
    />
    </>
  );
};

export default DayModal;

