/* DayCompletedV3 — детали выполненной тренировки ИНЛАЙН в DaySheet.
   Как в статистике (WorkoutDetailsModal): карта сверху + вкладки Обзор/Данные/Круги/Графики.
   Использует те же блоки: LeafletRouteMap, CombinedWorkoutChart. Грузит timeline/laps. */
import { lazy, Suspense, useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import CombinedWorkoutChart from '../../Stats/CombinedWorkoutChart';
import WorkoutShareButton from '../../Stats/WorkoutShareButton';
import MapLoadingSkeleton from '../../Stats/MapLoadingSkeleton';
import { formatWorkoutDuration, formatLapDuration, formatLapPace } from '../../../utils/lapFormat';
import { getSourceLabel } from '../../../utils/workoutFormUtils';
import { ShareIcon, ChevronDownIcon } from '../../common/Icons';
import { typeColorVar, typeLabel } from './calV3';

const RouteMap = lazy(() => (import.meta.env.VITE_MAPBOX_TOKEN
  ? import('../../Stats/MapboxRouteMap')
  : import('../../Stats/LeafletRouteMap')));

export default function DayCompletedV3({ workout, date, api, defaultType = 'easy', canEdit = false, onEdit, onDelete, relation = null, defaultExpanded = false, hideToggle = false, chartsInline = false }) {
  const [timeline, setTimeline] = useState(null);
  const [laps, setLaps] = useState(null);
  const [confirmDel, setConfirmDel] = useState(false);
  const [activeTab, setActiveTab] = useState('overview');
  const [hoverIndex, setHoverIndex] = useState(null);
  const [expanded, setExpanded] = useState(defaultExpanded);
  // mounted держит тяжёлый контент (карта/графики) в DOM на время анимации сворачивания
  const [mounted, setMounted] = useState(defaultExpanded);
  const collapseTimer = useRef(null);

  const toggle = useCallback(() => {
    if (collapseTimer.current) { clearTimeout(collapseTimer.current); collapseTimer.current = null; }
    setExpanded((prev) => {
      if (!prev) { setMounted(true); return true; }
      collapseTimer.current = setTimeout(() => { setMounted(false); collapseTimer.current = null; }, 300);
      return false;
    });
  }, []);
  useEffect(() => () => { if (collapseTimer.current) clearTimeout(collapseTimer.current); }, []);

  useEffect(() => {
    setTimeline(null);
    setLaps(null);
    setHoverIndex(null);
    if (!mounted || !api?.getWorkoutTimeline || !workout?.id || workout.is_manual) return undefined;
    let cancelled = false;
    (async () => {
      try {
        const res = await api.getWorkoutTimeline(workout.id);
        if (cancelled) return;
        let tl = null, lp = null;
        if (Array.isArray(res)) tl = res;
        else if (res && typeof res === 'object') {
          const p = res.data && typeof res.data === 'object' ? res.data : res;
          if (Array.isArray(p?.timeline)) tl = p.timeline;
          if (Array.isArray(p?.laps)) lp = p.laps;
        }
        if (!cancelled && tl?.length) setTimeline(tl);
        if (!cancelled && lp?.length) setLaps(lp);
      } catch { /* silent */ }
    })();
    return () => { cancelled = true; };
  }, [api, workout?.id, workout?.is_manual, mounted]);

  const dist = workout.distance_km ?? workout.distance;
  const dur = formatWorkoutDuration(workout)
    || (workout.duration ? formatLapDuration(Number(workout.duration) * 60) : null);
  const pace = workout.avg_pace ?? workout.pace;
  const type = (workout.detected_type ?? workout.type ?? workout.activity_type ?? defaultType).toLowerCase();
  const hasGps = Array.isArray(timeline) && timeline.some((p) => p && (p.lat != null || p.latitude != null));
  const hasChart = Array.isArray(timeline) && timeline.length > 1;
  const hasLaps = Array.isArray(laps) && laps.length > 0;
  const sourceLabel = workout.source && !workout.is_manual ? getSourceLabel(workout.source) : null;
  const startTimeStr = workout.start_time
    ? new Date(workout.start_time).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
    : null;

  // Доступные вкладки (как в статистике): Обзор всегда; Данные/Графики — для не-ручных; Круги — если есть.
  const tabs = [{ key: 'overview', label: 'Обзор' }];
  if (!workout.is_manual) tabs.push({ key: 'details', label: 'Данные' });
  if (hasLaps) tabs.push({ key: 'laps', label: 'Круги' });
  if (hasChart && !workout.is_manual && !chartsInline) tabs.push({ key: 'charts', label: 'Графики' });
  const curTab = tabs.some((t) => t.key === activeTab) ? activeTab : 'overview';

  return (
    <div className={`calv3-done${expanded ? ' is-expanded' : ' is-collapsed'}`}>
      <div className="calv3-done-head">
        <button type="button" className="calv3-done-head-main" onClick={hideToggle ? undefined : toggle}>
          <span className="calv3-done-head-top">
            <span className="calv3-done-accent" style={{ background: typeColorVar(type) }} />
            <span className="calv3-done-type">{typeLabel(type)}</span>
            {relation === 'plan' && <span className="calv3-done-badge calv3-done-badge--plan">ПО ПЛАНУ</span>}
            {relation === 'extra' && <span className="calv3-done-badge calv3-done-badge--extra">ВНЕ ПЛАНА</span>}
          </span>
          {!expanded && (
            <span className="calv3-done-summary">
              {dist != null && Number(dist) > 0 && <span><b>{Number(dist).toFixed(2).replace('.', ',')}</b> км</span>}
              {pace && <span><b>{pace}</b> /км</span>}
              {workout.avg_heart_rate ? <span><b>{workout.avg_heart_rate}</b> уд/м</span> : null}
            </span>
          )}
        </button>
        {expanded && (
          <div className="calv3-done-head-actions">
            <WorkoutShareButton
              workout={workout}
              date={date}
              timeline={timeline}
              api={api}
              className="calv3-done-icon-btn"
              title="Поделиться"
            >
              <ShareIcon size={16} />
            </WorkoutShareButton>
          </div>
        )}
        {!hideToggle && (
          <button
            type="button"
            className="calv3-done-chev-btn"
            onClick={toggle}
            aria-label={expanded ? 'Свернуть' : 'Развернуть'}
          >
            <ChevronDownIcon size={16} className={`calv3-done-chev${expanded ? ' is-up' : ''}`} />
          </button>
        )}
      </div>

      <div className={`calv3-done-body${expanded ? ' is-open' : ''}`}>
        <div className="calv3-done-body-inner">
      {mounted && (
        <>
      {/* Карта наверху (если есть GPS) — как в статистике */}
      {hasGps && (
        <div className="calv3-done-map">
          <Suspense fallback={<MapLoadingSkeleton />}>
            <RouteMap key={workout.id} timeline={timeline} hoverIndex={hoverIndex} />
          </Suspense>
        </div>
      )}

      {/* Вкладки */}
      {tabs.length > 1 && (
        <div className="calv3-done-tabs">
          {tabs.map((t) => (
            <button
              key={t.key}
              type="button"
              className={`calv3-done-tab${curTab === t.key ? ' is-active' : ''}`}
              onClick={() => setActiveTab(t.key)}
            >
              {t.label}
            </button>
          ))}
        </div>
      )}

      {/* Обзор: ключевые метрики + заметки */}
      {curTab === 'overview' && (
        <div className="calv3-done-tabc">
          {chartsInline && hasChart && (
            <div className="calv3-done-chart">
              <CombinedWorkoutChart timeline={timeline} onHoverIndex={setHoverIndex} />
            </div>
          )}
          <div className="calv3-done-metrics">
            {dist != null && Number(dist) > 0 && (
              <div className="calv3-done-card calv3-done-card--hero">
                <div className="calv3-done-val">{Number(dist).toFixed(2).replace('.', ',')}</div>
                <div className="calv3-done-sub">км · дистанция</div>
              </div>
            )}
            <div className="calv3-done-row">
              {dur && (
                <div className="calv3-done-card">
                  <div className="calv3-done-val">{dur}</div>
                  <div className="calv3-done-sub">время</div>
                </div>
              )}
              {pace && (
                <div className="calv3-done-card">
                  <div className="calv3-done-val">{pace}<span className="calv3-done-unit"> /км</span></div>
                  <div className="calv3-done-sub">средний темп</div>
                </div>
              )}
            </div>
            {(workout.avg_heart_rate || workout.max_heart_rate) && (
              <div className="calv3-done-row">
                {workout.avg_heart_rate && (
                  <div className="calv3-done-card">
                    <div className="calv3-done-val">{workout.avg_heart_rate}<span className="calv3-done-unit"> уд/м</span></div>
                    <div className="calv3-done-sub">средний пульс</div>
                  </div>
                )}
                {workout.max_heart_rate && (
                  <div className="calv3-done-card">
                    <div className="calv3-done-val">{workout.max_heart_rate}<span className="calv3-done-unit"> уд/м</span></div>
                    <div className="calv3-done-sub">макс. пульс</div>
                  </div>
                )}
              </div>
            )}
          </div>

          {workout.notes && <div className="calv3-done-notes">{workout.notes}</div>}
        </div>
      )}

      {/* Данные: подробные поля */}
      {curTab === 'details' && (
        <div className="calv3-done-tabc">
          <div className="calv3-done-details">
            {dist != null && Number(dist) > 0 && (
              <div className="calv3-done-drow"><span className="calv3-done-dlabel">Дистанция</span><span className="calv3-done-dval">{dist} км</span></div>
            )}
            {dur && (
              <div className="calv3-done-drow"><span className="calv3-done-dlabel">Время</span><span className="calv3-done-dval">{dur}</span></div>
            )}
            {pace && (
              <div className="calv3-done-drow"><span className="calv3-done-dlabel">Средний темп</span><span className="calv3-done-dval">{pace} /км</span></div>
            )}
            {workout.avg_heart_rate && (
              <div className="calv3-done-drow"><span className="calv3-done-dlabel">Средний пульс</span><span className="calv3-done-dval">{workout.avg_heart_rate} уд/мин</span></div>
            )}
            {workout.max_heart_rate && (
              <div className="calv3-done-drow"><span className="calv3-done-dlabel">Макс. пульс</span><span className="calv3-done-dval">{workout.max_heart_rate} уд/мин</span></div>
            )}
            {workout.elevation_gain && (
              <div className="calv3-done-drow"><span className="calv3-done-dlabel">Набор высоты</span><span className="calv3-done-dval">{Math.round(workout.elevation_gain)} м</span></div>
            )}
            {workout.cadence && (
              <div className="calv3-done-drow"><span className="calv3-done-dlabel">Каденс</span><span className="calv3-done-dval">{workout.cadence} шаг/мин</span></div>
            )}
            {workout.calories && (
              <div className="calv3-done-drow"><span className="calv3-done-dlabel">Калории</span><span className="calv3-done-dval">{workout.calories} ккал</span></div>
            )}
            {sourceLabel && (
              <div className="calv3-done-drow"><span className="calv3-done-dlabel">Источник</span><span className="calv3-done-dval">{sourceLabel}</span></div>
            )}
            {startTimeStr && (
              <div className="calv3-done-drow"><span className="calv3-done-dlabel">Начало</span><span className="calv3-done-dval">{startTimeStr}</span></div>
            )}
            {workout.id && (
              <div className="calv3-done-drow"><span className="calv3-done-dlabel">ID тренировки</span><span className="calv3-done-dval">#{workout.id}</span></div>
            )}
          </div>
        </div>
      )}

      {/* Круги */}
      {curTab === 'laps' && hasLaps && (
        <div className="calv3-done-tabc">
          <div className="calv3-done-laps">
            <table className="calv3-laps-table">
              <thead>
                <tr><th>Круг</th><th>Расст.</th><th>Время</th><th>Темп</th><th>Пульс</th></tr>
              </thead>
              <tbody>
                {laps.map((lap, i) => {
                  const hr = Number(lap.avg_heart_rate);
                  return (
                    <tr key={lap.lap_index ?? i}>
                      <td>{i + 1}</td>
                      <td>{Number.isFinite(Number(lap.distance_km)) ? `${Number(lap.distance_km).toFixed(2)} км` : '—'}</td>
                      <td>{formatLapDuration(lap.moving_seconds ?? lap.elapsed_seconds) ?? '—'}</td>
                      <td>{formatLapPace(lap) ?? '—'}</td>
                      <td>{Number.isFinite(hr) && hr > 0 ? Math.round(hr) : '—'}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Графики */}
      {curTab === 'charts' && hasChart && (
        <div className="calv3-done-tabc">
          <CombinedWorkoutChart timeline={timeline} onHoverIndex={setHoverIndex} />
        </div>
      )}

      {canEdit && (onDelete || (onEdit && workout.is_manual)) && (
        <div className="calv3-done-actions">
          {onEdit && workout.is_manual && (
            <button type="button" className="calv3-cta-ghost" onClick={() => onEdit(workout)}>Редактировать</button>
          )}
          {onDelete && (
            <button type="button" className="calv3-done-delete-btn" onClick={() => setConfirmDel(true)}>Удалить тренировку</button>
          )}
        </div>
      )}
        </>
      )}
        </div>
      </div>

      {confirmDel && onDelete && createPortal(
        <div className="calv3-confirm-overlay" onClick={() => setConfirmDel(false)}>
          <div className="calv3-confirm" role="dialog" aria-modal="true" onClick={(e) => e.stopPropagation()}>
            <div className="calv3-confirm-title">Удалить тренировку?</div>
            <div className="calv3-confirm-text">
              {typeLabel(type)}{dist != null && Number(dist) > 0 ? ` · ${Number(dist).toFixed(2).replace('.', ',')} км` : ''}. Это действие необратимо.
            </div>
            <div className="calv3-confirm-actions">
              <button type="button" className="calv3-confirm-btn" onClick={() => setConfirmDel(false)}>Отмена</button>
              <button
                type="button"
                className="calv3-confirm-btn calv3-confirm-btn--danger"
                onClick={() => { setConfirmDel(false); onDelete(workout); }}
              >Удалить</button>
            </div>
          </div>
        </div>,
        document.body,
      )}
    </div>
  );
}
