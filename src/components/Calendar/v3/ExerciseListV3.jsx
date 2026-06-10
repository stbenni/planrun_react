/* Список упражнений ОФП/СБУ (подходы×повторы). Общий для DaySheetV3 и WorkoutSheet. */

export function setsLabel(ex) {
  if (ex.sets && ex.reps) return `${ex.sets}×${ex.reps}`;
  if (ex.sets && (ex.duration || ex.distance)) return `${ex.sets}×${ex.duration || ex.distance}`;
  if (ex.duration) return ex.duration;
  if (ex.distance) return ex.distance;
  if (ex.weight) return ex.weight;
  return '';
}

export default function ExerciseListV3({ items }) {
  if (!items?.length) return null;
  return (
    <div className="calv3-ex-list">
      {items.map((ex, i) => (
        <div key={i} className="calv3-ex-item">
          <span className="calv3-ex-num">{i + 1}</span>
          <div className="calv3-ex-main">
            <div className="calv3-ex-name">{ex.name}</div>
            {ex.weight && <div className="calv3-ex-hint">{ex.weight}</div>}
          </div>
          {setsLabel(ex) && <span className="calv3-ex-sets">{setsLabel(ex)}</span>}
        </div>
      ))}
    </div>
  );
}
