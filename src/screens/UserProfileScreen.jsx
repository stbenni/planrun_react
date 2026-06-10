/**
 * UserProfileScreen - Экран профиля пользователя
 * Отображает профиль пользователя и его календарь
 */

import React, { useState, useEffect, useCallback } from 'react';
import { useParams, useLocation, Link, useNavigate } from 'react-router-dom';
import useAuthStore from '../stores/useAuthStore';
import useWorkoutRefreshStore from '../stores/useWorkoutRefreshStore';
import PublicHeader from '../components/common/PublicHeader';
import TopHeader from '../components/common/TopHeader';
import BottomNav from '../components/common/BottomNav';
import LoginModal from '../components/LoginModal';
import RegisterModal from '../components/RegisterModal';
import DayModal from '../components/Calendar/DayModal';
import WorkoutSheet from '../components/Stats/WorkoutSheet';
import LogoLoading from '../components/common/LogoLoading';
import ProfileV3 from './profile/ProfileV3';
import { processStatsData } from '../components/Stats/StatsUtils';
import { getPlanDayForDate, getDayCompletionStatus, planTypeToCategory, workoutTypeToCategory } from '../utils/calendarHelpers';
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
  if ((user.goal_type === 'race' || user.goal_type === 'time_improvement') && user.race_date) {
    const distLabel = user.race_distance ? (RACE_DISTANCE_LABELS[user.race_distance] || user.race_distance) : '';
    const dateStr = new Date(user.race_date + 'T00:00:00').toLocaleDateString('ru-RU', { day: 'numeric', month: 'short', year: 'numeric' });
    const timeStr = user.race_target_time ? `, ${user.race_target_time}` : '';
    return `${goalLabel}${distLabel ? `: ${distLabel}` : ''}, ${dateStr}${timeStr}`;
  }
  return goalLabel;
}

const UserProfileScreen = () => {
  const { username } = useParams();
  const location = useLocation();
  const { api, user: currentUser, updateUser, setSettingsPanelOpen } = useAuthStore();
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
  const [workoutsListData, setWorkoutsListData] = useState([]);
  const [records, setRecords] = useState(null);
  const [profilePlan, setProfilePlan] = useState(null);
  const [progressDataMap, setProgressDataMap] = useState({});
  const [weekProgress, setWeekProgress] = useState({ completed: 0, total: 0 });
  const [statsLoading, setStatsLoading] = useState(false);
  const [sheet, setSheet] = useState({ open: false, workout: null, date: null });
  const [dayModal, setDayModal] = useState({ isOpen: false, date: null, week: null, day: null });
  const [registerModalOpen, setRegisterModalOpen] = useState(false);
  const [loginModalOpen, setLoginModalOpen] = useState(false);
  const [requestingCoach, setRequestingCoach] = useState(false);
  const [coachRequested, setCoachRequested] = useState(false);
  const [coachRequestError, setCoachRequestError] = useState('');

  useEffect(() => {
    let cancelled = false;

    const loadUserProfile = async () => {
      if (!username) {
        setLoading(false);
        return;
      }

      if (!api) {
        // api ещё не готов — useEffect перезапустится когда api появится в сторе
        return;
      }
      const currentApi = api;

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
    return () => {
      cancelled = true;
    };
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
      setWorkoutsListData(Array.isArray(workoutsList) ? workoutsList : []);
      // Личные рекорды доступны только для своего профиля (API без viewContext).
      if (access.is_owner && typeof api.getPersonalRecords === 'function') {
        api.getPersonalRecords().then((res) => {
          const list = res?.data?.records ?? res?.records ?? [];
          const byKey = {};
          list.forEach((r) => { if (r?.distance_label) byKey[r.distance_label] = r; });
          setRecords(byKey);
        }).catch(() => setRecords({}));
      }

      const resultsData = {};
      (allResults?.results || []).forEach((r) => {
        if (r?.training_date) {
          if (!resultsData[r.training_date]) resultsData[r.training_date] = [];
          resultsData[r.training_date].push(r);
        }
      });
      const workoutsListByDate = {};
      (workoutsList || []).forEach((w) => {
        const d = w.date ?? w.start_time?.split?.('T')?.[0];
        if (d) {
          if (!workoutsListByDate[d]) workoutsListByDate[d] = [];
          workoutsListByDate[d].push(w);
        }
      });
      const summaryWorkouts = workoutsData?.workouts || {};
      const allDates = new Set([...Object.keys(resultsData), ...Object.keys(workoutsListByDate), ...Object.keys(summaryWorkouts)]);
      const progressMap = {};
      allDates.forEach((dateStr) => {
        const planDay = plan ? getPlanDayForDate(dateStr, plan) : null;
        const status = getDayCompletionStatus(dateStr, planDay, summaryWorkouts, resultsData, workoutsListByDate);
        if (status.status === 'completed') progressMap[dateStr] = true;
      });
      setProgressDataMap(progressMap);

      const today = new Date();
      const todayStr = today.toISOString().split('T')[0];
      const weeksData = plan?.weeks_data ?? plan?.weeks ?? plan?.phases?.[0]?.weeks ?? [];
      const addDays = (dateStr, days) => {
        const [y, m, d] = dateStr.split('-').map(Number);
        const d2 = new Date(Date.UTC(y, m - 1, d + days));
        return d2.toISOString().split('T')[0];
      };
      const getMondayOfWeek = (date) => {
        const d = new Date(date);
        const day = d.getDay();
        const diff = d.getDate() - day + (day === 0 ? -6 : 1);
        const monday = new Date(d);
        monday.setDate(diff);
        return monday.toISOString().split('T')[0];
      };
      const getDayItems = (dayData) => {
        if (!dayData) return [];
        const arr = Array.isArray(dayData) ? dayData : [dayData];
        return arr.filter((d) => d && d.type !== 'rest' && d.type !== 'free');
      };
      let wp = { completed: 0, total: 0 };
      let weekStartStr = null;
      let endDateStr = null;
      let currentWeek = null;
      if (Array.isArray(weeksData) && weeksData.length > 0) {
        for (const week of weeksData) {
          const start = week?.start_date ?? week?.startDate;
          if (!start || !week?.days) continue;
          const end = addDays(start, 6);
          if (todayStr >= start && todayStr <= end) {
            weekStartStr = start;
            endDateStr = end;
            currentWeek = week;
            break;
          }
        }
      }
      if (!weekStartStr) {
        weekStartStr = getMondayOfWeek(today);
        endDateStr = addDays(weekStartStr, 6);
      }
      if (weekStartStr && endDateStr && currentWeek) {
        const dayKeys = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        const plannedDays = [];
        for (let i = 0; i < 7; i++) {
          const dateStr = addDays(weekStartStr, i);
          const raw = currentWeek.days?.[dayKeys[i]];
          const items = getDayItems(raw);
          items.forEach((item) => {
            const cat = planTypeToCategory(item?.type);
            if (cat) plannedDays.push({ date: dateStr, plannedCategory: cat });
          });
        }
        const hasWorkout = (dateStr, category) => {
          // workoutsList — отдельные тренировки (Strava + workout_log), точная проверка по типу
          const listOnDate = (workoutsList || []).filter((w) => (w.date ?? w.start_time?.split?.('T')?.[0]) === dateStr);
          for (const w of listOnDate) {
            const cat = workoutTypeToCategory(w.activity_type ?? w.activity_type_name ?? 'running');
            if (cat === category) return true;
          }
          // allResults (workout_log) — ручные записи
          const r = (allResults?.results || []).find((x) => x?.training_date === dateStr);
          if (r) {
            const cat = workoutTypeToCategory(r.activity_type ?? r.activity_type_name ?? 'running');
            if (cat === category) return true;
          }
          // workoutsData — агрегат по дате (fallback, может быть неточен при нескольких типах в день)
          const w = workoutsData?.workouts?.[dateStr];
          if (w && (w.count > 0 || w.distance || w.duration || w.duration_seconds)) {
            const cat = workoutTypeToCategory(w.activity_type ?? 'running');
            if (cat === category) return true;
          }
          return false;
        };
        const completed = plannedDays.filter((p) => hasWorkout(p.date, p.plannedCategory)).length;
        wp = { completed, total: plannedDays.length };
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
    setSheet({ open: true, workout, date });
    try {
      const response = await api.getDay(date, viewContext || undefined);
      const raw = response?.data != null ? response.data : response;
      const workouts = Array.isArray(raw?.workouts) ? raw.workouts : [];
      const match = workouts.find((w) => String(w.id) === String(workout.id));
      if (match) {
        setSheet((prev) => (prev.open && prev.date === date ? { ...prev, workout: { ...workout, ...match } } : prev));
      }
    } catch {
      // Мгновенные данные уже отображены
    }
  }, [api, viewContext]);

  const handleCloseSheet = useCallback(() => setSheet({ open: false, workout: null, date: null }), []);

  const handleDeleteWorkout = useCallback(async (w) => {
    handleCloseSheet();
    try { await api.deleteWorkout(w.id ?? w.workout_id, !!w.is_manual); } catch { /* ignore */ }
    loadProfileStats({ silent: true });
  }, [api, handleCloseSheet, loadProfileStats]);

  const handleDayClick = useCallback((date, week, day) => {
    setDayModal({ isOpen: true, date, week, day });
  }, []);

  const handleCloseDayModal = useCallback(() => {
    setDayModal({ isOpen: false, date: null, week: null, day: null });
  }, []);

  const handleRequestCoach = useCallback(async () => {
    if (!api || !profileUser?.id || requestingCoach) return;
    setRequestingCoach(true);
    setCoachRequestError('');
    try {
      await api.requestCoach(profileUser.id);
      setCoachRequested(true);
    } catch (e) {
      setCoachRequestError(e.message || 'Ошибка отправки запроса');
    } finally {
      setRequestingCoach(false);
    }
  }, [api, profileUser?.id, requestingCoach]);

  const handleMessage = useCallback(() => {
    if (!profileUser) return;
    if (currentUser) {
      navigate(`/chat?contact=${encodeURIComponent(profileUser.username_slug || profileUser.username || profileUser.id)}`, {
        state: {
          contactUser: {
            id: profileUser.id, username: profileUser.username, username_slug: profileUser.username_slug,
            first_name: profileUser.first_name, last_name: profileUser.last_name, name: profileUser.name, avatar_path: profileUser.avatar_path,
          },
        },
      });
    } else {
      setRegisterModalOpen(true);
    }
  }, [profileUser, currentUser, navigate]);

  const ProfileHeader = () =>
    currentUser ? (
      <TopHeader />
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
            <div className="profile-loading"><LogoLoading size="sm" /></div>
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
            <div className="profile-loading"><LogoLoading size="sm" /></div>
          </div>
        </div>
        {currentUser && <BottomNav />}
      </div>
    );
  }

  const isOwner = access.is_owner;
  const goalText = formatGoalText(profileUser);
  const showTrainer = isOwner || (profileUser.privacy_show_trainer !== 0 && profileUser.privacy_show_trainer !== '0');
  const showCalendar = isOwner || (profileUser.privacy_show_calendar !== 0 && profileUser.privacy_show_calendar !== '0');
  const showMetrics = isOwner || (profileUser.privacy_show_metrics !== 0 && profileUser.privacy_show_metrics !== '0');
  const showWorkouts = isOwner || (profileUser.privacy_show_workouts !== 0 && profileUser.privacy_show_workouts !== '0');

  return (
    <div className="user-profile-screen">
      <ProfileHeader />

      <div className="page-transition-content">
      <ProfileV3
        api={api}
        currentUser={currentUser}
        profileUser={profileUser}
        access={access}
        coaches={coaches}
        profilePlan={profilePlan}
        progressDataMap={progressDataMap}
        weekProgress={weekProgress}
        viewContext={viewContext}
        statsLoading={statsLoading}
        profileStats={profileStats}
        workoutsList={workoutsListData}
        records={records}
        showCalendar={showCalendar}
        showMetrics={showMetrics}
        showWorkouts={showWorkouts}
        showTrainer={showTrainer}
        goalText={goalText}
        onSettings={() => setSettingsPanelOpen(true)}
        onMessage={handleMessage}
        onRequestCoach={handleRequestCoach}
        requestingCoach={requestingCoach}
        coachRequested={coachRequested}
        coachRequestError={coachRequestError}
        onGuestAction={() => setRegisterModalOpen(true)}
        onDayClick={handleDayClick}
        onWorkoutClick={handleWorkoutClick}
      />

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

      <WorkoutSheet
        open={sheet.open}
        workout={sheet.workout}
        date={sheet.date}
        api={api}
        viewContext={viewContext}
        canEdit={access.is_owner}
        onClose={handleCloseSheet}
        onDelete={access.is_owner ? handleDeleteWorkout : undefined}
      />
      </div>

      {currentUser && <BottomNav />}

      {!currentUser && (
        <LoginModal isOpen={loginModalOpen} onClose={() => setLoginModalOpen(false)} />
      )}
      <RegisterModal
        isOpen={registerModalOpen}
        onClose={() => setRegisterModalOpen(false)}
        onRegister={(userData) => updateUser(userData && typeof userData === 'object' ? { ...userData, authenticated: true } : { authenticated: true })}
        returnTo={profileUser ? { path: '/chat', state: { contactUser: { id: profileUser.id, username: profileUser.username, username_slug: profileUser.username_slug, first_name: profileUser.first_name, last_name: profileUser.last_name, name: profileUser.name, avatar_path: profileUser.avatar_path } } } : undefined}
      />
    </div>
  );
};

export default UserProfileScreen;
