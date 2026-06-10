/**
 * TrainersScreen — раздел «Тренеры»
 * role=user → заглушка + кнопка «Стать тренером»
 * role=coach → таб «Запросы» (ученики — в AthletesOverviewScreen)
 * role=admin → табы «Каталог» + «Мои ученики» + «Запросы»
 */

import { useState, useEffect, useCallback, useLayoutEffect, useRef } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import useAuthStore from '../stores/useAuthStore';
import { useSwipeableTabs } from '../hooks/useSwipeableTabs';
import { getAvatarSrc } from '../utils/avatarUrl';
import { getDisplayName, getInitials } from '../utils/displayName';
import { GraduationCapIcon, UsersIcon, MailIcon } from '../components/common/Icons';
import { CoachAvatar } from '../components/Coach/CoachPrimitives';
import FindTrainerV3 from './trainers/FindTrainerV3';
import CoachGroupsView from './coach/CoachGroupsView';
import './TrainersScreen.css';

const GOAL_LABELS = { race: 'Забег', health: 'Здоровье', weight_loss: 'Похудение', time_improvement: 'Улучшить время' };
const LEVEL_LABELS = { beginner: 'Новичок', intermediate: 'Средний', advanced: 'Опытный' };

function timeAgo(dateStr) {
  if (!dateStr) return '';
  const diff = Date.now() - new Date(dateStr.replace(' ', 'T')).getTime();
  const m = Math.floor(diff / 60000);
  if (m < 1) return 'только что';
  if (m < 60) return `${m} мин назад`;
  const h = Math.floor(m / 60);
  if (h < 24) return `${h} ч назад`;
  const d = Math.floor(h / 24);
  if (d === 1) return 'вчера';
  if (d < 7) return `${d} дн назад`;
  return new Date(dateStr.replace(' ', 'T')).toLocaleDateString('ru');
}

const SPEC_LABELS = {
  marathon: 'Марафон', half_marathon: 'Полумарафон', '5k_10k': '5/10 км',
  ultra: 'Ультра', trail: 'Трейл', beginner: 'Начинающие',
  injury_recovery: 'Травмы', nutrition: 'Питание', mental: 'Ментальные навыки',
};

export default function TrainersScreen() {
  const navigate = useNavigate();
  const { user, api } = useAuthStore();
  const role = user?.role || 'user';
  const isCoach = role === 'coach' || role === 'admin';
  const trainersTabsRef = useRef(null);
  const trainersPanelsRef = useRef(null);

  const [tab, setTab] = useState(role === 'admin' ? 'catalog' : 'groups');
  const [coaches, setCoaches] = useState([]);
  const [athletes, setAthletes] = useState([]);
  const [requests, setRequests] = useState([]);
  const [loading, setLoading] = useState(false);
  const [requestsCount, setRequestsCount] = useState(0);
  const [trainersTabPillStyle, setTrainersTabPillStyle] = useState({ left: 0, width: 0 });
  const visibleTabs = role === 'admin' ? ['catalog', 'athletes', 'groups', 'requests'] : ['groups', 'requests'];

  const loadCoaches = useCallback(async () => {
    if (!api) return;
    setLoading(true);
    try {
      const res = await api.listCoaches({ limit: 50 });
      setCoaches(res?.data?.coaches || res?.coaches || []);
    } catch (e) { console.error(e); }
    setLoading(false);
  }, [api]);

  const loadAthletes = useCallback(async () => {
    if (!api) return;
    setLoading(true);
    try {
      const res = await api.getCoachAthletes();
      setAthletes(res?.data?.athletes || res?.athletes || []);
    } catch (e) { console.error(e); }
    setLoading(false);
  }, [api]);

  const loadRequests = useCallback(async () => {
    if (!api) return;
    try {
      const res = await api.getCoachRequests({ status: 'pending' });
      const reqs = res?.data?.requests || res?.requests || [];
      setRequests(reqs);
      setRequestsCount(reqs.length);
    } catch (e) { console.error(e); }
  }, [api]);

  useEffect(() => {
    if (role === 'admin') {
      loadCoaches();
      loadAthletes();
      loadRequests();
    } else if (role === 'coach') {
      loadRequests();
    }
  }, [role, loadCoaches, loadAthletes, loadRequests]);

  const updateTrainersTabPill = useCallback(() => {
    const tabs = trainersTabsRef.current;
    if (!tabs) return;

    const activeButton = tabs.querySelector('.trainers-tab--active');
    if (!activeButton) {
      setTrainersTabPillStyle({ left: 0, width: 0 });
      return;
    }

    setTrainersTabPillStyle({
      left: activeButton.offsetLeft,
      width: activeButton.offsetWidth,
    });
  }, []);

  useLayoutEffect(() => {
    updateTrainersTabPill();
  }, [tab, updateTrainersTabPill]);

  useLayoutEffect(() => {
    if (!isCoach) return undefined;

    let frameId = 0;
    const scheduleUpdate = () => {
      cancelAnimationFrame(frameId);
      frameId = window.requestAnimationFrame(updateTrainersTabPill);
    };

    const tabs = trainersTabsRef.current;
    const resizeObserver = typeof ResizeObserver !== 'undefined' && tabs
      ? new ResizeObserver(scheduleUpdate)
      : null;

    if (tabs && resizeObserver) {
      resizeObserver.observe(tabs);
      tabs.querySelectorAll('.trainers-tab').forEach((item) => resizeObserver.observe(item));
    }

    window.addEventListener('resize', scheduleUpdate);
    if (document.fonts?.ready) {
      document.fonts.ready.then(scheduleUpdate).catch(() => {});
    }

    return () => {
      cancelAnimationFrame(frameId);
      window.removeEventListener('resize', scheduleUpdate);
      resizeObserver?.disconnect();
    };
  }, [isCoach, updateTrainersTabPill]);

  useSwipeableTabs({
    containerRef: trainersPanelsRef,
    tabs: visibleTabs,
    activeTab: tab,
    onTabChange: setTab,
    enabled: isCoach && visibleTabs.length > 1,
  });

  const handleAccept = async (requestId) => {
    try {
      await api.acceptCoachRequest(requestId);
      loadRequests();
    } catch (e) { alert(e.message); }
  };

  const handleReject = async (requestId) => {
    try {
      await api.rejectCoachRequest(requestId);
      loadRequests();
    } catch (e) { alert(e.message); }
  };

  // Обычный пользователь — каталог тренеров (v3)
  if (role === 'user') {
    return <FindTrainerV3 />;
  }

  // Coach / Admin
  return (
    <div className="trainers-screen">
      <h1 className="trainers-title">Тренеры</h1>

      {/* Табы */}
      <div
        ref={trainersTabsRef}
        className="trainers-tabs"
        style={{
          '--trainers-tabs-pill-left': `${trainersTabPillStyle.left}px`,
          '--trainers-tabs-pill-width': `${trainersTabPillStyle.width}px`,
        }}
      >
        <span className="trainers-tabs-pill" aria-hidden="true" />
        {role === 'admin' && (
          <button type="button" className={`trainers-tab ${tab === 'catalog' ? 'trainers-tab--active' : ''}`} onClick={() => { setTab('catalog'); loadCoaches(); }}>
            Каталог
          </button>
        )}
        {role === 'admin' && (
          <button type="button" className={`trainers-tab ${tab === 'athletes' ? 'trainers-tab--active' : ''}`} onClick={() => { setTab('athletes'); loadAthletes(); }}>
            Мои ученики
          </button>
        )}
        <button type="button" className={`trainers-tab ${tab === 'groups' ? 'trainers-tab--active' : ''}`} onClick={() => setTab('groups')}>
          Группы
        </button>
        <button type="button" className={`trainers-tab ${tab === 'requests' ? 'trainers-tab--active' : ''}`} onClick={() => { setTab('requests'); loadRequests(); }}>
          Запросы {requestsCount > 0 && <span className="trainers-badge">{requestsCount}</span>}
        </button>
      </div>

      <div ref={trainersPanelsRef} className="trainers-tab-panels">
      {loading && <div className="trainers-loading">Загрузка...</div>}

      {/* Каталог тренеров (admin) */}
      {tab === 'catalog' && !loading && (
        <div className="trainers-list trainers-list--catalog">
          {coaches.length === 0 && (
            <div className="trainers-empty">
              <GraduationCapIcon size={48} className="trainers-empty-icon" />
              <p>Тренеры пока не добавлены</p>
            </div>
          )}
          {coaches.map(c => (
            <Link key={c.id} to={`/${c.username_slug}`} className="coach-card card card--interactive">
              <div className="coach-card-avatar">
                {c.avatar_path ? <img src={getAvatarSrc(c.avatar_path, api?.baseUrl || '/api', 'md')} alt="" /> : <div className="coach-card-avatar-placeholder">{getInitials(c)}</div>}
              </div>
              <div className="coach-card-info">
                <div className="coach-card-name">{getDisplayName(c)}</div>
                {c.coach_bio && <div className="coach-card-bio">{c.coach_bio}</div>}
                <div className="coach-card-specs">
                  {(c.coach_specialization || []).map(s => (
                    <span key={s} className="coach-spec-tag">{SPEC_LABELS[s] || s}</span>
                  ))}
                </div>
                {c.coach_prices_on_request ? (
                  <span className="coach-card-price">Цены по запросу</span>
                ) : c.pricing?.length > 0 ? (
                  <span className="coach-card-price">от {Math.min(...c.pricing.filter(p => p.price).map(p => p.price))} руб.</span>
                ) : null}
              </div>
            </Link>
          ))}
        </div>
      )}

      {/* Мои ученики */}
      {tab === 'athletes' && !loading && (
        <div className="trainers-list">
          {athletes.length === 0 && (
            <div className="trainers-empty">
              <UsersIcon size={48} className="trainers-empty-icon" />
              <p>У вас пока нет учеников</p>
            </div>
          )}
          {athletes.map(a => (
            <div key={a.id} className="athlete-card card card--interactive">
              <div className="athlete-card-avatar">
                {a.avatar_path ? <img src={getAvatarSrc(a.avatar_path, api?.baseUrl || '/api', 'sm')} alt="" /> : <div className="coach-card-avatar-placeholder">{getInitials(a)}</div>}
              </div>
              <div className="athlete-card-info">
                <Link to={`/${a.username_slug}`} className="athlete-card-name">{getDisplayName(a)}</Link>
                {a.last_activity && <div className="athlete-card-activity">Был: {new Date(a.last_activity).toLocaleDateString('ru')}</div>}
              </div>
              <button className="btn btn-primary btn-sm" onClick={() => navigate(`/calendar?athlete=${a.username_slug}`)}>Календарь</button>
            </div>
          ))}
        </div>
      )}

      {/* Группы */}
      {tab === 'groups' && <CoachGroupsView />}

      {/* Запросы — v3 карточки заявок */}
      {tab === 'requests' && !loading && (
        <div className="treq-list">
          {requests.length === 0 && (
            <div className="trainers-empty">
              <MailIcon size={48} className="trainers-empty-icon" />
              <p>Нет новых запросов</p>
            </div>
          )}
          {requests.map(r => {
            const goal = r.goal_type === 'race' && r.race_distance
              ? `${GOAL_LABELS.race} · ${r.race_distance}${r.race_target_time ? ' ' + r.race_target_time : ''}`
              : (GOAL_LABELS[r.goal_type] || null);
            const level = LEVEL_LABELS[r.experience_level];
            return (
              <div key={r.id} className="treq-card">
                <div className="treq-top">
                  <CoachAvatar athlete={r} size={44} apiBaseUrl={api?.baseUrl || '/api'} />
                  <div className="treq-main">
                    <div className="treq-name-row">
                      <Link to={`/${r.username_slug}`} className="treq-name">{getDisplayName(r)}</Link>
                      <span className="treq-time">{timeAgo(r.created_at)}</span>
                    </div>
                    {level && <div className="treq-meta">{level}</div>}
                    {goal && <div className="treq-goal">🎯 {goal}</div>}
                  </div>
                </div>
                {r.message && <p className="treq-msg">«{r.message}»</p>}
                <div className="treq-actions">
                  <button type="button" className="treq-btn treq-btn--ghost" onClick={() => handleReject(r.id)}>Отклонить</button>
                  <button type="button" className="treq-btn treq-btn--primary" onClick={() => handleAccept(r.id)}>✓ Принять</button>
                </div>
              </div>
            );
          })}
        </div>
      )}
      </div>
    </div>
  );
}
