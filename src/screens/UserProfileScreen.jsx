/**
 * UserProfileScreen - Экран профиля пользователя
 * Отображает профиль пользователя и его календарь
 */

import React, { useState, useEffect, useCallback } from 'react';
import { useParams, useLocation, Link, useNavigate } from 'react-router-dom';
import useAuthStore from '../stores/useAuthStore';
import useWorkoutRefreshStore from '../stores/useWorkoutRefreshStore';
import { getAvatarSrc } from '../utils/avatarUrl';
import PublicHeader from '../components/common/PublicHeader';
import TopHeader from '../components/common/TopHeader';
import BottomNav from '../components/common/BottomNav';
import Notifications from '../components/common/Notifications';
import LoginModal from '../components/LoginModal';
import RegisterModal from '../components/RegisterModal';
import DashboardWeekStrip from '../components/Dashboard/DashboardWeekStrip';
import DashboardStatsWidget from '../components/Dashboard/DashboardStatsWidget';
import ProfileQuickMetricsWidget from '../components/Dashboard/ProfileQuickMetricsWidget';
import DayModal from '../components/Calendar/DayModal';
import { RecentWorkoutsList, WorkoutDetailsModal } from '../components/Stats';
import { processStatsData } from '../components/Stats/StatsUtils';
import { TargetIcon, SettingsIcon, GraduationCapIcon, MessageCircleIcon, BotIcon } from '../components/common/Icons';
import '../components/Dashboard/Dashboard.css';
import '../components/common/PageTransition.css';
import './StatsScreen.css';
import './UserProfileScreen.css';

const GOAL_TYPE_LABELS = {
  health: 'Здоровье',
  race: 'Забег',
  weight_loss: 'Снижение веса',
  time_improvement: 'Улучшить время',
};

const RACE_DISTANCE_LABELS = {
  '5k': '5 км',
  '10k': '10 км',
  half: 'Полумарафон',
  marathon: 'Марафон',
};

function formatGoalText(user) {
  if (!user?.goal_type) return null;
  const goalLabel = GOAL_TYPE_LABELS[user.goal_type] || user.goal_type;
  if (user.goal_type === 'race' && user.race_distance) {
    const distLabel = RACE_DISTANCE_LABELS[user.race_distance] || user.race_distance;
    const dateStr = user.race_date ? new Date(user.race_date + 'T00:00:00').toLocaleDateString('ru-RU', { day: 'numeric', month: 'short', year: 'numeric' }) : '';
    const timeStr = user.race_target_time ? `, ${user.race_target_time}` : '';
    return `${goalLabel}: ${distLabel}${dateStr ? `, ${dateStr}` : ''}${timeStr}`;
  }
  if (user.goal_type === 'time_improvement' && (user.target_marathon_date || user.race_date)) {
    const dateStr = (user.target_marathon_date || user.race_date) ? new Date((user.target_marathon_date || user.race_date) + 'T00:00:00').toLocaleDateString('ru-RU', { day: 'numeric', month: 'short', year: 'numeric' }) : '';
    return `${goalLabel}${dateStr ? `, ${dateStr}` : ''}`;
  }
  return goalLabel;
}

const UserProfileScreen = () => {
  const { username } = useParams();
  const location = useLocation();
  const { api, user: currentUser, updateUser } = useAuthStore();
  const navigate = useNavigate();
  const token = React.useMemo(() => {
    const params = new URLSearchParams(location.search);
    return params.get('token');
  }, [location.search]);
  const [profileUser, setProfileUser] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [access, setAccess] = useState({ can_edit: false, can_view: false, is_owner: false });
  const [coaches, setCoaches] = useState([]);
  const [profileStats, setProfileStats] = useState(null);
  const [profilePlan, setProfilePlan] = useState(null);
  const [progressDataMap, setProgressDataMap] = useState({});
  const [weekProgress, setWeekProgress] = useState({ completed: 0, total: 0 });
  const [statsLoading, setStatsLoading] = useState(false);
  const [workoutModal, setWorkoutModal] = useState({ isOpen: false, date: null, dayData: null, loading: false, selectedWorkoutId: null });
  const [dayModal, setDayModal] = useState({ isOpen: false, date: null, week: null, day: null });
  const [registerModalOpen, setRegisterModalOpen] = useState(false);
  const [loginModalOpen, setLoginModalOpen] = useState(false);

  useEffect(() => {
    const loadUserProfile = async () => {
      if (!username) {
        setLoading(false);
        return;
      }

      let currentApi = api;
      if (!currentApi) {
        currentApi = useAuthStore.getState().api;
      }

      if (!currentApi) {
        let attempts = 0;
        const maxAttempts = 50;
        
        const checkInterval = setInterval(() => {
          attempts++;
          const storeApi = useAuthStore.getState().api;
          if (storeApi) {
            clearInterval(checkInterval);
            loadUserProfile();
          } else if (attempts >= maxAttempts) {
            clearInterval(checkInterval);
            setError('API не инициализирован. Попробуйте обновить страницу.');
            setLoading(false);
          }
        }, 100);
        
        return;
      }

      try {
        const slug = username.startsWith('@') ? username.slice(1) : username;
        const response = await currentApi.getUserBySlug(slug, token);
        
        if (response?.success && response?.data) {
          setProfileUser(response.data.user);
          setAccess(response.data.access || {});
          setCoaches(response.data.coaches || []);
        } else if (response?.user) {
          setProfileUser(response.user);
          setAccess(response.access || {});
          setCoaches(response.coaches || []);
        } else {
          const errorMsg = response?.error || response?.message || 'Пользователь не найден';
          setError(errorMsg);
        }
      } catch (err) {
        setError('Ошибка загрузки профиля: ' + (err.message || 'Неизвестная ошибка'));
      } finally {
        setLoading(false);
      }
    };

    loadUserProfile();
  }, [api, username, token]);

  const viewContext = React.useMemo(() => {
    if (!profileUser?.username_slug) return null;
    return { slug: profileUser.username_slug, token: token || undefined };
  }, [profileUser?.username_slug, token]);

  const loadProfileStats = useCallback(async (options = {}) => {
    const silent = options.silent === true;
    if (!profileUser || !api || !access.can_view) return;
    const showCal = access.is_owner || (profileUser.privacy_show_calendar !== 0 && profileUser.privacy_show_calendar !== '0');
    const showMet = access.is_owner || (profileUser.privacy_show_metrics !== 0 && profileUser.privacy_show_metrics !== '0');
    const showWk = access.is_owner || (profileUser.privacy_show_workouts !== 0 && profileUser.privacy_show_workouts !== '0');
    if (!showCal && !showMet && !showWk) return;

    if (!silent) setStatsLoading(true);
    try {
      let workoutsData = { workouts: {} };
      let workoutsList = [];
      let allResults = { results: [] };
      let plan = null;
      const vc = viewContext || undefined;
      try {
        const [w, listRes] = await Promise.all([
          api.getAllWorkoutsSummary(vc),
          api.getAllWorkoutsList(vc, 500)
        ]);
        if (w && typeof w === 'object') {
          const raw = w.data ?? w;
          workoutsData = raw?.workouts != null ? { workouts: raw.workouts } : { workouts: typeof raw === 'object' && !Array.isArray(raw) ? raw : {} };
        }
        if (listRes && typeof listRes === 'object') {
          const raw = listRes.data ?? listRes;
          workoutsList = Array.isArray(raw?.workouts) ? raw.workouts : [];
        }
      } catch (e) { /* ignore */ }
      try {
        const r = await api.getAllResults(vc);
        if (r && typeof r === 'object') {
          const raw = r.data ?? r;
          const list = Array.isArray(raw) ? raw : raw?.results;
          allResults = { results: Array.isArray(list) ? list : [] };
        }
      } catch (e) { /* ignore */ }
      try {
        plan = await api.getPlan(null, vc);
        const raw = plan?.data ?? plan;
        plan = raw?.weeks_data ? raw : (typeof raw === 'object' && !Array.isArray(raw) ? raw : null);
      } catch (e) { /* ignore */ }

      const processed = processStatsData(workoutsData, allResults, plan, 'month', workoutsList);
      setProfileStats(processed);
      setProfilePlan(plan);

      const progressMap = {};
      (allResults?.results || []).forEach((r) => {
        if (r.training_date) progressMap[r.training_date] = true;
      });
      Object.entries(workoutsData?.workouts || {}).forEach(([date, d]) => {
        if (d && (d.distance || d.duration || d.duration_seconds)) progressMap[date] = true;
      });
      setProgressDataMap(progressMap);

      const today = new Date();
      const todayStr = today.toISOString().split('T')[0];
      const weeksData = plan?.weeks_data;
      let wp = { completed: 0, total: 0 };
      if (weeksData && Array.isArray(weeksData)) {
        const addDays = (dateStr, days) => {
          const [y, m, d] = dateStr.split('-').map(Number);
          const d2 = new Date(Date.UTC(y, m - 1, d + days));
          return d2.toISOString().split('T')[0];
        };
        const getDayItems = (dayData) => {
          if (!dayData) return [];
          const arr = Array.isArray(dayData) ? dayData : [dayData];
          return arr.filter((d) => d && d.type !== 'rest' && d.type !== 'free');
        };
        for (const week of weeksData) {
          if (!week.start_date || !week.days) continue;
          const endDateStr = addDays(week.start_date, 6);
          if (todayStr >= week.start_date && todayStr <= endDateStr) {
            const weekStart = new Date(week.start_date + 'T00:00:00');
            const weekEnd = new Date(endDateStr + 'T23:59:59');
            let completed = 0;
            (allResults?.results || []).forEach((r) => {
              if (r.training_date) {
                const resultDate = new Date(r.training_date);
                if (resultDate >= weekStart && resultDate <= weekEnd) completed++;
              }
            });
            let total = 0;
            Object.values(week.days || {}).forEach((dayData) => {
              total += getDayItems(dayData).length;
            });
            wp = { completed, total };
            break;
          }
        }
      }
      setWeekProgress(wp);
    } catch (err) {
      console.error('Error loading profile stats:', err);
    } finally {
      setStatsLoading(false);
    }
  }, [profileUser, api, access.can_view, access.is_owner, viewContext]);

  useEffect(() => {
    loadProfileStats();
  }, [loadProfileStats]);

  const workoutRefreshVersion = useWorkoutRefreshStore((s) => s.version);
  useEffect(() => {
    if (workoutRefreshVersion <= 0 || !access.is_owner || !profileUser || !api) return;
    const t = setTimeout(() => loadProfileStats({ silent: true }), 250);
    return () => clearTimeout(t);
  }, [workoutRefreshVersion, access.is_owner, profileUser, api, loadProfileStats]);

  const handleWorkoutClick = useCallback(async (workout) => {
    const date = workout?.start_time ? workout.start_time.split('T')[0] : workout?.date;
    if (!api || !date) return;
    const selectedWorkoutId = workout?.id ?? null;

    const immediateDayData = {
      planDays: [],
      dayExercises: [],
      workouts: [{ ...workout, start_time: workout.start_time || (date + 'T12:00:00') }],
    };
    setWorkoutModal({ isOpen: true, date, dayData: immediateDayData, loading: false, selectedWorkoutId });

    try {
      const response = await api.getDay(date, viewContext || undefined);
      const raw = response?.data ?? response;
      if (raw && typeof raw === 'object') {
        const fullDayData = {
          ...raw,
          planDays: raw.planDays ?? raw.plan_days ?? [],
          dayExercises: raw.dayExercises ?? raw.day_exercises ?? [],
          workouts: raw.workouts ?? [],
        };
        setWorkoutModal((prev) => prev.isOpen && prev.date === date ? { ...prev, dayData: fullDayData } : prev);
      }
    } catch {
      // Мгновенные данные уже отображены
    }
  }, [api, viewContext]);

  const handleCloseWorkoutModal = useCallback(() => {
    setWorkoutModal({ isOpen: false, date: null, dayData: null, loading: false, selectedWorkoutId: null });
  }, []);

  const handleDayClick = useCallback((date, week, day) => {
    setDayModal({ isOpen: true, date, week, day });
  }, []);

  const handleCloseDayModal = useCallback(() => {
    setDayModal({ isOpen: false, date: null, week: null, day: null });
  }, []);

  const ProfileHeader = () =>
    currentUser ? (
      <>
        <TopHeader />
        <Notifications api={api} isAdmin={currentUser?.role === 'admin'} />
      </>
    ) : (
      <PublicHeader
        onLoginClick={() => setLoginModalOpen(true)}
        onRegisterClick={() => setRegisterModalOpen(true)}
        registrationEnabled
      />
    );

  if (loading) {
    return (
      <div className="user-profile-screen">
        <ProfileHeader />
        <div className="page-transition-content">
          <div className="dashboard">
            <div className="profile-loading">Загрузка профиля...</div>
          </div>
        </div>
        {currentUser && <BottomNav />}
      </div>
    );
  }

  if (error && !profileUser) {
    return (
      <div className="user-profile-screen">
        <ProfileHeader />
        <div className="page-transition-content">
          <div className="dashboard">
            <div className="profile-error">
              <h2>Профиль не найден</h2>
              <p>{error || 'Пользователь с таким именем не существует'}</p>
              <Link to="/landing" className="btn">Вернуться на главную</Link>
            </div>
          </div>
        </div>
        {currentUser && <BottomNav />}
      </div>
    );
  }

  if (!profileUser) {
    return (
      <div className="user-profile-screen">
        <ProfileHeader />
        <div className="page-transition-content">
          <div className="dashboard">
            <div className="profile-loading">Загрузка...</div>
          </div>
        </div>
        {currentUser && <BottomNav />}
      </div>
    );
  }

  const isOwner = access.is_owner;
  const canView = access.can_view;
  const canEdit = access.can_edit;
  const goalText = formatGoalText(profileUser);
  const showEmail = isOwner || (profileUser.privacy_show_email !== 0 && profileUser.privacy_show_email !== '0');
  const showTrainer = isOwner || (profileUser.privacy_show_trainer !== 0 && profileUser.privacy_show_trainer !== '0');
  const showCalendar = isOwner || (profileUser.privacy_show_calendar !== 0 && profileUser.privacy_show_calendar !== '0');
  const showMetrics = isOwner || (profileUser.privacy_show_metrics !== 0 && profileUser.privacy_show_metrics !== '0');
  const showWorkouts = isOwner || (profileUser.privacy_show_workouts !== 0 && profileUser.privacy_show_workouts !== '0');

  return (
    <div className="user-profile-screen">
      <ProfileHeader />

      <div className="page-transition-content">
      <div className="dashboard">
      <div className="profile-header">
        <div className="profile-left">
          <div className="profile-avatar">
            {profileUser.avatar_path ? (
              <img
                src={getAvatarSrc(profileUser.avatar_path, api?.baseUrl || '/api')}
                alt={profileUser.username}
                className="avatar-large avatar-square"
              />
            ) : (
              <div className="avatar-large avatar-square avatar-placeholder">
                {profileUser.username ? profileUser.username.charAt(0).toUpperCase() : 'U'}
              </div>
            )}
          </div>
          {!isOwner && profileUser?.id !== currentUser?.id && (
            <button
              type="button"
              className="btn btn-secondary profile-message-btn"
              onClick={() => {
                if (currentUser) {
                  navigate(`/chat?contact=${encodeURIComponent(profileUser.username_slug || profileUser.username || profileUser.id)}`, {
                    state: {
                      contactUser: {
                        id: profileUser.id,
                        username: profileUser.username,
                        username_slug: profileUser.username_slug,
                        avatar_path: profileUser.avatar_path,
                      },
                    },
                  });
                } else {
                  setRegisterModalOpen(true);
                }
              }}
            >
              <MessageCircleIcon size={18} aria-hidden />
              Написать
            </button>
          )}
        </div>

        <div className="profile-info">
          <h1 className="profile-username">{profileUser.username}</h1>
          {profileUser.email && showEmail && (
            <p className="profile-email">{profileUser.email}</p>
          )}
          {goalText && (isOwner || canView) && (
            <p className="profile-goal">
              <TargetIcon size={18} className="profile-goal-icon" aria-hidden />
              {goalText}
            </p>
          )}
          <div className="profile-actions">
            {isOwner && (
              <Link to="/settings" className="btn btn-primary">
                <SettingsIcon size={18} aria-hidden />
                Настройки профиля
              </Link>
            )}
            {access.is_coach && (
              <div className="coach-badge">
                Вы тренер этого спортсмена
              </div>
            )}
          </div>
        </div>

        <div className="profile-right">
          {canView && showTrainer && ((profileUser.training_mode === 'ai' || profileUser.training_mode === 'both') || coaches?.length > 0) && (
            <div className="profile-coaches">
              <h3 className="profile-coaches-title">
                {(profileUser.training_mode === 'ai' || profileUser.training_mode === 'both') ? (
                  <BotIcon size={18} aria-hidden />
                ) : (
                  <GraduationCapIcon size={18} aria-hidden />
                )}
                {coaches.length > 1 ? 'С кем занимается' : 'Тренер'}
              </h3>
              <div className="profile-coaches-list">
                {(profileUser.training_mode === 'ai' || profileUser.training_mode === 'both') && (
                  <span className="profile-ai-trainer">
                    <span className="profile-ai-trainer-logo">
                      <span className="logo-plan">plan</span><span className="logo-run">RUN</span> <span className="logo-ai">AI</span>
                    </span>
                  </span>
                )}
                {coaches.map((coach) => (
                  <Link key={coach.id} to={`/${coach.username_slug}`} className="profile-coach-item">
                    {coach.avatar_path ? (
                      <img
                        src={getAvatarSrc(coach.avatar_path, api?.baseUrl || '/api')}
                        alt={coach.username}
                        className="profile-coach-avatar"
                      />
                    ) : (
                      <div className="profile-coach-avatar profile-coach-avatar-placeholder">
                        {coach.username ? coach.username.charAt(0).toUpperCase() : 'T'}
                      </div>
                    )}
                    <span className="profile-coach-name">{coach.username}</span>
                  </Link>
                ))}
              </div>
            </div>
          )}
        </div>
      </div>

      {canView ? (
        <>
          {showCalendar && (
            <div className="dashboard-section">
              <h2 className="section-title">Календарь</h2>
              <div className="dashboard-module-card">
                {statsLoading && !profilePlan ? (
                  <div className="profile-widget-loading">Загрузка...</div>
                ) : (
                  <DashboardWeekStrip
                    plan={profilePlan}
                    progressDataMap={progressDataMap}
                    onDayClick={handleDayClick}
                  />
                )}
              </div>
            </div>
          )}

          {showMetrics && (
            <div className="dashboard-row-two">
              <div className="dashboard-section dashboard-section-inline">
                <h2 className="section-title">Статистика</h2>
                <div className="dashboard-module-card">
                  <DashboardStatsWidget api={api} viewContext={viewContext} />
                </div>
              </div>
              <div className="dashboard-section dashboard-section-inline">
                <h2 className="section-title">Быстрые метрики</h2>
                <ProfileQuickMetricsWidget
                  api={api}
                  viewContext={viewContext}
                  plan={profilePlan}
                  progressDataMap={progressDataMap}
                  weekProgress={weekProgress}
                />
              </div>
            </div>
          )}

          {showWorkouts && (
            <div className="dashboard-section">
              <h2 className="section-title">Последние тренировки</h2>
              <div className="dashboard-module-card">
                {statsLoading && !profileStats ? (
                  <div className="profile-widget-loading">Загрузка...</div>
                ) : (
                  <RecentWorkoutsList
                    workouts={profileStats?.workouts || []}
                    api={api}
                    onWorkoutClick={handleWorkoutClick}
                  />
                )}
              </div>
            </div>
          )}

          <DayModal
            isOpen={dayModal.isOpen}
            onClose={handleCloseDayModal}
            date={dayModal.date}
            weekNumber={dayModal.week}
            dayKey={dayModal.day}
            api={api}
            canEdit={false}
            viewContext={viewContext}
          />

          <WorkoutDetailsModal
            isOpen={workoutModal.isOpen}
            onClose={handleCloseWorkoutModal}
            date={workoutModal.date}
            dayData={workoutModal.dayData}
            loading={workoutModal.loading}
            selectedWorkoutId={workoutModal.selectedWorkoutId}
            onDelete={access.is_owner ? () => { handleCloseWorkoutModal(); loadProfileStats({ silent: true }); } : undefined}
          />
        </>
      ) : (
        <div className="profile-access-denied dashboard-module-card">
          <h2>Доступ ограничен</h2>
          <p>
            {profileUser.privacy_level === 'private'
              ? 'Этот календарь доступен только тренерам и владельцу.'
              : 'Для доступа к этому календарю нужна специальная ссылка с токеном.'}
          </p>
          {!currentUser && (
            <Link to="/login" className="btn">
              Войти для доступа
            </Link>
          )}
        </div>
      )}
      </div>
      </div>

      {currentUser && <BottomNav />}

      {!currentUser && (
        <LoginModal isOpen={loginModalOpen} onClose={() => setLoginModalOpen(false)} />
      )}
      <RegisterModal
        isOpen={registerModalOpen}
        onClose={() => setRegisterModalOpen(false)}
        onRegister={(userData) => updateUser(userData && typeof userData === 'object' ? { ...userData, authenticated: true } : { authenticated: true })}
        returnTo={profileUser ? { path: '/chat', state: { contactUser: { id: profileUser.id, username: profileUser.username, username_slug: profileUser.username_slug, avatar_path: profileUser.avatar_path } } } : undefined}
      />
    </div>
  );
};

export default UserProfileScreen;
