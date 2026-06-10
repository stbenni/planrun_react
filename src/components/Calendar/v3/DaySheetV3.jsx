/* DaySheetV3 — поверхность дня (порт DaySheet из v3-calendar), полная версия.
   План (мультиактивность) + структурированные упражнения СБУ/ОФП + ИНЛАЙН детали
   выполненной тренировки (карта/график/круги) + действия. Грузит день через api.getDay. */
import { useEffect, useMemo, useRef, useState } from 'react';
import DayCompletedV3 from './DayCompletedV3';
import ExerciseListV3 from './ExerciseListV3';
import useSheetFocus from './useSheetFocus';
import { typeColorVar, typeLabel, stripHtml, buildRunSegments, RUN_SEGMENT_TYPES, paceToMin, estimateTimeMin } from './calV3';
import { planTypeToCategory, workoutTypeToCategory } from '../../../utils/calendarHelpers';
import { dayCacheKey, syncDayCacheVersion, getCachedDay, setCachedDay } from './dayCache';
import { formatWorkoutDuration } from '../../../utils/lapFormat';
import useWorkoutRefreshStore from '../../../stores/useWorkoutRefreshStore';

function hasMeaningfulWorkout(w) {
  const dist = w.distance_km ?? w.distance;
  const dur = w.duration_minutes ?? w.duration ?? w.duration_seconds;
  return (dist != null && Number(dist) > 0) || (dur != null && Number(dur) > 0);
}

/* Разминка/заминка простого бега — из текста описания (для структурного списка в попапе). */
function parseRunStructure(day) {
  const txt = (day.activities || [])
    .filter((a) => a.type !== 'rest' && a.type !== 'free')
    .map((a) => stripHtml(a.text || '')).join('\n');
  if (!txt) return null;
  const wk = txt.match(/Разминка[:\s]*([\d.,]+)\s*км/i);
  const wp = txt.match(/Разминка[^.]*в темпе\s+(\d{1,2}:\d{2})/i);
  const ck = txt.match(/Заминка[:\s]*([\d.,]+)\s*км/i);
  const cp = txt.match(/Заминка[^.]*в темпе\s+(\d{1,2}:\d{2})/i);
  const warmup = wk ? { km: wk[1].replace(',', '.'), pace: wp ? wp[1] : null } : null;
  const cooldown = ck ? { km: ck[1].replace(',', '.'), pace: cp ? cp[1] : null } : null;
  return (warmup || cooldown) ? { warmup, cooldown } : null;
}

function buildSegments(day) {
  const item = (day.activities || []).find((a) => RUN_SEGMENT_TYPES.includes(a.type));
  if (!item) return null;
  return buildRunSegments({ type: item.type, text: item.text, km: day.km, pace: day.pace });
}

export default function DaySheetV3({
  day,
  embedded = false,
  canEdit = false,
  api = null,
  viewContext = null,
  onClose,
  onEdit,
  onMarkDone,
  onEditResult,
  onDeleteResult,
  onAddTraining,
}) {
  const [dayData, setDayData] = useState(null);
  const [loading, setLoading] = useState(false);
  const sheetRef = useRef(null);
  useSheetFocus(sheetRef, !embedded && !!day);
  // глобальная версия данных: меняется после добавления/правки/удаления/синка → инвалидирует кэш дня
  const refreshVersion = useWorkoutRefreshStore((s) => s.version);

  useEffect(() => {
    if (!api?.getDay || !day?.date) { setDayData(null); return undefined; }
    syncDayCacheVersion(refreshVersion); // сбросит кэш, если данные глобально обновились
    const key = dayCacheKey(day.date, viewContext);
    const cached = getCachedDay(key);
    // Кэш version-aware (свежий относительно version) → на попадании показываем сразу, без запроса.
    if (cached) { setDayData(cached); setLoading(false); return undefined; }
    setLoading(true);
    let cancelled = false;
    (async () => {
      try {
        const res = await api.getDay(day.date, viewContext || undefined);
        const raw = res?.data != null ? res.data : res;
        if (!cancelled && raw && !raw.error) {
          const next = {
            planDays: raw.planDays ?? raw.plan_days ?? [],
            dayExercises: raw.dayExercises ?? raw.day_exercises ?? [],
            workouts: Array.isArray(raw.workouts) ? raw.workouts : [],
          };
          setCachedDay(key, next);
          setDayData(next);
        }
      } catch { /* silent */ }
      finally { if (!cancelled) setLoading(false); }
    })();
    return () => { cancelled = true; };
  }, [api, day?.date, viewContext, refreshVersion]);

  // Упражнения ОФП/СБУ — из структурированных dayExercises. getDay их всегда заполняет:
  // либо из training_day_exercises, либо парсит description на сервере (см. WorkoutService::getDay).
  // (useMemo до early-return — порядок хуков)
  const exItems = useMemo(() => (dayData?.dayExercises || []).map((e) => ({
    name: e.name || 'Упражнение',
    sets: e.sets,
    reps: e.reps,
    distance: e.distance_m ? `${e.distance_m} м` : '',
    duration: e.duration_sec ? (e.duration_sec >= 60 ? `${Math.round(e.duration_sec / 60)} мин` : `${e.duration_sec} с`) : '',
    weight: e.weight_kg ? `${e.weight_kg} кг` : '',
  })), [dayData]);

  // Полный план-день для редактора: из getDay (id/type/description/is_key_workout),
  // фолбэк — сырой item из модели дня. Без него «Изменить» открывал бы пустую форму.
  const editablePlanDay = useMemo(() => {
    const pds = dayData?.planDays || [];
    if (pds.length) {
      return pds.find((p) => p.type === day?.type)
        || pds.find((p) => p.type !== 'rest' && p.type !== 'free')
        || pds[0];
    }
    return (day?.items || []).find((it) => it && it.type !== 'rest' && it.type !== 'free') || (day?.items || [])[0] || null;
  }, [day, dayData]);

  if (!day) return null;
  const accent = typeColorVar(day.type);
  const isRest = day.type === 'rest' || day.type === 'free';
  const isDone = day.status === 'done';
  const km = day.km;
  const pace = day.pace;
  const paceSuggested = day.paceSuggested; // фолбэк: AI не дал темп → типичный темп типа из плана
  const timeMin = estimateTimeMin(km, pace || paceSuggested);

  const bodyItems = (day.activities || []).filter((a) => a.type !== 'rest' && a.type !== 'free');

  const completed = (dayData?.workouts || []).filter(hasMeaningfulWorkout);
  // «По плану» — только если вид совпал с типом плана (бег↔бег). Иначе тренировка «вне плана»,
  // а не подмена результата плана (исправляет привязку ходьбы к ОФП).
  const planCat = (day?.type && !isRest) ? planTypeToCategory(day.type) : null;
  const matchedW = planCat
    ? completed.find((w) => workoutTypeToCategory(w.activity_type ?? w.type ?? 'running') === planCat)
    : null;
  const orderedW = matchedW ? [matchedW, ...completed.filter((w) => w !== matchedW)] : completed;
  const hasResult = isDone || completed.length > 0;

  const factKm = matchedW ? Number(matchedW.distance_km ?? matchedW.distance) : null;
  const factPace = matchedW ? (matchedW.avg_pace ?? matchedW.pace) : null;
  const factTime = matchedW ? formatWorkoutDuration(matchedW) : null;
  const planPaceMin = paceToMin(pace || paceSuggested);
  const factPaceMin = paceToMin(factPace);
  const paceFaster = (matchedW && planPaceMin && factPaceMin) ? factPaceMin < planPaceMin : null;
  const distMore = (matchedW && km > 0 && factKm > 0) ? factKm >= km : null;
  const planSec = timeMin ? timeMin * 60 : null;
  const factSec = matchedW ? Number(matchedW.duration_seconds) : null;
  const timeMore = (planSec && factSec > 0) ? factSec >= planSec : null;

  const deltaSpan = (val, muted = false) => (val == null ? null
    : <span className={`calv3-cmp-delta calv3-cmp-delta--${muted ? 'muted' : (val ? 'good' : 'bad')}`}>{val ? '▲' : '▼'}</span>);

  const Inner = (
    <>
      {!embedded && <div className="calv3-sheet-grip" />}

      <div className={`calv3-day-cols${completed.length > 0 ? ' calv3-day-cols--done' : ''}${completed.length > 1 ? ' calv3-day-cols--multi' : ''}`}>
        <div className="calv3-day-main">

      <div className="calv3-sheet-head">
        <span className="calv3-sheet-accent" style={{ background: accent, opacity: isRest ? 0.4 : 1 }} />
        <div className="calv3-sheet-head-main">
          <div className="calv3-sheet-kicker">
            <span className="calv3-sheet-type">{typeLabel(day.type).toUpperCase()}</span>
            {day.key && <span className="calv3-tag">КЛЮЧ</span>}
            {hasResult && <span className="calv3-tag calv3-tag--done">ВЫПОЛНЕНО</span>}
          </div>
          <div className="calv3-sheet-title">{day.title}</div>
        </div>
        <div className="calv3-sheet-head-actions">
          {canEdit && !isRest && (
            <button type="button" className="calv3-sheet-edit" title="Изменить" onClick={() => onEdit?.(editablePlanDay, day)}>✎</button>
          )}
          {!embedded && (
            <button type="button" className="calv3-sheet-close" aria-label="Закрыть" onClick={onClose}>✕</button>
          )}
        </div>
      </div>

      {km > 0 ? (
        <div className="calv3-sheet-metrics">
          <div>
            <div className="calv3-m-num">{km}</div>
            <div className="calv3-m-lbl">км</div>
            {matchedW && (
              <div className="calv3-m-fact">{factKm > 0 ? factKm.toFixed(2).replace('.', ',') : '—'}{deltaSpan(distMore)}</div>
            )}
          </div>
          {pace ? (
            <div>
              <div className="calv3-m-num calv3-m-num--primary">{pace}</div>
              <div className="calv3-m-lbl">темп /км</div>
              {matchedW && (
                <div className="calv3-m-fact">{factPace || '—'}{deltaSpan(paceFaster)}</div>
              )}
            </div>
          ) : paceSuggested ? (
            <div title="Темп не задан в плане — ориентир по другим тренировкам этого типа">
              <div className="calv3-m-num calv3-m-num--primary">≈ {paceSuggested}</div>
              <div className="calv3-m-lbl">темп /км</div>
              {matchedW && (
                <div className="calv3-m-fact">{factPace || '—'}{deltaSpan(paceFaster)}</div>
              )}
            </div>
          ) : null}
          {timeMin && (
            <div>
              <div className="calv3-m-num">{timeMin}′</div>
              <div className="calv3-m-lbl">~ время</div>
              {matchedW && (
                <div className="calv3-m-fact">{factTime || '—'}{deltaSpan(timeMore, true)}</div>
              )}
            </div>
          )}
        </div>
      ) : null}

      {/* разбивка структуры (интервалы/темпо/фартлек) */}
      {(() => {
        const s = buildSegments(day);
        if (!s) return null;
        return (
          <div style={{ marginTop: 14 }}>
            <div className="calv3-segbar">
              {s.segs.map((seg, i) => (
                <div key={i} style={{ flex: seg.w, background: typeColorVar(seg.type) }} />
              ))}
            </div>
            <div className="calv3-segbar-note">{s.caption}</div>
          </div>
        );
      })()}

      {/* структура простого бега: разминка → бег → заминка (если указаны) */}
      {(() => {
        if (buildSegments(day)) return null; // у интервалов/фартлека уже есть визуальный бар
        const rs = parseRunStructure(day);
        if (!rs) return null;
        return (
          <div className="calv3-sheet-ex">
            <div className="calv3-ex-head">
              <span className="calv3-ex-head-label">СТРУКТУРА</span>
              <span className="calv3-ex-head-rule" />
            </div>
            <div className="calv3-ex-list">
              {rs.warmup && (
                <div className="calv3-ex-item">
                  <span className="calv3-ex-num">↑</span>
                  <div className="calv3-ex-main">
                    <div className="calv3-ex-name">Разминка</div>
                    {rs.warmup.pace && <div className="calv3-ex-hint">темп {rs.warmup.pace} /км</div>}
                  </div>
                  <span className="calv3-ex-sets">{rs.warmup.km} км</span>
                </div>
              )}
              {km > 0 && (
                <div className="calv3-ex-item">
                  <span className="calv3-ex-num" style={{ color: typeColorVar(day.type) }}>●</span>
                  <div className="calv3-ex-main">
                    <div className="calv3-ex-name">{typeLabel(day.type)}</div>
                    {pace && <div className="calv3-ex-hint">темп {pace} /км</div>}
                  </div>
                  <span className="calv3-ex-sets">{km} км</span>
                </div>
              )}
              {rs.cooldown && (
                <div className="calv3-ex-item">
                  <span className="calv3-ex-num">↓</span>
                  <div className="calv3-ex-main">
                    <div className="calv3-ex-name">Заминка</div>
                    {rs.cooldown.pace && <div className="calv3-ex-hint">темп {rs.cooldown.pace} /км</div>}
                  </div>
                  <span className="calv3-ex-sets">{rs.cooldown.km} км</span>
                </div>
              )}
            </div>
          </div>
        );
      })()}

      {/* структурированные упражнения (ОФП/СБУ) */}
      {exItems.length > 0 && (
        <div className="calv3-sheet-ex">
          <div className="calv3-ex-head">
            <span className="calv3-ex-head-label">{day.type === 'sbu' ? 'СБУ' : 'УПРАЖНЕНИЯ'} · {exItems.length}</span>
            <span className="calv3-ex-head-rule" />
          </div>
          <ExerciseListV3 items={exItems} />
        </div>
      )}

      {/* план-текст в стиле заметки тренера/AI (как в прототипе).
          Силовой текст скрываем только если упражнения уже показаны списком — иначе ОФП был бы пустым. */}
      {(() => {
        let noteText = bodyItems
          .filter((a) => !(exItems.length > 0 && (a.type === 'sbu' || a.type === 'other')))
          .map((a) => stripHtml(a.text))
          .filter(Boolean)
          .join('\n');
        // если разминку/заминку уже показали структурой — не дублируем их в заметке
        if (!buildSegments(day) && parseRunStructure(day)) {
          noteText = noteText
            .split(/\.\s+/)
            .filter((s) => !/^(Разминк|Заминк)/i.test(s.trim()))
            .join('. ')
            .trim();
        }
        if (!noteText) return null;
        return (
          <div className="calv3-sheet-note">
            <div className="calv3-sheet-note-av">AI</div>
            <div className="calv3-sheet-note-text">{noteText}</div>
          </div>
        );
      })()}

      {isRest && bodyItems.length === 0 && completed.length === 0 && (
        <div className="calv3-sheet-empty">День восстановления — лёгкая растяжка и сон.</div>
      )}

      {/* действия — внизу левой карточки (план); если день уже выполнен, скрываем (✎ есть в шапке) */}
      {canEdit && !hasResult && (
        <div className="calv3-sheet-actions">
          <button type="button" className="calv3-cta" onClick={() => onMarkDone?.(day)}>
            Отметить выполненной
          </button>
          {!isRest && (
            <button type="button" className="calv3-cta-icon" title="Изменить" onClick={() => onEdit?.(editablePlanDay, day)}>✎</button>
          )}
        </div>
      )}
        </div>{/* /calv3-day-main */}

      {completed.length > 0 && (
        <div className="calv3-day-side">
          <div className="calv3-ex-head">
            <span className="calv3-ex-head-label">ТРЕНИРОВКИ · {completed.length}</span>
            <span className="calv3-ex-head-rule" />
          </div>
          <div className="calv3-results">
            {orderedW.map((w, i) => (
              <DayCompletedV3
                key={w.id ?? w.workout_id ?? i}
                workout={w}
                date={day.date}
                api={api}
                defaultType={day.type}
                canEdit={canEdit}
                relation={w === matchedW ? 'plan' : 'extra'}
                onEdit={onEditResult ? (wk) => onEditResult(wk, day) : undefined}
                onDelete={onDeleteResult ? (wk) => onDeleteResult(wk, day) : undefined}
              />
            ))}
          </div>
        </div>
      )}
      </div>

      {loading && completed.length === 0 && (
        <div className="calv3-sheet-empty" style={{ marginTop: 12 }}>Загрузка дня…</div>
      )}

      {/* добавить тренировку в ЭТОТ день — мобильный sheet (на десктопе есть верхняя «+ Тренировка») */}
      {!embedded && canEdit && onAddTraining && (
        <button type="button" className="calv3-sheet-add" onClick={() => onAddTraining(day.date)}>
          + Добавить тренировку
        </button>
      )}
    </>
  );

  if (embedded) {
    return <div className="calv3-embed">{Inner}</div>;
  }
  return (
    <>
      <div className="calv3-sheet-scrim" onClick={onClose} />
      <div className="calv3-sheet" role="dialog" aria-modal="true" aria-label="Тренировка" ref={sheetRef}>{Inner}</div>
    </>
  );
}
