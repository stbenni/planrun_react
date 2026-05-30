/**
 * AthleteGrid — view 'grid' в CoachWorkspace: heatmap-тайлы compliance.
 *
 * Каждый тайл — атлет: верхняя цветная полоса compliance, аватар + имя + цель,
 * большое weekDone/weekTotal, VDOT, толстый sparkline, бейджи РИСК / ↑ Сегодня /
 * дни до гонки (если ≤ 60).
 *
 * Чекбокс выбора в правом верхнем углу — для bulk-actions (если режим выбора).
 */

import { CoachAvatar, Sparkline } from './CoachPrimitives';
import { coachHelpers } from '../../stores/useCoachStore';
import { UploadIcon, AlertTriangleIcon } from '../common/Icons';
import './AthleteGrid.css';

const DISTANCE_LABELS = {
  '5k': '5 км', '10k': '10 км', half: 'Полу', half_marathon: 'Полу',
  marathon: 'Марафон', ultra: 'Ультра',
};

function shortName(athlete) {
  const full = (athlete.name || athlete.username || '').trim();
  if (!full) return '—';
  const parts = full.split(/\s+/);
  if (parts.length >= 2) return `${parts[0]} ${parts[1][0]}.`;
  return parts[0];
}

function daysToRace(iso) {
  if (!iso) return null;
  const t = Date.parse(iso);
  if (Number.isNaN(t)) return null;
  const d = Math.ceil((t - Date.now()) / 86400000);
  return d > 0 ? d : null;
}

function complianceColor(pct) {
  if (pct == null) return 'var(--gray-300)';
  if (pct >= 0.8) return 'var(--success-500)';
  if (pct >= 0.5) return 'var(--warning-500)';
  if (pct > 0) return 'var(--danger-500)';
  return 'var(--gray-300)';
}

export default function AthleteGrid({
  athletes,
  activeId,
  onOpenAthlete,
  selected,
  onToggleSelected,
}) {
  if (!athletes || athletes.length === 0) {
    return (
      <div className="coach-grid-empty">
        <p>Нет атлетов в этом фильтре</p>
      </div>
    );
  }
  return (
    <div className="coach-grid">
      {athletes.map((a) => {
        const total = Number(a.week_total || 0);
        const done = Number(a.week_completed || 0);
        const pct = total > 0 ? done / total : null;
        const color = complianceColor(pct);
        const atRisk = coachHelpers.isAtRisk(a);
        const fresh = coachHelpers.hasFreshUpload(a);
        const ring = atRisk ? 'var(--danger-500)' : fresh ? 'var(--success-500)' : null;
        const days = daysToRace(a.race_date);
        const isActive = String(activeId) === String(a.id);
        const isSelected = selected?.has?.(a.id) || false;
        const distLabel = a.race_distance ? (DISTANCE_LABELS[a.race_distance] || a.race_distance) : null;
        const spark = Array.isArray(a.volume_spark) ? a.volume_spark : [];
        return (
          <div
            key={a.id}
            role="button"
            tabIndex={0}
            className={`coach-tile ${isActive ? 'coach-tile--active' : ''} ${isSelected ? 'coach-tile--selected' : ''}`}
            onClick={() => onOpenAthlete?.(a.id)}
            onKeyDown={(e) => {
              if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                onOpenAthlete?.(a.id);
              }
            }}
          >
            {/* compliance strip */}
            <div className="coach-tile__strip" aria-hidden>
              <div
                className="coach-tile__strip-fill"
                style={{ width: `${Math.min(100, (pct || 0) * 100)}%`, background: color }}
              />
            </div>

            <div className="coach-tile__head">
              <CoachAvatar athlete={a} size={36} ring={ring} />
              <div className="coach-tile__head-info">
                <div className="coach-tile__name">{shortName(a)}</div>
                <div className="coach-tile__sub">
                  {distLabel || (a.goal_type === 'health' ? 'Здоровье' : '—')}
                  {a.race_target_time ? ` · ${a.race_target_time}` : ''}
                </div>
              </div>
              <input
                type="checkbox"
                className="coach-tile__check"
                checked={isSelected}
                onChange={() => onToggleSelected?.(a.id)}
                onClick={(e) => e.stopPropagation()}
                aria-label={`Выбрать ${a.username}`}
              />
            </div>

            <div className="coach-tile__metrics">
              <div className="coach-tile__week" style={{ color: atRisk ? 'var(--danger-500)' : 'var(--text-primary)' }}>
                {done}
                <span className="coach-tile__week-total">/{total || 0}</span>
              </div>
              <div className="coach-tile__vdot">
                <div className="coach-tile__vdot-label">VDOT</div>
                <div className="coach-tile__vdot-value">{a.vdot || '—'}</div>
              </div>
            </div>

            <div className="coach-tile__chart">
              {spark.length > 1 ? (
                <Sparkline
                  data={spark}
                  w={220}
                  h={36}
                  color={atRisk ? 'var(--danger-500)' : 'var(--primary-500)'}
                  thick
                />
              ) : (
                <div className="coach-tile__chart-empty">7 дней — пока без активностей</div>
              )}
            </div>

            <div className="coach-tile__badges">
              {atRisk && <span className="coach-tile__badge coach-tile__badge--danger"><AlertTriangleIcon size={12} /> РИСК</span>}
              {fresh && <span className="coach-tile__badge coach-tile__badge--success"><UploadIcon size={12} /> Сегодня</span>}
              {!atRisk && !fresh && (
                <span className="coach-tile__badge coach-tile__badge--muted">
                  {formatLastActivityShort(a.last_activity)}
                </span>
              )}
              <span className="coach-tile__spacer" />
              {days != null && days <= 60 && (
                <span className="coach-tile__days-to-race">{days}д</span>
              )}
            </div>
          </div>
        );
      })}
    </div>
  );
}

function formatLastActivityShort(iso) {
  const d = coachHelpers.daysSince(iso);
  if (d === Infinity) return 'нет данных';
  if (d === 0) return 'Сегодня';
  if (d === 1) return 'Вчера';
  if (d < 7) return `${d} дн назад`;
  return `${Math.floor(d / 7)} нед назад`;
}
