import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import DayCompletedV3 from '../Calendar/v3/DayCompletedV3';
import ExerciseListV3 from '../Calendar/v3/ExerciseListV3';
import { typeLabel, typeColorVar } from '../Calendar/v3/calV3';
import { formatWorkoutDuration } from '../../utils/lapFormat';
import { CloseIcon } from '../common/Icons';
import { useMediaQuery } from '../../hooks/useMediaQuery';
import '../Calendar/v3/CalendarV3.css';
import './WorkoutSheet.css';

const STRENGTH_TYPES = ['other', 'ofp', 'sbu', 'strength'];

/** ОФП/СБУ — упражнения дня (подходы×повторы) в том же v3-виде. */
function OfpContent({ workout, exercises, canEdit, onDelete }) {
  const dur = formatWorkoutDuration(workout) || (workout.duration ? `${workout.duration} мин` : null);
  const t = String(workout.activity_type || workout.type || 'other').toLowerCase();
  return (
    <div className="calv3-done is-expanded">
      <div className="calv3-done-head">
        <span className="calv3-done-head-top">
          <span className="calv3-done-accent" style={{ background: typeColorVar(t) }} />
          <span className="calv3-done-type">{typeLabel(t)}</span>
        </span>
      </div>
      <div className="calv3-done-body is-open">
        <div className="calv3-done-body-inner">
          {dur && (
            <div className="calv3-done-metrics">
              <div className="calv3-done-card"><div className="calv3-done-val">{dur}</div><div className="calv3-done-sub">время</div></div>
            </div>
          )}
          {exercises == null
            ? <div className="calv3-ex-empty">Загрузка…</div>
            : exercises.length
              ? <ExerciseListV3 items={exercises} />
              : <div className="calv3-ex-empty">Упражнения не записаны</div>}
        </div>
      </div>
      {canEdit && onDelete && (
        <div className="calv3-done-actions">
          <button type="button" className="calv3-done-delete-btn" onClick={() => onDelete(workout)}>Удалить тренировку</button>
        </div>
      )}
    </div>
  );
}

/**
 * WorkoutSheet — детали тренировки в выезжающем окне (снизу на мобиле, справа на десктопе).
 * Бег/кардио → DayCompletedV3 (карта/графики/круги). ОФП/СБУ → список упражнений дня.
 */
export default function WorkoutSheet({ open, workout, date, api, viewContext = null, canEdit = false, onClose, onEdit, onDelete }) {
  const isDesktop = useMediaQuery('(min-width: 1024px)');
  const type = workout ? String(workout.detected_type || workout.activity_type || workout.type || 'easy').toLowerCase() : 'easy';
  const isStrength = !!workout && STRENGTH_TYPES.includes(type) && !(Number(workout.distance_km ?? workout.distance) > 0);

  const [exercises, setExercises] = useState(null);
  useEffect(() => {
    if (!open || !isStrength || !api?.getDay || !date) { setExercises(null); return undefined; }
    let cancelled = false;
    setExercises(null);
    api.getDay(date, viewContext || undefined)
      .then((res) => {
        if (cancelled) return;
        const raw = res?.data ?? res;
        const ex = raw?.dayExercises ?? raw?.day_exercises ?? [];
        setExercises(Array.isArray(ex) ? ex : []);
      })
      .catch(() => { if (!cancelled) setExercises([]); });
    return () => { cancelled = true; };
  }, [open, isStrength, api, date, viewContext]);

  useEffect(() => {
    if (!open) return undefined;
    const onKey = (e) => { if (e.key === 'Escape') onClose?.(); };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open, onClose]);

  if (!open || !workout) return null;
  const target = typeof document !== 'undefined' && (document.getElementById('modal-root') || document.body);
  if (!target) return null;

  return createPortal(
    <>
      <div className="wsheet-scrim" onClick={onClose} />
      <div className="wsheet calv3" role="dialog" aria-modal="true" aria-label="Тренировка">
        <div className="wsheet-grip" />
        <button type="button" className="wsheet-close" onClick={onClose} aria-label="Закрыть">
          <CloseIcon size={18} />
        </button>
        <div className="calv3-results">
          {isStrength ? (
            <OfpContent workout={workout} exercises={exercises} canEdit={canEdit} onDelete={onDelete} />
          ) : (
            <DayCompletedV3
              workout={workout}
              date={date}
              api={api}
              defaultType={type}
              canEdit={canEdit}
              defaultExpanded
              hideToggle
              chartsInline={isDesktop}
              onEdit={onEdit}
              onDelete={onDelete}
            />
          )}
        </div>
      </div>
    </>,
    target,
  );
}
