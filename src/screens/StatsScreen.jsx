import { useState, useEffect, useCallback, useRef, useMemo } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import useAuthStore from '../stores/useAuthStore';
import usePreloadStore from '../stores/usePreloadStore';
import useWorkoutRefreshStore from '../stores/useWorkoutRefreshStore';
import { useIsTabActive } from '../hooks/useIsTabActive';
import { isNativeCapacitor } from '../services/TokenStorageService';
import WorkoutSheet from '../components/Stats/WorkoutSheet';
import StatsV3 from '../components/Stats/v3/StatsV3';
import SkeletonScreen from '../components/common/SkeletonScreen';
import { BarChartIcon } from '../components/common/Icons';
import AthleteSelect from '../components/common/AthleteSelect';
import ResultModal from '../components/Calendar/ResultModal';
import './StatsScreen.css';

const StatsScreen = () => {
  const location = useLocation();
  const navigate = useNavigate();
  const isTabActive = useIsTabActive('/stats');
  const preloadTriggered = usePreloadStore((s) => s.preloadTriggered);
  const workoutRefreshVersion = useWorkoutRefreshStore((s) => s.version);
  const { api, user } = useAuthStore();
  const role = user?.role || 'user';
  const isCoach = role === 'coach' || role === 'admin';
  const [rawData, setRawData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [sheet, setSheet] = useState({ open: false, workout: null, date: null });
  const [editModal, setEditModal] = useState({ isOpen: false, date: null });

  const athleteSlug = useMemo(() => {
    const params = new URLSearchParams(location.search);
    return params.get('athlete') || null;
  }, [location.search]);

  const viewContext = useMemo(() => (athleteSlug ? { slug: athleteSlug } : null), [athleteSlug]);

  const [coachAthletes, setCoachAthletes] = useState([]);
  useEffect(() => {
    if (!isCoach || !api) return;
    api.getCoachAthletes().then((res) => {
      setCoachAthletes(res?.data?.athletes || res?.athletes || []);
    }).catch(() => {});
  }, [isCoach, api]);

  useEffect(() => {
    const isStats = location.pathname === '/stats' || location.pathname.startsWith('/stats');
    if (!isStats) setSheet((prev) => (prev.open ? { ...prev, open: false } : prev));
  }, [location.pathname]);

  const loadRawData = useCallback(async (options = {}) => {
    const silent = options.silent === true;
    if (!api || typeof api.getAllWorkoutsSummary !== 'function') {
      setLoading(false);
      return;
    }

    try {
      if (!silent) setLoading(true);

      const [summaryRes, listRes, resultsRes, planRes] = await Promise.allSettled([
        api.getAllWorkoutsSummary(viewContext),
        api.getAllWorkoutsList(viewContext, 500),
        api.getAllResults(viewContext),
        api.getPlan(null, viewContext),
      ]);

      let workoutsData = { workouts: {} };
      if (summaryRes.status === 'fulfilled' && summaryRes.value && typeof summaryRes.value === 'object') {
        const raw = summaryRes.value.data ?? summaryRes.value;
        workoutsData = raw?.workouts != null ? { workouts: raw.workouts } : { workouts: typeof raw === 'object' && !Array.isArray(raw) ? raw : {} };
      }

      let workoutsList = [];
      if (listRes.status === 'fulfilled' && listRes.value && typeof listRes.value === 'object') {
        const raw = listRes.value.data ?? listRes.value;
        workoutsList = Array.isArray(raw?.workouts) ? raw.workouts : [];
      }

      let allResults = { results: [] };
      if (resultsRes.status === 'fulfilled' && resultsRes.value && typeof resultsRes.value === 'object') {
        const r = resultsRes.value;
        allResults = { results: r.results ?? r };
      }

      let plan = null;
      if (planRes.status === 'fulfilled' && planRes.value && typeof planRes.value === 'object') {
        const r = planRes.value;
        plan = r.plan ?? r;
      }

      setRawData({ workoutsData, workoutsList, allResults, plan });
    } catch (error) {
      console.error('Error loading stats:', error);
    } finally {
      setLoading(false);
    }
  }, [api, viewContext]);

  const hasLoadedRef = useRef(false);
  useEffect(() => {
    const isNative = isNativeCapacitor();
    const shouldPreload = isNative && preloadTriggered;
    if (!isTabActive && !hasLoadedRef.current && !shouldPreload) return;
    if (api && typeof api.getAllWorkoutsSummary === 'function') {
      const alreadyLoaded = hasLoadedRef.current;
      hasLoadedRef.current = true;
      const silent = (shouldPreload && !isTabActive) || alreadyLoaded;
      loadRawData({ silent });
    } else {
      setLoading(false);
    }
  }, [api, isTabActive, preloadTriggered, loadRawData]);

  // Reload when athlete selection changes
  const prevAthleteRef = useRef(athleteSlug);
  useEffect(() => {
    if (prevAthleteRef.current !== athleteSlug && api) {
      prevAthleteRef.current = athleteSlug;
      setRawData(null);
      loadRawData();
    }
  }, [athleteSlug, api, loadRawData]);

  useEffect(() => {
    if (workoutRefreshVersion <= 0 || !api) return undefined;
    const t = setTimeout(() => loadRawData({ silent: true }), 250);
    return () => clearTimeout(t);
  }, [workoutRefreshVersion, api, loadRawData]);

  const handleWorkoutClick = async (workout) => {
    const date = workout?.start_time ? workout.start_time.split('T')[0] : workout?.date;
    if (!api || !date) return;
    // Мгновенно показываем sheet; затем обогащаем полными полями из getDay (по id).
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
      // Мгновенные данные уже отображены — ошибка enrichment не критична
    }
  };

  const handleCloseSheet = useCallback(() => setSheet({ open: false, workout: null, date: null }), []);

  const handleEditWorkout = useCallback((w) => {
    const editDate = w?.start_time ? w.start_time.split('T')[0] : (w?.date || null);
    handleCloseSheet();
    if (editDate) setEditModal({ isOpen: true, date: editDate });
  }, [handleCloseSheet]);

  const handleDeleteWorkout = useCallback(async (w) => {
    handleCloseSheet();
    try { await api.deleteWorkout(w.id ?? w.workout_id, !!w.is_manual); } catch { /* ignore */ }
    loadRawData({ silent: true });
    useWorkoutRefreshStore.getState().triggerRefresh();
  }, [api, handleCloseSheet, loadRawData]);

  const handleCloseEditModal = useCallback(() => {
    setEditModal({ isOpen: false, date: null });
  }, []);

  if (!api) {
    return (
      <div className="stats-screen">
        <div className="stats-empty">
          <div className="empty-icon">⚠️</div>
          <div className="empty-text">Клиент API не инициализирован</div>
        </div>
      </div>
    );
  }

  if (loading) {
    return (
      <div className="stats-screen">
        <SkeletonScreen type="stats" />
      </div>
    );
  }

  if (!rawData) {
    return (
      <div className="stats-screen">
        <div className="stats-empty">
          <div className="empty-icon" aria-hidden><BarChartIcon size={48} /></div>
          <div className="empty-text">Нет данных для отображения</div>
        </div>
      </div>
    );
  }

  return (
    <div className="stats-screen">
      {isCoach && coachAthletes.length > 0 && (
        <div className="coach-athlete-selector">
          <AthleteSelect
            value={athleteSlug}
            ownLabel="Моя статистика"
            athletes={coachAthletes}
            onChange={(slug) => navigate(slug ? `/stats?athlete=${slug}` : '/stats', { replace: true })}
          />
        </div>
      )}
      {athleteSlug && (
        <div className="coach-mode-banner">
          <span className="coach-mode-banner__label">Режим тренера</span>
          <span className="coach-mode-banner__name">{coachAthletes.find((a) => a.username_slug === athleteSlug)?.username || athleteSlug}</span>
        </div>
      )}

      <StatsV3
        api={api}
        viewContext={viewContext}
        rawData={rawData}
        user={user}
        onWorkoutClick={handleWorkoutClick}
      />

      <WorkoutSheet
        open={sheet.open}
        workout={sheet.workout}
        date={sheet.date}
        api={api}
        canEdit={!viewContext}
        onClose={handleCloseSheet}
        onEdit={handleEditWorkout}
        onDelete={handleDeleteWorkout}
      />

      <ResultModal
        isOpen={editModal.isOpen}
        onClose={handleCloseEditModal}
        date={editModal.date}
        api={api}
        onSave={() => { handleCloseEditModal(); loadRawData({ silent: true }); useWorkoutRefreshStore.getState().triggerRefresh(); }}
      />
    </div>
  );
};

export default StatsScreen;
