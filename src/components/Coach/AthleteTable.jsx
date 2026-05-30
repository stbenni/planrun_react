/**
 * AthleteTable — табличное представление атлетов в CoachWorkspace.
 * Фаза 1: read-only. Чекбоксы зарезервированы для Фазы 2 (bulk-actions).
 *
 * Колонки: Атлет / Цель / До гонки / Неделя / 7 дней объём / Сегодня по плану / Активность / VDOT.
 * Поля которых нет в текущем API (spark, vdot, today_plan) показываются как «—».
 */

import { useRef } from 'react';
import { CoachAvatar, ComplianceBar, GroupTag, Sparkline, WORKOUT_TYPE_COLOR } from './CoachPrimitives';
import { coachHelpers } from '../../stores/useCoachStore';
import { UploadIcon } from '../common/Icons';

const DISTANCE_LABELS = {
  '5k': '5 км',
  '10k': '10 км',
  half: 'Полумарафон',
  half_marathon: 'Полумарафон',
  '21.1k': 'Полумарафон',
  marathon: 'Марафон',
  '42.2k': 'Марафон',
  ultra: 'Ультра',
};

const GOAL_LABELS = {
  race: 'Забег',
  health: 'Здоровье',
  time_improvement: 'Улучшение времени',
  weight_loss: 'Снижение веса',
};

function formatGoal(a) {
  if (a.race_distance) return DISTANCE_LABELS[a.race_distance] || a.race_distance;
  if (a.goal_type) return GOAL_LABELS[a.goal_type] || a.goal_type;
  return '—';
}

function formatRaceDateShort(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
}

/** Первая непустая строка из текста (для краткого предпросмотра описания тренировки). */
function firstLine(text) {
  if (!text) return '';
  const line = String(text).split(/\r?\n/).find((s) => s.trim().length > 0) || '';
  return line.trim();
}

function daysToRace(iso) {
  if (!iso) return null;
  const t = Date.parse(iso);
  if (Number.isNaN(t)) return null;
  const days = Math.ceil((t - Date.now()) / 86400000);
  return days > 0 ? days : null;
}

function formatLastActivity(iso) {
  const d = coachHelpers.daysSince(iso);
  if (d === Infinity) return 'нет данных';
  if (d === 0) return 'Сегодня';
  if (d === 1) return 'Вчера';
  if (d < 7) return `${d} дн. назад`;
  if (d < 30) return `${Math.floor(d / 7)} нед. назад`;
  return `${d} дн. назад`;
}

export default function AthleteTable({
  athletes,
  activeId,
  onOpenAthlete,
  selected,
  onToggleSelected,
  onSelectMany,
}) {
  // Якорь последнего тапнутого чекбокса для Shift+Click range select
  const lastClickedIdRef = useRef(null);

  if (!athletes || athletes.length === 0) {
    return (
      <div className="coach-table-empty">
        <p>Нет атлетов в этом фильтре</p>
      </div>
    );
  }
  const allIds = athletes.map((a) => a.id);
  const selectedCount = allIds.filter((id) => selected?.has?.(id)).length;
  const allChecked = selectedCount > 0 && selectedCount === allIds.length;
  const someChecked = selectedCount > 0 && !allChecked;

  const handleCheckboxClick = (e, id) => {
    e.stopPropagation();
    if (e.shiftKey && lastClickedIdRef.current != null && lastClickedIdRef.current !== id) {
      // Range select: выбрать всё между last anchor и current id (в visible-фильтрованном порядке)
      const fromIdx = allIds.findIndex((x) => x === lastClickedIdRef.current);
      const toIdx = allIds.findIndex((x) => x === id);
      if (fromIdx !== -1 && toIdx !== -1) {
        const [lo, hi] = fromIdx < toIdx ? [fromIdx, toIdx] : [toIdx, fromIdx];
        const rangeIds = allIds.slice(lo, hi + 1);
        // Если anchor сейчас выбран — selecting range; иначе — deselecting
        const anchorSelected = selected?.has?.(lastClickedIdRef.current);
        onSelectMany?.(rangeIds, !!anchorSelected || !selected?.has?.(id));
        lastClickedIdRef.current = id;
        return;
      }
    }
    onToggleSelected?.(id);
    lastClickedIdRef.current = id;
  };

  return (
    <div className="coach-table">
      <div className="coach-table__head">
        <div className="coach-table__cell coach-table__cell--check">
          <input
            type="checkbox"
            checked={allChecked}
            ref={(el) => { if (el) el.indeterminate = someChecked; }}
            onChange={(e) => onSelectMany?.(allIds, e.target.checked)}
            aria-label={allChecked ? 'Снять выбор со всех' : 'Выбрать всех'}
          />
        </div>
        <div className="coach-table__cell coach-table__cell--athlete">АТЛЕТ</div>
        <div className="coach-table__cell coach-table__cell--goal">ЦЕЛЬ</div>
        <div className="coach-table__cell coach-table__cell--race">ДО ГОНКИ</div>
        <div className="coach-table__cell coach-table__cell--week">НЕДЕЛЯ</div>
        <div className="coach-table__cell coach-table__cell--volume">7 ДНЕЙ · ОБЪЁМ</div>
        <div className="coach-table__cell coach-table__cell--today">СЕГОДНЯ ПО ПЛАНУ</div>
        <div className="coach-table__cell coach-table__cell--activity">АКТИВНОСТЬ</div>
        <div className="coach-table__cell coach-table__cell--vdot">VDOT</div>
      </div>
      <div className="coach-table__body">
        {athletes.map((a) => {
          const atRisk = coachHelpers.isAtRisk(a);
          const fresh = coachHelpers.hasFreshUpload(a);
          const isActive = String(activeId) === String(a.id);
          const isSelected = selected?.has?.(a.id) || false;
          const ring = atRisk ? 'var(--danger-500)' : fresh ? 'var(--success-500)' : null;
          const days = daysToRace(a.race_date);
          const group = Array.isArray(a.groups) && a.groups.length > 0 ? a.groups[0] : null;
          const todayType = a.today_plan?.type;
          return (
            <div
              key={a.id}
              role="button"
              tabIndex={0}
              className={`coach-table__row ${isActive ? 'coach-table__row--active' : ''} ${isSelected ? 'coach-table__row--selected' : ''}`}
              onClick={() => onOpenAthlete?.(a.id)}
              onKeyDown={(e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                  e.preventDefault();
                  onOpenAthlete?.(a.id);
                }
              }}
            >
              <div className="coach-table__cell coach-table__cell--check">
                <input
                  type="checkbox"
                  checked={isSelected}
                  onChange={() => {}}
                  onClick={(e) => handleCheckboxClick(e, a.id)}
                  aria-label={`Выбрать ${a.name || a.username}`}
                  title="Shift+Click — выделить диапазон"
                />
              </div>
              <div className="coach-table__cell coach-table__cell--athlete">
                <CoachAvatar athlete={a} size={36} ring={ring} />
                <div className="coach-table__athlete-info">
                  <div className="coach-table__athlete-name">
                    {a.name || a.username}
                    {Number(a.unread_count) > 0 && (
                      <span className="coach-table__unread">{a.unread_count}</span>
                    )}
                  </div>
                  {group && (
                    <div className="coach-table__athlete-group">
                      <GroupTag group={group} />
                    </div>
                  )}
                </div>
              </div>
              <div className="coach-table__cell coach-table__cell--goal">
                <div className="coach-table__goal-name">{formatGoal(a)}</div>
                {a.race_target_time && (
                  <div className="coach-table__goal-target">цель {a.race_target_time}</div>
                )}
              </div>
              <div className="coach-table__cell coach-table__cell--race">
                {days != null ? (
                  <>
                    <div className={`coach-table__race-days ${days <= 30 ? 'coach-table__race-days--soon' : ''}`}>
                      {days}<span className="coach-table__race-days-unit"> дн.</span>
                    </div>
                    <div className="coach-table__race-date">{formatRaceDateShort(a.race_date)}</div>
                  </>
                ) : (
                  <span className="coach-table__muted">—</span>
                )}
              </div>
              <div className="coach-table__cell coach-table__cell--week">
                <ComplianceBar done={a.week_completed || 0} total={a.week_total || 0} w={48} />
                <span className="coach-table__week-text">
                  {a.week_completed || 0}/{a.week_total || 0}
                </span>
              </div>
              <div className="coach-table__cell coach-table__cell--volume">
                <Sparkline
                  data={Array.isArray(a.volume_spark) ? a.volume_spark : []}
                  w={70}
                  h={22}
                  color={atRisk ? 'var(--danger-500)' : 'var(--primary-500)'}
                />
                {Array.isArray(a.volume_spark) && a.volume_spark.length > 0 ? (
                  <span className="coach-table__volume-sum">
                    {a.volume_spark.reduce((s, x) => s + Number(x || 0), 0)}к
                  </span>
                ) : null}
              </div>
              <div className="coach-table__cell coach-table__cell--today" title={a.today_plan?.description || undefined}>
                {a.today_plan ? (
                  <>
                    <span className="coach-table__today-name">
                      <span
                        className="coach-table__today-dot"
                        style={{ background: WORKOUT_TYPE_COLOR[todayType] || 'var(--gray-400)' }}
                      />
                      {a.today_plan.label || a.today_plan.title || '—'}
                    </span>
                    {a.today_plan.distance > 0 ? (
                      <div className="coach-table__today-meta">
                        {a.today_plan.distance} км
                        {a.today_plan.pace ? ` · ${a.today_plan.pace}` : ''}
                      </div>
                    ) : a.today_plan.description ? (
                      <div className="coach-table__today-meta coach-table__today-desc">
                        {firstLine(a.today_plan.description)}
                      </div>
                    ) : null}
                  </>
                ) : (
                  <span className="coach-table__muted">—</span>
                )}
              </div>
              <div className="coach-table__cell coach-table__cell--activity">
                <div className={`coach-table__activity ${atRisk ? 'coach-table__activity--risk' : ''}`}>
                  {formatLastActivity(a.last_activity)}
                </div>
                {fresh && <div className="coach-table__fresh-tag"><UploadIcon size={12} /> новая</div>}
              </div>
              <div className="coach-table__cell coach-table__cell--vdot">
                {a.vdot ? (
                  <>
                    <div className="coach-table__vdot-value">{a.vdot}</div>
                    {a.pace_trend && (
                      <div
                        className={`coach-table__vdot-trend ${
                          String(a.pace_trend).startsWith('+') ? 'coach-table__vdot-trend--up' :
                          String(a.pace_trend).startsWith('−') || String(a.pace_trend).startsWith('-') ? 'coach-table__vdot-trend--down' :
                          ''
                        }`}
                      >
                        {a.pace_trend}
                      </div>
                    )}
                  </>
                ) : (
                  <span className="coach-table__muted">—</span>
                )}
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
