/**
 * Экран настроек профиля пользователя
 * Полная реализация с вкладками и всеми полями профиля
 */

import { useState, useEffect, useRef, useCallback } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import useAuthStore from '../stores/useAuthStore';
import useWorkoutRefreshStore from '../stores/useWorkoutRefreshStore';
import { useIsTabActive } from '../hooks/useIsTabActive';
import { useMediaQuery } from '../hooks/useMediaQuery';
import { useSwipeableTabs } from '../hooks/useSwipeableTabs';
import BiometricService from '../services/BiometricService';
import PinAuthService from '../services/PinAuthService';
import WebPushService from '../services/WebPushService';
import { isNativeCapacitor } from '../services/TokenStorageService';
import PinSetupModal from '../components/common/PinSetupModal';
import SkeletonScreen from '../components/common/SkeletonScreen';
import useHealthConnect from '../components/Integrations/useHealthConnect';
import { getAvatarSrc } from '../utils/avatarUrl';
import { CloseIcon, MailIcon, MessageCircleIcon, SmartphoneIcon } from '../components/common/Icons';
import { useMyCoaches } from './settings/useMyCoaches';
import { useCoachPricing } from './settings/useCoachPricing';
import { createInitialFormData } from './settings/profileForm';
import { NOTIFICATION_CHANNELS, ensureNotificationChannelsEnabled, normalizeNotificationSettings, createInitialNotificationSettings } from './settings/notificationSettings';
import { useSettingsActions } from './settings/useSettingsActions';
import { useSettingsProfile } from './settings/useSettingsProfile';
import { applyTheme, getSystemTheme, getThemePreference, VALID_TABS } from './settings/settingsUtils';
import SettingsV3 from './settings/v3/SettingsV3';
import { catById, catByTab } from './settings/v3/catalog';
import './SettingsScreen.css';

const TELEGRAM_LINK_PENDING_STORAGE_KEY = 'planrun.telegramLinkPendingAt';
const TELEGRAM_LINK_PENDING_MAX_AGE_MS = 30 * 60 * 1000;

function detectIOSDevice() {
  if (typeof navigator === 'undefined') {
    return false;
  }

  const ua = navigator.userAgent || '';
  const platform = navigator.platform || '';

  return /iPad|iPhone|iPod/.test(ua) || (platform === 'MacIntel' && navigator.maxTouchPoints > 1);
}

function getTelegramLinkPendingTimestamp() {
  if (typeof window === 'undefined') {
    return null;
  }

  try {
    const rawValue = window.localStorage.getItem(TELEGRAM_LINK_PENDING_STORAGE_KEY);
    if (!rawValue) {
      return null;
    }

    const timestamp = Number(rawValue);
    if (!Number.isFinite(timestamp)) {
      window.localStorage.removeItem(TELEGRAM_LINK_PENDING_STORAGE_KEY);
      return null;
    }

    if ((Date.now() - timestamp) > TELEGRAM_LINK_PENDING_MAX_AGE_MS) {
      window.localStorage.removeItem(TELEGRAM_LINK_PENDING_STORAGE_KEY);
      return null;
    }

    return timestamp;
  } catch (_) {
    return null;
  }
}

function markTelegramLinkPending() {
  if (typeof window === 'undefined') {
    return;
  }

  try {
    window.localStorage.setItem(TELEGRAM_LINK_PENDING_STORAGE_KEY, String(Date.now()));
  } catch (_) {
    // Ignore storage errors
  }
}

function clearTelegramLinkPending() {
  if (typeof window === 'undefined') {
    return;
  }

  try {
    window.localStorage.removeItem(TELEGRAM_LINK_PENDING_STORAGE_KEY);
  } catch (_) {
    // Ignore storage errors
  }
}

function getBrowserNotificationRecoveryText(permission) {
  if (permission === 'denied') {
    return 'Браузер уже заблокировал уведомления для этого сайта. Нажмите на значок замка рядом с адресом сайта, откройте пункт "Уведомления" и переключите его в "Разрешить", затем обновите страницу.';
  }

  if (permission === 'default') {
    return 'Нажмите кнопку ниже, и браузер покажет системное окно с запросом на уведомления.';
  }

  return '';
}

function BrowserWindowIcon({ className = '', size = 16, ...props }) {
  return (
    <svg
      viewBox="0 0 24 24"
      width={size}
      height={size}
      className={className}
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      {...props}
    >
      <rect x="3.5" y="4.5" width="17" height="15" rx="3.25" />
      <path d="M3.5 8.5h17" />
      <circle cx="6.75" cy="6.5" r="0.9" fill="currentColor" stroke="none" />
    </svg>
  );
}

const SettingsScreen = ({ onLogout, inPanel = false }) => {
  const isTabActive = useIsTabActive('/settings');
  const [panelCat, setPanelCat] = useState(null);
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const { api, updateUser, user: currentUser, logout } = useAuthStore();
  const tabFromUrl = searchParams.get('tab');
  const activeTab = tabFromUrl && VALID_TABS.includes(tabFromUrl) ? tabFromUrl : 'profile';
  // В панели навигация локальная (panelCat) — load-эффекты ориентируем на неё.
  const effectiveTab = inPanel ? (catById(panelCat)?.tab || 'profile') : activeTab;
  const settingsPanelsRef = useRef(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [hasUnsavedChanges, setHasUnsavedChanges] = useState(false);
  const [message, setMessage] = useState({ type: '', text: '' });
  const [csrfToken, setCsrfToken] = useState('');
  const skipNextAutoSaveRef = useRef(true); // не сохранять при первой установке formData из loadProfile
  const [themePreference, setThemePreference] = useState(getThemePreference);
  const [showBiometricSection, setShowBiometricSection] = useState(false);
  const [biometricAvailable, setBiometricAvailable] = useState(false);
  const [biometricEnabled, setBiometricEnabled] = useState(false);
  const [biometricEnabling, setBiometricEnabling] = useState(false);
  const [pinEnabled, setPinEnabled] = useState(false);
  const [pinDisabling, setPinDisabling] = useState(false);
  const [showPinSetupModal, setShowPinSetupModal] = useState(false);
  const [pinSetupTokens, setPinSetupTokens] = useState(null);
  const [integrationsStatus, setIntegrationsStatus] = useState({ huawei: false, strava: false, polar: false, garmin: false, coros: false, suunto: false });
  const [huaweiSyncing, setHuaweiSyncing] = useState(false);
  const [stravaSyncing, setStravaSyncing] = useState(false);
  const [polarSyncing, setPolarSyncing] = useState(false);
  const [garminSyncing, setGarminSyncing] = useState(false);
  const [corosSyncing, setCorosSyncing] = useState(false);
  const [suuntoSyncing, setSuuntoSyncing] = useState(false);
  const [suuntoMirror, setSuuntoMirrorState] = useState({ available: false, enabled: false, saving: false });
  const [stravaDebug, setStravaDebug] = useState(null);
  const [telegramLinkCode, setTelegramLinkCode] = useState(null);
  const [telegramLinkCodeLoading, setTelegramLinkCodeLoading] = useState(false);
  const [telegramLoginLoading, setTelegramLoginLoading] = useState(false);
  const telegramLoginPollRef = useRef(null);
  const telegramLoginTimeoutRef = useRef(null);
  const telegramLoginPollInFlightRef = useRef(false);
  const stravaPollRef = useRef(null);
  const stravaPollTimeoutRef = useRef(null);
  const [browserNotificationsSupported, setBrowserNotificationsSupported] = useState(false);
  const [browserNotificationPermission, setBrowserNotificationPermission] = useState('default');
  const [currentBrowserWebPushSubscribed, setCurrentBrowserWebPushSubscribed] = useState(false);
  const [, setCurrentBrowserWebPushEndpoint] = useState('');
  const [notificationActionLoading, setNotificationActionLoading] = useState('');
  const isMobileWebsiteViewport = useMediaQuery('(max-width: 1023px)');
  const isStandaloneDisplayMode = useMediaQuery('(display-mode: standalone)');
  const isNativeApp = isNativeCapacitor();
  const isMobileWeb = !isNativeApp && isMobileWebsiteViewport;
  const isIOSDevice = detectIOSDevice();
  const isStandaloneWebApp = !isNativeApp && (
    isStandaloneDisplayMode
    || (typeof navigator !== 'undefined' && Boolean(navigator.standalone))
  );
  const isIOSWebPushInstallRequired = !isNativeApp && isIOSDevice && !isStandaloneWebApp;

  // Состояние формы со всеми полями
  // ВАЖНО: Все поля должны быть инициализированы как строки/массивы, а не null
  // чтобы React правильно обрабатывал контролируемые компоненты
  const [formData, setFormData] = useState(createInitialFormData);
  const [slugStatus, setSlugStatus] = useState(null); // null | 'free' | 'taken'
  const [slugChecking, setSlugChecking] = useState(false);
  const { myCoaches, myCoachesLoading, removingCoachId, loadMyCoaches, handleRemoveCoach } = useMyCoaches(api, setMessage);
  // Health Connect (Android, нативно): состояние подключён/отключён + действия.
  const hc = useHealthConnect(api, (type, text) => {
    setMessage({ type, text });
    window.setTimeout(() => setMessage({ type: '', text: '' }), 3500);
  });
  const {
    coachPricing,
    coachPricingLoading,
    savingPricing,
    loadCoachPricing,
    handleAddPricingItem,
    handlePricingChange,
    handleRemovePricingItem,
    handleSavePricing,
  } = useCoachPricing(api, setMessage);
  const { loadProfile, handleInputChange, handleSave } = useSettingsProfile({
    api,
    formData,
    setFormData,
    setHasUnsavedChanges,
    csrfToken,
    setCsrfToken,
    setLoading,
    setSaving,
    setMessage,
    skipNextAutoSaveRef,
  });

  // Проверка доступности адреса профиля (slug) — переиспользуем validate_field('username').
  const checkSlugAvailability = async () => {
    const value = (formData.username || '').trim();
    if (value.length < 3) return;
    const currentApi = api || useAuthStore.getState().api;
    if (!currentApi) return;
    setSlugChecking(true);
    setSlugStatus(null);
    try {
      // Свой текущий slug считаем свободным (не ошибка «занят самим собой»).
      if (value === (currentUser?.username || '')) {
        setSlugStatus('free');
        return;
      }
      const res = await currentApi.validateField('username', value);
      setSlugStatus(res?.valid ? 'free' : 'taken');
    } catch {
      setSlugStatus(null);
    } finally {
      setSlugChecking(false);
    }
  };
  const {
    handleAddFingerprint,
    handleAvatarUpload,
    handleDisableLock,
    handleEnableLock,
    handleGenerateTelegramLinkCode,
    handlePinSetupSuccess,
    handleRemoveAvatar,
    handleUnlinkTelegram,
    runHuaweiSync,
    runStravaSync,
  } = useSettingsActions({
    api,
    csrfToken,
    setBiometricAvailable,
    setBiometricEnabled,
    setBiometricEnabling,
    setCsrfToken,
    setFormData,
    setHuaweiSyncing,
    setMessage,
    setPinDisabling,
    setPinEnabled,
    setPinSetupTokens,
    setShowPinSetupModal,
    setStravaSyncing,
    updateUser,
  });

  const stopTelegramLoginPolling = useCallback(() => {
    if (telegramLoginPollRef.current) {
      window.clearInterval(telegramLoginPollRef.current);
      telegramLoginPollRef.current = null;
    }
    if (telegramLoginTimeoutRef.current) {
      window.clearTimeout(telegramLoginTimeoutRef.current);
      telegramLoginTimeoutRef.current = null;
    }
    telegramLoginPollInFlightRef.current = false;
  }, []);

  const refreshTelegramConnection = useCallback(async () => {
    const currentApi = api || useAuthStore.getState().api;
    if (!currentApi) {
      return false;
    }

    const nextFormData = await loadProfile(currentApi, { silent: true });
    const linked = Boolean(nextFormData?.telegram_id);

    if (linked && typeof updateUser === 'function') {
      const authStore = useAuthStore.getState();
      updateUser({
        ...(authStore.user || currentUser || {}),
        telegram_id: nextFormData.telegram_id,
      });
    }

    return linked;
  }, [api, currentUser, loadProfile, updateUser]);

  const showTelegramConnectedMessage = useCallback(() => {
    setMessage({ type: 'success', text: 'Telegram успешно подключен' });
    window.setTimeout(() => setMessage({ type: '', text: '' }), 3000);
  }, [setMessage]);

  const handleTelegramConnected = useCallback(async () => {
    const linked = await refreshTelegramConnection();
    if (!linked) {
      return false;
    }

    stopTelegramLoginPolling();
    setTelegramLoginLoading(false);
    clearTelegramLinkPending();
    showTelegramConnectedMessage();
    return true;
  }, [refreshTelegramConnection, showTelegramConnectedMessage, stopTelegramLoginPolling]);

  const startTelegramLoginPolling = useCallback(() => {
    stopTelegramLoginPolling();

    const pollOnce = async () => {
      if (telegramLoginPollInFlightRef.current) {
        return;
      }

      telegramLoginPollInFlightRef.current = true;
      try {
        await handleTelegramConnected();
      } finally {
        telegramLoginPollInFlightRef.current = false;
      }
    };

    pollOnce();
    telegramLoginPollRef.current = window.setInterval(pollOnce, 3000);
    telegramLoginTimeoutRef.current = window.setTimeout(() => {
      stopTelegramLoginPolling();
      setTelegramLoginLoading(false);
    }, 180000);
  }, [handleTelegramConnected, stopTelegramLoginPolling]);

  // Обработка OAuth callback (connected=huawei|strava|polar|garmin|coros|telegram, error=...)
  useEffect(() => {
    const connected = searchParams.get('connected');
    const errorParam = searchParams.get('error');
    if (connected === 'huawei') {
      setIntegrationsStatus(prev => ({ ...prev, huawei: true }));
      setSearchParams({ tab: 'integrations' });
      let currentApi = api || useAuthStore.getState().api;
      if (!currentApi) {
        setTimeout(async () => {
          currentApi = useAuthStore.getState().api;
          if (currentApi) runHuaweiSync(currentApi, true);
        }, 1000);
      } else {
        runHuaweiSync(currentApi, true);
      }
    } else if (connected === 'strava') {
      setIntegrationsStatus(prev => ({ ...prev, strava: true }));
      setSearchParams({ tab: 'integrations' });
      // Fallback: если OAuth callback открылся в этой вкладке (попап был заблокирован)
      let currentApi = api || useAuthStore.getState().api;
      if (!currentApi) {
        setTimeout(async () => {
          currentApi = useAuthStore.getState().api;
          if (currentApi) runStravaSync(currentApi);
        }, 1000);
      } else {
        runStravaSync(currentApi);
      }
    } else if (connected === 'polar') {
      setIntegrationsStatus(prev => ({ ...prev, polar: true }));
      setMessage({ type: 'success', text: 'Polar успешно подключен' });
      setSearchParams({ tab: 'integrations' });
      setTimeout(() => setMessage({ type: '', text: '' }), 3000);
    } else if (connected === 'garmin') {
      setIntegrationsStatus(prev => ({ ...prev, garmin: true }));
      setMessage({ type: 'success', text: 'Garmin успешно подключен' });
      setSearchParams({ tab: 'integrations' });
      setTimeout(() => setMessage({ type: '', text: '' }), 3000);
    } else if (connected === 'coros') {
      setIntegrationsStatus(prev => ({ ...prev, coros: true }));
      setMessage({ type: 'success', text: 'COROS успешно подключен' });
      setSearchParams({ tab: 'integrations' });
      setTimeout(() => setMessage({ type: '', text: '' }), 3000);
    } else if (connected === 'suunto') {
      setIntegrationsStatus(prev => ({ ...prev, suunto: true }));
      setMessage({ type: 'success', text: 'Suunto успешно подключен' });
      setSearchParams({ tab: 'integrations' });
      setTimeout(() => setMessage({ type: '', text: '' }), 3000);
    } else if (connected === 'telegram') {
      setSearchParams({ tab: 'integrations' });
      handleTelegramConnected();
    } else if (errorParam) {
      stopTelegramLoginPolling();
      setTelegramLoginLoading(false);
      setMessage({ type: 'error', text: errorParam === 'not_authenticated' ? 'Требуется авторизация' : decodeURIComponent(errorParam) });
      setSearchParams({ tab: 'integrations' });
      const currentApi = api || useAuthStore.getState().api;
      const errDecoded = decodeURIComponent(errorParam || '');
      if (currentApi && (errDecoded.includes('Strava') || errDecoded.includes('токена'))) {
        currentApi.getStravaTokenError().then((res) => {
          const d = res?.data?.debug ?? res?.debug;
          if (d) setStravaDebug(d);
        }).catch(() => {});
      }
    }
  }, [api, handleTelegramConnected, runHuaweiSync, runStravaSync, searchParams, stopTelegramLoginPolling]);

  useEffect(() => {
    if (effectiveTab !== 'integrations') return;
    const currentApi = api || useAuthStore.getState().api;
    if (!currentApi) return;
    currentApi.getIntegrationsStatus()
      .then((res) => {
        const data = res?.data?.integrations ?? res?.integrations ?? {};
        setIntegrationsStatus(prev => ({ ...prev, ...data }));
        const avail = res?.data?.suunto_mirror_available ?? res?.suunto_mirror_available ?? false;
        const en = res?.data?.suunto_mirror_enabled ?? res?.suunto_mirror_enabled ?? false;
        setSuuntoMirrorState(prev => ({ ...prev, available: !!avail, enabled: !!en }));
      })
      .catch(() => {});
  }, [effectiveTab, api]);

  useEffect(() => {
    if (typeof window === 'undefined') {
      return;
    }

    const supported = WebPushService.isSupported();
    setBrowserNotificationsSupported(supported);
    setBrowserNotificationPermission(supported ? WebPushService.getPermission() : 'unsupported');

    // Track permission changes while page is open
    if (supported && window.Notification?.permission !== undefined) {
      const permStatus = navigator.permissions?.query?.({ name: 'notifications' });
      if (permStatus) {
        permStatus.then((status) => {
          status.onchange = () => {
            setBrowserNotificationPermission(status.state === 'prompt' ? 'default' : status.state);
          };
        }).catch(() => {});
      }
    }
  }, []);

  useEffect(() => {
    const onTelegramLoginMessage = async (event) => {
      if (event.origin !== window.location.origin) {
        return;
      }

      const data = event.data;
      if (!data || data.type !== 'planrun:telegram-login') {
        return;
      }

      if (data.status === 'connected') {
        const linked = await handleTelegramConnected();
        if (!linked) {
          startTelegramLoginPolling();
        }
        return;
      }

      if (data.status === 'error') {
        stopTelegramLoginPolling();
        setTelegramLoginLoading(false);
        setMessage({ type: 'error', text: data.message || 'Ошибка подключения Telegram' });
      }
    };

    window.addEventListener('message', onTelegramLoginMessage);
    return () => window.removeEventListener('message', onTelegramLoginMessage);
  }, [handleTelegramConnected, setMessage, startTelegramLoginPolling, stopTelegramLoginPolling]);

  useEffect(() => () => stopTelegramLoginPolling(), [stopTelegramLoginPolling]);

  // Cleanup Strava polling on unmount
  useEffect(() => () => {
    if (stravaPollRef.current) clearInterval(stravaPollRef.current);
    if (stravaPollTimeoutRef.current) clearTimeout(stravaPollTimeoutRef.current);
  }, []);

  useEffect(() => {
    if (formData.telegram_id) {
      return undefined;
    }

    if (!getTelegramLinkPendingTimestamp()) {
      return undefined;
    }

    const resumeTelegramCheck = () => {
      if (!formData.telegram_id && getTelegramLinkPendingTimestamp()) {
        startTelegramLoginPolling();
      }
    };

    const handleVisibilityChange = () => {
      if (document.visibilityState === 'visible') {
        resumeTelegramCheck();
      }
    };

    resumeTelegramCheck();
    window.addEventListener('focus', resumeTelegramCheck);
    window.addEventListener('pageshow', resumeTelegramCheck);
    document.addEventListener('visibilitychange', handleVisibilityChange);

    return () => {
      window.removeEventListener('focus', resumeTelegramCheck);
      window.removeEventListener('pageshow', resumeTelegramCheck);
      document.removeEventListener('visibilitychange', handleVisibilityChange);
    };
  }, [formData.telegram_id, startTelegramLoginPolling]);

  // Статус биометрии и PIN (только на Android/iOS; используем isNativeCapacitor для надёжной проверки)
  useEffect(() => {
    if (!isNativeCapacitor()) return;
    setShowBiometricSection(true);
    BiometricService.checkAvailability()
      .then((r) => {
        if (process.env.NODE_ENV !== 'production') {
          console.log('[Biometric] checkAvailability:', r);
        }
        setBiometricAvailable(r?.available ?? false);
      })
      .catch((err) => {
        console.warn('[Biometric] Settings availability check failed:', err);
        setBiometricAvailable(false);
      });
    BiometricService.isBiometricEnabled().then(setBiometricEnabled);
    PinAuthService.isPinEnabled().then(setPinEnabled);
  }, []);

  // Загрузка профиля
  const hasLoadedProfileRef = useRef(false);
  useEffect(() => {
    // В выезжающей панели грузим всегда (роут != /settings, isTabActive=false).
    if (!isTabActive && !inPanel && !hasLoadedProfileRef.current) return;
    const loadProfileData = async () => {
      if (!api) {
        // api ещё не готов — useEffect перезапустится когда api появится в сторе
        return;
      }
      hasLoadedProfileRef.current = true;
      await loadProfile(api);
    };

    loadProfileData();
  }, [api, isTabActive, inPanel, loadProfile]);
  

  const handleTabChange = (tab) => {
    setSearchParams({ tab });
    if (window.innerWidth <= 768) {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
  };

  const openTelegramBot = useCallback(async () => {
    if (telegramLinkCodeLoading || telegramLoginLoading) {
      return;
    }

    const fallbackUrl = 'https://t.me/running_cal_bot';
    const currentCode = telegramLinkCode?.code;
    const expiresAt = telegramLinkCode?.expiresAt ? new Date(telegramLinkCode.expiresAt).getTime() : 0;
    const hasFreshCode = Boolean(currentCode && expiresAt > Date.now() + 60_000);
    const popup = isNativeCapacitor() ? null : window.open('', '_blank');

    const navigateToTelegram = async (url) => {
      if (isNativeCapacitor()) {
        const { Browser } = await import('@capacitor/browser');
        await Browser.open({ url });
        return;
      }

      if (popup && !popup.closed) {
        popup.location.replace(url);
        return;
      }

      window.location.href = url;
    };

    setTelegramLinkCodeLoading(true);
    setTelegramLoginLoading(true);
    markTelegramLinkPending();

    try {
      let code = currentCode;

      if (!hasFreshCode) {
        const result = await handleGenerateTelegramLinkCode();
        if (result?.code) {
          setTelegramLinkCode(result);
          code = result.code;
        }
      }

      const deepLink = code
        ? `${fallbackUrl}?start=link_${code}`
        : fallbackUrl;

      startTelegramLoginPolling();
      await navigateToTelegram(deepLink);
    } catch (_) {
      startTelegramLoginPolling();
      await navigateToTelegram(fallbackUrl);
    } finally {
      setTelegramLinkCodeLoading(false);
    }
  }, [handleGenerateTelegramLinkCode, startTelegramLoginPolling, telegramLinkCode, telegramLinkCodeLoading, telegramLoginLoading]);

  const isTelegramConnecting = telegramLinkCodeLoading || telegramLoginLoading;

  useSwipeableTabs({
    containerRef: settingsPanelsRef,
    tabs: VALID_TABS,
    activeTab,
    onTabChange: handleTabChange,
    enabled: !loading,
  });

  // Автосохранение при изменении полей (debounce 800 ms), без сохранения при загрузке профиля
  useEffect(() => {
    if (skipNextAutoSaveRef.current) {
      skipNextAutoSaveRef.current = false;
      return;
    }
    if (loading) return;
    const timerId = setTimeout(() => {
      handleSave();
    }, 350);
    return () => clearTimeout(timerId);
  }, [formData]);

  useEffect(() => {
    const onBeforeUnload = (event) => {
      if (!hasUnsavedChanges && !saving) return;
      event.preventDefault();
      event.returnValue = '';
    };

    window.addEventListener('beforeunload', onBeforeUnload);
    return () => window.removeEventListener('beforeunload', onBeforeUnload);
  }, [hasUnsavedChanges, saving]);

  const handleLogout = async () => {
    await (onLogout || logout)();
    if (isNativeCapacitor()) {
      window.location.href = '/landing';
    } else {
      navigate('/login');
    }
  };


  const toggleDay = (field, day) => {
    setFormData(prev => {
      const currentDays = prev[field] || [];
      const newDays = currentDays.includes(day)
        ? currentDays.filter(d => d !== day)
        : [...currentDays, day];
      
      // Автоматически обновляем sessions_per_week если изменяем preferred_days
      const updates = { [field]: newDays };
      if (field === 'preferred_days') {
        updates.sessions_per_week = String(newDays.length);
      }
      
      return { ...prev, ...updates };
    });
  };

  const avatarDisplayName = (
    formData.username?.trim()
    || currentUser?.username?.trim()
    || currentUser?.email?.split('@')[0]
    || 'Ваш профиль'
  );

  const avatarInitials = avatarDisplayName
    .split(/[\s._-]+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join('');

  const notificationSettings = ensureNotificationChannelsEnabled(
    normalizeNotificationSettings(
      formData.notification_settings,
      formData.timezone || currentUser?.timezone || 'Europe/Moscow'
    )
  );
  const effectiveTelegramLinked = Boolean(
    notificationSettings.channels?.telegram?.available
    || notificationSettings.channels?.telegram?.linked
    || String(formData.telegram_id || '').trim()
  );
  const effectiveEmailAvailable = Boolean(
    notificationSettings.channels?.email?.available
    || String(formData.email || '').trim()
  );
  const effectiveNotificationSettings = {
    ...notificationSettings,
    channels: {
      ...notificationSettings.channels,
      telegram: {
        ...(notificationSettings.channels?.telegram || {}),
        available: effectiveTelegramLinked,
        linked: effectiveTelegramLinked,
      },
      email: {
        ...(notificationSettings.channels?.email || {}),
        available: effectiveEmailAvailable,
      },
    },
  };
  const webPushChannel = notificationSettings.channels?.web_push || {};

  const updateNotificationSettings = useCallback((updater) => {
    setHasUnsavedChanges(true);
    setFormData((prev) => {
      const currentSettings = normalizeNotificationSettings(
        prev.notification_settings,
        prev.timezone || currentUser?.timezone || 'Europe/Moscow'
      );
      const nextSettings = typeof updater === 'function' ? updater(currentSettings) : updater;
      return {
        ...prev,
        notification_settings: ensureNotificationChannelsEnabled(
          normalizeNotificationSettings(nextSettings, prev.timezone || currentSettings.timezone)
        ),
      };
    });
  }, [currentUser?.timezone, setFormData, setHasUnsavedChanges]);

  const updateNotificationPreference = useCallback((eventKey, channelKey, enabled) => {
    updateNotificationSettings((prev) => ({
      ...prev,
      preferences: {
        ...prev.preferences,
        [eventKey]: {
          ...(prev.preferences?.[eventKey] || {}),
          [`${channelKey}_enabled`]: enabled,
        },
      },
    }));
  }, [updateNotificationSettings]);

  const updateNotificationTime = useCallback((field, fallbackValue, nextValue) => {
    updateNotificationSettings((prev) => ({
      ...prev,
      schedule: {
        ...prev.schedule,
        [field]: nextValue || fallbackValue,
      },
    }));
  }, [updateNotificationSettings]);

  const updateQuietHours = useCallback((field, value) => {
    updateNotificationSettings((prev) => ({
      ...prev,
      quiet_hours: {
        ...prev.quiet_hours,
        [field]: value,
      },
    }));
  }, [updateNotificationSettings]);

  const updatePaused = useCallback((value) => {
    updateNotificationSettings((prev) => ({
      ...prev,
      paused: Boolean(value),
    }));
  }, [updateNotificationSettings]);

  const refreshCurrentBrowserWebPushState = useCallback(async () => {
    if (!browserNotificationsSupported || browserNotificationPermission !== 'granted') {
      setCurrentBrowserWebPushSubscribed(false);
      setCurrentBrowserWebPushEndpoint('');
      return null;
    }

    try {
      const subscription = await WebPushService.getCurrentSubscription();
      const endpoint = subscription?.endpoint || '';
      setCurrentBrowserWebPushSubscribed(Boolean(endpoint));
      setCurrentBrowserWebPushEndpoint(endpoint);
      return subscription;
    } catch (_) {
      setCurrentBrowserWebPushSubscribed(false);
      setCurrentBrowserWebPushEndpoint('');
      return null;
    }
  }, [browserNotificationPermission, browserNotificationsSupported]);

  const handleBrowserNotificationPermission = useCallback(async (options = {}) => {
    if (!browserNotificationsSupported || typeof window === 'undefined' || typeof window.Notification === 'undefined') {
      return false;
    }

    if (window.Notification.permission === 'denied') {
      setBrowserNotificationPermission('denied');
      setMessage({
        type: 'error',
        text: getBrowserNotificationRecoveryText('denied'),
      });
      return false;
    }

    try {
      setNotificationActionLoading('web_push-permission');
      const permission = await window.Notification.requestPermission();
      setBrowserNotificationPermission(permission);
      if (permission === 'granted') {
        await refreshCurrentBrowserWebPushState();
        if (!options.silentSuccess) {
          setMessage({ type: 'success', text: 'Разрешение браузера выдано. Теперь можно подключить этот браузер к web push.' });
        }
        return true;
      } else if (permission === 'denied') {
        setMessage({
          type: 'error',
          text: getBrowserNotificationRecoveryText('denied'),
        });
      }
      return false;
    } catch (_) {
      setBrowserNotificationPermission(window.Notification.permission || 'default');
      setMessage({
        type: 'error',
        text: 'Браузер не открыл системный запрос. Попробуйте нажать кнопку ещё раз или проверьте, не заблокированы ли уведомления для сайта.',
      });
      return false;
    } finally {
      setNotificationActionLoading('');
    }
  }, [browserNotificationsSupported, refreshCurrentBrowserWebPushState, setMessage]);

  const syncWebPushSubscription = useCallback(async (options = {}) => {
    const currentApi = api || useAuthStore.getState().api;
    const vapidPublicKey = webPushChannel.public_key || '';

    if (!browserNotificationsSupported || !currentApi || !csrfToken) {
      return false;
    }
    if (browserNotificationPermission !== 'granted') {
      return false;
    }
    if (!webPushChannel.enabled) {
      return false;
    }
    if (!webPushChannel.delivery_ready || !vapidPublicKey) {
      return false;
    }

    try {
      setNotificationActionLoading('web_push-connect');
      const subscription = await WebPushService.ensureSubscription({
        api: currentApi,
        csrfToken,
        vapidPublicKey,
      });
      setCurrentBrowserWebPushSubscribed(Boolean(subscription?.endpoint));
      setCurrentBrowserWebPushEndpoint(subscription?.endpoint || '');
      await loadProfile(currentApi, { silent: true });
      if (!options.silent) {
        setMessage({ type: 'success', text: 'Web push подключён для этого браузера' });
      }
      return true;
    } catch (error) {
      if (!options.silent) {
        setMessage({ type: 'error', text: error.message || 'Не удалось подключить web push' });
      }
      return false;
    } finally {
      setNotificationActionLoading('');
    }
  }, [
    api,
    browserNotificationPermission,
    browserNotificationsSupported,
    csrfToken,
    loadProfile,
    setMessage,
    setNotificationActionLoading,
    webPushChannel.delivery_ready,
    webPushChannel.enabled,
    webPushChannel.public_key,
  ]);

  const disconnectCurrentBrowserWebPush = useCallback(async () => {
    const currentApi = api || useAuthStore.getState().api;
    if (!browserNotificationsSupported || !currentApi || !csrfToken) {
      return false;
    }

    try {
      setNotificationActionLoading('web_push-disconnect');
      const removed = await WebPushService.unregister({
        api: currentApi,
        csrfToken,
      });
      if (!removed) {
        setMessage({ type: 'error', text: 'Для этого браузера нет активной подписки' });
        return false;
      }

      setCurrentBrowserWebPushSubscribed(false);
      setCurrentBrowserWebPushEndpoint('');
      await loadProfile(currentApi, { silent: true });
      setMessage({ type: 'success', text: 'Этот браузер отключён от web push' });
      return true;
    } catch (error) {
      setMessage({ type: 'error', text: error.message || 'Не удалось отключить этот браузер' });
      return false;
    } finally {
      setNotificationActionLoading('');
    }
  }, [api, browserNotificationsSupported, csrfToken, loadProfile, setMessage]);

  const showIOSWebPushInstallInstructions = useCallback(() => {
    setMessage({
      type: 'success',
      text: 'На iPhone или iPad откройте меню "Поделиться" в Safari, выберите "На экран Домой", затем откройте сайт с домашнего экрана и снова включите уведомления.',
    });
  }, [setMessage]);

  const showWebPushBlockedInstructions = useCallback(() => {
    setMessage({
      type: 'error',
      text: getBrowserNotificationRecoveryText('denied'),
    });
  }, [setMessage]);

  const getWebPushSetupState = useCallback(() => {
    if (!webPushChannel.delivery_ready) {
      return {
        key: 'server_unavailable',
        summary: 'Уведомления в браузере пока недоступны.',
        actionLabel: '',
        action: null,
        actionBusy: false,
      };
    }

    if (isIOSWebPushInstallRequired) {
      return {
        key: 'install_required',
        summary: 'На iPhone и iPad добавьте сайт на экран Домой, чтобы включить уведомления.',
        actionLabel: 'Как установить',
        action: showIOSWebPushInstallInstructions,
        actionBusy: false,
      };
    }

    if (!browserNotificationsSupported) {
      return {
        key: 'unsupported',
        summary: 'Этот браузер не поддерживает уведомления сайта.',
        actionLabel: '',
        action: null,
        actionBusy: false,
      };
    }

    if (notificationActionLoading === 'web_push-permission') {
      return {
        key: 'requesting_permission',
        summary: 'Открываем системный запрос браузера...',
        actionLabel: 'Открываем...',
        action: handleBrowserNotificationPermission,
        actionBusy: true,
      };
    }

    if (notificationActionLoading === 'web_push-connect') {
      return {
        key: 'connecting',
        summary: 'Подключаем этот браузер к уведомлениям...',
        actionLabel: 'Подключаем...',
        action: () => syncWebPushSubscription(),
        actionBusy: true,
      };
    }

    if (notificationActionLoading === 'web_push-disconnect') {
      return {
        key: 'disconnecting',
        summary: 'Отключаем этот браузер от уведомлений...',
        actionLabel: 'Отключаем...',
        action: disconnectCurrentBrowserWebPush,
        actionBusy: true,
      };
    }

    if (browserNotificationPermission === 'denied') {
      return {
        key: 'denied',
        summary: 'Уведомления для этого сайта заблокированы в браузере.',
        actionLabel: 'Как разблокировать',
        action: showWebPushBlockedInstructions,
        actionBusy: false,
      };
    }

    if (browserNotificationPermission !== 'granted') {
      return {
        key: 'permission_required',
        summary: 'Разрешите уведомления, и браузер подключится автоматически.',
        actionLabel: 'Разрешить',
        action: async () => {
          const granted = await handleBrowserNotificationPermission({ silentSuccess: true });
          if (granted) {
            await syncWebPushSubscription();
          }
        },
        actionBusy: false,
      };
    }

    if (!currentBrowserWebPushSubscribed) {
      return {
        key: 'subscription_required',
        summary: 'Разрешение уже есть. Осталось подключить этот браузер.',
        actionLabel: 'Подключить браузер',
        action: () => syncWebPushSubscription(),
        actionBusy: false,
      };
    }

    return {
      key: 'connected',
      summary: 'Этот браузер уже подключён и будет получать выбранные события.',
      actionLabel: 'Отключить браузер',
      action: disconnectCurrentBrowserWebPush,
      actionBusy: false,
    };
  }, [
    browserNotificationPermission,
    browserNotificationsSupported,
    currentBrowserWebPushSubscribed,
    disconnectCurrentBrowserWebPush,
    handleBrowserNotificationPermission,
    isIOSWebPushInstallRequired,
    notificationActionLoading,
    showIOSWebPushInstallInstructions,
    showWebPushBlockedInstructions,
    syncWebPushSubscription,
    webPushChannel.delivery_ready,
  ]);

  const ensureNotificationChannelReady = useCallback(async (channelKey) => {
    const currentApi = api || useAuthStore.getState().api;
    const channel = effectiveNotificationSettings.channels?.[channelKey] || {};

    if (channelKey === 'web_push') {
      if (!webPushChannel.delivery_ready) {
        setMessage({ type: 'error', text: 'Web push ещё не настроен на сервере.' });
        return false;
      }
      if (isIOSWebPushInstallRequired) {
        showIOSWebPushInstallInstructions();
        return false;
      }
      if (!browserNotificationsSupported) {
        setMessage({ type: 'error', text: 'Этот браузер не поддерживает web push.' });
        return false;
      }
      if (browserNotificationPermission === 'denied') {
        showWebPushBlockedInstructions();
        return false;
      }
      if (!webPushChannel.public_key) {
        setMessage({ type: 'error', text: 'Web push ещё не настроен на сервере.' });
        return false;
      }
      if (currentBrowserWebPushSubscribed) {
        return true;
      }

      const permissionGranted = browserNotificationPermission === 'granted'
        ? true
        : await handleBrowserNotificationPermission({ silentSuccess: true });

      if (!permissionGranted) {
        return false;
      }

      return syncWebPushSubscription();
    }

    if (channelKey === 'mobile_push') {
      if (!isNativeApp) {
        if (channel.available) {
          return true;
        }
        setMessage({ type: 'error', text: 'Push на телефон можно подключить только в приложении.' });
        return false;
      }

      if (channel.available) {
        return true;
      }

      if (!currentApi) {
        setMessage({ type: 'error', text: 'API не инициализирован. Попробуйте обновить страницу.' });
        return false;
      }

      try {
        setNotificationActionLoading('mobile_push-connect');
        const { registerPushNotifications } = await import('../services/PushService');
        const result = await registerPushNotifications(currentApi);

        if (!result?.ok) {
          setMessage({ type: 'error', text: result?.reason || 'Не удалось подключить push на телефон.' });
          return false;
        }

        setMessage({ type: 'success', text: 'Разрешение на push получено. Устройство подключается к уведомлениям.' });
        setTimeout(() => {
          loadProfile(currentApi, { silent: true }).catch(() => {});
        }, 1200);
        return true;
      } catch (error) {
        setMessage({ type: 'error', text: error?.message || 'Не удалось подключить push на телефон.' });
        return false;
      } finally {
        setNotificationActionLoading('');
      }
    }

    if (channelKey === 'telegram') {
      if (!channel.available || !channel.linked) {
        setMessage({ type: 'error', text: 'Сначала подключите Telegram во вкладке интеграций.' });
        return false;
      }
      return true;
    }

    if (channelKey === 'email') {
      if (!channel.available) {
        setMessage({ type: 'error', text: 'Укажите email в профиле, чтобы получать письма.' });
        return false;
      }
      return true;
    }

    return true;
  }, [
    api,
    browserNotificationPermission,
    browserNotificationsSupported,
    currentBrowserWebPushSubscribed,
    effectiveNotificationSettings.channels,
    handleBrowserNotificationPermission,
    isIOSWebPushInstallRequired,
    isNativeApp,
    loadProfile,
    setMessage,
    showIOSWebPushInstallInstructions,
    showWebPushBlockedInstructions,
    syncWebPushSubscription,
    webPushChannel.delivery_ready,
    webPushChannel.public_key,
  ]);

  const handleNotificationPreferenceToggle = useCallback(async (eventKey, channelKey, enabled) => {
    if (!enabled) {
      updateNotificationPreference(eventKey, channelKey, false);
      return;
    }

    const isReady = await ensureNotificationChannelReady(channelKey);
    if (!isReady) {
      return;
    }

    updateNotificationPreference(eventKey, channelKey, true);
  }, [ensureNotificationChannelReady, updateNotificationPreference]);

  useEffect(() => {
    if (!browserNotificationsSupported) {
      return;
    }
    if (browserNotificationPermission !== 'granted') {
      return;
    }
    if (!webPushChannel.enabled) {
      return;
    }
    if (!webPushChannel.delivery_ready) {
      return;
    }

    syncWebPushSubscription({ silent: true }).catch(() => {});
  }, [
    browserNotificationPermission,
    browserNotificationsSupported,
    syncWebPushSubscription,
    webPushChannel.delivery_ready,
    webPushChannel.enabled,
  ]);

  useEffect(() => {
    refreshCurrentBrowserWebPushState().catch(() => {});
  }, [
    browserNotificationPermission,
    browserNotificationsSupported,
    notificationSettings.channels?.web_push?.subscriptions,
    refreshCurrentBrowserWebPushState,
  ]);

  const channelMeta = {
    mobile_push: {
      label: 'Push на телефон',
      shortLabel: 'Push',
      description: 'FCM для Android и iOS',
      Icon: SmartphoneIcon,
    },
    web_push: {
      label: 'Уведомления в браузере',
      shortLabel: 'Браузер',
      description: 'Разрешение браузера и web push',
      Icon: BrowserWindowIcon,
    },
    telegram: {
      label: 'Telegram',
      shortLabel: 'Telegram',
      description: 'Сообщения через подключённого бота',
      Icon: MessageCircleIcon,
      logoSrc: '/integrations/telegram.svg',
    },
    email: {
      label: 'Email',
      shortLabel: 'Email',
      description: 'Письма на адрес профиля',
      Icon: MailIcon,
    },
  };

  const visibleNotificationGroups = (notificationSettings.catalog || [])
    .map((group) => ({
      ...group,
      events: (group.events || []).filter((event) => {
        const roles = Array.isArray(event.roles) ? event.roles : [];
        return roles.length === 0 || roles.includes(currentUser?.role || 'user');
      }),
    }))
    .filter((group) => group.key !== 'system' && group.events.length > 0);

  const getEventChannelState = (event, channelKey) => {
    const supportsChannel = (event.channels || []).includes(channelKey);
    const channel = effectiveNotificationSettings.channels?.[channelKey] || {};
    const field = `${channelKey}_enabled`;
    const isChannelBusy = (channelKey === 'web_push' && notificationActionLoading.startsWith('web_push'))
      || (channelKey === 'mobile_push' && notificationActionLoading === 'mobile_push-connect');
    const checked = event.locked
      ? channelKey === 'email'
      : Boolean(effectiveNotificationSettings.preferences?.[event.event_key]?.[field]);
    const telegramNotLinkedHere = channelKey === 'telegram' && !channel.linked;
    const disabled = !supportsChannel
      || Boolean(event.locked)
      || isChannelBusy
      || telegramNotLinkedHere;

    return {
      supportsChannel,
      channel,
      checked,
      disabled,
    };
  };

  // Загрузка тренеров/цен при переходе на вкладку social
  useEffect(() => {
    if (effectiveTab === 'social' && api) {
      loadMyCoaches();
      const role = currentUser?.role;
      if (role === 'coach' || role === 'admin') loadCoachPricing();
    }
  }, [effectiveTab, api]);

  useEffect(() => {
    if (formData.telegram_id) {
      stopTelegramLoginPolling();
      setTelegramLoginLoading(false);
      setTelegramLinkCode(null);
      clearTelegramLinkPending();
    }
  }, [formData.telegram_id, stopTelegramLoginPolling]);

  // ── Редизайн v3: собираем ctx из существующего состояния/хендлеров (логику не дублируем) ──
  const avatarSrc = formData.avatar_path
    ? getAvatarSrc(formData.avatar_path, api?.baseUrl || '/api', 'md')
    : null;

  const onThemeChange = (value) => {
    setThemePreference(value);
    if (value === 'system') {
      localStorage.removeItem('theme');
      applyTheme(getSystemTheme());
    } else {
      localStorage.setItem('theme', value);
      applyTheme(value);
    }
  };

  const syncingSetters = {
    strava: setStravaSyncing, polar: setPolarSyncing, garmin: setGarminSyncing,
    coros: setCorosSyncing, suunto: setSuuntoSyncing, huawei: setHuaweiSyncing,
  };
  const syncingFlags = {
    strava: stravaSyncing, polar: polarSyncing, garmin: garminSyncing,
    coros: corosSyncing, suunto: suuntoSyncing, huawei: huaweiSyncing,
  };
  const PROVIDER_LABELS = { strava: 'Strava', polar: 'Polar', garmin: 'Garmin', coros: 'COROS', suunto: 'Suunto', huawei: 'Huawei Health' };

  const connectProvider = async (id) => {
    const currentApi = api || useAuthStore.getState().api;
    if (!currentApi) return;
    try {
      const native = isNativeCapacitor();
      const supportsNative = id === 'strava' || id === 'suunto' || id === 'huawei';
      const res = await currentApi.getIntegrationOAuthUrl(id, (supportsNative && native) ? { from_app: '1' } : {});
      const url = res?.data?.auth_url ?? res?.auth_url;
      if (!url) { setMessage({ type: 'error', text: 'Провайдер не настроен' }); return; }
      if (supportsNative && native) {
        const { Browser } = await import('@capacitor/browser');
        await Browser.open({ url });
        return;
      }
      if (id === 'strava') {
        window.open(url, '_blank');
        if (stravaPollRef.current) clearInterval(stravaPollRef.current);
        if (stravaPollTimeoutRef.current) clearTimeout(stravaPollTimeoutRef.current);
        stravaPollRef.current = setInterval(async () => {
          try {
            const statusRes = await currentApi.getIntegrationsStatus();
            const isConnected = statusRes?.data?.integrations?.strava ?? statusRes?.integrations?.strava ?? false;
            if (isConnected) {
              clearInterval(stravaPollRef.current);
              stravaPollRef.current = null;
              setIntegrationsStatus((prev) => ({ ...prev, strava: true }));
              runStravaSync(currentApi);
            }
          } catch { /* ignore */ }
        }, 3000);
        stravaPollTimeoutRef.current = setTimeout(() => {
          if (stravaPollRef.current) clearInterval(stravaPollRef.current);
          stravaPollRef.current = null;
        }, 300000);
        return;
      }
      window.location.href = url;
    } catch (e) {
      setMessage({ type: 'error', text: 'Ошибка: ' + (e?.message || '') });
    }
  };

  const syncProvider = async (id) => {
    const currentApi = api || useAuthStore.getState().api;
    if (!currentApi) return;
    if (id === 'huawei') { await runHuaweiSync(currentApi); return; }
    syncingSetters[id]?.(true);
    try {
      const res = await currentApi.syncWorkouts(id);
      setMessage({ type: 'success', text: `Синхронизировано: ${res?.data?.imported ?? res?.imported ?? 0} новых тренировок` });
      useWorkoutRefreshStore.getState().triggerRefresh();
      setTimeout(() => setMessage({ type: '', text: '' }), 3000);
    } catch (err) {
      setMessage({ type: 'error', text: 'Ошибка синхронизации: ' + (err?.message || '') });
    } finally {
      syncingSetters[id]?.(false);
    }
  };

  const unlinkProvider = async (id) => {
    if (!window.confirm(`Отвязать ${PROVIDER_LABELS[id] || id}?`)) return;
    const currentApi = api || useAuthStore.getState().api;
    if (!currentApi) return;
    try {
      await currentApi.unlinkIntegration(id);
      setIntegrationsStatus((prev) => ({ ...prev, [id]: false }));
      setMessage({ type: 'success', text: `${PROVIDER_LABELS[id] || id} отключен` });
      setTimeout(() => setMessage({ type: '', text: '' }), 3000);
    } catch (err) {
      setMessage({ type: 'error', text: 'Ошибка: ' + (err?.message || '') });
    }
  };

  const onSetSuuntoMirror = async (enabled) => {
    const currentApi = api || useAuthStore.getState().api;
    if (!currentApi) return;
    setSuuntoMirrorState((prev) => ({ ...prev, enabled, saving: true }));
    try {
      await currentApi.setSuuntoMirror(enabled);
      setMessage({ type: 'success', text: enabled ? 'Тренировки будут улетать в Suunto' : 'Зеркалирование в Suunto выключено' });
      setTimeout(() => setMessage({ type: '', text: '' }), 3000);
    } catch (err) {
      setSuuntoMirrorState((prev) => ({ ...prev, enabled: !enabled }));
      setMessage({ type: 'error', text: 'Ошибка: ' + (err?.message || '') });
    } finally {
      setSuuntoMirrorState((prev) => ({ ...prev, saving: false }));
    }
  };

  const onTestNotification = async () => {
    if (!api) return;
    try {
      const result = await api.request('send_test_notification', { channel: '' }, 'POST');
      if (result?.success || result?.sent) {
        alert('Тестовое уведомление отправлено в активные каналы. Проверьте их.');
      } else {
        alert(result?.error || 'Не удалось отправить тестовое уведомление');
      }
    } catch (e) {
      alert(e.message || 'Не удалось отправить тестовое уведомление');
    }
  };

  const onResetNotifications = () => {
    if (window.confirm('Сбросить настройки уведомлений к умолчаниям? Все события и расписание вернутся к стандартным значениям.')) {
      setFormData((prev) => ({
        ...prev,
        notification_settings: ensureNotificationChannelsEnabled(
          createInitialNotificationSettings(prev.timezone || 'Europe/Moscow')
        ),
      }));
    }
  };

  const v3AvailableChannels = NOTIFICATION_CHANNELS.filter((key) => {
    const ch = effectiveNotificationSettings.channels?.[key] || {};
    if (key === 'telegram') return true;
    if (key === 'mobile_push' && isMobileWeb) return false;
    if (key === 'web_push' && isNativeApp) return false;
    if (key === 'web_push') return true;
    if (key === 'mobile_push') return isNativeApp || ch.available;
    return true;
  });

  const isCoachRole = currentUser?.role === 'coach' || currentUser?.role === 'admin';
  const v3ActiveCat = tabFromUrl ? (catByTab(tabFromUrl)?.id || null) : null;
  const setCat = (idOrNull) => {
    if (!idOrNull) { setSearchParams({}); return; }
    const c = catById(idOrNull);
    setSearchParams(c ? { tab: c.tab } : {});
  };

  const settingsCtx = {
    formData, setFormData, onField: handleInputChange, api, currentUser,
    avatarSrc, avatarInitials, onAvatarUpload: handleAvatarUpload, onRemoveAvatar: handleRemoveAvatar,
    slugStatus, slugChecking, onCheckSlug: checkSlugAvailability, setSlugStatus,
    onToggleRunDay: (v) => toggleDay('preferred_days', v),
    onToggleOfpDay: (v) => toggleDay('preferred_ofp_days', v),
    themePreference, onThemeChange,
    showBiometricSection, pinEnabled, biometricEnabled, biometricAvailable, biometricEnabling, pinDisabling,
    onEnableLock: handleEnableLock, onDisableLock: handleDisableLock, onAddFingerprint: handleAddFingerprint,
    onChangePassword: () => navigate('/forgot-password'), onLogout: handleLogout,
    notificationSettings, effectiveNotificationSettings, availableChannels: v3AvailableChannels,
    channelMeta, visibleNotificationGroups, getEventChannelState,
    onToggleNotification: handleNotificationPreferenceToggle,
    updatePaused, updateNotificationTime, updateQuietHours,
    webPushSetupState: getWebPushSetupState(), showWebPushSetup: !isNativeApp,
    telegramNotLinked: !effectiveNotificationSettings.channels?.telegram?.linked,
    onResetNotifications, onTestNotification, goToTab: handleTabChange,
    myCoaches, myCoachesLoading, removingCoachId, onRemoveCoach: handleRemoveCoach,
    onFindTrainer: () => navigate('/trainers'),
    onEditCoachPage: () => navigate('/trainers/page'),
    isCoachRole, coachPricing, coachPricingLoading, savingPricing,
    onAddPricing: handleAddPricingItem, onPricingChange: handlePricingChange,
    onRemovePricing: handleRemovePricingItem, onSavePricing: handleSavePricing,
    integrationsStatus, syncingFlags, connectProvider, syncProvider, unlinkProvider,
    isTelegramConnecting, onConnectTelegram: openTelegramBot, onUnlinkTelegram: handleUnlinkTelegram,
    hc, suuntoMirror, onSetSuuntoMirror,
    activeCat: inPanel ? panelCat : v3ActiveCat, setCat: inPanel ? setPanelCat : setCat, onSave: handleSave, saving,
  };

  if (loading) {
    return (
      <div className="settings-container">
        <div className="settings-content">
          <SkeletonScreen type="settings" />
        </div>
      </div>
    );
  }

  return (
    <div className={`settings-container ${inPanel ? 'settings-in-panel' : 'settings-page'}`}>
      <div className="settings-content">
        {message.type === 'error' && message.text && (
          <div className="settings-message settings-message--error" role="alert">
            <span>{message.text}</span>
            <button
              type="button"
              className="settings-message-close"
              onClick={() => { setMessage({ type: '', text: '' }); setStravaDebug(null); }}
              aria-label="Закрыть"
            >
              <CloseIcon className="modal-close-icon" />
            </button>
          </div>
        )}
        {message.type === 'success' && message.text && (
          <div className="settings-message settings-message--success" role="status">
            <span>{message.text}</span>
          </div>
        )}
        {stravaDebug && effectiveTab === 'integrations' && (
          <div className="settings-message settings-message--error settings-strava-debug">
            <strong>Отладка Strava:</strong>
            <pre>
              HTTP {stravaDebug.http_code}
              redirect_uri: {stravaDebug.redirect_uri_used || '(не задан)'}
              Response: {stravaDebug.response || '(пусто)'}
            </pre>
            <button type="button" className="btn btn-secondary btn-sm settings-strava-debug-btn" onClick={() => setStravaDebug(null)}>Скрыть</button>
          </div>
        )}
        <SettingsV3 ctx={settingsCtx} layout={inPanel ? 'drill' : 'auto'} />
      </div>

      <PinSetupModal
        isOpen={showPinSetupModal}
        onClose={() => { setShowPinSetupModal(false); setPinSetupTokens(null); }}
        onSuccess={handlePinSetupSuccess}
        tokens={pinSetupTokens}
      />
    </div>
  );
};

export default SettingsScreen;
