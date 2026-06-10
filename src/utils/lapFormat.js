/* Форматтеры кругов/длительности тренировки — общие для DayCompletedV3 и WorkoutDetailsModal.
   Вынесены дословно из WorkoutDetailsModal (поведение сохранено). Возвращают null при невалидных данных. */

export function formatLapDuration(totalSeconds) {
  const seconds = Number(totalSeconds);
  if (!Number.isFinite(seconds) || seconds <= 0) return null;
  const safeSeconds = Math.round(seconds);
  const hours = Math.floor(safeSeconds / 3600);
  const minutes = Math.floor((safeSeconds % 3600) / 60);
  const secs = safeSeconds % 60;
  if (hours > 0) {
    return `${hours}:${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
  }
  return `${minutes}:${String(secs).padStart(2, '0')}`;
}

export function formatWorkoutDuration(workout) {
  if (workout?.duration_seconds != null && workout.duration_seconds > 0) {
    const h = Math.floor(workout.duration_seconds / 3600);
    const m = Math.floor((workout.duration_seconds % 3600) / 60);
    const s = workout.duration_seconds % 60;
    return (h > 0 ? `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}` : `${m}:${String(s).padStart(2, '0')}`);
  }
  if (workout?.duration_minutes != null && workout.duration_minutes > 0) {
    const h = Math.floor(workout.duration_minutes / 60);
    const m = workout.duration_minutes % 60;
    return h > 0 ? `${h}ч ${m}м` : `${m}м`;
  }
  return null;
}

export function formatLapDistance(distanceKm) {
  const value = Number(distanceKm);
  if (!Number.isFinite(value) || value <= 0) return null;
  if (value < 1) return `${Math.round(value * 1000)} м`;
  return `${value.toFixed(value >= 10 ? 1 : 2).replace(/\.0$/, '').replace(/(\.\d)0$/, '$1')} км`;
}

export function getLapPaceSeconds(lap) {
  const explicit = Number(lap?.pace_seconds_per_km);
  if (Number.isFinite(explicit) && explicit > 0) return explicit;
  const distanceKm = Number(lap?.distance_km);
  const movingSeconds = Number(lap?.moving_seconds ?? lap?.elapsed_seconds);
  if (Number.isFinite(distanceKm) && distanceKm > 0 && Number.isFinite(movingSeconds) && movingSeconds > 0) {
    return movingSeconds / distanceKm;
  }
  const averageSpeed = Number(lap?.average_speed);
  if (Number.isFinite(averageSpeed) && averageSpeed > 0) return 1000 / averageSpeed;
  return null;
}

export function formatLapPace(lap) {
  const paceSeconds = getLapPaceSeconds(lap);
  if (!Number.isFinite(paceSeconds) || paceSeconds <= 0) return null;
  const rounded = Math.round(paceSeconds);
  return `${Math.floor(rounded / 60)}:${String(rounded % 60).padStart(2, '0')}`;
}
