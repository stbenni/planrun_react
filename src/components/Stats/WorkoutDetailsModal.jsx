/**
 * Модальное окно деталей тренировки — стиль Garmin с вкладками.
 * Вкладки: Обзор | Круги | Графики
 */

import React, {
  useState,
  useEffect,
  useRef,
  useCallback,
  Suspense,
  lazy,
  useMemo,
} from 'react';
import CombinedWorkoutChart from './CombinedWorkoutChart';
import MapLoadingSkeleton from './MapLoadingSkeleton';
import Modal from '../common/Modal';
import LogoLoading from '../common/LogoLoading';
import useAuthStore from '../../stores/useAuthStore';
import useWorkoutRefreshStore from '../../stores/useWorkoutRefreshStore';
import {
  getActivityTypeLabel, getWorkoutDisplayType, getSourceLabel,
} from '../../utils/workoutFormUtils';
import { groupExercisesByCategory } from '../../utils/structuredExercises';
import { formatLapDuration, formatLapDistance, getLapPaceSeconds, formatLapPace, formatWorkoutDuration } from '../../utils/lapFormat';
import '../Calendar/WorkoutCard.css';
import './WorkoutDetailsModal.css';
import ShareComposer from '../Share/ShareComposer';

const RouteMap = lazy(() => (import.meta.env.VITE_MAPBOX_TOKEN
  ? import('./MapboxRouteMap')
  : import('./LeafletRouteMap')));

/* ────── helpers ────── */

const matchesSelectedWorkout = (workout, selectedWorkoutId) => {
  if (!selectedWorkoutId) return true;
  if (typeof selectedWorkoutId === 'string' && selectedWorkoutId.startsWith('log_')) {
    const logId = parseInt(selectedWorkoutId.replace('log_', ''), 10);
    return workout.is_manual && workout.id === logId;
  }
  return String(workout.id) === String(selectedWorkoutId);
};

const GENERIC_LAP_NAME_RE = /^lap\s+\d+$/i;

const formatDuration = formatWorkoutDuration;

const getLapLabel = (lap) => {
  const rawName = typeof lap?.name === 'string' ? lap.name.trim() : '';
  const lapIndex = lap?.lap_index != null ? Number(lap.lap_index) : null;
  if (!rawName || GENERIC_LAP_NAME_RE.test(rawName)) return lapIndex ? `Круг ${lapIndex}` : 'Круг';
  return rawName;
};

const GENERIC_DISPLAY_LAP_NAME_RE = /^круг\s+\d+$/i;
const getLapTableLabel = (lap, fallbackIndex) => {
  const label = getLapLabel(lap);
  if (GENERIC_DISPLAY_LAP_NAME_RE.test(label)) {
    const lapIndex = lap?.lap_index != null ? Number(lap.lap_index) : fallbackIndex;
    return Number.isFinite(lapIndex) && lapIndex > 0 ? String(lapIndex) : label;
  }
  return label;
};

const detectIntervalPattern = (laps) => {
  if (!Array.isArray(laps) || laps.length < 4) {
    return { isLikelyInterval: false, rolesByLapIndex: {}, pairCount: 0 };
  }
  const candidates = laps.map((lap, pos) => {
    const lapIndex = lap?.lap_index != null ? Number(lap.lap_index) : pos + 1;
    const distanceKm = Number(lap?.distance_km);
    const movingSeconds = Number(lap?.moving_seconds ?? lap?.elapsed_seconds);
    const paceSeconds = getLapPaceSeconds(lap);
    if (!Number.isFinite(distanceKm) || distanceKm < 0.15 || distanceKm > 2.5) return null;
    if (!Number.isFinite(movingSeconds) || movingSeconds < 30 || movingSeconds > 1200) return null;
    if (!Number.isFinite(paceSeconds) || paceSeconds <= 0) return null;
    return { lapIndex, distanceKm, movingSeconds, paceSeconds };
  }).filter(Boolean);
  if (candidates.length < 4) return { isLikelyInterval: false, rolesByLapIndex: {}, pairCount: 0 };
  const rolesByLapIndex = {};
  let pairCount = 0;
  for (let i = 0; i < candidates.length - 1; i++) {
    const cur = candidates[i], nxt = candidates[i + 1];
    const relGap = nxt.paceSeconds / cur.paceSeconds;
    const absGap = nxt.paceSeconds - cur.paceSeconds;
    const recovOk = nxt.distanceKm >= 0.1 && nxt.distanceKm <= Math.max(cur.distanceKm * 1.8, 0.6);
    if (recovOk && relGap >= 1.12 && absGap >= 18) {
      rolesByLapIndex[cur.lapIndex] = 'work';
      rolesByLapIndex[nxt.lapIndex] = 'recovery';
      pairCount++;
      i++;
    }
  }
  if (pairCount < 2) return { isLikelyInterval: false, rolesByLapIndex: {}, pairCount: 0 };
  return { isLikelyInterval: true, rolesByLapIndex, pairCount };
};


/* ────── Tab definitions ────── */
const TABS = [
  { key: 'overview', label: 'Обзор' },
  { key: 'details', label: 'Данные' },
  { key: 'laps', label: 'Круги' },
  { key: 'charts', label: 'Графики' },
];

/* ────── Структурированный список ОФП/СБУ-упражнений ──────
   Использует те же классы, что и плановая карточка (WorkoutCard.css),
   чтобы выполненная и запланированная ОФП выглядели одинаково. */
const ExerciseList = ({ items }) => (
  <ul className="workout-card-exercise-list">
    {items.map((ex, idx) => (
      <li key={idx} className="workout-card-exercise-row">
        <span className="workout-card-exercise-name">{ex.name}</span>
        {(ex.sets || ex.reps || ex.weight || ex.duration || ex.distance) && (
          <span className="workout-card-exercise-meta">
            {ex.sets && ex.reps && (
              <span className="workout-card-chip">{ex.sets}×{ex.reps}</span>
            )}
            {ex.sets && !ex.reps && (ex.duration || ex.distance) && (
              <span className="workout-card-chip">{ex.sets}×{ex.duration || ex.distance}</span>
            )}
            {ex.weight && (
              <span className="workout-card-chip workout-card-chip--accent">{ex.weight}</span>
            )}
            {!ex.sets && ex.duration && (
              <span className="workout-card-chip">{ex.duration}</span>
            )}
          </span>
        )}
      </li>
    ))}
  </ul>
);

/* ────── Main component ────── */
const WorkoutDetailsModal = ({ isOpen, onClose, date, dayData, loading, onEdit, onDelete, selectedWorkoutId }) => {
  const { api } = useAuthStore();
  const [deleting, setDeleting] = useState(false);
  const [activeTab, setActiveTab] = useState('overview');
  const [timelineHoverIndex, setTimelineHoverIndex] = useState(null);

  const displayedWorkouts = useMemo(() => {
    const workouts = dayData?.workouts ?? [];
    if (!selectedWorkoutId) return workouts;
    const filtered = workouts.filter((w) => matchesSelectedWorkout(w, selectedWorkoutId));
    return filtered.length > 0 ? filtered : workouts;
  }, [dayData?.workouts, selectedWorkoutId]);

  const workout = displayedWorkouts?.[0];

  // Reset tab on open
  useEffect(() => {
    if (isOpen) setActiveTab('overview');
  }, [isOpen]);

  /* ── Timeline loading ── */
  const [timelineData, setTimelineData] = useState({});
  const [lapsData, setLapsData] = useState({});
  const [loadingTimeline, setLoadingTimeline] = useState({});
  const loadedWorkoutsRef = useRef(new Set());

  useEffect(() => {
    if (!isOpen || !dayData || !dayData.workouts || !api) {
      if (!isOpen) {
        loadedWorkoutsRef.current.clear();
        setTimelineData({});
        setLapsData({});
        setLoadingTimeline({});
      }
      return;
    }
    let cancelled = false;
    const loadTimeline = async (workoutId) => {
      if (loadedWorkoutsRef.current.has(workoutId)) return;
      setLoadingTimeline(prev => ({ ...prev, [workoutId]: true }));
      try {
        const response = await api.getWorkoutTimeline(workoutId);
        if (cancelled) return;
        let timeline = null, laps = null;
        if (Array.isArray(response)) {
          timeline = response;
        } else if (response && typeof response === 'object') {
          const payload = response.data && typeof response.data === 'object' ? response.data : response;
          if (Array.isArray(payload?.timeline)) timeline = payload.timeline;
          if (Array.isArray(payload?.laps)) laps = payload.laps;
        }
        if (timeline?.length > 0) setTimelineData(prev => ({ ...prev, [workoutId]: timeline }));
        if (laps?.length > 0) setLapsData(prev => ({ ...prev, [workoutId]: laps }));
        loadedWorkoutsRef.current.add(workoutId);
      } catch (error) {
        if (!cancelled && error?.name !== 'AbortError') console.error('Error loading workout timeline:', error);
      } finally {
        if (!cancelled) setLoadingTimeline(prev => ({ ...prev, [workoutId]: false }));
      }
    };
    displayedWorkouts.forEach(w => {
      if (w.id && !w.is_manual && !loadedWorkoutsRef.current.has(w.id)) loadTimeline(w.id);
    });
    return () => { cancelled = true; };
  }, [isOpen, dayData, api, displayedWorkouts]);

  /* ── Share logic ── */
  const [composerOpen, setComposerOpen] = useState(false);

  /* ── Delete handler ── */
  const handleDeleteWorkout = useCallback(async () => {
    if (!onDelete || !displayedWorkouts?.length || deleting) return;
    const w = displayedWorkouts[0];
    const wId = w.is_manual ? w.id : (w.id ?? w.workout_id);
    if (!wId) return;
    const msg = w.is_manual ? 'Удалить эту запись?' : 'Удалить тренировку и все данные?';
    if (!window.confirm(msg)) return;
    setDeleting(true);
    try {
      await api.deleteWorkout(wId, !!w.is_manual);
      useWorkoutRefreshStore.getState().triggerRefresh(); // мгновенно обновить дашборд/календарь/прогнозы
      onDelete();
      onClose();
    }
    catch (err) { alert('Ошибка: ' + (err?.message || 'Не удалось удалить')); }
    finally { setDeleting(false); }
  }, [onDelete, displayedWorkouts, deleting, api, onClose]);

  /* ── Derived data ── */
  const timeline = workout?.id ? timelineData[workout.id] : null;
  const workoutLaps = workout?.id ? lapsData[workout.id] : null;
  const hasGps = timeline?.some(p => p.latitude != null && p.longitude != null);
  const hasLaps = Array.isArray(workoutLaps) && workoutLaps.length > 0;
  const hasTimeline = timeline?.length > 0;
  const intervalPattern = hasLaps ? detectIntervalPattern(workoutLaps) : null;
  const sourceLabel = workout?.source && !workout.is_manual ? getSourceLabel(workout.source) : null;
  const workoutDate = workout?.start_time ? new Date(workout.start_time) : (date ? new Date(date + 'T12:00:00') : null);
  const activityLabel = getWorkoutDisplayType(workout) ? getActivityTypeLabel(getWorkoutDisplayType(workout)) : null;

  // ОФП/СБУ: парсим notes в структурированные упражнения (как в плановой карточке).
  const structuredExercises = useMemo(() => {
    if (!workout?.notes) return { ofp: [], sbu: [], other: '' };
    return groupExercisesByCategory(workout.notes);
  }, [workout?.notes]);
  const hasStructuredExercises = structuredExercises.ofp.length > 0 || structuredExercises.sbu.length > 0;

  // Available tabs
  const availableTabs = useMemo(() => {
    if (!workout) return [];
    return TABS.filter(t => {
      if (t.key === 'details') return !workout.is_manual;
      if (t.key === 'laps') return hasLaps;
      if (t.key === 'charts') return hasTimeline && !workout.is_manual;
      return true;
    });
  }, [workout, hasLaps, hasTimeline]);

  /* ── Title ── */
  const titleNode = (
    <>
      <span className="workout-details-modal-title--short">
        {date ? new Date(date + 'T00:00:00').toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' }) : ''}
        {activityLabel && <> {activityLabel.toUpperCase()}</>}
      </span>
      <span className="workout-details-modal-title--full">
        {date ? new Date(date + 'T00:00:00').toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' }) : ''}
        {activityLabel && <> {activityLabel.toUpperCase()}</>}
      </span>
    </>
  );


  /* ── Render ── */
  return (
    <>
      <Modal isOpen={isOpen} onClose={onClose} title={titleNode} size="medium" variant="modern" mobilePresentation="fullscreen">
        {loading ? (
          <div className="workout-details-loading"><LogoLoading size="sm" /></div>
        ) : workout ? (
          <div className="wd">
            {/* ── Карта наверху (если есть GPS) ── */}
            {hasGps && !workout.is_manual && (
              <div className="wd-map">
                <Suspense fallback={<MapLoadingSkeleton />}>
                  <RouteMap timeline={timeline} hoverIndex={timelineHoverIndex} />
                </Suspense>
              </div>
            )}

            {/* ── Tabs ── */}
            {availableTabs.length > 1 && (
              <div className="wd-tabs">
                {availableTabs.map(t => (
                  <button
                    key={t.key}
                    type="button"
                    className={`wd-tab${activeTab === t.key ? ' is-active' : ''}`}
                    onClick={() => setActiveTab(t.key)}
                  >
                    {t.label}
                  </button>
                ))}
              </div>
            )}

            {/* ── Tab: Обзор ── */}
            {activeTab === 'overview' && (
              <div className="wd-tab-content">
                {/* Ключевые метрики — карточки в стиле приложения */}
                <div className="wd-overview-cards">
                  {workout.distance_km && (
                    <div className="wd-card wd-card--hero">
                      <div className="wd-card-value">{Number(workout.distance_km).toFixed(2).replace('.', ',')}</div>
                      <div className="wd-card-sub">км · Дистанция</div>
                    </div>
                  )}
                  <div className="wd-card-row">
                    {formatDuration(workout) && (
                      <div className="wd-card">
                        <div className="wd-card-value">{formatDuration(workout)}</div>
                        <div className="wd-card-sub">Время</div>
                      </div>
                    )}
                    {workout.avg_pace && (
                      <div className="wd-card">
                        <div className="wd-card-value">{workout.avg_pace} <span className="wd-card-unit">/км</span></div>
                        <div className="wd-card-sub">Средний темп</div>
                      </div>
                    )}
                  </div>
                  {(workout.avg_heart_rate || workout.max_heart_rate) && (
                    <div className="wd-card-row">
                      {workout.avg_heart_rate && (
                        <div className="wd-card">
                          <div className="wd-card-value">{workout.avg_heart_rate} <span className="wd-card-unit">уд/м</span></div>
                          <div className="wd-card-sub">Средний пульс</div>
                        </div>
                      )}
                      {workout.max_heart_rate && (
                        <div className="wd-card">
                          <div className="wd-card-value">{workout.max_heart_rate} <span className="wd-card-unit">уд/м</span></div>
                          <div className="wd-card-sub">Макс. пульс</div>
                        </div>
                      )}
                    </div>
                  )}
                </div>

                {hasStructuredExercises && (
                  <div className="wd-exercises">
                    {structuredExercises.ofp.length > 0 && (
                      <div className="wd-exercises-section wd-exercises-section--ofp">
                        <div className="wd-exercises-title">ОФП</div>
                        <ExerciseList items={structuredExercises.ofp} />
                      </div>
                    )}
                    {structuredExercises.sbu.length > 0 && (
                      <div className="wd-exercises-section wd-exercises-section--sbu">
                        <div className="wd-exercises-title">СБУ</div>
                        <ExerciseList items={structuredExercises.sbu} />
                      </div>
                    )}
                  </div>
                )}

                {workout.notes && (!hasStructuredExercises || structuredExercises.other) && (
                  <div className="wd-notes">
                    <div className="wd-notes-text">{hasStructuredExercises ? structuredExercises.other : workout.notes}</div>
                  </div>
                )}

                <div className="wd-actions">
                  <button type="button" className="btn btn-secondary wd-action-btn" onClick={() => setComposerOpen(true)}>
                    Поделиться
                  </button>
                  {onEdit && workout.is_manual && (
                    <button type="button" className="btn btn-secondary wd-action-btn" onClick={onEdit}>Редактировать</button>
                  )}
                  {onDelete && (
                    <button type="button" className="btn btn-secondary btn--danger-text wd-action-btn" onClick={handleDeleteWorkout} disabled={deleting}>
                      {deleting ? 'Удаление…' : 'Удалить'}
                    </button>
                  )}
                </div>
              </div>
            )}

            {/* ── Tab: Данные ── */}
            {activeTab === 'details' && (
              <div className="wd-tab-content">
                <div className="wd-details-list">
                  {workout.distance_km && (
                    <div className="wd-detail-row">
                      <span className="wd-detail-label">Дистанция</span>
                      <span className="wd-detail-value">{workout.distance_km} км</span>
                    </div>
                  )}
                  {formatDuration(workout) && (
                    <div className="wd-detail-row">
                      <span className="wd-detail-label">Время</span>
                      <span className="wd-detail-value">{formatDuration(workout)}</span>
                    </div>
                  )}
                  {workout.avg_pace && (
                    <div className="wd-detail-row">
                      <span className="wd-detail-label">Средний темп</span>
                      <span className="wd-detail-value">{workout.avg_pace} /км</span>
                    </div>
                  )}
                  {workout.avg_heart_rate && (
                    <div className="wd-detail-row">
                      <span className="wd-detail-label">Средний пульс</span>
                      <span className="wd-detail-value">{workout.avg_heart_rate} уд/мин</span>
                    </div>
                  )}
                  {workout.max_heart_rate && (
                    <div className="wd-detail-row">
                      <span className="wd-detail-label">Макс. пульс</span>
                      <span className="wd-detail-value">{workout.max_heart_rate} уд/мин</span>
                    </div>
                  )}
                  {workout.elevation_gain && (
                    <div className="wd-detail-row">
                      <span className="wd-detail-label">Набор высоты</span>
                      <span className="wd-detail-value">{Math.round(workout.elevation_gain)} м</span>
                    </div>
                  )}
                  {workout.cadence && (
                    <div className="wd-detail-row">
                      <span className="wd-detail-label">Каденс</span>
                      <span className="wd-detail-value">{workout.cadence} шаг/мин</span>
                    </div>
                  )}
                  {workout.calories && (
                    <div className="wd-detail-row">
                      <span className="wd-detail-label">Калории</span>
                      <span className="wd-detail-value">{workout.calories} ккал</span>
                    </div>
                  )}
                  {sourceLabel && (
                    <div className="wd-detail-row">
                      <span className="wd-detail-label">Источник</span>
                      <span className="wd-detail-value">{sourceLabel}</span>
                    </div>
                  )}
                  {workout.start_time && (
                    <div className="wd-detail-row">
                      <span className="wd-detail-label">Начало</span>
                      <span className="wd-detail-value">{workoutDate.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', second: '2-digit' })}</span>
                    </div>
                  )}
                  {workout.id && (
                    <div className="wd-detail-row">
                      <span className="wd-detail-label">ID тренировки</span>
                      <span className="wd-detail-value">#{workout.id}</span>
                    </div>
                  )}
                </div>
              </div>
            )}

            {/* ── Tab: Круги ── */}
            {activeTab === 'laps' && hasLaps && (
              <div className="wd-tab-content">
                <div className="workout-details-laps">
                  <div className="workout-details-laps-grid">
                    <table className="workout-details-laps-table">
                      <colgroup>
                        <col className="workout-details-laps-col workout-details-laps-col--lap" />
                        <col className="workout-details-laps-col workout-details-laps-col--distance" />
                        <col className="workout-details-laps-col workout-details-laps-col--time" />
                        <col className="workout-details-laps-col workout-details-laps-col--pace" />
                        <col className="workout-details-laps-col workout-details-laps-col--pulse" />
                      </colgroup>
                      <thead>
                        <tr>
                          <th scope="col" className="workout-details-laps-head-cell workout-details-laps-head-cell--lap">Круг</th>
                          <th scope="col" className="workout-details-laps-head-cell workout-details-laps-head-cell--distance">Расст.</th>
                          <th scope="col" className="workout-details-laps-head-cell workout-details-laps-head-cell--time">Время</th>
                          <th scope="col" className="workout-details-laps-head-cell workout-details-laps-head-cell--pace">Темп</th>
                          <th scope="col" className="workout-details-laps-head-cell workout-details-laps-head-cell--pulse">Пульс</th>
                        </tr>
                      </thead>
                      <tbody>
                        {workoutLaps.map((lap, index) => {
                          const role = intervalPattern?.rolesByLapIndex?.[lap.lap_index] ?? null;
                          const lapHR = Number(lap.avg_heart_rate);
                          return (
                            <tr key={`${workout.id}-${lap.lap_index}`} className={`workout-details-lap-row${role ? ` is-${role}` : ''}`}>
                              <td className="workout-details-lap-cell workout-details-lap-cell--name" title={getLapLabel(lap)}>{getLapTableLabel(lap, index + 1)}</td>
                              <td className="workout-details-lap-cell workout-details-lap-cell--distance">{formatLapDistance(lap.distance_km) ?? '—'}</td>
                              <td className="workout-details-lap-cell workout-details-lap-cell--time">{formatLapDuration(lap.moving_seconds ?? lap.elapsed_seconds) ?? '—'}</td>
                              <td className="workout-details-lap-cell workout-details-lap-cell--pace">{formatLapPace(lap) ?? '—'}</td>
                              <td className="workout-details-lap-cell workout-details-lap-cell--pulse">{Number.isFinite(lapHR) && lapHR > 0 ? Math.round(lapHR) : '—'}</td>
                            </tr>
                          );
                        })}
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            )}

            {/* ── Tab: Графики ── */}
            {activeTab === 'charts' && hasTimeline && (
              <div className="wd-tab-content">
                <CombinedWorkoutChart timeline={timeline} onHoverIndex={setTimelineHoverIndex} />
              </div>
            )}
          </div>
        ) : (
          <div className="workout-details-empty">Нет данных о тренировке</div>
        )}
      </Modal>
      <ShareComposer
        open={composerOpen}
        onClose={() => setComposerOpen(false)}
        api={api}
        date={date}
        workout={workout}
        timeline={timeline}
      />
    </>
  );
};

export default WorkoutDetailsModal;
