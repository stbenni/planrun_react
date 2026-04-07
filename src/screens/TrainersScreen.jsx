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
import { GraduationCapIcon, UsersIcon, MailIcon } from '../components/common/Icons';
import './TrainersScreen.css';

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

  const [tab, setTab] = useState(isCoach ? 'requests' : 'catalog');
  const [coaches, setCoaches] = useState([]);
  const [athletes, setAthletes] = useState([]);
  const [requests, setRequests] = useState([]);
  const [loading, setLoading] = useState(false);
  const [requestsCount, setRequestsCount] = useState(0);
  const [trainersTabPillStyle, setTrainersTabPillStyle] = useState({ left: 0, width: 0 });
  const visibleTabs = role === 'admin' ? ['catalog', 'athletes', 'requests'] : ['requests'];

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

  // Обычный пользователь — hero-блок
  if (role === 'user') {
    return (
      <div className="trainers-screen">
        <h1 className="trainers-title">Тренеры</h1>
        <div className="trainers-placeholder">
          <GraduationCapIcon size={64} strokeWidth={1.5} className="trainers-placeholder-icon" />
          <h2 className="trainers-placeholder-title">Персональные тренеры</h2>
          <p className="trainers-placeholder-text">
            Раздел в разработке. Здесь будет каталог тренеров и возможность выбрать персонального тренера для ваших тренировок.
          </p>
          <button className="btn btn-primary trainers-apply-btn" onClick={() => navigate('/trainers/apply')}>
            Хотите стать тренером? Подать заявку
          </button>
        </div>
      </div>
    );
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
                {c.avatar_path ? <img src={getAvatarSrc(c.avatar_path, api?.baseUrl || '/api', 'md')} alt="" /> : <div className="coach-card-avatar-placeholder">{(c.username || '?')[0]}</div>}
              </div>
              <div className="coach-card-info">
                <div className="coach-card-name">{c.username}</div>
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
                {a.avatar_path ? <img src={getAvatarSrc(a.avatar_path, api?.baseUrl || '/api', 'sm')} alt="" /> : <div className="coach-card-avatar-placeholder">{(a.username || '?')[0]}</div>}
              </div>
              <div className="athlete-card-info">
                <Link to={`/${a.username_slug}`} className="athlete-card-name">{a.username}</Link>
                {a.last_activity && <div className="athlete-card-activity">Был: {new Date(a.last_activity).toLocaleDateString('ru')}</div>}
              </div>
              <button className="btn btn-primary btn-sm" onClick={() => navigate(`/calendar?athlete=${a.username_slug}`)}>Календарь</button>
            </div>
          ))}
        </div>
      )}

      {/* Запросы */}
      {tab === 'requests' && !loading && (
        <div className="trainers-list">
          {requests.length === 0 && (
            <div className="trainers-empty">
              <MailIcon size={48} className="trainers-empty-icon" />
              <p>Нет новых запросов</p>
            </div>
          )}
          {requests.map(r => (
            <div key={r.id} className="request-card card card--interactive">
              <div className="request-card-avatar">
                {r.avatar_path ? <img src={getAvatarSrc(r.avatar_path, api?.baseUrl || '/api', 'sm')} alt="" /> : <div className="coach-card-avatar-placeholder">{(r.username || '?')[0]}</div>}
              </div>
              <div className="request-card-info">
                <Link to={`/${r.username_slug}`} className="request-card-name">{r.username}</Link>
                {r.message && <div className="request-card-message">{r.message}</div>}
                <div className="request-card-date">{new Date(r.created_at).toLocaleDateString('ru')}</div>
              </div>
              <div className="request-card-actions">
                <button className="btn btn-primary btn-sm" onClick={() => handleAccept(r.id)}>Принять</button>
                <button className="btn btn-secondary btn-sm" onClick={() => handleReject(r.id)}>Отклонить</button>
              </div>
            </div>
          ))}
        </div>
      )}
      </div>
    </div>
  );
}
