/**
 * Модальное окно деталей тренировки.
 * Использует общий компонент Modal (единая оболочка с AddTrainingModal, ResultModal).
 */

import React, { useState, useEffect, useRef, useCallback } from 'react';
import { createPortal } from 'react-dom';
import html2canvas from 'html2canvas';
import { HeartRateChart, PaceChart } from './index';
import WorkoutShareCard from './WorkoutShareCard';
import Modal from '../common/Modal';
import useAuthStore from '../../stores/useAuthStore';
import {
  getActivityTypeLabel, getWorkoutDisplayType, getSourceLabel,
} from '../../utils/workoutFormUtils';
import './WorkoutDetailsModal.css';

const matchesSelectedWorkout = (workout, selectedWorkoutId) => {
  if (!selectedWorkoutId) return true;
  if (typeof selectedWorkoutId === 'string' && selectedWorkoutId.startsWith('log_')) {
    const logId = parseInt(selectedWorkoutId.replace('log_', ''), 10);
    return workout.is_manual && workout.id === logId;
  }
  return String(workout.id) === String(selectedWorkoutId);
};

const WorkoutDetailsModal = ({ isOpen, onClose, date, dayData, loading, weekNumber, dayKey, onEdit, onDelete, selectedWorkoutId }) => {
  const { api } = useAuthStore();
  const [deleting, setDeleting] = useState(false);

  const displayedWorkouts = React.useMemo(() => {
    const workouts = dayData?.workouts ?? [];
    if (!selectedWorkoutId) return workouts;
    const filtered = workouts.filter((w) => matchesSelectedWorkout(w, selectedWorkoutId));
    return filtered.length > 0 ? filtered : workouts;
  }, [dayData?.workouts, selectedWorkoutId]);

  const [timelineData, setTimelineData] = useState({});
  const [loadingTimeline, setLoadingTimeline] = useState({});
  const [shareGenerating, setShareGenerating] = useState(false);
  const [sharePopup, setSharePopup] = useState({ open: false, dataUrl: null, fileName: '' });

  const closeSharePopup = useCallback(() => {
    setSharePopup({ open: false, dataUrl: null, fileName: '' });
  }, []);
  const loadedWorkoutsRef = useRef(new Set());

  useEffect(() => {
    if (!isOpen || !dayData || !dayData.workouts || !api) {
      if (!isOpen) {
        loadedWorkoutsRef.current.clear();
        setTimelineData({});
        setLoadingTimeline({});
      }
      return;
    }

    const loadTimeline = async (workoutId) => {
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

    displayedWorkouts.forEach(workout => {
      if (workout.id && !workout.is_manual) {
        loadTimeline(workout.id);
      }
    });
  }, [isOpen, dayData, api, displayedWorkouts]);

  const [shareCardForCapture, setShareCardForCapture] = useState(null);
  const shareCardRef = useRef(null);

  const handleShare = useCallback(async () => {
    const workout = displayedWorkouts?.[0];
    if (!workout || !date) return;

    const chartWorkout = displayedWorkouts?.find(w => w.id && !w.is_manual && timelineData[w.id]);
    const timeline = chartWorkout ? timelineData[chartWorkout.id] : null;

    setShareGenerating(true);
    setShareCardForCapture({ date, workout, timeline });
  }, [date, displayedWorkouts, timelineData]);

  useEffect(() => {
    if (!shareCardForCapture || !shareCardRef.current) return;

    const { date: captureDate, workout } = shareCardForCapture;
    const cardEl = shareCardRef.current;

    const run = async () => {
      try {
        await new Promise(r => requestAnimationFrame(() => setTimeout(r, 600)));

        const canvas = await html2canvas(cardEl, {
          backgroundColor: '#ffffff',
          scale: 2,
          useCORS: true,
          logging: false,
          allowTaint: true,
          onclone: (_doc, clonedEl) => {
            clonedEl.style.opacity = '1';
          },
        });

        const dataUrl = canvas.toDataURL('image/png');
        const fileName = `planrun-${captureDate}-${getWorkoutDisplayType(workout) || 'workout'}.png`;
        if (!dataUrl || dataUrl.length < 100) throw new Error('Canvas empty');
        setSharePopup({ open: true, dataUrl, fileName });
      } catch (err) {
        if (process.env.NODE_ENV !== 'production') {
          console.error('Share error:', err);
        }
      } finally {
        setShareCardForCapture(null);
        setShareGenerating(false);
      }
    };

    run();
  }, [shareCardForCapture]);

  // --- Build title ---
  const titleNode = (
    <>
      <span className="workout-details-modal-title--short">
        {date ? new Date(date + 'T00:00:00').toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' }) : ''}
        {!loading && getWorkoutDisplayType(displayedWorkouts?.[0]) && (
          <> {getActivityTypeLabel(getWorkoutDisplayType(displayedWorkouts[0])).toUpperCase()}</>
        )}
      </span>
      <span className="workout-details-modal-title--full">
        {date ? new Date(date + 'T00:00:00').toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' }) : ''}
        {!loading && getWorkoutDisplayType(displayedWorkouts?.[0]) && (
          <> {getActivityTypeLabel(getWorkoutDisplayType(displayedWorkouts[0])).toUpperCase()}</>
        )}
      </span>
    </>
  );

  // --- Build header subtitle (source) ---
  const firstWorkout = displayedWorkouts?.[0];
  const sourceLabel = !loading && firstWorkout?.source && !firstWorkout.is_manual
    ? getSourceLabel(firstWorkout.source)
    : null;
  const headerSubtitle = sourceLabel ? <>Импортировано из: {sourceLabel}</> : null;

  // --- Delete handler ---
  const handleDeleteWorkout = useCallback(async () => {
    if (!onDelete || !displayedWorkouts?.length || deleting) return;
    const workout = displayedWorkouts[0];
    const workoutId = workout.is_manual ? workout.id : (workout.id ?? workout.workout_id);
    if (!workoutId) return;

    const msg = workout.is_manual
      ? 'Удалить эту запись о тренировке?'
      : 'Удалить эту тренировку?\n\nВнимание: будут удалены все данные тренировки, включая трек и точки маршрута.';
    if (!window.confirm(msg)) return;

    setDeleting(true);
    try {
      await api.deleteWorkout(workoutId, !!workout.is_manual);
      onDelete();
      onClose();
    } catch (err) {
      console.error('Delete workout error:', err);
      alert('Ошибка удаления: ' + (err?.message || 'Не удалось удалить тренировку'));
    } finally {
      setDeleting(false);
    }
  }, [onDelete, displayedWorkouts, deleting, api, onClose]);

  // --- Build header actions ---
  const headerActions = onEdit && displayedWorkouts?.every((w) => w.is_manual) ? (
    <button
      type="button"
      className="btn btn-secondary"
      onClick={onEdit}
    >
      Редактировать результат
    </button>
  ) : null;

  // --- Share capture (rendered outside modal via portal) ---
  const portalTarget = typeof document !== 'undefined' && (document.getElementById('modal-root') || document.body);

  const shareElements = portalTarget && (shareCardForCapture || (sharePopup.open && sharePopup.dataUrl)) ? createPortal(
    <>
      {shareCardForCapture && (
        <div
          ref={shareCardRef}
          className="workout-share-capture-wrap"
          style={{
            position: 'fixed',
            left: 0,
            top: 0,
            width: 400,
            opacity: 0.001,
            pointerEvents: 'none',
            zIndex: 0,
          }}
          data-theme="light"
        >
          <WorkoutShareCard
            date={shareCardForCapture.date}
            workout={shareCardForCapture.workout}
            timeline={shareCardForCapture.timeline}
          />
        </div>
      )}

      {sharePopup.open && sharePopup.dataUrl && (
        <div className="workout-share-popup-overlay" onClick={closeSharePopup}>
          <div className="workout-share-popup" onClick={(e) => e.stopPropagation()}>
            <button
              type="button"
              className="workout-share-popup-close"
              onClick={closeSharePopup}
              aria-label="Закрыть"
            >
              ×
            </button>
            <div className="workout-share-popup-image-wrap">
              <img src={sharePopup.dataUrl} alt="Тренировка" className="workout-share-popup-image" />
            </div>
            <div className="workout-share-popup-actions">
              <button
                type="button"
                className="btn btn-primary btn--block"
                onClick={() => {
                  const a = document.createElement('a');
                  a.href = sharePopup.dataUrl;
                  a.download = sharePopup.fileName;
                  a.click();
                }}
              >
                Сохранить
              </button>
              <button
                type="button"
                className="btn btn-secondary btn--block"
                onClick={async () => {
                  try {
                    const res = await fetch(sharePopup.dataUrl);
                    const blob = await res.blob();
                    const file = new File([blob], sharePopup.fileName, { type: 'image/png' });
                    if (navigator.share && navigator.canShare?.({ files: [file] })) {
                      await navigator.share({
                        title: sharePopup.fileName.replace('.png', ''),
                        files: [file],
                      });
                    } else {
                      const a = document.createElement('a');
                      a.href = sharePopup.dataUrl;
                      a.download = sharePopup.fileName;
                      a.click();
                    }
                  } catch (err) {
                    if (process.env.NODE_ENV !== 'production') console.error('Share error:', err);
                  }
                }}
              >
                Поделиться
              </button>
            </div>
          </div>
        </div>
      )}
    </>,
    portalTarget,
  ) : null;

  return (
    <>
      <Modal
        isOpen={isOpen}
        onClose={onClose}
        title={titleNode}
        size="medium"
        variant="modern"
        headerActions={headerActions}
        headerSubtitle={headerSubtitle}
      >
        {!loading && dayData?.workouts?.length > 0 && (
          <div className="workout-details-share-row">
            <button
              type="button"
              className="btn btn-secondary workout-details-share-btn workout-details-share-btn--body"
              onClick={handleShare}
              disabled={shareGenerating}
            >
              {shareGenerating ? 'Создание…' : 'Поделиться'}
            </button>
            {onDelete && (
              <button
                type="button"
                className="btn btn-secondary btn--danger-text"
                onClick={handleDeleteWorkout}
                disabled={deleting}
              >
                {deleting ? 'Удаление…' : 'Удалить'}
              </button>
            )}
          </div>
        )}
        {loading ? (
          <div className="workout-details-loading">Загрузка...</div>
        ) : dayData && displayedWorkouts && displayedWorkouts.length > 0 ? (
          <div className="workout-details-list">
            {displayedWorkouts.map((workout, index) => {
              const workoutDate = workout.start_time ? new Date(workout.start_time) : new Date(date + 'T12:00:00');
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

              const startTimeStr = workout.start_time
                ? workoutDate.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
                : '—';

              return (
                <div key={index} className="workout-details-item">
                  <div className="workout-details-item-header">
                    <div className="workout-details-item-time">{startTimeStr}</div>
                    {getWorkoutDisplayType(workout) && (
                      <div className="workout-details-item-type">
                        {getActivityTypeLabel(getWorkoutDisplayType(workout))}
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
                    {(workout.duration_seconds != null || workout.duration_minutes != null) && (
                      <div className="workout-details-metric">
                        <span className="workout-details-metric-label">Время:</span>
                        <span className="workout-details-metric-value">{durationStr}</span>
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

                  {workout.id && !workout.is_manual && timelineData[workout.id] && (
                    <div className="workout-details-charts">
                      <HeartRateChart timeline={timelineData[workout.id]} />
                      <PaceChart timeline={timelineData[workout.id]} />
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        ) : (
          <div className="workout-details-empty">Нет данных о тренировке</div>
        )}
      </Modal>
      {shareElements}
    </>
  );
};

export default WorkoutDetailsModal;
