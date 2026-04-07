/**
 * Экран настроек профиля пользователя
 * Полная реализация с вкладками и всеми полями профиля
 */

import { useState, useEffect, useRef, useCallback, useLayoutEffect } from 'react';
import { useSearchParams, Link } from 'react-router-dom';
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
import { getAvatarSrc } from '../utils/avatarUrl';
import { UserIcon, RunningIcon, LockIcon, LinkIcon, ImageIcon, PaletteIcon, TargetIcon, OtherIcon, UsersIcon, BellIcon, GraduationCapIcon, CloseIcon, MailIcon, MessageCircleIcon, SmartphoneIcon } from '../components/common/Icons';
import { useMyCoaches } from './settings/useMyCoaches';
import { useCoachPricing } from './settings/useCoachPricing';
import { createInitialFormData, daysOfWeek } from './settings/profileForm';
import { NOTIFICATION_CHANNELS, ensureNotificationChannelsEnabled, normalizeNotificationSettings } from './settings/notificationSettings';
import { useSettingsActions } from './settings/useSettingsActions';
import { useSettingsProfile } from './settings/useSettingsProfile';
import { applyTheme, getSystemTheme, getThemePreference, VALID_TABS } from './settings/settingsUtils';
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

function getNotificationReminderTime(eventKey, schedule) {
  if (eventKey === 'workout.reminder.today') {
    return schedule?.workout_today_time || '08:00';
  }

  if (eventKey === 'workout.reminder.tomorrow') {
    return schedule?.workout_tomorrow_time || '20:00';
  }

  return '';
}

function getNotificationMobileSummary(event, schedule) {
  const reminderTime = getNotificationReminderTime(event.event_key, schedule);
  return reminderTime || '';
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

function renderNotificationChannelVisual(channelConfig, className, size = 16) {
  if (channelConfig.logoSrc) {
    return (
      <img
        src={channelConfig.logoSrc}
        alt=""
        aria-hidden="true"
        className={className}
      />
    );
  }

  const Icon = channelConfig.Icon;
  return <Icon size={size} className={className} />;
}

const SettingsScreen = () => {
  const isTabActive = useIsTabActive('/settings');
  const [searchParams, setSearchParams] = useSearchParams();
  const { api, updateUser, user: currentUser } = useAuthStore();
  const tabFromUrl = searchParams.get('tab');
  const activeTab = tabFromUrl && VALID_TABS.includes(tabFromUrl) ? tabFromUrl : 'profile';
  const settingsTabsRef = useRef(null);
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
  const [integrationsStatus, setIntegrationsStatus] = useState({ huawei: false, strava: false, polar: false, garmin: false, coros: false });
  // Провайдеры, скрытые до получения доступа к API (Garmin, COROS, Huawei)
  const hiddenProviders = ['garmin', 'coros', 'huawei'];
  const [huaweiSyncing, setHuaweiSyncing] = useState(false);
  const [stravaSyncing, setStravaSyncing] = useState(false);
  const [polarSyncing, setPolarSyncing] = useState(false);
  const [garminSyncing, setGarminSyncing] = useState(false);
  const [corosSyncing, setCorosSyncing] = useState(false);
  const [stravaDebug, setStravaDebug] = useState(null);
  const [settingsTabPillStyle, setSettingsTabPillStyle] = useState({ left: 0, width: 0 });
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
  const [expandedNotificationEventKey, setExpandedNotificationEventKey] = useState('');
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
  const { myCoaches, myCoachesLoading, removingCoachId, loadMyCoaches, handleRemoveCoach } = useMyCoaches(api, setMessage);
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
    if (activeTab !== 'integrations') return;
    const currentApi = api || useAuthStore.getState().api;
    if (!currentApi) return;
    currentApi.getIntegrationsStatus()
      .then((res) => {
        const data = res?.data?.integrations ?? res?.integrations ?? {};
        setIntegrationsStatus(prev => ({ ...prev, ...data }));
      })
      .catch(() => {});
  }, [activeTab, api]);

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
    if (!isTabActive && !hasLoadedProfileRef.current) return;
    const loadProfileData = async () => {
      if (!api) {
        // api ещё не готов — useEffect перезапустится когда api появится в сторе
        return;
      }
      hasLoadedProfileRef.current = true;
      await loadProfile(api);
    };
    
    loadProfileData();
  }, [api, isTabActive, loadProfile]);
  

  const updateSettingsTabPill = useCallback(() => {
    const tabs = settingsTabsRef.current;
    if (!tabs) return;

    const activeButton = tabs.querySelector('.settings-tab.active');
    if (!activeButton) {
      setSettingsTabPillStyle({ left: 0, width: 0 });
      return;
    }

    setSettingsTabPillStyle({
      left: activeButton.offsetLeft,
      width: activeButton.offsetWidth,
    });
  }, []);

  useLayoutEffect(() => {
    if (loading) return;
    updateSettingsTabPill();
  }, [activeTab, loading, updateSettingsTabPill]);

  useLayoutEffect(() => {
    if (loading) return undefined;
    let frameId = 0;
    const scheduleUpdate = () => {
      cancelAnimationFrame(frameId);
      frameId = window.requestAnimationFrame(updateSettingsTabPill);
    };

    const tabs = settingsTabsRef.current;
    const resizeObserver = typeof ResizeObserver !== 'undefined' && tabs
      ? new ResizeObserver(scheduleUpdate)
      : null;

    if (tabs && resizeObserver) {
      resizeObserver.observe(tabs);
      tabs.querySelectorAll('.settings-tab').forEach((item) => resizeObserver.observe(item));
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
  }, [loading, updateSettingsTabPill]);

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
          setMessage({ type: 'success', text: 'Разрешение браузера выдано. Теперь можно подключить этот браузер к уведомлениям.' });
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
        setMessage({ type: 'success', text: 'Уведомления в браузере подключены для этого браузера' });
      }
      return true;
    } catch (error) {
      if (!options.silent) {
        setMessage({ type: 'error', text: error.message || 'Не удалось подключить уведомления в браузере' });
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
      setMessage({ type: 'success', text: 'Этот браузер отключён от уведомлений в браузере' });
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
        setMessage({ type: 'error', text: 'Уведомления в браузере ещё не настроены на сервере.' });
        return false;
      }
      if (isIOSWebPushInstallRequired) {
        showIOSWebPushInstallInstructions();
        return false;
      }
      if (!browserNotificationsSupported) {
        setMessage({ type: 'error', text: 'Этот браузер не поддерживает уведомления в браузере.' });
        return false;
      }
      if (browserNotificationPermission === 'denied') {
        showWebPushBlockedInstructions();
        return false;
      }
      if (!webPushChannel.public_key) {
        setMessage({ type: 'error', text: 'Уведомления в браузере ещё не настроены на сервере.' });
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
        setMessage({ type: 'error', text: 'Уведомления на телефон можно подключить только в приложении.' });
        return false;
      }

      if (channel.available) {
        return true;
      }

      if (!currentApi) {
        setMessage({ type: 'error', text: 'Клиент сервиса не готов. Попробуйте обновить страницу.' });
        return false;
      }

      try {
        setNotificationActionLoading('mobile_push-connect');
        const { registerPushNotifications } = await import('../services/PushService');
        const result = await registerPushNotifications(currentApi);

        if (!result?.ok) {
          setMessage({ type: 'error', text: result?.reason || 'Не удалось подключить уведомления на телефон.' });
          return false;
        }

        setMessage({ type: 'success', text: 'Разрешение на уведомления получено. Устройство подключается к уведомлениям.' });
        setTimeout(() => {
          loadProfile(currentApi, { silent: true }).catch(() => {});
        }, 1200);
        return true;
      } catch (error) {
        setMessage({ type: 'error', text: error?.message || 'Не удалось подключить уведомления на телефон.' });
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
        setMessage({ type: 'error', text: 'Укажите адрес почты в профиле, чтобы получать письма.' });
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
      label: 'Уведомления на телефон',
      shortLabel: 'Телефон',
      description: 'Системные уведомления Android и iOS',
      Icon: SmartphoneIcon,
    },
    web_push: {
      label: 'Уведомления в браузере',
      shortLabel: 'Браузер',
      description: 'Разрешение браузера и уведомления для сайта',
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
      label: 'Почта',
      shortLabel: 'Почта',
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

  const toggleNotificationEventExpanded = (eventKey) => {
    setExpandedNotificationEventKey((currentEventKey) => (currentEventKey === eventKey ? '' : eventKey));
  };

  const getEventChannelState = (event, channelKey) => {
    const supportsChannel = (event.channels || []).includes(channelKey);
    const channel = effectiveNotificationSettings.channels?.[channelKey] || {};
    const field = `${channelKey}_enabled`;
    const isChannelBusy = (channelKey === 'web_push' && notificationActionLoading.startsWith('web_push'))
      || (channelKey === 'mobile_push' && notificationActionLoading === 'mobile_push-connect');
    const checked = event.locked
      ? channelKey === 'email'
      : Boolean(effectiveNotificationSettings.preferences?.[event.event_key]?.[field]);
    const disabled = !supportsChannel
      || Boolean(event.locked)
      || isChannelBusy;

    return {
      supportsChannel,
      channel,
      checked,
      disabled,
    };
  };

  const isNotificationChannelEffectivelyActive = useCallback((event, channelKey, checked) => {
    if (event.locked) {
      return channelKey === 'email';
    }

    if (!checked) {
      return false;
    }

    if (channelKey === 'web_push') {
      return getWebPushSetupState().key === 'connected';
    }

    if (channelKey === 'mobile_push') {
      return !isNativeApp || Boolean(effectiveNotificationSettings.channels?.mobile_push?.available);
    }

    if (channelKey === 'telegram') {
      return Boolean(effectiveNotificationSettings.channels?.telegram?.linked);
    }

    if (channelKey === 'email') {
      return Boolean(effectiveNotificationSettings.channels?.email?.available);
    }

    return true;
  }, [effectiveNotificationSettings.channels, getWebPushSetupState, isNativeApp]);

  const getEventMobileChannels = (event, channelKeys) => channelKeys.reduce((items, channelKey) => {
    const state = getEventChannelState(event, channelKey);
    if (!state.supportsChannel) {
      return items;
    }

    items.push({
      channelKey,
      ...state,
      isActive: isNotificationChannelEffectivelyActive(event, channelKey, state.checked),
    });
    return items;
  }, []);

  const getNotificationChannelStatusText = useCallback((event, channelKey, checked) => {
    if (event.locked) {
      return 'Обязательный канал';
    }

    if (channelKey === 'web_push') {
      const state = getWebPushSetupState();

      if (state.key === 'install_required') {
        return 'Добавьте на экран Домой';
      }
      if (state.key === 'unsupported') {
        return 'Браузер не поддерживает';
      }
      if (state.key === 'server_unavailable') {
        return 'Пока недоступно';
      }
      if (state.key === 'denied') {
        return 'Заблокировано в браузере';
      }
      if (state.key === 'requesting_permission' || state.key === 'permission_required') {
        return 'Нужно разрешение';
      }
      if (state.key === 'connecting' || state.key === 'subscription_required') {
        return checked ? 'Подключаем браузер' : 'Нужно подключить браузер';
      }

      return checked ? 'Включено' : 'Доступно';
    }

    if (channelKey === 'mobile_push' && isNativeApp && !effectiveNotificationSettings.channels?.mobile_push?.available) {
      return 'Нужно разрешение на устройстве';
    }

    return checked ? 'Включено' : 'Выключено';
  }, [
    effectiveNotificationSettings.channels,
    getWebPushSetupState,
    isNativeApp,
  ]);

  // Загрузка тренеров/цен при переходе на вкладку social
  useEffect(() => {
    if (activeTab === 'social' && api) {
      loadMyCoaches();
      const role = currentUser?.role;
      if (role === 'coach' || role === 'admin') loadCoachPricing();
    }
  }, [activeTab, api]);

  useEffect(() => {
    if (formData.telegram_id) {
      stopTelegramLoginPolling();
      setTelegramLoginLoading(false);
      setTelegramLinkCode(null);
      clearTelegramLinkPending();
    }
  }, [formData.telegram_id, stopTelegramLoginPolling]);

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
    <div className="settings-container settings-page">
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
        {stravaDebug && activeTab === 'integrations' && (
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
        <div
          ref={settingsTabsRef}
          className="settings-tabs"
          style={{
            '--settings-tabs-pill-left': `${settingsTabPillStyle.left}px`,
            '--settings-tabs-pill-width': `${settingsTabPillStyle.width}px`,
          }}
        >
          <span className="settings-tabs-pill" aria-hidden="true" />
          <button
            className={`settings-tab ${activeTab === 'profile' ? 'active' : ''}`}
            type="button"
            onClick={() => handleTabChange('profile')}
          >
            <UserIcon size={18} className="settings-tab-icon" aria-hidden /> Профиль
          </button>
          <button
            className={`settings-tab ${activeTab === 'training' ? 'active' : ''}`}
            type="button"
            onClick={() => handleTabChange('training')}
          >
            <RunningIcon size={18} className="settings-tab-icon" aria-hidden /> Тренировки
          </button>
          <button
            className={`settings-tab ${activeTab === 'notifications' ? 'active' : ''}`}
            type="button"
            onClick={() => handleTabChange('notifications')}
          >
            <BellIcon size={18} className="settings-tab-icon" aria-hidden /> Уведомления
          </button>
          <button
            className={`settings-tab ${activeTab === 'social' ? 'active' : ''}`}
            type="button"
            onClick={() => handleTabChange('social')}
          >
            <LockIcon size={18} className="settings-tab-icon" aria-hidden /> Конфиденциальность
          </button>
          <button
            className={`settings-tab ${activeTab === 'integrations' ? 'active' : ''}`}
            type="button"
            onClick={() => handleTabChange('integrations')}
          >
            <LinkIcon size={18} className="settings-tab-icon" aria-hidden /> Интеграции
          </button>
        </div>

        <div ref={settingsPanelsRef} className="settings-tab-panels">
        {/* Вкладка Профиль */}
        {activeTab === 'profile' && (
          <div className="tab-content active">
            <div className="settings-section">
              <h2><UserIcon size={22} className="section-icon" aria-hidden /> Личная информация</h2>
              <p>Основные данные вашего профиля</p>

              {/* Аватар */}
              <div className="form-group">
                <label>Аватар</label>
                <div className="avatar-upload-shell">
                  <input
                    type="file"
                    id="avatar-upload"
                    className="avatar-upload-input"
                    accept="image/jpeg,image/jpg,image/png,image/gif,image/webp"
                    onChange={handleAvatarUpload}
                  />

                  <div className="avatar-preview-container">
                    {formData.avatar_path ? (
                      <img
                        src={getAvatarSrc(formData.avatar_path, api?.baseUrl || '/api', 'md')}
                        alt="Аватар"
                        className="avatar-preview avatar-preview--current"
                      />
                    ) : (
                      <div className="avatar-placeholder avatar-placeholder--profile" aria-hidden>
                        {avatarInitials || <UserIcon size={44} />}
                      </div>
                    )}

                    <div className="avatar-preview-meta">
                      <div className="avatar-preview-copy">
                        <span className={`avatar-preview-badge ${formData.avatar_path ? 'avatar-preview-badge--ready' : ''}`}>
                          {formData.avatar_path ? 'Текущий аватар' : 'Фото профиля'}
                        </span>
                        <div className="avatar-preview-title">{avatarDisplayName}</div>
                      </div>

                      <div className="avatar-actions-panel">
                        <div className="avatar-actions">
                          <label htmlFor="avatar-upload" className="avatar-upload-label avatar-upload-label--inline">
                            <ImageIcon size={18} className="avatar-upload-icon" aria-hidden />
                            <span>{formData.avatar_path ? 'Изменить фото' : 'Загрузить фото'}</span>
                          </label>

                          {formData.avatar_path && (
                            <button
                              type="button"
                              className="btn btn-secondary btn-sm avatar-remove-btn"
                              onClick={handleRemoveAvatar}
                            >
                              Удалить
                            </button>
                          )}
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div className="form-group">
                <label>Имя пользователя *</label>
                <input
                  type="text"
                  value={formData.username}
                  onChange={(e) => handleInputChange('username', e.target.value)}
                  placeholder="Ваше имя"
                />
              </div>

              <div className="form-group">
                <label>Эл. почта <span className="required">*</span></label>
                <input
                  type="email"
                  value={formData.email || ''}
                  onChange={(e) => handleInputChange('email', e.target.value)}
                  placeholder="email@example.com"
                />
              </div>

              <div className="form-row">
                <div className="form-group">
                  <label>Пол</label>
                  <select
                    value={formData.gender || ''}
                    onChange={(e) => handleInputChange('gender', e.target.value || null)}
                  >
                    <option value="">Не указано</option>
                    <option value="male">Мужской</option>
                    <option value="female">Женский</option>
                  </select>
                </div>

                <div className="form-group">
                  <label>Год рождения</label>
                  <input
                    type="number"
                    min="1900"
                    max={new Date().getFullYear()}
                    value={formData.birth_year || ''}
                    onChange={(e) => handleInputChange('birth_year', e.target.value)}
                    placeholder="1990"
                  />
                </div>
              </div>

              <div className="form-row">
                <div className="form-group">
                  <label>Рост (см)</label>
                  <input
                    type="number"
                    min="50"
                    max="250"
                    value={formData.height_cm || ''}
                    onChange={(e) => handleInputChange('height_cm', e.target.value)}
                    placeholder="175"
                  />
                </div>

                <div className="form-group">
                  <label>Вес (кг)</label>
                  <input
                    type="number"
                    min="20"
                    max="300"
                    step="0.1"
                    value={formData.weight_kg || ''}
                    onChange={(e) => handleInputChange('weight_kg', e.target.value)}
                    placeholder="70"
                  />
                </div>
              </div>

              <div className="form-row">
                <div className="form-group">
                  <label>Макс ЧСС</label>
                  <input
                    type="number"
                    min="120"
                    max="230"
                    value={formData.max_hr || ''}
                    onChange={(e) => handleInputChange('max_hr', e.target.value)}
                    placeholder={formData?.hr_zones_data?.detected_max_hr ? `${formData.hr_zones_data.detected_max_hr} (авто)` : '190'}
                  />
                </div>

                <div className="form-group">
                  <label>ЧСС покоя</label>
                  <input
                    type="number"
                    min="30"
                    max="120"
                    value={formData.rest_hr || ''}
                    onChange={(e) => handleInputChange('rest_hr', e.target.value)}
                    placeholder="60"
                  />
                </div>
              </div>

              {(() => {
                const serverData = formData?.hr_zones_data || {};
                const inputMaxHr = formData.max_hr ? parseInt(formData.max_hr, 10) : 0;
                const validInput = inputMaxHr >= 120 && inputMaxHr <= 230;
                const detectedMaxHr = serverData.detected_max_hr || 0;
                const formulaMaxHr = serverData.formula_max_hr || 0;

                // Приоритет: тренировки > ручной > формула
                let effectiveMaxHr, source;
                if (detectedMaxHr) {
                  effectiveMaxHr = detectedMaxHr;
                  source = 'detected';
                } else if (validInput) {
                  effectiveMaxHr = inputMaxHr;
                  source = 'manual';
                } else if (formulaMaxHr) {
                  effectiveMaxHr = formulaMaxHr;
                  source = 'formula';
                } else {
                  return null;
                }

                // Ручной ввод переопределяет, если он отличается от авто
                if (validInput && source === 'detected' && inputMaxHr !== detectedMaxHr) {
                  effectiveMaxHr = inputMaxHr;
                  source = 'override';
                }

                const zoneNames = ['Восстановительная', 'Аэробная', 'Темповая', 'Пороговая', 'Максимальная'];
                const zonePcts = [[0.50, 0.60], [0.60, 0.70], [0.70, 0.80], [0.80, 0.90], [0.90, 1.00]];

                // ЧСС покоя: ручной ввод или из серверных данных
                const inputRestHr = formData.rest_hr ? parseInt(formData.rest_hr, 10) : 0;
                const effectiveRestHr = (inputRestHr >= 35 && inputRestHr < effectiveMaxHr)
                  ? inputRestHr
                  : (serverData.effective_rest_hr || 0);
                const useKarvonen = effectiveRestHr >= 35 && effectiveRestHr < effectiveMaxHr;

                const zones = zoneNames.map((name, i) => {
                  let minHr, maxHr;
                  if (useKarvonen) {
                    // Формула Карвонена: RestHR + (MaxHR - RestHR) × %
                    const hrr = effectiveMaxHr - effectiveRestHr;
                    minHr = Math.round(effectiveRestHr + hrr * zonePcts[i][0]);
                    maxHr = Math.round(effectiveRestHr + hrr * zonePcts[i][1]);
                  } else {
                    minHr = Math.round(effectiveMaxHr * zonePcts[i][0]);
                    maxHr = Math.round(effectiveMaxHr * zonePcts[i][1]);
                  }
                  return { zone: i + 1, name, min_hr: minHr, max_hr: maxHr };
                });

                const methodLabel = useKarvonen ? ', Карвонен' : '';
                const sourceLabel = source === 'detected' ? `из тренировок (${effectiveMaxHr} уд/м${methodLabel})`
                  : source === 'override' ? `${effectiveMaxHr} уд/м (вручную, авто: ${detectedMaxHr}${methodLabel})`
                  : source === 'manual' ? `${effectiveMaxHr} уд/м (вручную${methodLabel})`
                  : source === 'formula' ? `по формуле 220−возраст (${effectiveMaxHr} уд/м${methodLabel})`
                  : '';

                return (
                  <div className="hr-zones-display">
                    <div className="hr-zones-header">
                      <span>Зоны ЧСС</span>
                      <span className="hr-zones-source">{sourceLabel}</span>
                    </div>
                    <div className="hr-zones-table">
                      {zones.map(z => (
                        <div key={z.zone} className={`hr-zone-row hr-zone-${z.zone}`}>
                          <span className="hr-zone-num">Z{z.zone}</span>
                          <span className="hr-zone-name">{z.name}</span>
                          <span className="hr-zone-range">{z.min_hr}–{z.max_hr}</span>
                        </div>
                      ))}
                    </div>
                  </div>
                );
              })()}

              {(() => {
                const realRanges = formData?.hr_zones_data?.real_hr_ranges;
                if (!realRanges) return null;
                const buckets = [
                  { key: 'easy', label: 'Лёгкий бег', pace: '≥5:30/км' },
                  { key: 'moderate', label: 'Умеренный', pace: '5:00–5:29/км' },
                  { key: 'intense', label: 'Интенсивный', pace: '<5:00/км' },
                ];
                const hasBuckets = buckets.some(b => realRanges[b.key]);
                if (!hasBuckets) return null;
                const trendArrow = (t) => t === 'improving' ? ' ↓' : t === 'worsening' ? ' ↑' : '';
                const trendLabel = (t) => t === 'improving' ? 'снижается' : t === 'worsening' ? 'растёт' : '';
                return (
                  <div className="hr-zones-display" style={{ marginTop: 8 }}>
                    <div className="hr-zones-header">
                      <span>Реальный пульс из тренировок</span>
                      <span className="hr-zones-source">за 6 недель</span>
                    </div>
                    <div className="hr-zones-table">
                      {buckets.map(b => {
                        const d = realRanges[b.key];
                        if (!d) return null;
                        return (
                          <div key={b.key} className="hr-zone-row hr-zone-2">
                            <span className="hr-zone-name">{b.label} ({b.pace})</span>
                            <span className="hr-zone-range">
                              {d.p25}–{d.p75} уд/м{trendArrow(d.trend)}
                              {d.trend && d.trend !== 'stable' && <small style={{ opacity: 0.7 }}> ({trendLabel(d.trend)})</small>}
                              <small style={{ opacity: 0.5 }}> ({d.count} тр.)</small>
                            </span>
                          </div>
                        );
                      })}
                    </div>
                  </div>
                );
              })()}

              <div className="form-group">
                <label>Часовой пояс</label>
                <select
                  value={formData.timezone}
                  onChange={(e) => handleInputChange('timezone', e.target.value)}
                >
                  <option value="Europe/Moscow">Москва (UTC+3)</option>
                  <option value="Europe/Kiev">Киев (UTC+2)</option>
                  <option value="Europe/Minsk">Минск (UTC+3)</option>
                  <option value="Asia/Almaty">Алматы (UTC+6)</option>
                  <option value="Europe/London">Лондон (UTC+0)</option>
                  <option value="America/New_York">Нью-Йорк (UTC-5)</option>
                </select>
              </div>
            </div>
            <div className="settings-section">
              <h2><PaletteIcon size={22} className="section-icon" aria-hidden /> Внешний вид</h2>
              <div className="theme-options" role="radiogroup" aria-label="Тема оформления">
                {[
                  { value: 'system', label: 'Как в системе' },
                  { value: 'light', label: 'Светлая' },
                  { value: 'dark', label: 'Тёмная' },
                ].map(({ value, label }) => (
                  <label key={value} className={`theme-option ${themePreference === value ? 'selected' : ''}`}>
                    <input
                      type="radio"
                      name="theme"
                      value={value}
                      checked={themePreference === value}
                      onChange={() => {
                        setThemePreference(value);
                        if (value === 'system') {
                          localStorage.removeItem('theme');
                          applyTheme(getSystemTheme());
                        } else {
                          localStorage.setItem('theme', value);
                          applyTheme(value);
                        }
                      }}
                    />
                    <span className="theme-option-label">{label}</span>
                  </label>
                ))}
              </div>
            </div>

            {showBiometricSection && (
              <div className="settings-section settings-app-lock-section">
                <h2>Блокировка приложения</h2>
                <p className="settings-app-lock-desc">
                  ПИН-код обязателен для разблокировки. Отпечаток пальца — опционально, для быстрого входа.
                </p>
                <div className="settings-biometric-row">
                  <p>
                    {!pinEnabled
                      ? 'Блокировка выключена'
                      : biometricEnabled
                        ? 'Включена (ПИН-код + отпечаток)'
                        : 'Включена (ПИН-код)'}
                  </p>
                  <div className="settings-app-lock-actions">
                    {!pinEnabled ? (
                      <button
                        type="button"
                        className="btn btn-primary btn-sm"
                        onClick={handleEnableLock}
                      >
                        Включить
                      </button>
                    ) : (
                      <>
                        {!biometricEnabled && biometricAvailable && (
                          <button
                            type="button"
                            className="btn btn-primary btn-sm"
                            onClick={handleAddFingerprint}
                            disabled={biometricEnabling}
                          >
                            {biometricEnabling ? '…' : 'Добавить отпечаток'}
                          </button>
                        )}
                        <button
                          type="button"
                          className="btn btn-secondary btn-sm"
                          onClick={handleDisableLock}
                          disabled={pinDisabling}
                        >
                          {pinDisabling ? '…' : 'Отключить'}
                        </button>
                      </>
                    )}
                  </div>
                </div>
                {!biometricAvailable && pinEnabled && !biometricEnabled && (
                  <p className="settings-biometric-hint">На этом устройстве отпечаток недоступен.</p>
                )}
              </div>
            )}
          </div>
        )}

        {/* Вкладка Уведомления */}
        {activeTab === 'notifications' && (() => {
          const webPushSetupState = getWebPushSetupState();
          const availableChannels = NOTIFICATION_CHANNELS.filter((key) => {
            const ch = effectiveNotificationSettings.channels?.[key] || {};
            if (key === 'telegram') return ch.available && ch.linked;
            if (key === 'mobile_push' && isMobileWeb) return false;
            if (key === 'web_push' && isNativeApp) return false;
            if (key === 'web_push') return true;
            if (key === 'mobile_push') return isNativeApp || ch.available;
            return true; // email always shown
          });
          const showWebPushSetup = !isNativeApp;

          return (
          <div className="tab-content active">
            <div className="settings-section notification-center">
              <h2><BellIcon size={22} className="section-icon" aria-hidden /> Уведомления</h2>

              {showWebPushSetup && (
                <div className={`notification-inline-helper ${webPushSetupState.key === 'connected' ? 'is-connected' : ''}`}>
                  <div className="notification-inline-helper-copy">
                    <strong>Уведомления в браузере</strong>
                    <span>{webPushSetupState.summary}</span>
                  </div>
                  {webPushSetupState.actionLabel && webPushSetupState.action && (
                    <button
                      type="button"
                      className="btn btn-secondary btn-sm"
                      onClick={webPushSetupState.action}
                      disabled={webPushSetupState.actionBusy}
                    >
                      {webPushSetupState.actionLabel}
                    </button>
                  )}
                </div>
              )}

              {/* Расписание напоминаний */}
              <div className="notification-schedule-grid">
                <div className="form-group">
                  <label>Напоминание на сегодня</label>
                  <input
                    type="time"
                    value={notificationSettings.schedule?.workout_today_time || '08:00'}
                    onChange={(e) => updateNotificationTime('workout_today_time', '08:00', e.target.value)}
                  />
                </div>
                <div className="form-group">
                  <label>Напоминание на завтра</label>
                  <input
                    type="time"
                    value={notificationSettings.schedule?.workout_tomorrow_time || '20:00'}
                    onChange={(e) => updateNotificationTime('workout_tomorrow_time', '20:00', e.target.value)}
                  />
                </div>
              </div>

              <div className="notification-quiet-hours">
                <label className="checkbox-label">
                  <input
                    type="checkbox"
                    checked={Boolean(notificationSettings.quiet_hours?.enabled)}
                    onChange={(e) => updateQuietHours('enabled', e.target.checked)}
                  />
                  <span>Тихие часы</span>
                </label>
                {notificationSettings.quiet_hours?.enabled && (
                  <div className="notification-schedule-grid">
                    <div className="form-group">
                      <label>С</label>
                      <input
                        type="time"
                        value={notificationSettings.quiet_hours?.start || '22:00'}
                        onChange={(e) => updateQuietHours('start', e.target.value || '22:00')}
                      />
                    </div>
                    <div className="form-group">
                      <label>До</label>
                      <input
                        type="time"
                        value={notificationSettings.quiet_hours?.end || '07:00'}
                        onChange={(e) => updateQuietHours('end', e.target.value || '07:00')}
                      />
                    </div>
                  </div>
                )}
              </div>

              {/* Матрица событий — только доступные каналы */}
                <div className="notification-groups">
                  {visibleNotificationGroups.map((group) => (
                    <div key={group.key} className="notification-group">
                      <div className="notification-group-heading">
                        <h3>{group.label}</h3>
                      </div>
                      <div className="notification-matrix notification-matrix--desktop">
                        <div className="notification-matrix-header notification-matrix-row">
                          <div className="notification-event-copy">Событие</div>
                          {availableChannels.map((channelKey) => (
                            <div key={`${group.key}-${channelKey}-head`} className="notification-channel-head">
                              {channelMeta[channelKey].shortLabel}
                          </div>
                        ))}
                      </div>

                        {group.events.map((event) => (
                          <div key={event.event_key} className="notification-matrix-row">
                            <div className="notification-event-copy">
                              <strong>{event.label}</strong>
                              {event.locked && <em>Обязательно</em>}
                            </div>
                            {availableChannels.map((channelKey) => {
                              const { supportsChannel, checked, disabled } = getEventChannelState(event, channelKey);

                              return (
                                <label key={`${event.event_key}-${channelKey}`} className={`notification-toggle ${disabled ? 'is-disabled' : ''}`}>
                                <input
                                  type="checkbox"
                                  checked={checked}
                                  disabled={disabled}
                                  onChange={(e) => handleNotificationPreferenceToggle(event.event_key, channelKey, e.target.checked)}
                                />
                                <span>{supportsChannel ? channelMeta[channelKey].shortLabel : '—'}</span>
                              </label>
                            );
                            })}
                          </div>
                        ))}
                      </div>

                      <div className="notification-mobile-list">
                        {group.events.map((event) => {
                          const mobileChannels = getEventMobileChannels(event, availableChannels);
                          const summary = getNotificationMobileSummary(event, notificationSettings.schedule);
                          const isExpanded = expandedNotificationEventKey === event.event_key;

                          return (
                            <div
                              key={`${group.key}-${event.event_key}-mobile`}
                              className={`notification-mobile-item ${isExpanded ? 'is-expanded' : ''}`}
                            >
                              <button
                                type="button"
                                className="notification-mobile-item-head"
                                onClick={() => toggleNotificationEventExpanded(event.event_key)}
                                aria-expanded={isExpanded}
                              >
                                <div className="notification-mobile-item-copy">
                                  <strong>{event.label}</strong>
                                  {summary ? <span>{summary}</span> : null}
                                </div>
                                <div className="notification-mobile-item-meta">
                                  <div className="notification-mobile-channel-icons" aria-hidden="true">
                                      {mobileChannels.map(({ channelKey, isActive }) => {
                                        return (
                                          <span
                                            key={`${event.event_key}-${channelKey}-icon`}
                                            className={`notification-mobile-channel-chip notification-mobile-channel-chip--${channelKey} ${isActive ? 'is-active' : 'is-inactive'}`}
                                            title={channelMeta[channelKey].label}
                                          >
                                            {renderNotificationChannelVisual(channelMeta[channelKey], 'notification-mobile-channel-chip-icon', 16)}
                                          </span>
                                        );
                                      })}
                                  </div>
                                  <span className={`notification-mobile-expand-indicator ${isExpanded ? 'is-open' : ''}`} aria-hidden="true" />
                                </div>
                              </button>

                              {isExpanded && (
                                <div className="notification-mobile-item-body">
                                  {availableChannels.map((channelKey) => {
                                    const { supportsChannel, checked, disabled } = getEventChannelState(event, channelKey);
                                    if (!supportsChannel) {
                                      return null;
                                    }

                                    const isActive = isNotificationChannelEffectivelyActive(event, channelKey, checked);
                                    const statusText = getNotificationChannelStatusText(event, channelKey, checked);

                                    return (
                                      <label
                                        key={`${event.event_key}-${channelKey}-mobile-toggle`}
                                        className={`notification-mobile-channel-row ${disabled ? 'is-disabled' : ''} ${isActive ? 'is-active' : 'is-inactive'}`}
                                      >
                                        <div className="notification-mobile-channel-copy">
                                          <span
                                            className={`notification-mobile-channel-icon notification-mobile-channel-icon--${channelKey} ${isActive ? 'is-active' : 'is-inactive'}`}
                                            aria-hidden="true"
                                          >
                                            {renderNotificationChannelVisual(channelMeta[channelKey], 'notification-mobile-channel-row-icon', 18)}
                                          </span>
                                          <div className="notification-mobile-channel-text">
                                            <strong>{channelMeta[channelKey].label}</strong>
                                            <span>{statusText}</span>
                                          </div>
                                        </div>
                                        <input
                                          type="checkbox"
                                          checked={checked}
                                          disabled={disabled}
                                          onChange={(e) => handleNotificationPreferenceToggle(event.event_key, channelKey, e.target.checked)}
                                        />
                                      </label>
                                    );
                                  })}
                                </div>
                              )}
                            </div>
                          );
                        })}
                      </div>
                    </div>
                  ))}
                </div>
            </div>
          </div>
          );
        })()}

        {/* Вкладка Тренировки (объединены Цели, Тренировки и Здоровье) */}
        {activeTab === 'training' && (
          <div className="tab-content active" key={`training-${formData.weekly_base_km}-${formData.preferred_days?.length}`}>
            {/* Секция: Цели */}
            <div className="settings-section">
              <h2><TargetIcon size={22} className="section-icon" aria-hidden /> Мои цели</h2>
              <p>Расскажите о ваших целях для персонализированного плана</p>

              <div className="form-group">
                <label>Тип цели *</label>
                <div className="radio-group">
                  <label className="radio-label">
                    <input
                      type="radio"
                      name="goal_type"
                      value="health"
                      checked={formData.goal_type === 'health'}
                      onChange={(e) => handleInputChange('goal_type', e.target.value)}
                    />
                    <span>Здоровье</span>
                  </label>
                  <label className="radio-label">
                    <input
                      type="radio"
                      name="goal_type"
                      value="race"
                      checked={formData.goal_type === 'race'}
                      onChange={(e) => handleInputChange('goal_type', e.target.value)}
                    />
                    <span>Забег</span>
                  </label>
                  <label className="radio-label">
                    <input
                      type="radio"
                      name="goal_type"
                      value="weight_loss"
                      checked={formData.goal_type === 'weight_loss'}
                      onChange={(e) => handleInputChange('goal_type', e.target.value)}
                    />
                    <span>Похудение</span>
                  </label>
                  <label className="radio-label">
                    <input
                      type="radio"
                      name="goal_type"
                      value="time_improvement"
                      checked={formData.goal_type === 'time_improvement'}
                      onChange={(e) => handleInputChange('goal_type', e.target.value)}
                    />
                    <span>Улучшение времени</span>
                  </label>
                </div>
              </div>

              {formData.goal_type === 'race' && (
                <div className="goal-race-section" style={{ display: 'block' }}>
                  <h3>Параметры забега</h3>
                  <div className="form-group">
                    <label>Целевая дистанция забега</label>
                    <select
                      key={`race_distance-${formData.race_distance || 'empty'}`}
                      value={formData.race_distance || ''}
                      onChange={(e) => {
                        const val = e.target.value || '';
                        handleInputChange('race_distance', val);
                      }}
                    >
                      <option value="">Выберите дистанцию</option>
                      <option value="5k">5 км</option>
                      <option value="10k">10 км</option>
                      <option value="half">Полумарафон (21.1 км)</option>
                      <option value="marathon">Марафон (42.2 км)</option>
                    </select>
                    <small style={{ color: 'var(--gray-600)', fontSize: '12px', display: 'block', marginTop: '4px' }}>
                      Какую дистанцию вы планируете пробежать?
                    </small>
                    {process.env.NODE_ENV === 'development' && (
                      <small style={{ color: 'gray', fontSize: '12px', display: 'block', marginTop: '4px' }}>
                        {`Debug: race_distance = "${formData.race_distance}" (type: ${typeof formData.race_distance})`}
                      </small>
                    )}
                  </div>
                  <div className="form-row">
                    <div className="form-group">
                      <label>Дата забега</label>
                      <input
                        type="date"
                        value={formData.race_date ? String(formData.race_date) : ''}
                        onChange={(e) => handleInputChange('race_date', e.target.value || null)}
                      />
                    </div>
                    <div className="form-group">
                      <label>Целевое время</label>
                      <input
                        type="time"
                        step="1"
                        value={formData.race_target_time ? String(formData.race_target_time) : ''}
                        onChange={(e) => handleInputChange('race_target_time', e.target.value || null)}
                      />
                    </div>
                  </div>
                </div>
              )}

              {formData.goal_type === 'time_improvement' && (
                <div className="goal-race-section" style={{ display: 'block' }}>
                  <h3>Улучшение результата</h3>
                  <p style={{ color: 'var(--gray-600)', fontSize: '14px', marginBottom: '16px' }}>
                    Укажите дистанцию, дату и целевое время
                  </p>
                  <div className="form-group">
                    <label>Целевая дистанция</label>
                    <select
                      key={`race_distance_ti-${formData.race_distance || 'empty'}`}
                      value={formData.race_distance || ''}
                      onChange={(e) => handleInputChange('race_distance', e.target.value || '')}
                    >
                      <option value="">Выберите дистанцию</option>
                      <option value="5k">5 км</option>
                      <option value="10k">10 км</option>
                      <option value="half">Полумарафон (21.1 км)</option>
                      <option value="marathon">Марафон (42.2 км)</option>
                    </select>
                  </div>
                  <div className="form-row">
                    <div className="form-group">
                      <label>Дата целевого забега</label>
                      <input
                        type="date"
                        value={formData.target_marathon_date || ''}
                        onChange={(e) => handleInputChange('target_marathon_date', e.target.value || null)}
                      />
                    </div>
                    <div className="form-group">
                      <label>Целевое время</label>
                      <input
                        type="time"
                        step="1"
                        value={formData.target_marathon_time || ''}
                        onChange={(e) => handleInputChange('target_marathon_time', e.target.value || null)}
                      />
                    </div>
                  </div>
                </div>
              )}

              {formData.goal_type === 'weight_loss' && (
                <div className="goal-race-section" style={{ display: 'block' }}>
                  <h3>Цель по весу</h3>
                  <div className="form-row">
                    <div className="form-group">
                      <label>Целевой вес (кг)</label>
                      <input
                        type="number"
                        min="20"
                        max="300"
                        step="0.1"
                        value={formData.weight_goal_kg || ''}
                        onChange={(e) => handleInputChange('weight_goal_kg', e.target.value)}
                      />
                    </div>
                    <div className="form-group">
                      <label>Дата достижения</label>
                      <input
                        type="date"
                        value={formData.weight_goal_date || ''}
                        onChange={(e) => handleInputChange('weight_goal_date', e.target.value || null)}
                      />
                    </div>
                  </div>
                </div>
              )}
            </div>

            {/* Секция: Настройки тренировок */}
            <div className="settings-section">
              <h2><RunningIcon size={22} className="section-icon" aria-hidden /> Настройки тренировок</h2>
              <p>Параметры для создания персонализированного плана</p>

              <div className="form-group">
                <label>Уровень подготовки *</label>
                <select
                  key={`experience_level-${formData.experience_level || 'novice'}`}
                  value={formData.experience_level || 'novice'}
                  onChange={(e) => handleInputChange('experience_level', e.target.value)}
                >
                  <option value="novice">Новичок (не бегаю или менее 3 месяцев)</option>
                  <option value="beginner">Начинающий (3-6 месяцев регулярного бега)</option>
                  <option value="intermediate">Средний (6-12 месяцев регулярного бега)</option>
                  <option value="advanced">Продвинутый (1-2 года регулярного бега)</option>
                  <option value="expert">Опытный (более 2 лет регулярного бега)</option>
                </select>
                <small style={{ color: 'var(--gray-600)', fontSize: '12px', display: 'block', marginTop: '4px' }}>
                  Выберите уровень, который лучше всего описывает ваш опыт в беге
                </small>
                  {process.env.NODE_ENV === 'development' && (
                    <small style={{ color: 'gray', fontSize: '12px', display: 'block', marginTop: '4px' }}>
                      {`Debug: experience_level = "${formData.experience_level}" (type: ${typeof formData.experience_level})`}
                    </small>
                  )}
              </div>

              <div className="form-row">
                <div className="form-group">
                  <label>Еженедельный объем (км)</label>
                  <input
                    type="number"
                    min="0"
                    max="200"
                    step="0.1"
                    value={formData.weekly_base_km || ''}
                    onChange={(e) => handleInputChange('weekly_base_km', e.target.value)}
                    placeholder="20"
                  />
                  {process.env.NODE_ENV === 'development' && (
                    <small style={{ color: 'gray', fontSize: '12px', display: 'block', marginTop: '4px' }}>
                      Debug: {JSON.stringify({ value: formData.weekly_base_km, type: typeof formData.weekly_base_km })}
                    </small>
                  )}
                </div>

                <div className="form-group">
                  <label>Тренировок в неделю</label>
                  <input
                    type="number"
                    min="1"
                    max="7"
                    value={formData.preferred_days?.length || formData.sessions_per_week || ''}
                    onChange={(e) => handleInputChange('sessions_per_week', e.target.value)}
                    placeholder="3"
                    readOnly
                    style={{ backgroundColor: 'var(--gray-100)', cursor: 'not-allowed' }}
                  />
                  <small style={{ color: 'var(--gray-600)', fontSize: '12px', display: 'block', marginTop: '4px' }}>
                    Автоматически рассчитано из выбранных дней для бега
                  </small>
                  {process.env.NODE_ENV === 'development' && (
                    <small style={{ color: 'gray', fontSize: '12px', display: 'block', marginTop: '4px' }}>
                      Debug: {JSON.stringify({ value: formData.sessions_per_week, preferred_days_length: formData.preferred_days?.length })}
                    </small>
                  )}
                </div>
              </div>

              <div className="form-group">
                <label>Предпочитаемые дни для бега</label>
                <div className="radio-group">
                  {daysOfWeek.map(day => (
                    <label key={day.value} className="radio-label">
                      <input
                        type="checkbox"
                        checked={Array.isArray(formData.preferred_days) && formData.preferred_days.includes(day.value)}
                        onChange={() => {
                          toggleDay('preferred_days', day.value);
                          // Автоматически обновляем sessions_per_week
                          const currentDays = formData.preferred_days || [];
                          const newDays = currentDays.includes(day.value)
                            ? currentDays.filter(d => d !== day.value)
                            : [...currentDays, day.value];
                          setFormData(prev => ({ ...prev, sessions_per_week: String(newDays.length) }));
                        }}
                      />
                      <span>{day.label}</span>
                    </label>
                  ))}
                </div>
                {process.env.NODE_ENV === 'development' && (
                  <small style={{ color: 'gray', fontSize: '12px' }}>
                    Debug: {JSON.stringify(formData.preferred_days)}
                  </small>
                )}
              </div>

              <div className="form-group">
                <label>Предпочитаемые дни для ОФП</label>
                <div className="radio-group">
                  {daysOfWeek.map(day => (
                    <label key={day.value} className="radio-label">
                      <input
                        type="checkbox"
                        checked={Array.isArray(formData.preferred_ofp_days) && formData.preferred_ofp_days.includes(day.value)}
                        onChange={() => toggleDay('preferred_ofp_days', day.value)}
                      />
                      <span>{day.label}</span>
                    </label>
                  ))}
                </div>
                {process.env.NODE_ENV === 'development' && (
                  <small style={{ color: 'gray', fontSize: '12px' }}>
                    Debug: {JSON.stringify(formData.preferred_ofp_days)}
                  </small>
                )}
              </div>

              <div className="form-group">
                <label>Предпочтения по ОФП</label>
                <select
                  key={`ofp_preference-${formData.ofp_preference || 'empty'}`}
                  value={formData.ofp_preference || ''}
                  onChange={(e) => handleInputChange('ofp_preference', e.target.value || null)}
                >
                  <option value="">Не указано</option>
                  <option value="gym">Тренажерный зал</option>
                  <option value="home">Дома</option>
                  <option value="both">И зал, и дома</option>
                  <option value="group_classes">Групповые занятия</option>
                  <option value="online">Онлайн</option>
                </select>
                {process.env.NODE_ENV === 'development' && (
                  <small style={{ color: 'gray', fontSize: '12px', display: 'block', marginTop: '4px' }}>
                    {`Debug: ofp_preference = "${formData.ofp_preference}" (type: ${typeof formData.ofp_preference})`}
                  </small>
                )}
              </div>

              <div className="form-group">
                <label>Предпочтительное время тренировок</label>
                <select
                  key={`training_time_pref-${formData.training_time_pref || 'empty'}`}
                  value={formData.training_time_pref || ''}
                  onChange={(e) => handleInputChange('training_time_pref', e.target.value || null)}
                >
                  <option value="">Не указано</option>
                  <option value="morning">Утро</option>
                  <option value="day">День</option>
                  <option value="evening">Вечер</option>
                </select>
                {process.env.NODE_ENV === 'development' && (
                  <small style={{ color: 'gray', fontSize: '12px', display: 'block', marginTop: '4px' }}>
                    {`Debug: training_time_pref = "${formData.training_time_pref}" (type: ${typeof formData.training_time_pref})`}
                  </small>
                )}
              </div>

              <div className="form-group">
                <label>
                  <input
                    type="checkbox"
                    key={`has_treadmill-${formData.has_treadmill}`}
                    checked={formData.has_treadmill || false}
                    onChange={(e) => handleInputChange('has_treadmill', e.target.checked)}
                  />
                  Есть беговая дорожка
                </label>
                {process.env.NODE_ENV === 'development' && (
                  <small style={{ color: 'gray', fontSize: '12px', display: 'block', marginTop: '4px' }}>
                    Debug: has_treadmill = {String(formData.has_treadmill)}
                  </small>
                )}
              </div>

              <div className="form-group">
                <label>Режим тренировок</label>
                <select
                  key={`training_mode-${formData.training_mode || 'ai'}`}
                  value={formData.training_mode || 'ai'}
                  onChange={(e) => handleInputChange('training_mode', e.target.value)}
                >
                  <option value="ai">План с ИИ</option>
                  <option value="coach">С тренером</option>
                  <option value="both">ИИ + тренер</option>
                  <option value="self">Самостоятельно</option>
                </select>
                {process.env.NODE_ENV === 'development' && (
                  <small style={{ color: 'gray', fontSize: '12px', display: 'block', marginTop: '4px' }}>
                    {`Debug: training_mode = "${formData.training_mode}" (type: ${typeof formData.training_mode})`}
                  </small>
                )}
              </div>

              <div className="form-group">
                <label>Стиль ИИ-тренера</label>
                <select
                  value={formData.coach_style || 'motivational'}
                  onChange={(e) => handleInputChange('coach_style', e.target.value)}
                >
                  <option value="motivational">Мотивирующий</option>
                  <option value="analytical">Аналитический</option>
                  <option value="minimal">Лаконичный</option>
                </select>
                <small style={{ color: 'var(--gray-600)', fontSize: '12px', display: 'block', marginTop: '4px' }}>
                  Как тренер общается с вами в чате
                </small>
              </div>

              <div className="form-group">
                <label>Дата начала тренировок</label>
                <input
                  type="date"
                  value={formData.training_start_date || ''}
                  onChange={(e) => handleInputChange('training_start_date', e.target.value || null)}
                />
                <small style={{ color: 'var(--gray-600)', fontSize: '12px', display: 'block', marginTop: '4px' }}>
                  С какой даты вы планируете начать тренировки?
                </small>
              </div>
            </div>

            {/* Секция: Здоровье и опыт */}
            <div className="settings-section">
              <h2><OtherIcon size={22} className="section-icon" aria-hidden /> Здоровье и опыт</h2>
              <p>Дополнительная информация для точной оценки</p>

              <div className="form-group">
                <label>Заметки о здоровье</label>
                <textarea
                  value={formData.health_notes || ''}
                  onChange={(e) => handleInputChange('health_notes', e.target.value || null)}
                  placeholder="Особенности здоровья, травмы, ограничения..."
                  rows="4"
                />
              </div>

              {formData.goal_type === 'health' && (
                <>
                  <div className="form-group">
                    <label>Программа здоровья</label>
                    <select
                      key={`health_program-${formData.health_program || 'empty'}`}
                      value={formData.health_program || ''}
                      onChange={(e) => handleInputChange('health_program', e.target.value || null)}
                    >
                      <option value="">Не указано</option>
                      <option value="start_running">Начать бегать</option>
                      <option value="couch_to_5k">Постепенный выход на 5 км</option>
                      <option value="regular_running">Регулярный бег</option>
                      <option value="custom">Своя программа</option>
                    </select>
                    {process.env.NODE_ENV === 'development' && (
                      <small style={{ color: 'gray', fontSize: '12px', display: 'block', marginTop: '4px' }}>
                        {`Debug: health_program = "${formData.health_program}" (type: ${typeof formData.health_program})`}
                      </small>
                    )}
                  </div>

                  {formData.health_program && (
                    <div className="form-group">
                      <label>Длительность программы (недели)</label>
                      <input
                        type="number"
                        min="1"
                        max="52"
                        value={formData.health_plan_weeks || ''}
                        onChange={(e) => handleInputChange('health_plan_weeks', e.target.value)}
                      />
                    </div>
                  )}
                </>
              )}

              <div className="form-group">
                <label>Комфортный темп (минуты:секунды на км)</label>
                <input
                  type="text"
                  value={formData.easy_pace_min || ''}
                  onChange={(e) => {
                    const value = e.target.value;
                    // Разрешаем промежуточный ввод: пусто, "5", "5:", "5:3", "5:30"
                    const allowed = value === '' || /^\d{0,2}(:\d{0,2})?$/.test(value);
                    if (!allowed) return;
                    handleInputChange('easy_pace_min', value);
                    // Полный формат MM:SS — конвертируем в секунды
                    if (/^\d{1,2}:\d{2}$/.test(value)) {
                      const [min, sec] = value.split(':').map(Number);
                      if (!isNaN(min) && !isNaN(sec)) {
                        const totalSec = min * 60 + sec;
                        if (totalSec >= 180 && totalSec <= 600) {
                          handleInputChange('easy_pace_sec', String(totalSec));
                        }
                      }
                    } else if (value === '') {
                      handleInputChange('easy_pace_sec', '');
                    }
                  }}
                  placeholder="7:00"
                  pattern="\d{1,2}:\d{2}"
                />
                <small>Введите темп в формате минуты:секунды (например, 7:00 означает 7 минут на километр)</small>
                {process.env.NODE_ENV === 'development' && (
                  <small style={{ color: 'gray', fontSize: '12px', display: 'block', marginTop: '4px' }}>
                    {`Debug: easy_pace_min = "${formData.easy_pace_min}", easy_pace_sec = "${formData.easy_pace_sec}"`}
                  </small>
                )}
              </div>

              {formData.goal_type === 'race' && (
                <>
                  <div className="form-group">
                    <label>
                      <input
                        type="checkbox"
                        checked={formData.is_first_race_at_distance === 1 || formData.is_first_race_at_distance === true}
                        onChange={(e) => handleInputChange('is_first_race_at_distance', e.target.checked ? 1 : 0)}
                      />
                      Первый забег на эту дистанцию
                    </label>
                  </div>

                  <div className="form-group">
                    <label>Дистанция последнего забега</label>
                    <select
                      key={`last_race_distance-${formData.last_race_distance || 'empty'}`}
                      value={formData.last_race_distance || ''}
                      onChange={(e) => handleInputChange('last_race_distance', e.target.value || null)}
                    >
                      <option value="">Не указано</option>
                      <option value="5k">5 км</option>
                      <option value="10k">10 км</option>
                      <option value="half">Полумарафон</option>
                      <option value="marathon">Марафон</option>
                      <option value="other">Другая</option>
                    </select>
                    <small style={{ color: 'var(--gray-600)', fontSize: '12px', display: 'block', marginTop: '4px' }}>
                      На какой дистанции вы бежали в последний раз?
                    </small>
                    {process.env.NODE_ENV === 'development' && (
                      <small style={{ color: 'gray', fontSize: '12px', display: 'block', marginTop: '4px' }}>
                        {`Debug: last_race_distance = "${formData.last_race_distance}" (type: ${typeof formData.last_race_distance})`}
                      </small>
                    )}
                  </div>

                  {formData.last_race_distance === 'other' && (
                    <div className="form-group">
                      <label>Дистанция последнего забега (км)</label>
                      <input
                        type="number"
                        min="0"
                        max="200"
                        step="0.1"
                        value={formData.last_race_distance_km || ''}
                        onChange={(e) => handleInputChange('last_race_distance_km', e.target.value)}
                      />
                      <small style={{ color: 'var(--gray-600)', fontSize: '12px', display: 'block', marginTop: '4px' }}>
                        Укажите точную дистанцию в километрах, если она отличается от стандартных
                      </small>
                    </div>
                  )}

                  <div className="form-row">
                    <div className="form-group">
                      <label>Время последнего забега</label>
                      <input
                        type="time"
                        step="1"
                        value={formData.last_race_time || ''}
                        onChange={(e) => handleInputChange('last_race_time', e.target.value || null)}
                      />
                    </div>
                    <div className="form-group">
                      <label>Дата последнего забега</label>
                      <input
                        type="date"
                        value={formData.last_race_date || ''}
                        onChange={(e) => handleInputChange('last_race_date', e.target.value || null)}
                      />
                    </div>
                  </div>
                </>
              )}
            </div>
          </div>
        )}

        {/* Вкладка Социальное */}
        {activeTab === 'social' && (
          <div className="tab-content active">
            <div className="settings-section">
              <h2><UsersIcon size={22} className="section-icon" aria-hidden /> Конфиденциальность</h2>
              <p>Управляйте тем, как другие видят ваш тренировочный календарь</p>
              <p className="form-hint">
                <Link to="/privacy">Полная политика конфиденциальности</Link>
              </p>

              <div className="form-group">
                <label>Уровень приватности</label>
                <div className="privacy-options">
                  <label className="privacy-option">
                    <input
                      type="radio"
                      name="privacy_level"
                      value="public"
                      checked={formData.privacy_level === 'public'}
                      onChange={(e) => handleInputChange('privacy_level', e.target.value)}
                    />
                    <div className="privacy-content">
                      <strong>Публичный</strong>
                      <small>Ваш профиль и календарь видны всем</small>
                    </div>
                  </label>
                  <label className="privacy-option">
                    <input
                      type="radio"
                      name="privacy_level"
                      value="private"
                      checked={formData.privacy_level === 'private'}
                      onChange={(e) => handleInputChange('privacy_level', e.target.value)}
                    />
                    <div className="privacy-content">
                      <strong>Приватный</strong>
                      <small>Только вы видите свой профиль</small>
                    </div>
                  </label>
                  <label className="privacy-option">
                    <input
                      type="radio"
                      name="privacy_level"
                      value="link"
                      checked={formData.privacy_level === 'link'}
                      onChange={(e) => handleInputChange('privacy_level', e.target.value)}
                    />
                    <div className="privacy-content">
                      <strong>По ссылке</strong>
                      <small>Доступ только по специальной ссылке</small>
                    </div>
                  </label>
                </div>
              </div>

              <div className="form-group" style={{ marginTop: 'var(--space-6)' }}>
                <label>Что показывать на странице профиля</label>
                <p className="form-hint">Выберите, какие данные видны на вашей публичной странице</p>
                <div className="privacy-options privacy-options--checkboxes">
                  <label className="privacy-option">
                    <input
                      type="checkbox"
                      checked={formData.privacy_show_email}
                      onChange={(e) => handleInputChange('privacy_show_email', e.target.checked)}
                    />
                    <div className="privacy-content">
                      <strong>Эл. почта</strong>
                      <small>Адрес электронной почты</small>
                    </div>
                  </label>
                  <label className="privacy-option">
                    <input
                      type="checkbox"
                      checked={formData.privacy_show_trainer}
                      onChange={(e) => handleInputChange('privacy_show_trainer', e.target.checked)}
                    />
                    <div className="privacy-content">
                      <strong>Тренер</strong>
                      <small>Блок «Тренер» и planRUN ИИ</small>
                    </div>
                  </label>
                  <label className="privacy-option">
                    <input
                      type="checkbox"
                      checked={formData.privacy_show_calendar}
                      onChange={(e) => handleInputChange('privacy_show_calendar', e.target.checked)}
                    />
                    <div className="privacy-content">
                      <strong>Календарь</strong>
                      <small>Неделя с планом тренировок</small>
                    </div>
                  </label>
                  <label className="privacy-option">
                    <input
                      type="checkbox"
                      checked={formData.privacy_show_metrics}
                      onChange={(e) => handleInputChange('privacy_show_metrics', e.target.checked)}
                    />
                    <div className="privacy-content">
                      <strong>Метрики</strong>
                      <small>Статистика и быстрые метрики</small>
                    </div>
                  </label>
                  <label className="privacy-option">
                    <input
                      type="checkbox"
                      checked={formData.privacy_show_workouts}
                      onChange={(e) => handleInputChange('privacy_show_workouts', e.target.checked)}
                    />
                    <div className="privacy-content">
                      <strong>Тренировки</strong>
                      <small>Последние тренировки</small>
                    </div>
                  </label>
                </div>
              </div>

              {formData.privacy_level === 'link' && (formData.public_token || formData.username_slug) && (
                <div className="form-group" style={{ marginTop: 'var(--space-6)' }}>
                  <label>Ссылка на ваш профиль</label>
                  <div className="profile-link-row">
                    <input
                      type="text"
                      readOnly
                      className="profile-link-input"
                      value={
                        typeof window !== 'undefined'
                          ? `${window.location.origin}/${formData.username_slug || formData.username || ''}${formData.public_token ? `?token=${formData.public_token}` : ''}`
                          : ''
                      }
                    />
                    <button
                      type="button"
                      className="btn btn-secondary btn--sm"
                      onClick={async () => {
                        const url =
                          typeof window !== 'undefined'
                            ? `${window.location.origin}/${formData.username_slug || formData.username || ''}${formData.public_token ? `?token=${formData.public_token}` : ''}`
                            : '';
                        try {
                          await navigator.clipboard.writeText(url);
                          setMessage({ type: 'success', text: 'Ссылка скопирована' });
                          setTimeout(() => setMessage({ type: '', text: '' }), 2000);
                        } catch {
                          setMessage({ type: 'error', text: 'Не удалось скопировать' });
                        }
                      }}
                    >
                      Копировать
                    </button>
                  </div>
                </div>
              )}
            </div>

            {/* Мои тренеры */}
            <div className="settings-section">
              <h2><GraduationCapIcon size={22} className="section-icon" aria-hidden /> Мои тренеры</h2>
              {myCoachesLoading ? (
                <p className="settings-loading-text">Загрузка...</p>
              ) : myCoaches.length === 0 ? (
                <p className="form-hint">У вас пока нет тренеров</p>
              ) : (
                <div className="settings-coaches-list">
                  {myCoaches.map((coach) => (
                    <div key={coach.id} className="settings-coach-item">
                      <div className="settings-coach-info">
                        <strong>{coach.username}</strong>
                      </div>
                      <button
                        type="button"
                        className="btn btn-secondary btn-sm"
                        disabled={removingCoachId === coach.id}
                        onClick={() => handleRemoveCoach(coach.id)}
                      >
                        {removingCoachId === coach.id ? '...' : 'Отвязать'}
                      </button>
                    </div>
                  ))}
                </div>
              )}
            </div>

            {/* Стоимость услуг (для тренеров) */}
            {(currentUser?.role === 'coach' || currentUser?.role === 'admin') && (
              <div className="settings-section">
                <h2>Стоимость услуг</h2>
                <p className="form-hint">Укажите ваши тарифы для учеников</p>
                {coachPricingLoading ? (
                  <p className="settings-loading-text">Загрузка...</p>
                ) : (
                  <>
                    {coachPricing.map((item, idx) => (
                      <div key={item.id || idx} className="settings-pricing-item">
                        <div className="settings-pricing-row">
                          <select
                            value={item.type}
                            onChange={(e) => handlePricingChange(idx, 'type', e.target.value)}
                          >
                            <option value="individual">Индивидуально</option>
                            <option value="group">Группа</option>
                            <option value="consultation">Консультация</option>
                            <option value="custom">Другое</option>
                          </select>
                          <input
                            type="text"
                            placeholder="Название"
                            value={item.label}
                            onChange={(e) => handlePricingChange(idx, 'label', e.target.value)}
                          />
                        </div>
                        <div className="settings-pricing-row">
                          <input
                            type="number"
                            placeholder="Цена"
                            value={item.price || ''}
                            onChange={(e) => handlePricingChange(idx, 'price', e.target.value)}
                            style={{ width: '120px' }}
                          />
                          <select
                            value={item.period}
                            onChange={(e) => handlePricingChange(idx, 'period', e.target.value)}
                          >
                            <option value="month">В месяц</option>
                            <option value="week">В неделю</option>
                            <option value="one_time">Разово</option>
                            <option value="custom">Другое</option>
                          </select>
                          <button
                            type="button"
                            className="btn-icon-remove"
                            onClick={() => handleRemovePricingItem(idx)}
                            title="Удалить"
                          >
                            &times;
                          </button>
                        </div>
                      </div>
                    ))}
                    <div className="settings-pricing-actions">
                      <button type="button" className="btn btn-secondary btn-sm" onClick={handleAddPricingItem}>
                        + Добавить тариф
                      </button>
                      {coachPricing.length > 0 && (
                        <button
                          type="button"
                          className="btn btn-primary btn-sm"
                          disabled={savingPricing}
                          onClick={handleSavePricing}
                        >
                          {savingPricing ? 'Сохранение...' : 'Сохранить'}
                        </button>
                      )}
                    </div>
                  </>
                )}
              </div>
            )}
          </div>
        )}

        {/* Вкладка Интеграции */}
        {activeTab === 'integrations' && (
          <div className="tab-content active">
            <div className="settings-section">
              <h2>Подключить</h2>

              {/* Подключено — горизонтальные карточки: логотип | кнопки */}
              {(formData.telegram_id || (!hiddenProviders.includes('huawei') && integrationsStatus.huawei) || integrationsStatus.strava || integrationsStatus.polar || (!hiddenProviders.includes('garmin') && integrationsStatus.garmin) || (!hiddenProviders.includes('coros') && integrationsStatus.coros)) && (
                <div className="integrations-connected-section">
                  <p className="integrations-connected-label">Подключено:</p>
                  <div className="integrations-connected-row">
                    {formData.telegram_id && (
                      <div className="integration-connected-card">
                        <div className="integration-connected-card__logo">
                          <img src="/integrations/telegram.svg" alt="Telegram" />
                        </div>
                        <div className="integration-connected-card__actions">
                          <button type="button" className="btn btn-secondary btn--sm" onClick={handleUnlinkTelegram}>Отвязать</button>
                        </div>
                      </div>
                    )}
                    {!hiddenProviders.includes('huawei') && integrationsStatus.huawei && (
                      <div className="integration-connected-card">
                        <div className="integration-connected-card__logo">
                          <img src="/integrations/huawei.svg" alt="Huawei Health" />
                        </div>
                        <div className="integration-connected-card__actions">
                          <button type="button" className="btn btn-primary btn--sm" disabled={huaweiSyncing} onClick={async () => {
                            const currentApi = api || useAuthStore.getState().api;
                            if (!currentApi) return;
                            await runHuaweiSync(currentApi);
                          }}>{huaweiSyncing ? '...' : 'Синхр.'}</button>
                          <button type="button" className="btn btn-secondary btn--sm" onClick={async () => {
                            if (!window.confirm('Отвязать Huawei Health?')) return;
                            const currentApi = api || useAuthStore.getState().api;
                            if (!currentApi) return;
                            try {
                              await currentApi.unlinkIntegration('huawei');
                              setIntegrationsStatus(prev => ({ ...prev, huawei: false }));
                              setMessage({ type: 'success', text: 'Huawei Health отключен' });
                              setTimeout(() => setMessage({ type: '', text: '' }), 3000);
                            } catch (err) {
                              setMessage({ type: 'error', text: 'Ошибка: ' + (err?.message || '') });
                            }
                          }}>Отвязать</button>
                        </div>
                      </div>
                    )}
                    {integrationsStatus.strava && (
                      <div className="integration-connected-card">
                        <div className="integration-connected-card__logo">
                          <img src="/integrations/strava.svg" alt="Strava" />
                        </div>
                        <div className="integration-connected-card__actions">
                          <button type="button" className="btn btn-primary btn--sm" disabled={stravaSyncing} onClick={async () => {
                            const currentApi = api || useAuthStore.getState().api;
                            if (!currentApi) return;
                            setStravaSyncing(true);
                            try {
                              const res = await currentApi.syncWorkouts('strava');
                              setMessage({ type: 'success', text: `Синхронизировано: ${res?.data?.imported ?? res?.imported ?? 0} новых тренировок` });
                              useWorkoutRefreshStore.getState().triggerRefresh();
                              setTimeout(() => setMessage({ type: '', text: '' }), 3000);
                            } catch (err) {
                              setMessage({ type: 'error', text: 'Ошибка синхронизации: ' + (err?.message || '') });
                            } finally {
                              setStravaSyncing(false);
                            }
                          }}>{stravaSyncing ? '...' : 'Синхр.'}</button>
                          <button type="button" className="btn btn-secondary btn--sm" onClick={async () => {
                            if (!window.confirm('Отвязать Strava?')) return;
                            const currentApi = api || useAuthStore.getState().api;
                            if (!currentApi) return;
                            try {
                              await currentApi.unlinkIntegration('strava');
                              setIntegrationsStatus(prev => ({ ...prev, strava: false }));
                              setMessage({ type: 'success', text: 'Strava отключен' });
                              setTimeout(() => setMessage({ type: '', text: '' }), 3000);
                            } catch (err) {
                              setMessage({ type: 'error', text: 'Ошибка: ' + (err?.message || '') });
                            }
                          }}>Отвязать</button>
                        </div>
                      </div>
                    )}
                    {integrationsStatus.polar && (
                      <div className="integration-connected-card">
                        <div className="integration-connected-card__logo">
                          <img src="/integrations/polar.svg" alt="Polar" />
                        </div>
                        <div className="integration-connected-card__actions">
                          <button type="button" className="btn btn-primary btn--sm" disabled={polarSyncing} onClick={async () => {
                            const currentApi = api || useAuthStore.getState().api;
                            if (!currentApi) return;
                            setPolarSyncing(true);
                            try {
                              const res = await currentApi.syncWorkouts('polar');
                              setMessage({ type: 'success', text: `Синхронизировано: ${res?.data?.imported ?? res?.imported ?? 0} новых тренировок` });
                              useWorkoutRefreshStore.getState().triggerRefresh();
                              setTimeout(() => setMessage({ type: '', text: '' }), 3000);
                            } catch (err) {
                              setMessage({ type: 'error', text: 'Ошибка синхронизации: ' + (err?.message || '') });
                            } finally {
                              setPolarSyncing(false);
                            }
                          }}>{polarSyncing ? '...' : 'Синхр.'}</button>
                          <button type="button" className="btn btn-secondary btn--sm" onClick={async () => {
                            if (!window.confirm('Отвязать Polar?')) return;
                            const currentApi = api || useAuthStore.getState().api;
                            if (!currentApi) return;
                            try {
                              await currentApi.unlinkIntegration('polar');
                              setIntegrationsStatus(prev => ({ ...prev, polar: false }));
                              setMessage({ type: 'success', text: 'Polar отключен' });
                              setTimeout(() => setMessage({ type: '', text: '' }), 3000);
                            } catch (err) {
                              setMessage({ type: 'error', text: 'Ошибка: ' + (err?.message || '') });
                            }
                          }}>Отвязать</button>
                        </div>
                      </div>
                    )}
                    {!hiddenProviders.includes('garmin') && integrationsStatus.garmin && (
                      <div className="integration-connected-card">
                        <div className="integration-connected-card__logo">
                          <img src="/integrations/garmin.svg" alt="Garmin" />
                        </div>
                        <div className="integration-connected-card__actions">
                          <button type="button" className="btn btn-primary btn--sm" disabled={garminSyncing} onClick={async () => {
                            const currentApi = api || useAuthStore.getState().api;
                            if (!currentApi) return;
                            setGarminSyncing(true);
                            try {
                              const res = await currentApi.syncWorkouts('garmin');
                              setMessage({ type: 'success', text: `Синхронизировано: ${res?.data?.imported ?? res?.imported ?? 0} новых тренировок` });
                              useWorkoutRefreshStore.getState().triggerRefresh();
                              setTimeout(() => setMessage({ type: '', text: '' }), 3000);
                            } catch (err) {
                              setMessage({ type: 'error', text: 'Ошибка синхронизации: ' + (err?.message || '') });
                            } finally {
                              setGarminSyncing(false);
                            }
                          }}>{garminSyncing ? '...' : 'Синхр.'}</button>
                          <button type="button" className="btn btn-secondary btn--sm" onClick={async () => {
                            if (!window.confirm('Отвязать Garmin?')) return;
                            const currentApi = api || useAuthStore.getState().api;
                            if (!currentApi) return;
                            try {
                              await currentApi.unlinkIntegration('garmin');
                              setIntegrationsStatus(prev => ({ ...prev, garmin: false }));
                              setMessage({ type: 'success', text: 'Garmin отключен' });
                              setTimeout(() => setMessage({ type: '', text: '' }), 3000);
                            } catch (err) {
                              setMessage({ type: 'error', text: 'Ошибка: ' + (err?.message || '') });
                            }
                          }}>Отвязать</button>
                        </div>
                      </div>
                    )}
                    {!hiddenProviders.includes('coros') && integrationsStatus.coros && (
                      <div className="integration-connected-card">
                        <div className="integration-connected-card__logo">
                          <img src="/integrations/coros.svg" alt="COROS" />
                        </div>
                        <div className="integration-connected-card__actions">
                          <button type="button" className="btn btn-primary btn--sm" disabled={corosSyncing} onClick={async () => {
                            const currentApi = api || useAuthStore.getState().api;
                            if (!currentApi) return;
                            setCorosSyncing(true);
                            try {
                              const res = await currentApi.syncWorkouts('coros');
                              setMessage({ type: 'success', text: `Синхронизировано: ${res?.data?.imported ?? res?.imported ?? 0} новых тренировок` });
                              useWorkoutRefreshStore.getState().triggerRefresh();
                              setTimeout(() => setMessage({ type: '', text: '' }), 3000);
                            } catch (err) {
                              setMessage({ type: 'error', text: 'Ошибка синхронизации: ' + (err?.message || '') });
                            } finally {
                              setCorosSyncing(false);
                            }
                          }}>{corosSyncing ? '...' : 'Синхр.'}</button>
                          <button type="button" className="btn btn-secondary btn--sm" onClick={async () => {
                            if (!window.confirm('Отвязать COROS?')) return;
                            const currentApi = api || useAuthStore.getState().api;
                            if (!currentApi) return;
                            try {
                              await currentApi.unlinkIntegration('coros');
                              setIntegrationsStatus(prev => ({ ...prev, coros: false }));
                              setMessage({ type: 'success', text: 'COROS отключен' });
                              setTimeout(() => setMessage({ type: '', text: '' }), 3000);
                            } catch (err) {
                              setMessage({ type: 'error', text: 'Ошибка: ' + (err?.message || '') });
                            }
                          }}>Отвязать</button>
                        </div>
                      </div>
                    )}
                  </div>
                </div>
              )}

              {/* Не подключено — логотипы-кнопки для подключения */}
              {(!formData.telegram_id || (!hiddenProviders.includes('huawei') && !integrationsStatus.huawei) || !integrationsStatus.strava || !integrationsStatus.polar || (!hiddenProviders.includes('garmin') && !integrationsStatus.garmin) || (!hiddenProviders.includes('coros') && !integrationsStatus.coros)) && (
              <>
              {(formData.telegram_id || (!hiddenProviders.includes('huawei') && integrationsStatus.huawei) || integrationsStatus.strava || integrationsStatus.polar || (!hiddenProviders.includes('garmin') && integrationsStatus.garmin) || (!hiddenProviders.includes('coros') && integrationsStatus.coros)) && (
                <p className="integrations-disconnected-label">Подключить:</p>
              )}
              <div className="integrations-logos">
                {!formData.telegram_id && (
                  <div
                    className="integration-logo-btn"
                    role="button"
                    tabIndex={0}
                    aria-disabled={isTelegramConnecting}
                    onClick={() => {
                      if (!isTelegramConnecting) {
                        openTelegramBot();
                      }
                    }}
                    onKeyDown={(event) => {
                      if (isTelegramConnecting) return;
                      if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        openTelegramBot();
                      }
                    }}
                  >
                    <div className="integration-logo-btn__icon">
                      <img src="/integrations/telegram.svg" alt="Telegram" />
                    </div>
                    <span>{isTelegramConnecting ? 'Открываем...' : 'Telegram'}</span>
                  </div>
                )}
                {!hiddenProviders.includes('huawei') && !integrationsStatus.huawei && (
                  <div className="integration-logo-btn" role="button" tabIndex={0} onClick={async () => {
                    const currentApi = api || useAuthStore.getState().api;
                    if (!currentApi) return;
                    try {
                      const native = isNativeCapacitor();
                      const res = await currentApi.getIntegrationOAuthUrl('huawei', native ? { from_app: '1' } : {});
                      const url = res?.data?.auth_url ?? res?.auth_url;
                      if (!url) {
                        setMessage({ type: 'error', text: 'Провайдер не настроен' });
                        return;
                      }
                      if (native) {
                        // Android/iOS: In-App Browser -> HTTPS callback -> deep link planrun://oauth-callback
                        const { Browser } = await import('@capacitor/browser');
                        await Browser.open({ url });
                      } else {
                        window.location.href = url;
                      }
                    } catch (e) {
                      setMessage({ type: 'error', text: 'Ошибка: ' + (e?.message || '') });
                    }
                  }}>
                    <div className="integration-logo-btn__icon">
                      <img src="/integrations/huawei.svg" alt="Huawei Health" />
                    </div>
                    <span>Huawei Health</span>
                  </div>
                )}
                {!integrationsStatus.strava && (
                  <div className="integration-logo-btn" role="button" tabIndex={0} onClick={async () => {
                    const currentApi = api || useAuthStore.getState().api;
                    if (!currentApi) return;
                    try {
                      const native = isNativeCapacitor();
                      const res = await currentApi.getIntegrationOAuthUrl('strava', native ? { from_app: '1' } : {});
                      const url = res?.data?.auth_url ?? res?.auth_url;
                      if (!url) { setMessage({ type: 'error', text: 'Провайдер не настроен' }); return; }
                      if (native) {
                        // Android: In-App Browser → OAuth → deep link planrun:// вернёт в приложение
                        // Callback обработается через App.jsx → appUrlOpen → redirect на /settings?connected=...
                        // → существующий useEffect OAuth callback в этом компоненте запустит runStravaSync
                        const { Browser } = await import('@capacitor/browser');
                        await Browser.open({ url });
                      } else {
                        // Web: новая вкладка + поллинг статуса подключения
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
                              setIntegrationsStatus(prev => ({ ...prev, strava: true }));
                              runStravaSync(currentApi);
                            }
                          } catch (error) {
                            void error;
                          }
                        }, 3000);
                        stravaPollTimeoutRef.current = setTimeout(() => {
                          if (stravaPollRef.current) clearInterval(stravaPollRef.current);
                          stravaPollRef.current = null;
                        }, 300000);
                      }
                    } catch (e) {
                      setMessage({ type: 'error', text: 'Ошибка: ' + (e?.message || '') });
                    }
                  }}>
                    <div className="integration-logo-btn__icon">
                      <img src="/integrations/strava.svg" alt="Strava" />
                    </div>
                    <span>Strava</span>
                  </div>
                )}
                {!integrationsStatus.polar && (
                  <div className="integration-logo-btn" role="button" tabIndex={0} onClick={async () => {
                    const currentApi = api || useAuthStore.getState().api;
                    if (!currentApi) return;
                    try {
                      const res = await currentApi.getIntegrationOAuthUrl('polar');
                      const url = res?.data?.auth_url ?? res?.auth_url;
                      if (url) window.location.href = url;
                      else setMessage({ type: 'error', text: 'Провайдер не настроен' });
                    } catch (e) {
                      setMessage({ type: 'error', text: 'Ошибка: ' + (e?.message || '') });
                    }
                  }}>
                    <div className="integration-logo-btn__icon">
                      <img src="/integrations/polar.svg" alt="Polar" />
                    </div>
                    <span>Polar</span>
                  </div>
                )}
                {!hiddenProviders.includes('garmin') && !integrationsStatus.garmin && (
                  <div className="integration-logo-btn" role="button" tabIndex={0} onClick={async () => {
                    const currentApi = api || useAuthStore.getState().api;
                    if (!currentApi) return;
                    try {
                      const res = await currentApi.getIntegrationOAuthUrl('garmin');
                      const url = res?.data?.auth_url ?? res?.auth_url;
                      if (url) window.location.href = url;
                      else setMessage({ type: 'error', text: 'Провайдер не настроен' });
                    } catch (e) {
                      setMessage({ type: 'error', text: 'Ошибка: ' + (e?.message || '') });
                    }
                  }}>
                    <div className="integration-logo-btn__icon">
                      <img src="/integrations/garmin.svg" alt="Garmin" />
                    </div>
                    <span>Garmin</span>
                  </div>
                )}
                {!hiddenProviders.includes('coros') && !integrationsStatus.coros && (
                  <div className="integration-logo-btn" role="button" tabIndex={0} onClick={async () => {
                    const currentApi = api || useAuthStore.getState().api;
                    if (!currentApi) return;
                    try {
                      const res = await currentApi.getIntegrationOAuthUrl('coros');
                      const url = res?.data?.auth_url ?? res?.auth_url;
                      if (url) window.location.href = url;
                      else setMessage({ type: 'error', text: 'Провайдер не настроен' });
                    } catch (e) {
                      setMessage({ type: 'error', text: 'Ошибка: ' + (e?.message || '') });
                    }
                  }}>
                    <div className="integration-logo-btn__icon">
                      <img src="/integrations/coros.svg" alt="COROS" />
                    </div>
                    <span>COROS</span>
                  </div>
                )}
              </div>

              </>
              )}
            </div>
          </div>
        )}

        </div>
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
