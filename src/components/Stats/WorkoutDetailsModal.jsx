/**
 * Модальное окно деталей тренировки — стиль Garmin с вкладками.
 * Вкладки: Обзор | Круги | Графики
 */

import {
  useState,
  useEffect,
  useRef,
  useCallback,
  Suspense,
  lazy,
  useMemo,
  useLayoutEffect,
} from 'react';
import { createPortal } from 'react-dom';
import html2canvas from 'html2canvas';
import { HeartRateChart, PaceChart } from './index';
import WorkoutShareCard from './WorkoutShareCard';
import Modal from '../common/Modal';
import LogoLoading from '../common/LogoLoading';
import { CloseIcon } from '../common/Icons';
import useAuthStore from '../../stores/useAuthStore';
import { useSwipeableTabs } from '../../hooks/useSwipeableTabs';
import {
  getActivityTypeLabel, getWorkoutDisplayType, getSourceLabel,
} from '../../utils/workoutFormUtils';
import './WorkoutDetailsModal.css';

const LeafletRouteMap = lazy(() => import('./LeafletRouteMap'));

/* ────── helpers ────── */

const matchesSelectedWorkout = (workout, selectedWorkoutId) => {
  if (!selectedWorkoutId) return true;
  if (typeof selectedWorkoutId === 'string' && selectedWorkoutId.startsWith('log_')) {
    const logId = parseInt(selectedWorkoutId.replace('log_', ''), 10);
    return workout.is_manual && workout.id === logId;
  }
  return String(workout.id) === String(selectedWorkoutId);
};

const GENERIC_LAP_NAME_RE = /^lap\s+\d+$/i;

const formatLapDuration = (totalSeconds) => {
  const seconds = Number(totalSeconds);
  if (!Number.isFinite(seconds) || seconds <= 0) return null;
  const safeSeconds = Math.round(seconds);
  const hours = Math.floor(safeSeconds / 3600);
  const minutes = Math.floor((safeSeconds % 3600) / 60);
  const secs = safeSeconds % 60;
  if (hours > 0) {
    return `${hours}:${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
  }
  return `${minutes}:${String(secs).padStart(2, '0')}`;
};

const formatDuration = (workout) => {
  if (workout.duration_seconds != null && workout.duration_seconds > 0) {
    const h = Math.floor(workout.duration_seconds / 3600);
    const m = Math.floor((workout.duration_seconds % 3600) / 60);
    const s = workout.duration_seconds % 60;
    return (h > 0 ? `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}` : `${m}:${String(s).padStart(2, '0')}`);
  }
  if (workout.duration_minutes != null && workout.duration_minutes > 0) {
    const h = Math.floor(workout.duration_minutes / 60);
    const m = workout.duration_minutes % 60;
    return h > 0 ? `${h}ч ${m}м` : `${m}м`;
  }
  return null;
};

const formatLapDistance = (distanceKm) => {
  const value = Number(distanceKm);
  if (!Number.isFinite(value) || value <= 0) return null;
  if (value < 1) return `${Math.round(value * 1000)} м`;
  return `${value.toFixed(value >= 10 ? 1 : 2).replace(/\.0$/, '').replace(/(\.\d)0$/, '$1')} км`;
};

const getLapPaceSeconds = (lap) => {
  const explicit = Number(lap?.pace_seconds_per_km);
  if (Number.isFinite(explicit) && explicit > 0) return explicit;
  const distanceKm = Number(lap?.distance_km);
  const movingSeconds = Number(lap?.moving_seconds ?? lap?.elapsed_seconds);
  if (Number.isFinite(distanceKm) && distanceKm > 0 && Number.isFinite(movingSeconds) && movingSeconds > 0) {
    return movingSeconds / distanceKm;
  }
  const averageSpeed = Number(lap?.average_speed);
  if (Number.isFinite(averageSpeed) && averageSpeed > 0) return 1000 / averageSpeed;
  return null;
};

const formatLapPace = (lap) => {
  const paceSeconds = getLapPaceSeconds(lap);
  if (!Number.isFinite(paceSeconds) || paceSeconds <= 0) return null;
  const rounded = Math.round(paceSeconds);
  return `${Math.floor(rounded / 60)}:${String(rounded % 60).padStart(2, '0')}`;
};

const getLapLabel = (lap) => {
  const rawName = typeof lap?.name === 'string' ? lap.name.trim() : '';
  const lapIndex = lap?.lap_index != null ? Number(lap.lap_index) : null;
  if (!rawName || GENERIC_LAP_NAME_RE.test(rawName)) return lapIndex ? `Круг ${lapIndex}` : 'Круг';
  return rawName;
};

const GENERIC_DISPLAY_LAP_NAME_RE = /^круг\s+\d+$/i;
const getLapTableLabel = (lap, fallbackIndex) => {
  const label = getLapLabel(lap);
  if (GENERIC_DISPLAY_LAP_NAME_RE.test(label)) {
    const lapIndex = lap?.lap_index != null ? Number(lap.lap_index) : fallbackIndex;
    return Number.isFinite(lapIndex) && lapIndex > 0 ? String(lapIndex) : label;
  }
  return label;
};

const detectIntervalPattern = (laps) => {
  if (!Array.isArray(laps) || laps.length < 4) {
    return { isLikelyInterval: false, rolesByLapIndex: {}, pairCount: 0 };
  }
  const candidates = laps.map((lap, pos) => {
    const lapIndex = lap?.lap_index != null ? Number(lap.lap_index) : pos + 1;
    const distanceKm = Number(lap?.distance_km);
    const movingSeconds = Number(lap?.moving_seconds ?? lap?.elapsed_seconds);
    const paceSeconds = getLapPaceSeconds(lap);
    if (!Number.isFinite(distanceKm) || distanceKm < 0.15 || distanceKm > 2.5) return null;
    if (!Number.isFinite(movingSeconds) || movingSeconds < 30 || movingSeconds > 1200) return null;
    if (!Number.isFinite(paceSeconds) || paceSeconds <= 0) return null;
    return { lapIndex, distanceKm, movingSeconds, paceSeconds };
  }).filter(Boolean);
  if (candidates.length < 4) return { isLikelyInterval: false, rolesByLapIndex: {}, pairCount: 0 };
  const rolesByLapIndex = {};
  let pairCount = 0;
  for (let i = 0; i < candidates.length - 1; i++) {
    const cur = candidates[i], nxt = candidates[i + 1];
    const relGap = nxt.paceSeconds / cur.paceSeconds;
    const absGap = nxt.paceSeconds - cur.paceSeconds;
    const recovOk = nxt.distanceKm >= 0.1 && nxt.distanceKm <= Math.max(cur.distanceKm * 1.8, 0.6);
    if (recovOk && relGap >= 1.12 && absGap >= 18) {
      rolesByLapIndex[cur.lapIndex] = 'work';
      rolesByLapIndex[nxt.lapIndex] = 'recovery';
      pairCount++;
      i++;
    }
  }
  if (pairCount < 2) return { isLikelyInterval: false, rolesByLapIndex: {}, pairCount: 0 };
  return { isLikelyInterval: true, rolesByLapIndex, pairCount };
};

const downloadBlob = (blob, fileName) => {
  if (!blob || !fileName || typeof document === 'undefined' || typeof URL === 'undefined') return;
  const objectUrl = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = objectUrl;
  link.download = fileName;
  link.click();
  setTimeout(() => URL.revokeObjectURL(objectUrl), 0);
};

const SHARE_TEMPLATES = [
  { key: 'route', label: 'Маршрут' },
  { key: 'minimal', label: 'Мини' },
];

const getShareTemplates = (timeline) => {
  const hasRoute = Array.isArray(timeline)
    && timeline.some((point) => point?.latitude != null && point?.longitude != null);
  return hasRoute ? SHARE_TEMPLATES : SHARE_TEMPLATES.filter((template) => template.key !== 'route');
};

const getShareTemplateDirection = (templates, currentKey, nextKey) => {
  const keys = Array.isArray(templates) ? templates.map((template) => template.key) : [];
  const currentIndex = keys.indexOf(currentKey);
  const nextIndex = keys.indexOf(nextKey);
  if (currentIndex === -1 || nextIndex === -1) return 'forward';
  return nextIndex > currentIndex ? 'forward' : 'backward';
};

const buildShareFileName = (captureDate, captureWorkout, templateKey) => {
  const suffix = templateKey && templateKey !== 'route' ? `-${templateKey}` : '';
  return `planrun-${captureDate}-${getWorkoutDisplayType(captureWorkout) || 'workout'}${suffix}.png`;
};

const hasRoutePoints = (timeline) => Array.isArray(timeline)
  && timeline.some((point) => point?.latitude != null && point?.longitude != null);

const SHARE_MAP_REQUEST = {
  width: 364,
  height: 236,
  scale: 2,
};

const SHARE_MAP_ATTRIBUTIONS = {
  mapbox: '© OpenStreetMap contributors · Mapbox',
  maptiler: '© OpenStreetMap contributors · MapTiler',
};

const waitForImagesToLoad = async (rootElement) => {
  if (!rootElement || typeof rootElement.querySelectorAll !== 'function') return;
  const images = Array.from(rootElement.querySelectorAll('img'));
  await Promise.all(images.map((image) => {
    if (image.complete && image.naturalWidth > 0) {
      return Promise.resolve();
    }
    return new Promise((resolve) => {
      const handleDone = () => {
        image.removeEventListener('load', handleDone);
        image.removeEventListener('error', handleDone);
        resolve();
      };
      image.addEventListener('load', handleDone);
      image.addEventListener('error', handleDone);
    });
  }));
};

const waitForShareCardLayout = async (rootElement, { minWidth = 360, minHeight = 320, timeoutMs = 2500 } = {}) => {
  if (!rootElement) return;

  const startedAt = Date.now();
  while (Date.now() - startedAt < timeoutMs) {
    const width = Math.max(
      rootElement.offsetWidth || 0,
      rootElement.scrollWidth || 0,
      rootElement.firstElementChild?.scrollWidth || 0,
    );
    const height = Math.max(
      rootElement.offsetHeight || 0,
      rootElement.scrollHeight || 0,
      rootElement.firstElementChild?.scrollHeight || 0,
    );

    if (width >= minWidth && height >= minHeight) {
      return;
    }

    // Let React flush layout/styles before the next html2canvas attempt.
    // eslint-disable-next-line no-await-in-loop
    await new Promise((resolve) => setTimeout(resolve, 60));
  }
};

const blobToDataUrl = (blob) => new Promise((resolve, reject) => {
  if (!(blob instanceof Blob)) {
    reject(new Error('Invalid blob'));
    return;
  }

  const reader = new FileReader();
  reader.onloadend = () => {
    if (typeof reader.result === 'string' && reader.result.startsWith('data:')) {
      resolve(reader.result);
      return;
    }
    reject(new Error('Failed to convert blob to data URL'));
  };
  reader.onerror = () => reject(reader.error || new Error('Failed to read blob'));
  reader.readAsDataURL(blob);
});

/* ────── Tab definitions ────── */
const TABS = [
  { key: 'overview', label: 'Обзор' },
  { key: 'ai', label: 'ИИ-анализ' },
  { key: 'details', label: 'Данные' },
  { key: 'laps', label: 'Круги' },
  { key: 'charts', label: 'Графики' },
];

/* ────── Main component ────── */
const WorkoutDetailsModal = ({ isOpen, onClose, date, dayData, loading, onEdit, onDelete, selectedWorkoutId }) => {
  const { api } = useAuthStore();
  const [deleting, setDeleting] = useState(false);
  const [activeTab, setActiveTab] = useState('overview');
  const [timelineHoverIndex, setTimelineHoverIndex] = useState(null);
  const [aiAnalysis, setAiAnalysis] = useState(null);
  const [aiAnalysisLoading, setAiAnalysisLoading] = useState(false);

  const displayedWorkouts = useMemo(() => {
    const workouts = dayData?.workouts ?? [];
    if (!selectedWorkoutId) return workouts;
    const filtered = workouts.filter((w) => matchesSelectedWorkout(w, selectedWorkoutId));
    return filtered.length > 0 ? filtered : workouts;
  }, [dayData?.workouts, selectedWorkoutId]);

  const workout = displayedWorkouts?.[0];

  // Reset tab on open
  useEffect(() => {
    if (isOpen) setActiveTab('overview');
  }, [isOpen]);

  /* ── Timeline loading ── */
  const [timelineData, setTimelineData] = useState({});
  const [lapsData, setLapsData] = useState({});
  const [, setLoadingTimeline] = useState({});
  const loadedWorkoutsRef = useRef(new Set());

  useEffect(() => {
    if (!isOpen || !dayData || !dayData.workouts || !api) {
      if (!isOpen) {
        loadedWorkoutsRef.current.clear();
        setTimelineData({});
        setLapsData({});
        setLoadingTimeline({});
      }
      return;
    }
    let cancelled = false;
    const loadTimeline = async (workoutId) => {
      if (loadedWorkoutsRef.current.has(workoutId)) return;
      setLoadingTimeline(prev => ({ ...prev, [workoutId]: true }));
      try {
        const response = await api.getWorkoutTimeline(workoutId);
        if (cancelled) return;
        let timeline = null, laps = null;
        if (Array.isArray(response)) {
          timeline = response;
        } else if (response && typeof response === 'object') {
          const payload = response.data && typeof response.data === 'object' ? response.data : response;
          if (Array.isArray(payload?.timeline)) timeline = payload.timeline;
          if (Array.isArray(payload?.laps)) laps = payload.laps;
        }
        if (timeline?.length > 0) setTimelineData(prev => ({ ...prev, [workoutId]: timeline }));
        if (laps?.length > 0) setLapsData(prev => ({ ...prev, [workoutId]: laps }));
        loadedWorkoutsRef.current.add(workoutId);
      } catch (error) {
        if (!cancelled && error?.name !== 'AbortError') console.error('Error loading workout timeline:', error);
      } finally {
        if (!cancelled) setLoadingTimeline(prev => ({ ...prev, [workoutId]: false }));
      }
    };
    displayedWorkouts.forEach(w => {
      if (w.id && !w.is_manual && !loadedWorkoutsRef.current.has(w.id)) loadTimeline(w.id);
    });
    return () => { cancelled = true; };
  }, [isOpen, dayData, api, displayedWorkouts]);

  /* ── Share logic ── */
  const [shareGenerating, setShareGenerating] = useState(false);
  const [sharePopup, setSharePopup] = useState({
    open: false,
    status: 'idle',
    templateKey: 'route',
    previewUrl: null,
    blob: null,
    fileName: '',
    error: '',
    transitionTick: 0,
    transitionDirection: 'forward',
  });
  const [shareSession, setShareSession] = useState(null);
  const [shareCardForCapture, setShareCardForCapture] = useState(null);
  const shareCardRef = useRef(null);
  const shareTabsRef = useRef(null);
  const sharePreviewRef = useRef(null);
  const sharePopupRef = useRef(null);
  const shareRequestTokenRef = useRef(0);
  const sharePreviewObjectUrlRef = useRef(null);
  const shareMapObjectUrlRef = useRef(null);
  const shareTransitionDirectionRef = useRef('forward');
  const [shareTemplatePillStyle, setShareTemplatePillStyle] = useState({ left: 0, width: 0 });

  const cleanupShareAssets = useCallback(() => {
    if (sharePreviewObjectUrlRef.current && typeof URL !== 'undefined') {
      URL.revokeObjectURL(sharePreviewObjectUrlRef.current);
      sharePreviewObjectUrlRef.current = null;
    }
    if (shareMapObjectUrlRef.current && typeof URL !== 'undefined') {
      URL.revokeObjectURL(shareMapObjectUrlRef.current);
      shareMapObjectUrlRef.current = null;
    }
  }, []);

  const closeSharePopup = useCallback(() => {
    shareRequestTokenRef.current += 1;
    cleanupShareAssets();
    setShareSession(null);
    setShareCardForCapture(null);
    setShareGenerating(false);
    setSharePopup({
      open: false,
      status: 'idle',
      templateKey: 'route',
      previewUrl: null,
      blob: null,
      fileName: '',
      error: '',
      transitionTick: 0,
      transitionDirection: 'forward',
    });
  }, [cleanupShareAssets]);

  useEffect(() => cleanupShareAssets, [cleanupShareAssets]);
  useEffect(() => {
    sharePopupRef.current = sharePopup;
  }, [sharePopup]);

  const shareImageFile = useCallback(async (blob, fileName) => {
    if (!blob || !fileName || typeof navigator === 'undefined' || typeof navigator.share !== 'function') {
      return { shared: false, cancelled: false };
    }

    try {
      const file = new File([blob], fileName, { type: blob.type || 'image/png' });

      if (typeof navigator.canShare === 'function') {
        const canShareFiles = navigator.canShare({ files: [file] });
        if (!canShareFiles) {
          return { shared: false, cancelled: false };
        }
      }

      await navigator.share({
        title: fileName.replace(/\.png$/i, ''),
        files: [file],
      });

      return { shared: true, cancelled: false };
    } catch (error) {
      if (error?.name === 'AbortError') {
        return { shared: false, cancelled: true };
      }
      return { shared: false, cancelled: false };
    }
  }, []);

  const ensureShareMapAsset = useCallback(async (session, requestToken) => {
    if (!api || !session?.workout?.id || !hasRoutePoints(session.timeline)) {
      return session;
    }

    if (session.staticMapUrl || session.staticMapStatus === 'unavailable') {
      return session;
    }

    try {
      const mapResponse = await api.getWorkoutShareMap(session.workout.id, SHARE_MAP_REQUEST);
      if (shareRequestTokenRef.current !== requestToken) {
        return session;
      }

      if (shareMapObjectUrlRef.current && typeof URL !== 'undefined') {
        URL.revokeObjectURL(shareMapObjectUrlRef.current);
      }

      const objectUrl = URL.createObjectURL(mapResponse.blob);
      shareMapObjectUrlRef.current = objectUrl;

      const nextSession = {
        ...session,
        staticMapUrl: objectUrl,
        staticMapAttribution: SHARE_MAP_ATTRIBUTIONS[mapResponse.provider] || '© OpenStreetMap contributors',
        staticMapProvider: mapResponse.provider || null,
        staticMapStatus: 'ready',
      };
      setShareSession((prev) => (prev && prev.workout?.id === session.workout.id ? nextSession : prev));
      return nextSession;
    } catch (error) {
      if (process.env.NODE_ENV !== 'production') {
        console.warn('Share map fallback:', error?.message || error);
      }
      const nextSession = { ...session, staticMapStatus: 'unavailable' };
      setShareSession((prev) => (prev && prev.workout?.id === session.workout.id ? nextSession : prev));
      return nextSession;
    }
  }, [api]);

  const persistShareCardToCache = useCallback(async ({
    workoutId,
    isManual,
    templateKey,
    blob,
    fileName,
    mapProvider = null,
  }) => {
    if (!api || !workoutId || !(blob instanceof Blob)) return;

    try {
      const imageDataUrl = await blobToDataUrl(blob);
      await api.storeWorkoutShareCard(workoutId, {
        template: templateKey,
        workout_kind: isManual ? 'manual' : 'workout',
        image_data_url: imageDataUrl,
        file_name: fileName,
        map_provider: mapProvider,
      });
    } catch (error) {
      if (process.env.NODE_ENV !== 'production') {
        console.warn('Share cache persist skipped:', error?.message || error);
      }
    }
  }, [api]);

  const tryLoadShareCardFromCache = useCallback(async (session, templateKey, requestToken, transitionMeta = {}) => {
    if (!api || !session?.workout?.id || !session?.date) return false;

    try {
      const response = await api.getWorkoutShareCard(session.workout.id, {
        template: templateKey,
        workout_kind: session.workout.is_manual ? 'manual' : 'workout',
        cache_only: true,
        preferred_renderer: 'client',
      });
      if (response?.empty || !(response?.blob instanceof Blob)) {
        return false;
      }

      const previewUrl = URL.createObjectURL(response.blob);
      if (shareRequestTokenRef.current !== requestToken) {
        URL.revokeObjectURL(previewUrl);
        return true;
      }

      const previousPreviewUrl = transitionMeta.previousPreviewUrl || null;
      const stalePreviewUrl = sharePreviewObjectUrlRef.current
      && sharePreviewObjectUrlRef.current !== previewUrl
        ? sharePreviewObjectUrlRef.current
        : null;
      sharePreviewObjectUrlRef.current = previewUrl;

      setShareCardForCapture(null);
      setSharePopup({
        open: true,
        status: 'ready',
        templateKey,
        previewUrl,
        blob: response.blob,
        fileName: buildShareFileName(session.date, session.workout, templateKey),
        error: '',
        transitionTick: Date.now(),
        transitionDirection: transitionMeta.transitionDirection || 'forward',
      });
      setShareGenerating(false);

      [previousPreviewUrl, stalePreviewUrl]
        .filter((candidate, index, list) => candidate && list.indexOf(candidate) === index)
        .forEach((candidate) => {
          if (typeof URL === 'undefined') return;
          requestAnimationFrame(() => {
            URL.revokeObjectURL(candidate);
          });
        });

      return true;
    } catch (error) {
      if (process.env.NODE_ENV !== 'production') {
        console.warn('Share cache miss, falling back to client render:', error?.message || error);
      }
      return false;
    }
  }, [api]);

  const startShareGeneration = useCallback(async (session, templateKey) => {
    if (!session?.workout || !session?.date || !api) return;
    const requestToken = shareRequestTokenRef.current + 1;
    shareRequestTokenRef.current = requestToken;
    const currentPopup = sharePopupRef.current;
    const transitionDirection = shareTransitionDirectionRef.current || 'forward';
    const isTemplateSwitch = Boolean(
      currentPopup?.open
      && currentPopup?.status === 'ready'
      && currentPopup?.previewUrl
      && currentPopup?.templateKey !== templateKey,
    );
    const previousPreviewUrl = isTemplateSwitch ? currentPopup.previewUrl : null;
    const previousBlob = isTemplateSwitch ? currentPopup.blob : null;
    const previousFileName = isTemplateSwitch ? currentPopup.fileName : '';
    setShareGenerating(true);
    setShareCardForCapture(null);
    if (!isTemplateSwitch && sharePreviewObjectUrlRef.current && typeof URL !== 'undefined') {
      URL.revokeObjectURL(sharePreviewObjectUrlRef.current);
      sharePreviewObjectUrlRef.current = null;
    }
    if (isTemplateSwitch) {
      setSharePopup({
        open: true,
        status: 'switching',
        templateKey,
        previewUrl: previousPreviewUrl,
        blob: previousBlob,
        fileName: previousFileName,
        error: '',
        transitionTick: currentPopup?.transitionTick || 0,
        transitionDirection,
      });
    }

    const loadedFromCache = await tryLoadShareCardFromCache(session, templateKey, requestToken, {
      previousPreviewUrl,
      transitionDirection,
    });
    if (loadedFromCache) {
      return;
    }

    let preparedSession = session;
    if (templateKey === 'route' && hasRoutePoints(session.timeline)) {
      preparedSession = await ensureShareMapAsset(session, requestToken);
      if (shareRequestTokenRef.current !== requestToken) return;
    }

    if (!isTemplateSwitch) {
      setSharePopup({
        open: true,
        status: 'loading',
        templateKey,
        previewUrl: null,
        blob: null,
        fileName: '',
        error: '',
        transitionTick: 0,
        transitionDirection,
      });
    }

    setShareCardForCapture({
      date: preparedSession.date,
      workout: preparedSession.workout,
      timeline: preparedSession.timeline,
      staticMapUrl: preparedSession.staticMapUrl || null,
      staticMapAttribution: preparedSession.staticMapAttribution || null,
      staticMapProvider: preparedSession.staticMapProvider || null,
      templateKey,
      requestToken,
      previousPreviewUrl,
      transitionDirection,
    });
  }, [api, ensureShareMapAsset, tryLoadShareCardFromCache]);

  const handleShare = useCallback(async () => {
    if (!workout || !date) return;
    const chartW = displayedWorkouts?.find(w => w.id && !w.is_manual && timelineData[w.id]);
    const timelineForShare = chartW ? timelineData[chartW.id] : null;
    const templates = getShareTemplates(timelineForShare);
    const defaultTemplate = templates[0]?.key || 'minimal';
    const session = {
      date,
      workout,
      timeline: timelineForShare,
      templates,
    };
    setShareSession(session);
    await startShareGeneration(session, defaultTemplate);
  }, [date, workout, displayedWorkouts, timelineData, startShareGeneration]);

  useEffect(() => {
    if (!shareCardForCapture || !shareCardRef.current) return;
    const {
      date: captureDate,
      workout: captureWorkout,
      templateKey,
      requestToken,
      previousPreviewUrl,
      transitionDirection,
      staticMapProvider,
    } = shareCardForCapture;
    const cardEl = shareCardRef.current;

    (async () => {
      try {
        await new Promise((resolve) => requestAnimationFrame(() => setTimeout(resolve, 350)));
        await waitForImagesToLoad(cardEl);
        await waitForShareCardLayout(cardEl);
        const canvas = await html2canvas(cardEl, {
          backgroundColor: null,
          scale: 2,
          useCORS: true,
          logging: false,
          allowTaint: true,
          onclone: (_doc, element) => {
            element.style.opacity = '1';
          },
        });

        if (canvas.width < 300 || canvas.height < 300) {
          throw new Error(`Share canvas too small: ${canvas.width}x${canvas.height}`);
        }

        const blob = await new Promise((resolve, reject) => {
          canvas.toBlob((nextBlob) => {
            if (nextBlob) {
              resolve(nextBlob);
              return;
            }
            reject(new Error('Canvas empty'));
          }, 'image/png');
        });

        const previewUrl = URL.createObjectURL(blob);
        if (shareRequestTokenRef.current !== requestToken) {
          URL.revokeObjectURL(previewUrl);
          return;
        }

        const stalePreviewUrl = sharePreviewObjectUrlRef.current
        && sharePreviewObjectUrlRef.current !== previewUrl
          ? sharePreviewObjectUrlRef.current
          : null;
        sharePreviewObjectUrlRef.current = previewUrl;

        setSharePopup({
          open: true,
          status: 'ready',
          templateKey,
          previewUrl,
          blob,
          fileName: buildShareFileName(captureDate, captureWorkout, templateKey),
          error: '',
          transitionTick: Date.now(),
          transitionDirection: transitionDirection || 'forward',
        });

        void persistShareCardToCache({
          workoutId: captureWorkout?.id,
          isManual: Boolean(captureWorkout?.is_manual),
          templateKey,
          blob,
          fileName: buildShareFileName(captureDate, captureWorkout, templateKey),
          mapProvider: staticMapProvider || null,
        });

        [previousPreviewUrl, stalePreviewUrl]
          .filter((candidate, index, list) => candidate && list.indexOf(candidate) === index)
          .forEach((candidate) => {
            if (typeof URL === 'undefined') return;
            requestAnimationFrame(() => {
              URL.revokeObjectURL(candidate);
            });
          });
      } catch (err) {
        if (process.env.NODE_ENV !== 'production') console.error('Share error:', err);
        if (shareRequestTokenRef.current !== requestToken) return;
        setSharePopup({
          open: true,
          status: 'error',
          templateKey,
          previewUrl: null,
          blob: null,
          fileName: '',
          error: 'Не удалось подготовить карточку для шаринга.',
          transitionTick: 0,
          transitionDirection: transitionDirection || 'forward',
        });
      } finally {
        if (shareRequestTokenRef.current === requestToken) {
          setShareCardForCapture(null);
          setShareGenerating(false);
        }
      }
    })();
  }, [persistShareCardToCache, shareCardForCapture]);

  const handleSharePopupDownload = useCallback(() => {
    if (sharePopup.status !== 'ready') return;
    downloadBlob(sharePopup.blob, sharePopup.fileName);
  }, [sharePopup.blob, sharePopup.fileName, sharePopup.status]);

  const handleShareTemplateChange = useCallback((templateKey) => {
    if (!shareSession || !templateKey || sharePopup.templateKey === templateKey) return;
    shareTransitionDirectionRef.current = getShareTemplateDirection(
      shareSession.templates,
      sharePopup.templateKey,
      templateKey,
    );
    void startShareGeneration(shareSession, templateKey);
  }, [sharePopup.templateKey, shareSession, startShareGeneration]);

  const shareTemplateTabs = useMemo(
    () => (shareSession?.templates || []).map((template) => template.key),
    [shareSession?.templates],
  );

  const updateShareTemplatePill = useCallback(() => {
    const tabs = shareTabsRef.current;
    if (!tabs) return;

    const activeButton = tabs.querySelector('.workout-share-popup-template.is-active');
    if (!activeButton) {
      setShareTemplatePillStyle({ left: 0, width: 0 });
      return;
    }

    setShareTemplatePillStyle({
      left: activeButton.offsetLeft,
      width: activeButton.offsetWidth,
    });
  }, []);

  useLayoutEffect(() => {
    updateShareTemplatePill();
  }, [sharePopup.open, sharePopup.templateKey, shareSession?.templates, updateShareTemplatePill]);

  useEffect(() => {
    if (!sharePopup.open || shareTemplateTabs.length < 2) return undefined;

    let frameId = 0;
    const scheduleUpdate = () => {
      cancelAnimationFrame(frameId);
      frameId = window.requestAnimationFrame(updateShareTemplatePill);
    };

    const tabs = shareTabsRef.current;
    const resizeObserver = typeof ResizeObserver !== 'undefined' && tabs
      ? new ResizeObserver(scheduleUpdate)
      : null;

    if (tabs && resizeObserver) {
      resizeObserver.observe(tabs);
      tabs.querySelectorAll('.workout-share-popup-template').forEach((item) => resizeObserver.observe(item));
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
  }, [sharePopup.open, shareTemplateTabs.length, updateShareTemplatePill]);

  useSwipeableTabs({
    containerRef: sharePreviewRef,
    tabs: shareTemplateTabs,
    activeTab: sharePopup.templateKey,
    onTabChange: handleShareTemplateChange,
    enabled: sharePopup.open && sharePopup.status === 'ready' && shareTemplateTabs.length > 1,
  });

  const handleSharePopupShare = useCallback(async () => {
    if (sharePopup.status !== 'ready') return;
    const shareResult = await shareImageFile(sharePopup.blob, sharePopup.fileName);
    if (shareResult.shared) {
      closeSharePopup();
      return;
    }
    if (!shareResult.cancelled) {
      handleSharePopupDownload();
    }
  }, [closeSharePopup, handleSharePopupDownload, shareImageFile, sharePopup.blob, sharePopup.fileName, sharePopup.status]);

  /* ── Delete handler ── */
  const handleDeleteWorkout = useCallback(async () => {
    if (!onDelete || !displayedWorkouts?.length || deleting) return;
    const w = displayedWorkouts[0];
    const wId = w.is_manual ? w.id : (w.id ?? w.workout_id);
    if (!wId) return;
    const msg = w.is_manual ? 'Удалить эту запись?' : 'Удалить тренировку и все данные?';
    if (!window.confirm(msg)) return;
    setDeleting(true);
    try { await api.deleteWorkout(wId, !!w.is_manual); onDelete(); onClose(); }
    catch (err) { alert('Ошибка: ' + (err?.message || 'Не удалось удалить')); }
    finally { setDeleting(false); }
  }, [onDelete, displayedWorkouts, deleting, api, onClose]);

  /* ── Derived data ── */
  const timeline = workout?.id ? timelineData[workout.id] : null;
  const workoutLaps = workout?.id ? lapsData[workout.id] : null;
  const hasGps = timeline?.some(p => p.latitude != null && p.longitude != null);
  const hasLaps = Array.isArray(workoutLaps) && workoutLaps.length > 0;
  const hasTimeline = timeline?.length > 0;
  const intervalPattern = hasLaps ? detectIntervalPattern(workoutLaps) : null;
  const sourceLabel = workout?.source && !workout.is_manual ? getSourceLabel(workout.source) : null;
  const workoutDate = workout?.start_time ? new Date(workout.start_time) : (date ? new Date(date + 'T12:00:00') : null);
  const activityLabel = getWorkoutDisplayType(workout) ? getActivityTypeLabel(getWorkoutDisplayType(workout)) : null;

  const loadAiAnalysis = useCallback(async () => {
    if (!api || !date || aiAnalysis || aiAnalysisLoading) return;
    setAiAnalysisLoading(true);
    try {
      const res = await api.analyzeWorkoutAi(date, 0);
      const data = res?.data || res;
      setAiAnalysis(data);
    } catch {
      setAiAnalysis({ error: true });
    } finally {
      setAiAnalysisLoading(false);
    }
  }, [api, date, aiAnalysis, aiAnalysisLoading]);

  useEffect(() => {
    if (activeTab === 'ai' && !aiAnalysis && !aiAnalysisLoading) {
      loadAiAnalysis();
    }
  }, [activeTab, aiAnalysis, aiAnalysisLoading, loadAiAnalysis]);

  useEffect(() => {
    if (!isOpen) {
      setAiAnalysis(null);
      setAiAnalysisLoading(false);
    }
  }, [isOpen]);

  // Available tabs
  const availableTabs = useMemo(() => {
    if (!workout) return [];
    return TABS.filter(t => {
      if (t.key === 'ai') return !workout.is_manual;
      if (t.key === 'details') return !workout.is_manual;
      if (t.key === 'laps') return hasLaps;
      if (t.key === 'charts') return hasTimeline && !workout.is_manual;
      return true;
    });
  }, [workout, hasLaps, hasTimeline]);

  /* ── Title ── */
  const titleNode = (
    <>
      <span className="workout-details-modal-title--short">
        {date ? new Date(date + 'T00:00:00').toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' }) : ''}
        {activityLabel && <> {activityLabel.toUpperCase()}</>}
      </span>
      <span className="workout-details-modal-title--full">
        {date ? new Date(date + 'T00:00:00').toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' }) : ''}
        {activityLabel && <> {activityLabel.toUpperCase()}</>}
      </span>
    </>
  );

  /* ── Portal for share ── */
  const portalTarget = typeof document !== 'undefined' && (document.getElementById('modal-root') || document.body);
  const shareElements = portalTarget && (shareCardForCapture || sharePopup.open) ? createPortal(
    <>
      {shareCardForCapture && (
        <div
          ref={shareCardRef}
          className="workout-share-capture-wrap"
          style={{ position: 'fixed', left: 0, top: 0, width: 420, opacity: 0.001, pointerEvents: 'none', zIndex: 0 }}
          data-theme="light"
        >
          <WorkoutShareCard
            date={shareCardForCapture.date}
            workout={shareCardForCapture.workout}
            timeline={shareCardForCapture.timeline}
            staticMapUrl={shareCardForCapture.staticMapUrl}
            staticMapAttribution={shareCardForCapture.staticMapAttribution}
            variant={shareCardForCapture.templateKey}
          />
        </div>
      )}
      <div className="workout-share-popup-overlay" onClick={closeSharePopup}>
        <div className="workout-share-popup" onClick={(e) => e.stopPropagation()}>
          <button type="button" className="workout-share-popup-close" onClick={closeSharePopup} aria-label="Закрыть"><CloseIcon className="modal-close-icon" /></button>
          {shareSession?.templates?.length > 1 && (
            <div className="workout-share-popup-toolbar">
              <div
                ref={shareTabsRef}
                className="workout-share-popup-templates"
                style={{
                  '--workout-share-tabs-pill-left': `${shareTemplatePillStyle.left}px`,
                  '--workout-share-tabs-pill-width': `${shareTemplatePillStyle.width}px`,
                }}
              >
                <span className="workout-share-popup-templates-pill" aria-hidden="true" />
                {shareSession.templates.map((template) => (
                  <button
                    key={template.key}
                    type="button"
                    className={`workout-share-popup-template${sharePopup.templateKey === template.key ? ' is-active' : ''}`}
                    onClick={() => handleShareTemplateChange(template.key)}
                    aria-pressed={sharePopup.templateKey === template.key}
                  >
                    {template.label}
                  </button>
                ))}
              </div>
            </div>
          )}
          {sharePopup.status === 'loading' && !sharePopup.previewUrl && (
            <div className="workout-share-popup-state">
              <LogoLoading size="sm" />
              <div className="workout-share-popup-state-title">Готовим карточку</div>
              <div className="workout-share-popup-state-text">Сейчас соберем красивую картинку для шаринга.</div>
            </div>
          )}
          {sharePopup.status === 'error' && (
            <div className="workout-share-popup-state workout-share-popup-state--error">
              <div className="workout-share-popup-state-title">Не получилось создать изображение</div>
              <div className="workout-share-popup-state-text">{sharePopup.error || 'Попробуйте еще раз через пару секунд.'}</div>
            </div>
          )}
          {(sharePopup.status === 'ready' || sharePopup.status === 'switching') && sharePopup.previewUrl && (
            <>
              <div
                ref={sharePreviewRef}
                className={`workout-share-popup-image-wrap${sharePopup.status === 'switching' ? ' is-switching' : ''}`}
              >
                <img
                  key={`${sharePopup.previewUrl}-${sharePopup.transitionTick || 0}`}
                  src={sharePopup.previewUrl}
                  alt="Тренировка"
                  className={`workout-share-popup-image${sharePopup.status === 'ready' ? ` is-entering is-${sharePopup.transitionDirection || 'forward'}` : ''}`}
                />
                {sharePopup.status === 'switching' && (
                  <div className="workout-share-popup-image-overlay" aria-live="polite">
                    <LogoLoading size="sm" />
                    <span>Переключаем шаблон…</span>
                  </div>
                )}
              </div>
              {sharePopup.status === 'ready' && (
                <div className="workout-share-popup-actions">
                  <button type="button" className="btn btn-primary btn--block" onClick={handleSharePopupDownload}>Сохранить</button>
                  <button type="button" className="btn btn-secondary btn--block" onClick={handleSharePopupShare}>Поделиться</button>
                </div>
              )}
            </>
          )}
        </div>
      </div>
    </>,
    portalTarget,
  ) : null;

  /* ── Render ── */
  return (
    <>
      <Modal isOpen={isOpen} onClose={onClose} title={titleNode} size="medium" variant="modern" mobilePresentation="fullscreen">
        {loading ? (
          <div className="workout-details-loading"><LogoLoading size="sm" /></div>
        ) : workout ? (
          <div className="wd">
            {/* ── Карта наверху (если есть GPS) ── */}
            {hasGps && !workout.is_manual && (
              <div className="wd-map">
                <Suspense fallback={null}>
                  <LeafletRouteMap timeline={timeline} hoverIndex={timelineHoverIndex} />
                </Suspense>
              </div>
            )}

            {/* ── Tabs ── */}
            {availableTabs.length > 1 && (
              <div className="wd-tabs">
                {availableTabs.map(t => (
                  <button
                    key={t.key}
                    type="button"
                    className={`wd-tab${activeTab === t.key ? ' is-active' : ''}`}
                    onClick={() => setActiveTab(t.key)}
                  >
                    {t.label}
                  </button>
                ))}
              </div>
            )}

            {/* ── Tab: Обзор ── */}
            {activeTab === 'overview' && (
              <div className="wd-tab-content">
                {/* Ключевые метрики — карточки в стиле приложения */}
                <div className="wd-overview-cards">
                  {workout.distance_km && (
                    <div className="wd-card wd-card--hero">
                      <div className="wd-card-value">{Number(workout.distance_km).toFixed(2).replace('.', ',')}</div>
                      <div className="wd-card-sub">км · Дистанция</div>
                    </div>
                  )}
                  <div className="wd-card-row">
                    {formatDuration(workout) && (
                      <div className="wd-card">
                        <div className="wd-card-value">{formatDuration(workout)}</div>
                        <div className="wd-card-sub">Время</div>
                      </div>
                    )}
                    {workout.avg_pace && (
                      <div className="wd-card">
                        <div className="wd-card-value">{workout.avg_pace} <span className="wd-card-unit">/км</span></div>
                        <div className="wd-card-sub">Средний темп</div>
                      </div>
                    )}
                  </div>
                  {(workout.avg_heart_rate || workout.max_heart_rate) && (
                    <div className="wd-card-row">
                      {workout.avg_heart_rate && (
                        <div className="wd-card">
                          <div className="wd-card-value">{workout.avg_heart_rate} <span className="wd-card-unit">уд/м</span></div>
                          <div className="wd-card-sub">Средний пульс</div>
                        </div>
                      )}
                      {workout.max_heart_rate && (
                        <div className="wd-card">
                          <div className="wd-card-value">{workout.max_heart_rate} <span className="wd-card-unit">уд/м</span></div>
                          <div className="wd-card-sub">Макс. пульс</div>
                        </div>
                      )}
                    </div>
                  )}
                </div>

                {workout.notes && (
                  <div className="wd-notes">
                    <div className="wd-notes-text">{workout.notes}</div>
                  </div>
                )}

                <div className="wd-actions">
                  <button type="button" className="btn btn-secondary wd-action-btn" onClick={handleShare} disabled={shareGenerating}>
                    {shareGenerating ? 'Создание…' : 'Поделиться'}
                  </button>
                  {onEdit && workout.is_manual && (
                    <button type="button" className="btn btn-secondary wd-action-btn" onClick={onEdit}>Редактировать</button>
                  )}
                  {onDelete && (
                    <button type="button" className="btn btn-secondary btn--danger-text wd-action-btn" onClick={handleDeleteWorkout} disabled={deleting}>
                      {deleting ? 'Удаление…' : 'Удалить'}
                    </button>
                  )}
                </div>
              </div>
            )}

            {/* ── Tab: AI-анализ ── */}
            {activeTab === 'ai' && (
              <div className="wd-tab-content wd-ai-analysis">
                {aiAnalysisLoading && (
                  <div className="wd-ai-loading">
                    <LogoLoading size="sm" />
                    <p className="wd-ai-loading-text">ИИ анализирует тренировку...</p>
                  </div>
                )}
                {aiAnalysis && !aiAnalysis.error && (
                  <>
                    {aiAnalysis.ai_narrative && (
                      <div className="wd-ai-narrative">
                        <p>{aiAnalysis.ai_narrative}</p>
                      </div>
                    )}
                    {aiAnalysis.pace_analysis?.splits?.length > 0 && (
                      <div className="wd-ai-splits">
                        <h4 className="wd-ai-section-title">Темп по км</h4>
                        <div className="wd-ai-splits-list">
                          {aiAnalysis.pace_analysis.splits.map(s => (
                            <div key={s.km} className="wd-ai-split-row">
                              <span className="wd-ai-split-km">{s.km} км</span>
                              <span className="wd-ai-split-pace">{s.pace}</span>
                              {s.avg_hr && <span className="wd-ai-split-hr">{s.avg_hr} уд</span>}
                            </div>
                          ))}
                        </div>
                        {aiAnalysis.pace_analysis.split_type && (
                          <p className="wd-ai-split-type">
                            {aiAnalysis.pace_analysis.split_type === 'negative_split' ? 'Негативный сплит — ускорение к финишу' :
                             aiAnalysis.pace_analysis.split_type === 'positive_split' ? 'Позитивный сплит — замедление к финишу' :
                             'Ровный темп'}
                          </p>
                        )}
                      </div>
                    )}
                    {aiAnalysis.hr_zones?.length > 0 && (
                      <div className="wd-ai-zones">
                        <h4 className="wd-ai-section-title">Зоны ЧСС</h4>
                        <div className="wd-ai-zones-list">
                          {aiAnalysis.hr_zones.map(z => (
                            <div key={z.zone} className="wd-ai-zone-row">
                              <span className="wd-ai-zone-name">{z.zone}</span>
                              <div className="wd-ai-zone-bar-wrap">
                                <div className="wd-ai-zone-bar" style={{ width: `${Math.min(z.percent, 100)}%` }} />
                              </div>
                              <span className="wd-ai-zone-pct">{z.percent}%</span>
                            </div>
                          ))}
                        </div>
                      </div>
                    )}
                  </>
                )}
                {aiAnalysis?.error && (
                  <div className="wd-ai-error">Не удалось загрузить анализ</div>
                )}
              </div>
            )}

            {/* ── Tab: Данные ── */}
            {activeTab === 'details' && (
              <div className="wd-tab-content">
                <div className="wd-details-list">
                  {workout.distance_km && (
                    <div className="wd-detail-row">
                      <span className="wd-detail-label">Дистанция</span>
                      <span className="wd-detail-value">{workout.distance_km} км</span>
                    </div>
                  )}
                  {formatDuration(workout) && (
                    <div className="wd-detail-row">
                      <span className="wd-detail-label">Время</span>
                      <span className="wd-detail-value">{formatDuration(workout)}</span>
                    </div>
                  )}
                  {workout.avg_pace && (
                    <div className="wd-detail-row">
                      <span className="wd-detail-label">Средний темп</span>
                      <span className="wd-detail-value">{workout.avg_pace} /км</span>
                    </div>
                  )}
                  {workout.avg_heart_rate && (
                    <div className="wd-detail-row">
                      <span className="wd-detail-label">Средний пульс</span>
                      <span className="wd-detail-value">{workout.avg_heart_rate} уд/мин</span>
                    </div>
                  )}
                  {workout.max_heart_rate && (
                    <div className="wd-detail-row">
                      <span className="wd-detail-label">Макс. пульс</span>
                      <span className="wd-detail-value">{workout.max_heart_rate} уд/мин</span>
                    </div>
                  )}
                  {workout.elevation_gain && (
                    <div className="wd-detail-row">
                      <span className="wd-detail-label">Набор высоты</span>
                      <span className="wd-detail-value">{Math.round(workout.elevation_gain)} м</span>
                    </div>
                  )}
                  {workout.cadence && (
                    <div className="wd-detail-row">
                      <span className="wd-detail-label">Каденс</span>
                      <span className="wd-detail-value">{workout.cadence} шаг/мин</span>
                    </div>
                  )}
                  {workout.calories && (
                    <div className="wd-detail-row">
                      <span className="wd-detail-label">Калории</span>
                      <span className="wd-detail-value">{workout.calories} ккал</span>
                    </div>
                  )}
                  {sourceLabel && (
                    <div className="wd-detail-row">
                      <span className="wd-detail-label">Источник</span>
                      <span className="wd-detail-value">{sourceLabel}</span>
                    </div>
                  )}
                  {workout.start_time && (
                    <div className="wd-detail-row">
                      <span className="wd-detail-label">Начало</span>
                      <span className="wd-detail-value">{workoutDate.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', second: '2-digit' })}</span>
                    </div>
                  )}
                  {workout.id && (
                    <div className="wd-detail-row">
                      <span className="wd-detail-label">ID тренировки</span>
                      <span className="wd-detail-value">#{workout.id}</span>
                    </div>
                  )}
                </div>
              </div>
            )}

            {/* ── Tab: Круги ── */}
            {activeTab === 'laps' && hasLaps && (
              <div className="wd-tab-content">
                <div className="workout-details-laps">
                  <div className="workout-details-laps-grid">
                    <table className="workout-details-laps-table">
                      <colgroup>
                        <col className="workout-details-laps-col workout-details-laps-col--lap" />
                        <col className="workout-details-laps-col workout-details-laps-col--distance" />
                        <col className="workout-details-laps-col workout-details-laps-col--time" />
                        <col className="workout-details-laps-col workout-details-laps-col--pace" />
                        <col className="workout-details-laps-col workout-details-laps-col--pulse" />
                      </colgroup>
                      <thead>
                        <tr>
                          <th scope="col" className="workout-details-laps-head-cell workout-details-laps-head-cell--lap">Круг</th>
                          <th scope="col" className="workout-details-laps-head-cell workout-details-laps-head-cell--distance">Расст.</th>
                          <th scope="col" className="workout-details-laps-head-cell workout-details-laps-head-cell--time">Время</th>
                          <th scope="col" className="workout-details-laps-head-cell workout-details-laps-head-cell--pace">Темп</th>
                          <th scope="col" className="workout-details-laps-head-cell workout-details-laps-head-cell--pulse">Пульс</th>
                        </tr>
                      </thead>
                      <tbody>
                        {workoutLaps.map((lap, index) => {
                          const role = intervalPattern?.rolesByLapIndex?.[lap.lap_index] ?? null;
                          const lapHR = Number(lap.avg_heart_rate);
                          return (
                            <tr key={`${workout.id}-${lap.lap_index}`} className={`workout-details-lap-row${role ? ` is-${role}` : ''}`}>
                              <td className="workout-details-lap-cell workout-details-lap-cell--name" title={getLapLabel(lap)}>{getLapTableLabel(lap, index + 1)}</td>
                              <td className="workout-details-lap-cell workout-details-lap-cell--distance">{formatLapDistance(lap.distance_km) ?? '—'}</td>
                              <td className="workout-details-lap-cell workout-details-lap-cell--time">{formatLapDuration(lap.moving_seconds ?? lap.elapsed_seconds) ?? '—'}</td>
                              <td className="workout-details-lap-cell workout-details-lap-cell--pace">{formatLapPace(lap) ?? '—'}</td>
                              <td className="workout-details-lap-cell workout-details-lap-cell--pulse">{Number.isFinite(lapHR) && lapHR > 0 ? Math.round(lapHR) : '—'}</td>
                            </tr>
                          );
                        })}
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            )}

            {/* ── Tab: Графики ── */}
            {activeTab === 'charts' && hasTimeline && (
              <div className="wd-tab-content">
                <HeartRateChart timeline={timeline} onHoverIndex={setTimelineHoverIndex} />
                <PaceChart timeline={timeline} onHoverIndex={setTimelineHoverIndex} />
              </div>
            )}
          </div>
        ) : (
          <div className="workout-details-empty">Нет данных о тренировке</div>
        )}
      </Modal>
      {shareElements}
    </>
  );
};

export default WorkoutDetailsModal;
