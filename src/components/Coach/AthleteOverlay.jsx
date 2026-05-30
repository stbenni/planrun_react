/**
 * AthleteOverlay — slide-in панель деталей атлета (drill-in).
 * Открывается поверх CoachWorkspace при клике на строку атлета.
 *
 * Фаза 1: только tab «Обзор» (метрики + сегодняшний план + объём 7д).
 * Tabs «План недели / Графики / Чат» — заглушки на этом этапе.
 *
 * Закрытие: ✕, scrim click, Esc. URL-sync будет в Фазе 2.
 */

import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { useNavigate } from 'react-router-dom';
import {
  CloseIcon, MailIcon, PenLineIcon, ArrowLeftRightIcon, ClipboardListIcon,
} from '../common/Icons';
import { CoachAvatar, GroupTag, Sparkline, WORKOUT_TYPE_COLOR } from './CoachPrimitives';
import useAuthStore from '../../stores/useAuthStore';
import useCoachStore, { coachHelpers } from '../../stores/useCoachStore';
import './AthleteOverlay.css';

const DAY_OF_WEEK_LABEL = { 1: 'Пн', 2: 'Вт', 3: 'Ср', 4: 'Чт', 5: 'Пт', 6: 'Сб', 7: 'Вс' };

const DISTANCE_LABELS = {
  '5k': '5 км', '10k': '10 км', half: 'Полумарафон', half_marathon: 'Полумарафон',
  marathon: 'Марафон', ultra: 'Ультра',
};

function formatGoal(a) {
  const parts = [];
  if (a.race_distance) parts.push(DISTANCE_LABELS[a.race_distance] || a.race_distance);
  if (a.race_target_time) parts.push(`цель ${a.race_target_time}`);
  return parts.join(' · ');
}

function daysToRace(iso) {
  if (!iso) return null;
  const t = Date.parse(iso);
  if (Number.isNaN(t)) return null;
  return Math.max(0, Math.ceil((t - Date.now()) / 86400000));
}

/** Возвращает массив подписей дат для sparkline за n дней (от старого к новому, включая сегодня). */
function sparkDateLabels(n) {
  const labels = [];
  const now = new Date();
  for (let i = n - 1; i >= 0; i--) {
    const d = new Date(now);
    d.setDate(d.getDate() - i);
    labels.push(d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short', weekday: 'short' }));
  }
  return labels;
}

export default function AthleteOverlay({ athlete, onClose }) {
  const navigate = useNavigate();
  const { api } = useAuthStore();
  const openBulkAssign = useCoachStore((s) => s.openBulkAssign);
  const setSelected = useCoachStore((s) => s.selectMany);
  const [tab, setTab] = useState('overview');
  const [details, setDetails] = useState(null);
  const [detailsLoading, setDetailsLoading] = useState(false);
  const [detailsError, setDetailsError] = useState(null);

  const slug = athlete?.username_slug || athlete?.username;

  // Загрузка детальной информации при открытии overlay
  useEffect(() => {
    if (!athlete?.id || !api) return;
    let cancelled = false;
    setDetailsLoading(true);
    setDetailsError(null);
    api.getAthleteDetails(athlete.id)
      .then((res) => {
        if (cancelled) return;
        setDetails(res?.data || res || null);
      })
      .catch((e) => {
        if (cancelled) return;
        setDetailsError(e?.message || 'Не удалось загрузить детали');
      })
      .finally(() => { if (!cancelled) setDetailsLoading(false); });
    return () => { cancelled = true; };
  }, [athlete?.id, api]);

  const handleOpenChat = () => {
    if (!athlete) return;
    onClose?.();
    navigate('/chat', { state: { contactUser: { id: athlete.id, username: athlete.username, username_slug: athlete.username_slug } } });
  };

  const handleEditPlan = () => {
    if (!slug) return;
    onClose?.();
    navigate(`/calendar?athlete=${slug}`);
  };

  const handleApplyTemplate = () => {
    if (!athlete) return;
    // Pre-select только этого атлета и открыть мастер
    setSelected([athlete.id], true);
    onClose?.();
    openBulkAssign();
  };

  const handleReschedule = () => {
    if (!slug) return;
    // На «Перенести» — открываем календарь атлета (где можно drag-and-drop / edit)
    onClose?.();
    navigate(`/calendar?athlete=${slug}`);
  };

  useEffect(() => {
    const onKey = (e) => { if (e.key === 'Escape') onClose?.(); };
    document.addEventListener('keydown', onKey);
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = prev;
    };
  }, [onClose]);

  if (!athlete) return null;

  const atRisk = coachHelpers.isAtRisk(athlete);
  const fresh = coachHelpers.hasFreshUpload(athlete);
  const group = Array.isArray(athlete.groups) && athlete.groups.length > 0 ? athlete.groups[0] : null;
  const compliance = athlete.week_total > 0
    ? Math.round((athlete.week_completed / athlete.week_total) * 100)
    : null;
  const volume7d = Array.isArray(athlete.volume_spark)
    ? Math.round(athlete.volume_spark.reduce((s, x) => s + Number(x || 0), 0) * 10) / 10
    : null;
  const sparkLabels = Array.isArray(athlete.volume_spark) && athlete.volume_spark.length > 0
    ? sparkDateLabels(athlete.volume_spark.length)
    : null;
  const days = daysToRace(athlete.race_date);
  const todayType = athlete.today_plan?.type;
  const goalText = formatGoal(athlete);

  const portalTarget = (typeof document !== 'undefined' && document.getElementById('modal-root')) || document?.body;
  if (!portalTarget) return null;

  const content = (
    <>
      <div className="coach-overlay__scrim" onClick={onClose} aria-hidden />
      <aside className="coach-overlay" role="dialog" aria-label={`Атлет ${athlete.name || athlete.username}`}>
        <header className="coach-overlay__head">
          <CoachAvatar athlete={athlete} size={56} ring={atRisk ? 'var(--danger-500)' : fresh ? 'var(--success-500)' : null} />
          <div className="coach-overlay__head-info">
            <div className="coach-overlay__name">{athlete.name || athlete.username}</div>
            <div className="coach-overlay__head-sub">
              {group && <GroupTag group={group} />}
              {goalText && <span className="coach-overlay__head-goal">· {goalText}</span>}
            </div>
          </div>
          <button type="button" className="coach-overlay__close" onClick={onClose} aria-label="Закрыть">
            <CloseIcon size={18} />
          </button>
        </header>

        <div className="coach-overlay__actions">
          <button type="button" className="coach-overlay__action coach-overlay__action--primary" onClick={handleOpenChat}>
            <MailIcon size={18} /> Чат
          </button>
          <button type="button" className="coach-overlay__action" onClick={handleEditPlan}>
            <PenLineIcon size={18} /> Править план
          </button>
          <button type="button" className="coach-overlay__action" onClick={handleReschedule}>
            <ArrowLeftRightIcon size={18} /> Перенести
          </button>
          <button type="button" className="coach-overlay__action" onClick={handleApplyTemplate}>
            <ClipboardListIcon size={18} /> Шаблон
          </button>
        </div>

        <nav className="coach-overlay__tabs" aria-label="Разделы">
          {[
            ['overview', 'Обзор'],
            ['plan', 'План недели'],
            ['stats', 'Графики'],
            ['chat', `Чат · ${athlete.unread_count || 0}`],
          ].map(([k, l]) => (
            <button
              key={k}
              type="button"
              className={`coach-overlay__tab ${tab === k ? 'coach-overlay__tab--active' : ''}`}
              onClick={() => setTab(k)}
            >
              {l}
            </button>
          ))}
        </nav>

        <div className="coach-overlay__body">
          {tab === 'overview' && (
            <>
              <div className="coach-overlay__metrics">
                <Metric label="ВЫПОЛНЕНИЕ" value={compliance != null ? `${compliance}%` : '—'}
                  color={compliance == null ? 'var(--text-tertiary)' :
                    compliance >= 80 ? 'var(--success-500)' :
                    compliance >= 50 ? 'var(--warning-500)' : 'var(--danger-500)'}
                />
                <Metric label="ОБЪЁМ · 7 ДН" value={volume7d != null ? volume7d : '—'} suffix={volume7d != null ? 'км' : ''} />
                <Metric label="VDOT" value={athlete.vdot || '—'} delta={athlete.pace_trend} />
                <Metric label={days != null ? 'ДО ГОНКИ' : 'ЦЕЛЬ'} value={days != null ? days : '∞'} suffix={days != null ? 'дн.' : ''} />
              </div>

              <section className="coach-overlay__section">
                <div className="coach-overlay__section-label">СЕГОДНЯ ПО ПЛАНУ</div>
                {athlete.today_plan ? (
                  <div className="coach-overlay__today">
                    <span className="coach-overlay__today-stripe" style={{ background: WORKOUT_TYPE_COLOR[todayType] || 'var(--gray-400)' }} />
                    <div className="coach-overlay__today-info">
                      <div className="coach-overlay__today-name">{athlete.today_plan.label || athlete.today_plan.title}</div>
                      {(athlete.today_plan.distance || athlete.today_plan.pace) && (
                        <div className="coach-overlay__today-meta">
                          {athlete.today_plan.distance ? `${athlete.today_plan.distance} км` : ''}
                          {athlete.today_plan.pace ? ` · ${athlete.today_plan.pace}` : ''}
                        </div>
                      )}
                      {athlete.today_plan.description && (
                        <div className="coach-overlay__today-desc">{athlete.today_plan.description}</div>
                      )}
                    </div>
                    <button type="button" className="coach-overlay__today-btn" onClick={handleEditPlan}>Открыть</button>
                  </div>
                ) : (
                  <div className="coach-overlay__placeholder">План на сегодня не назначен</div>
                )}
              </section>

              <section className="coach-overlay__section">
                <div className="coach-overlay__section-label">ОБЪЁМ · ПОСЛЕДНИЕ 7 ДНЕЙ</div>
                <div className="coach-overlay__volume">
                  <span className="coach-overlay__volume-value">{volume7d != null ? volume7d : '—'}</span>
                  <span className="coach-overlay__volume-unit">{volume7d != null ? 'км' : ''}</span>
                  <span className="coach-overlay__volume-spacer" />
                  {Array.isArray(athlete.volume_spark) && athlete.volume_spark.length > 0 && (
                    <Sparkline data={athlete.volume_spark} labels={sparkLabels} w={180} h={48} color="var(--primary-500)" thick />
                  )}
                </div>
              </section>
            </>
          )}

          {tab === 'plan' && (
            <WeekPlanTab
              loading={detailsLoading}
              error={detailsError}
              weekStart={details?.week_start}
              days={details?.week_plan || []}
              onEditPlan={handleEditPlan}
            />
          )}
          {tab === 'stats' && (
            <ChartsTab
              loading={detailsLoading}
              error={detailsError}
              volumeWeeks={details?.volume_weeks || []}
              vdotHistory={details?.vdot_history || []}
            />
          )}
          {tab === 'chat' && (
            <ChatTab
              loading={detailsLoading}
              error={detailsError}
              notes={details?.recent_notes || []}
              athleteName={athlete.name || athlete.username}
              onOpenChat={handleOpenChat}
            />
          )}
        </div>
      </aside>
    </>
  );

  return createPortal(content, portalTarget);
}

/** Таб «План недели» — 7 карточек дней. */
function WeekPlanTab({ loading, error, weekStart, days, onEditPlan }) {
  if (loading) return <div className="coach-overlay__placeholder">Загрузка плана…</div>;
  if (error) return <div className="coach-overlay__placeholder">{error}</div>;
  if (!Array.isArray(days) || days.length === 0) {
    return <div className="coach-overlay__placeholder">План недели не назначен</div>;
  }

  const weekRange = weekStart ? formatWeekRange(weekStart) : '';
  const todayIso = new Date().toISOString().slice(0, 10);

  return (
    <div className="coach-overlay__week">
      <div className="coach-overlay__week-head">
        <div className="coach-overlay__section-label">НЕДЕЛЯ · {weekRange}</div>
        <button type="button" className="coach-overlay__week-edit" onClick={onEditPlan}>
          <PenLineIcon size={14} /> В календарь
        </button>
      </div>
      <div className="coach-overlay__week-list">
        {days.map((d) => {
          const isToday = d.date === todayIso;
          const stripe = d.type ? (WORKOUT_TYPE_COLOR[d.type] || 'var(--gray-400)') : 'var(--gray-200)';
          return (
            <div
              key={d.date}
              className={`coach-overlay__day ${isToday ? 'coach-overlay__day--today' : ''} ${d.completed ? 'coach-overlay__day--done' : ''}`}
            >
              <span className="coach-overlay__day-stripe" style={{ background: stripe }} />
              <div className="coach-overlay__day-dow">{DAY_OF_WEEK_LABEL[d.day_of_week] || ''}</div>
              <div className="coach-overlay__day-info">
                <div className="coach-overlay__day-name">
                  {d.label || '—'}
                  {d.is_key && <span className="coach-overlay__day-key" title="Ключевая тренировка">★</span>}
                </div>
                {d.description && (
                  <div className="coach-overlay__day-desc">{firstLine(d.description)}</div>
                )}
              </div>
              <div className="coach-overlay__day-meta">
                {d.completed ? (
                  <span className="coach-overlay__day-done">
                    {d.distance_done != null ? `${d.distance_done} км` : '✓'}
                    {d.pace_done ? ` · ${d.pace_done}` : ''}
                  </span>
                ) : (
                  <span className="coach-overlay__day-pending">·</span>
                )}
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}

/** Таб «Графики» — VDOT history + 8-week volume. */
function ChartsTab({ loading, error, volumeWeeks, vdotHistory }) {
  if (loading) return <div className="coach-overlay__placeholder">Загрузка графиков…</div>;
  if (error) return <div className="coach-overlay__placeholder">{error}</div>;

  const hasVolume = Array.isArray(volumeWeeks) && volumeWeeks.length >= 2;
  const hasVdot = Array.isArray(vdotHistory) && vdotHistory.length >= 2;

  if (!hasVolume && !hasVdot) {
    return <div className="coach-overlay__placeholder">Недостаточно данных для графиков</div>;
  }

  return (
    <div className="coach-overlay__charts">
      {hasVolume && (
        <section className="coach-overlay__chart-card">
          <div className="coach-overlay__section-label">ОБЪЁМ · 8 НЕДЕЛЬ</div>
          <div className="coach-overlay__chart-row">
            <span className="coach-overlay__chart-value">
              {Math.round(volumeWeeks.reduce((s, w) => s + Number(w.km || 0), 0))}
            </span>
            <span className="coach-overlay__chart-unit">км всего</span>
            <span style={{ flex: 1 }} />
            <Sparkline
              data={volumeWeeks.map((w) => Number(w.km || 0))}
              labels={volumeWeeks.map((w) => `нед. ${formatShortDate(w.week_start)}`)}
              w={240} h={56} color="var(--primary-500)" thick
            />
          </div>
        </section>
      )}
      {hasVdot && (
        <section className="coach-overlay__chart-card">
          <div className="coach-overlay__section-label">VDOT · ДИНАМИКА</div>
          <div className="coach-overlay__chart-row">
            <span className="coach-overlay__chart-value">
              {vdotHistory[vdotHistory.length - 1]?.vdot ?? '—'}
            </span>
            <span className="coach-overlay__chart-unit">текущий</span>
            <span style={{ flex: 1 }} />
            <Sparkline
              data={vdotHistory.map((v) => Number(v.vdot || 0))}
              labels={vdotHistory.map((v) => formatShortDate(v.date))}
              w={240} h={56} color="var(--success-500)" thick unit=""
            />
          </div>
        </section>
      )}
    </div>
  );
}

/** Таб «Чат» — последние заметки + CTA для перехода в полный чат. */
function ChatTab({ loading, error, notes, athleteName, onOpenChat }) {
  if (loading) return <div className="coach-overlay__placeholder">Загрузка…</div>;
  if (error) return <div className="coach-overlay__placeholder">{error}</div>;

  return (
    <div className="coach-overlay__chat">
      <button type="button" className="coach-overlay__chat-cta" onClick={onOpenChat}>
        <MailIcon size={16} /> Открыть полный чат с {athleteName}
      </button>
      {notes.length === 0 ? (
        <div className="coach-overlay__placeholder">Пока нет заметок к тренировкам</div>
      ) : (
        <div className="coach-overlay__notes">
          <div className="coach-overlay__section-label">ПОСЛЕДНИЕ ЗАМЕТКИ ПО ТРЕНИРОВКАМ</div>
          {notes.map((n) => (
            <div
              key={n.id}
              className={`coach-overlay__note ${n.author_is_coach ? 'coach-overlay__note--coach' : ''}`}
            >
              <div className="coach-overlay__note-head">
                <span className="coach-overlay__note-author">
                  {n.author_is_coach ? 'Вы' : (n.author_username || 'Атлет')}
                </span>
                <span className="coach-overlay__note-date">
                  {formatShortDate(n.date)} · {formatRelative(n.created_at)}
                </span>
              </div>
              <div className="coach-overlay__note-content">{n.content}</div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

function firstLine(text) {
  if (!text) return '';
  const line = String(text).split(/\r?\n/).find((s) => s.trim().length > 0) || '';
  return line.trim();
}

function formatShortDate(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
}

function formatWeekRange(startIso) {
  const start = new Date(startIso);
  if (Number.isNaN(start.getTime())) return '';
  const end = new Date(start);
  end.setDate(end.getDate() + 6);
  return `${start.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' })} – ${end.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' })}`;
}

function formatRelative(iso) {
  if (!iso) return '';
  const t = Date.parse(iso);
  if (Number.isNaN(t)) return '';
  const sec = Math.floor((Date.now() - t) / 1000);
  if (sec < 60) return 'только что';
  if (sec < 3600) return `${Math.floor(sec / 60)} мин назад`;
  if (sec < 86400) return `${Math.floor(sec / 3600)} ч назад`;
  return `${Math.floor(sec / 86400)} дн назад`;
}

function Metric({ label, value, suffix, color, delta }) {
  return (
    <div className="coach-overlay__metric">
      <div className="coach-overlay__metric-label">{label}</div>
      <div className="coach-overlay__metric-row">
        <span className="coach-overlay__metric-value" style={color ? { color } : undefined}>{value}</span>
        {suffix && <span className="coach-overlay__metric-suffix">{suffix}</span>}
        {delta && (
          <span
            className={`coach-overlay__metric-delta ${
              String(delta).startsWith('+') ? 'coach-overlay__metric-delta--up' :
              String(delta).startsWith('−') || String(delta).startsWith('-') ? 'coach-overlay__metric-delta--down' : ''
            }`}
          >
            {delta}
          </span>
        )}
      </div>
    </div>
  );
}
