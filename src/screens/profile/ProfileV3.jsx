/**
 * ProfileV3 — публичная страница профиля (атлет + тренер), дизайн из redesign/v3-profiles.
 * Карточки прототипа 1-в-1, данные — реальные (деривация теми же утилитами, что и StatsV3:
 * processOverviewV3 / processTrendsV3 / computeAchievements / records / прогноз).
 */

import { cloneElement, useMemo } from 'react';
import { Link } from 'react-router-dom';
import { getAvatarSrc } from '../../utils/avatarUrl';
import { getDisplayName, getInitials } from '../../utils/displayName';
import { SettingsIcon, MessageCircleIcon } from '../../components/common/Icons';
import TrendsSmallV3 from '../../components/Dashboard/v3/TrendsSmallV3';
import { processOverviewV3, processTrendsV3 } from '../../components/Stats/statsV3Utils';
import { computeAchievements } from '../../components/Stats/statsAchievements';
import { RecentList } from '../../components/Stats/v3/blocks';
import WeekSectionV3 from '../../components/Dashboard/v3/WeekSectionV3';
import '../../components/Stats/v3/StatsV3.css';
import './ProfileV3.css';

const SPEC_LABELS = {
  marathon: 'Марафон', half_marathon: 'Полумарафон', '5k_10k': '5К / 10К', ultra: 'Ультра',
  trail: 'Трейл', beginner: 'Новичкам', injury_recovery: 'Восстановление', nutrition: 'Питание', mental: 'Ментальная подготовка',
};
const LEVEL_LABELS = { novice: 'Новичок', beginner: 'Начинающий', intermediate: 'Средний', advanced: 'Продвинутый', expert: 'Опытный' };
const RACE_LABELS = { '5k': '5 км', '10k': '10 км', half: 'Полумарафон', marathon: 'Марафон' };
const HOW_STEPS = [
  { n: '1', t: 'Заявка и созвон', d: 'Бесплатно обсудим цель, опыт и график' },
  { n: '2', t: 'Персональный план', d: 'Соберу план под твою гонку с учётом нагрузки и жизни' },
  { n: '3', t: 'Ведение каждый день', d: 'Анализирую тренировки, корректирую, отвечаю в чате' },
];

function daysUntil(dateStr) {
  if (!dateStr) return null;
  const d = new Date(dateStr + 'T00:00:00');
  if (Number.isNaN(d.getTime())) return null;
  const t = new Date(); t.setHours(0, 0, 0, 0);
  return Math.ceil((d - t) / 86400000);
}
function fmtTime(sec) {
  const s = Number(sec);
  if (!s || s <= 0) return '—';
  const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), ss = Math.round(s % 60);
  return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${String(ss).padStart(2, '0')}` : `${m}:${String(ss).padStart(2, '0')}`;
}
function Avatar({ user, api, size }) {
  const src = user?.avatar_path ? getAvatarSrc(user.avatar_path, api?.baseUrl || '/api', size > 60 ? 'md' : 'sm') : null;
  const style = { width: size, height: size };
  return src ? <img src={src} alt="" className="pv3-avatar" style={style} /> : <div className="pv3-avatar" style={style}>{getInitials(user)}</div>;
}
function Stat({ v, l }) { return <div><div className="pv3-stat-v">{v}</div><div className="pv3-stat-l">{l}</div></div>; }

export default function ProfileV3({
  api, currentUser, profileUser, access, coaches = [],
  profilePlan, progressDataMap, weekProgress, workoutsList = [], records,
  showTrainer, showCalendar, showMetrics, showWorkouts, goalText,
  onSettings, onMessage, onRequestCoach, requestingCoach, coachRequested, coachRequestError, onGuestAction,
  onWorkoutClick,
}) {
  const isOwner = !!access?.is_owner;
  const canView = !!access?.can_view;
  const isCoachProfile = (profileUser.role === 'coach' || profileUser.role === 'admin') && profileUser.coach_bio;
  const isAi = profileUser.training_mode === 'ai';
  const level = LEVEL_LABELS[profileUser.experience_level];
  const isRaceGoal = profileUser.goal_type === 'race' || profileUser.goal_type === 'time_improvement';
  const goalDays = isRaceGoal ? daysUntil(profileUser.race_date) : null;
  const raceLabel = profileUser.race_distance ? (RACE_LABELS[profileUser.race_distance] || profileUser.race_distance) : '';
  const specs = useMemo(() => { try { return JSON.parse(profileUser.coach_specialization || '[]'); } catch { return []; } }, [profileUser.coach_specialization]);

  // ── Деривации (реальные данные), только если метрики видны ──
  const metricsOk = canView && showMetrics;
  const year = useMemo(() => (metricsOk && workoutsList.length ? processOverviewV3(workoutsList, profilePlan, 'year', 'run') : null), [metricsOk, workoutsList, profilePlan]);
  const trends = useMemo(() => {
    if (!metricsOk || workoutsList.length < 3) return null;
    const r = processTrendsV3(workoutsList, 'run', 12);
    return Array.isArray(r) ? { cards: r } : r;
  }, [metricsOk, workoutsList]);
  const vdot = trends?.cards?.find((c) => c.key === 'vdot')?.value || null;
  const workoutsByDate = useMemo(() => {
    const m = {};
    workoutsList.forEach((w) => {
      const d = w.date || (w.start_time ? w.start_time.split('T')[0] : null);
      if (d) (m[d] = m[d] || []).push(w);
    });
    return m;
  }, [workoutsList]);
  const ach = useMemo(() => (metricsOk && workoutsList.length ? computeAchievements({ workoutsList, vdot: Number(vdot) || null, records }) : null), [metricsOk, workoutsList, vdot, records]);
  const earnedBadges = ach ? ach.categories.flatMap((c) => c.badges).filter((b) => b.got) : [];
  const recent = useMemo(() => {
    if (!canView || !showWorkouts || workoutsList.length === 0) return [];
    const ov = processOverviewV3(workoutsList, profilePlan, 'year', 'run');
    return Array.isArray(ov?.recent) ? ov.recent : [];
  }, [canView, showWorkouts, workoutsList, profilePlan]);
  const prs = [['5 КМ', records?.['5k']], ['10 КМ', records?.['10k']], ['ПОЛУ', records?.half]];

  const Hero = (
    <div className="pv3-hero">
      <div className={`pv3-cover ${isCoachProfile ? '' : 'pv3-cover--athlete'}`} />
      <div className="pv3-hero-body">
        <div className="pv3-hero-top">
          <div className="pv3-avatar-ring">
            <Avatar user={profileUser} api={api} size={84} />
            {isCoachProfile && profileUser.coach_accepts ? <span className="pv3-online-dot" /> : null}
          </div>
          <div className="pv3-hero-actions">
            {isOwner ? (
              <button type="button" className="pv3-primary-btn" onClick={onSettings}><SettingsIcon size={16} aria-hidden /> Настройки</button>
            ) : profileUser?.id !== currentUser?.id ? (
              <button type="button" className="pv3-ghost-btn" onClick={onMessage}><MessageCircleIcon size={16} aria-hidden /> Написать</button>
            ) : null}
          </div>
        </div>
        <div className="pv3-name-row">
          <h1 className="pv3-name">{getDisplayName(profileUser)}</h1>
          {isCoachProfile && <span className="pv3-verified">✓</span>}
          {!isCoachProfile && level && <span className="pv3-level">{level}</span>}
        </div>
        <div className="pv3-meta">@{profileUser.username_slug || profileUser.username}{goalText ? ` · ${goalText}` : ''}</div>

        {isCoachProfile ? (
          <div className="pv3-stats">
            {profileUser.coach_experience_years ? <Stat v={`${profileUser.coach_experience_years} лет`} l="опыта" /> : null}
            {specs.length > 0 ? <Stat v={specs.length} l="направлений" /> : null}
          </div>
        ) : year && year.totalDistance > 0 ? (
          <div className="pv3-stats">
            <Stat v={Math.round(year.totalDistance)} l="км · за год" />
            <Stat v={year.totalWorkouts} l="трен. · за год" />
            {vdot ? <Stat v={vdot} l="VDOT · форма" /> : null}
            {year.deltaPct != null ? <Stat v={`${year.deltaPct > 0 ? '+' : ''}${year.deltaPct}%`} l="к прошл. году" /> : null}
          </div>
        ) : weekProgress?.total > 0 ? (
          <div className="pv3-stats"><Stat v={`${weekProgress.completed}/${weekProgress.total}`} l="эта неделя" /></div>
        ) : null}

        {isCoachProfile && profileUser.coach_accepts ? <div className="pv3-slots">🟢 Берёт учеников</div> : null}
      </div>
    </div>
  );

  const Goal = (!isCoachProfile && goalDays != null && goalDays >= 0) ? (
    <div className="pv3-goal">
      <div className="pv3-goal-eyebrow">ГЛАВНАЯ ЦЕЛЬ</div>
      <div className="pv3-goal-row">
        <div>
          <div className="pv3-goal-title">{raceLabel || 'Забег'}</div>
          <div className="pv3-goal-date">{new Date(profileUser.race_date + 'T00:00:00').toLocaleDateString('ru-RU', { day: 'numeric', month: 'short', year: 'numeric' })}</div>
        </div>
        <div><div className="pv3-goal-days">{goalDays}</div><div className="pv3-goal-days-l">ДНЕЙ</div></div>
      </div>
      {profileUser.race_target_time && (
        <div className="pv3-goal-split"><div><div className="pv3-goal-k">ЦЕЛЬ</div><div className="pv3-goal-v pv3-goal-v--good">{profileUser.race_target_time}</div></div></div>
      )}
    </div>
  ) : null;

  const Records = (!isCoachProfile && metricsOk && prs.some(([, r]) => r && r.time_sec > 0)) ? (
    <div className="pv3-card">
      <div className="pv3-card-lbl">ЛИЧНЫЕ РЕКОРДЫ</div>
      <div className="pv3-pr-grid">
        {prs.map(([label, r]) => {
          const has = r && r.time_sec > 0;
          const fresh = has && r.date && (Date.now() - new Date(r.date + 'T00:00:00').getTime()) < 14 * 86400000;
          return (
            <div key={label} className={`pv3-pr ${fresh ? 'pv3-pr--fresh' : ''}`}>
              {fresh && <span className="pv3-pr-star">★</span>}
              <div className="pv3-pr-d">{label}</div>
              <div className="pv3-pr-t">{has ? fmtTime(r.time_sec) : '—'}</div>
            </div>
          );
        })}
      </div>
    </div>
  ) : null;

  const Form = (!isCoachProfile && metricsOk && workoutsList.length >= 2) ? (
    <TrendsSmallV3 workoutsByDate={workoutsByDate} />
  ) : null;

  const Week = (!isCoachProfile && showCalendar && profilePlan) ? (
    <div className="pv3-card">
      <WeekSectionV3 plan={profilePlan} workoutsByDate={workoutsByDate} progressDataMap={progressDataMap} compact />
    </div>
  ) : null;

  const Recent = (!isCoachProfile && showWorkouts && recent.length > 0) ? (
    <div className="pv3-card pv3-recent-card">
      <RecentList recent={recent} onWorkoutClick={onWorkoutClick} />
    </div>
  ) : null;

  const Badges = (!isCoachProfile && earnedBadges.length > 0) ? (
    <div className="pv3-card">
      <div className="pv3-card-head">
        <div className="pv3-card-lbl">ДОСТИЖЕНИЯ</div>
        <div className="pv3-spacer" />
        {ach?.totalPoints != null && <span className="pv3-badges-pts">{ach.totalPoints} очков</span>}
      </div>
      <div className="pv3-badges">
        {earnedBadges.map((b, i) => <div key={i} className="pv3-hex" title={b.title}>{b.ic}</div>)}
      </div>
    </div>
  ) : null;

  const Trainer = (!isCoachProfile && canView && showTrainer && (isAi || coaches.length > 0)) ? (
    <div className="pv3-card">
      <div className="pv3-card-lbl">{coaches.length > 1 ? 'ТРЕНЕРЫ' : 'ТРЕНЕР'}</div>
      {isAi && (
        <div className="pv3-trainer-row">
          <div className="pv3-ai-badge">AI</div>
          <div style={{ flex: 1 }}><div className="pv3-trainer-name">planRUN AI</div><div className="pv3-trainer-sub">Персональный AI-тренер</div></div>
        </div>
      )}
      {coaches.map((coach) => (
        <Link key={coach.id} to={`/${coach.username_slug}`} className="pv3-trainer-row pv3-coach-link">
          <Avatar user={coach} api={api} size={40} />
          <div style={{ flex: 1 }}><div className="pv3-trainer-name">{getDisplayName(coach)}</div><div className="pv3-trainer-sub">Тренер</div></div>
        </Link>
      ))}
    </div>
  ) : null;

  // ── Coach selling cards ──
  const About = isCoachProfile ? (
    <div className="pv3-card">
      <div className="pv3-card-lbl">О ТРЕНЕРЕ</div>
      <p className="pv3-bio">{profileUser.coach_bio}</p>
      {profileUser.coach_philosophy && (
        <div className="pv3-approach"><div className="pv3-approach-lbl">ПОДХОД</div><p>{profileUser.coach_philosophy}</p></div>
      )}
      {specs.length > 0 && (<><div className="pv3-sublbl">Специализация</div><div className="pv3-specs">{specs.map((s) => <span key={s} className="pv3-spec-tag">{SPEC_LABELS[s] || s}</span>)}</div></>)}
      {profileUser.coach_experience_years && (<><div className="pv3-sublbl">Квалификация</div><div className="pv3-cred"><span className="pv3-cred-tick">✓</span>{profileUser.coach_experience_years} лет опыта</div></>)}
    </div>
  ) : null;

  const Pricing = (isCoachProfile && Array.isArray(profileUser.pricing) && profileUser.pricing.length > 0 && !profileUser.coach_prices_on_request) ? (
    <div className="pv3-card">
      <div className="pv3-card-lbl">ТАРИФЫ</div>
      <div style={{ marginTop: 8 }}>
        {profileUser.pricing.map((p) => (
          <div key={p.id} className="pv3-price-item">
            <span className="pv3-price-label">{p.label}</span>
            <span className="pv3-price-value">
              {p.price ? `${Number(p.price).toLocaleString('ru')} ${(p.currency === 'RUB' || !p.currency) ? '₽' : p.currency}` : 'Бесплатно'}
              {p.period === 'month' ? '/мес' : p.period === 'week' ? '/нед' : ''}
            </span>
          </div>
        ))}
      </div>
    </div>
  ) : null;

  const How = isCoachProfile ? (
    <div className="pv3-card">
      <div className="pv3-card-lbl">КАК МЫ БУДЕМ РАБОТАТЬ</div>
      {HOW_STEPS.map((s) => (
        <div key={s.n} className="pv3-how-step"><div className="pv3-how-n">{s.n}</div><div style={{ flex: 1 }}><div className="pv3-how-t">{s.t}</div><div className="pv3-how-d">{s.d}</div></div></div>
      ))}
    </div>
  ) : null;

  const RequestCta = (isCoachProfile && !isOwner && profileUser.coach_accepts) ? (
    <div className="pv3-card">
      <div style={{ fontSize: 13, color: 'var(--text-secondary)', lineHeight: 1.45, textAlign: 'center' }}>
        Первый созвон — <b style={{ color: 'var(--text-primary)' }}>бесплатно</b>. Обсудим цель и поймём, подходим ли друг другу.
      </div>
      {coachRequested ? (
        <div className="pv3-coach-badge" style={{ marginTop: 12, textAlign: 'center' }}>Запрос отправлен</div>
      ) : currentUser ? (
        <>
          <button type="button" className="pv3-primary-btn pv3-btn-full" style={{ marginTop: 14 }} onClick={onRequestCoach} disabled={requestingCoach}>{requestingCoach ? 'Отправка…' : 'Запросить тренера'}</button>
          {coachRequestError && <div style={{ fontSize: 12, color: 'var(--danger-500)', marginTop: 8, textAlign: 'center' }}>{coachRequestError}</div>}
        </>
      ) : (
        <button type="button" className="pv3-primary-btn pv3-btn-full" style={{ marginTop: 14 }} onClick={onGuestAction}>Запросить тренера</button>
      )}
    </div>
  ) : null;

  const CoachBadge = access?.is_coach ? (
    <div className="pv3-card">
      <div className="pv3-coach-badge">Вы тренер этого спортсмена</div>
      {profileUser.username_slug && <Link to={`/calendar?athlete=${profileUser.username_slug}`} className="pv3-primary-btn pv3-btn-full" style={{ marginTop: 10 }}>Открыть календарь</Link>}
    </div>
  ) : null;

  const accessDenied = !canView ? (
    <div className="pv3-card">
      <div className="pv3-card-lbl">ДОСТУП ОГРАНИЧЕН</div>
      <p className="pv3-bio">{profileUser.privacy_level === 'private' ? 'Этот профиль доступен только тренерам и владельцу.' : 'Для доступа нужна специальная ссылка с токеном.'}</p>
    </div>
  ) : null;

  const main = isCoachProfile
    ? [About, How, Pricing]
    : [Goal, Records, Form, Recent, accessDenied];
  const side = isCoachProfile
    ? [RequestCta, CoachBadge]
    : [Trainer, Week, Badges, CoachBadge];

  return (
    <div className="pv3">
      <div className="pv3-body">
        <div className="pv3-hero-cell">{Hero}</div>
        <div className="pv3-main">{main.filter(Boolean).map((el, i) => cloneElement(el, { key: `m${i}` }))}</div>
        <div className="pv3-side">{side.filter(Boolean).map((el, i) => cloneElement(el, { key: `s${i}` }))}</div>
      </div>
    </div>
  );
}
