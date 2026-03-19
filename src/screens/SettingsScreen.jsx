/**
 * Экран настроек профиля пользователя
 * Полная реализация с вкладками и всеми полями профиля
 */

import React, { useState, useEffect, useRef, useCallback, useLayoutEffect } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import useAuthStore from '../stores/useAuthStore';
import useWorkoutRefreshStore from '../stores/useWorkoutRefreshStore';
import { useIsTabActive } from '../hooks/useIsTabActive';
import { useSwipeableTabs } from '../hooks/useSwipeableTabs';
import BiometricService from '../services/BiometricService';
import PinAuthService from '../services/PinAuthService';
import { isNativeCapacitor } from '../services/TokenStorageService';
import PinSetupModal from '../components/common/PinSetupModal';
import SkeletonScreen from '../components/common/SkeletonScreen';
import { getAvatarSrc } from '../utils/avatarUrl';
import { UserIcon, RunningIcon, LockIcon, LinkIcon, ImageIcon, PaletteIcon, TargetIcon, OtherIcon, UsersIcon, BellIcon, GraduationCapIcon, CloseIcon } from '../components/common/Icons';
import { useMyCoaches } from './settings/useMyCoaches';
import { useCoachPricing } from './settings/useCoachPricing';
import { createInitialFormData, daysOfWeek } from './settings/profileForm';
import { useSettingsActions } from './settings/useSettingsActions';
import { useSettingsProfile } from './settings/useSettingsProfile';
import { applyTheme, getSystemTheme, getThemePreference, VALID_TABS } from './settings/settingsUtils';
import './SettingsScreen.css';

const TELEGRAM_LINK_PENDING_STORAGE_KEY = 'planrun.telegramLinkPendingAt';
const TELEGRAM_LINK_PENDING_MAX_AGE_MS = 30 * 60 * 1000;

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

const SettingsScreen = ({ onLogout }) => {
  const isTabActive = useIsTabActive('/settings');
  const navigate = useNavigate();
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
  const [integrationsStatus, setIntegrationsStatus] = useState({ huawei: false, strava: false, polar: false });
  const [huaweiSyncing, setHuaweiSyncing] = useState(false);
  const [stravaSyncing, setStravaSyncing] = useState(false);
  const [polarSyncing, setPolarSyncing] = useState(false);
  const [stravaDebug, setStravaDebug] = useState(null);
  const [settingsTabPillStyle, setSettingsTabPillStyle] = useState({ left: 0, width: 0 });
  const [telegramLinkCode, setTelegramLinkCode] = useState(null);
  const [telegramLinkCodeLoading, setTelegramLinkCodeLoading] = useState(false);
  const [telegramLoginLoading, setTelegramLoginLoading] = useState(false);
  const telegramLoginPollRef = useRef(null);
  const telegramLoginTimeoutRef = useRef(null);
  const telegramLoginPollInFlightRef = useRef(false);

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
    handleStartTelegramLogin,
    handleUnlinkTelegram,
    runStravaSync,
  } = useSettingsActions({
    api,
    csrfToken,
    setBiometricAvailable,
    setBiometricEnabled,
    setBiometricEnabling,
    setCsrfToken,
    setFormData,
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

  // Обработка OAuth callback (connected=huawei|strava|telegram, error=...)
  useEffect(() => {
    const connected = searchParams.get('connected');
    const errorParam = searchParams.get('error');
    if (connected === 'huawei') {
      setIntegrationsStatus(prev => ({ ...prev, huawei: true }));
      setMessage({ type: 'success', text: 'Huawei Health успешно подключен' });
      setSearchParams({ tab: 'integrations' });
      setTimeout(() => setMessage({ type: '', text: '' }), 3000);
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
  }, [api, handleTelegramConnected, searchParams, stopTelegramLoginPolling]);

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
      const currentApi = api || useAuthStore.getState().api;
      if (!currentApi) {
        const checkApi = setInterval(() => {
          const { api: storeApi } = useAuthStore.getState();
          if (storeApi) {
            clearInterval(checkApi);
            loadProfile(storeApi);
          }
        }, 100);
        setTimeout(() => clearInterval(checkApi), 5000);
        return;
      }
      hasLoadedProfileRef.current = true;
      await loadProfile(currentApi);
    };
    
    loadProfileData();
  }, [api, isTabActive]);
  
  // Отдельный useEffect для принудительного обновления select'ов после загрузки данных
  useEffect(() => {
    if (!loading) {
      // Используем небольшую задержку, чтобы DOM успел обновиться
      const timer = setTimeout(() => {
        // Принудительно обновляем все select'ы через DOM API
        const selectFields = [
          { field: 'race_distance', selector: 'select[key*="race_distance"]' },
          { field: 'experience_level', selector: 'select[key*="experience_level"]' },
          { field: 'training_mode', selector: 'select[key*="training_mode"]' },
          { field: 'ofp_preference', selector: 'select[key*="ofp_preference"]' },
          { field: 'training_time_pref', selector: 'select[key*="training_time_pref"]' },
          { field: 'health_program', selector: 'select[key*="health_program"]' },
          { field: 'last_race_distance', selector: 'select[key*="last_race_distance"]' },
        ];
        
        selectFields.forEach(({ field, selector }) => {
          const value = formData[field];
          if (value) {
            const select = document.querySelector(selector);
            if (select && select.value !== value) {
              select.value = value;
              // Триггерим событие change для React
              const event = new Event('change', { bubbles: true });
              select.dispatchEvent(event);
            }
          }
        });
      }, 100);
      
      return () => clearTimeout(timer);
    }
  }, [loading, formData.race_distance, formData.experience_level, formData.training_mode, 
      formData.ofp_preference, formData.training_time_pref, formData.health_program,
      formData.last_race_distance]);

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

  const handleTelegramLoginConnect = useCallback(async () => {
    setTelegramLoginLoading(true);

    const result = await handleStartTelegramLogin({ fromApp: isNativeCapacitor() });
    if (!result?.authUrl) {
      setTelegramLoginLoading(false);
      return;
    }

    if (isNativeCapacitor()) {
      try {
        const { Browser } = await import('@capacitor/browser');
        await Browser.open({ url: result.authUrl });
        startTelegramLoginPolling();
        return;
      } catch (_) {
        // Fallback ниже
      }
    }

    const popup = window.open(result.authUrl, 'planrunTelegramLogin', 'width=480,height=720');
    if (!popup) {
      window.location.href = result.authUrl;
      return;
    }

    try {
      popup.focus();
    } catch (_) {
      // Ignore focus errors
    }

    startTelegramLoginPolling();
  }, [handleStartTelegramLogin, startTelegramLoginPolling]);

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
    await onLogout();
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
                <label>Email <span className="required">*</span></label>
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
                  PIN обязателен для разблокировки. Отпечаток пальца — опционально, для быстрого входа.
                </p>
                <div className="settings-biometric-row">
                  <p>
                    {!pinEnabled
                      ? 'Блокировка выключена'
                      : biometricEnabled
                        ? 'Включена (PIN + отпечаток)'
                        : 'Включена (PIN)'}
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

            {isNativeCapacitor() && (
              <div className="settings-section">
                <h2><BellIcon size={22} className="section-icon" aria-hidden /> Push-уведомления</h2>
                <p className="settings-section-desc">Выберите, когда присылать уведомления на устройство</p>
                <div className="form-group">
                  <label className="checkbox-label">
                    <input
                      type="checkbox"
                      checked={formData.push_workouts_enabled === 1}
                      onChange={(e) => handleInputChange('push_workouts_enabled', e.target.checked ? 1 : 0)}
                    />
                    <span>Напоминания о тренировках</span>
                  </label>
                </div>
                {formData.push_workouts_enabled === 1 && (
                  <div className="form-group">
                    <label>Время напоминания</label>
                    <input
                      type="time"
                      value={`${String(formData.push_workout_hour).padStart(2, '0')}:${String(formData.push_workout_minute).padStart(2, '0')}`}
                      onChange={(e) => {
                        const [h, m] = (e.target.value || '20:00').split(':').map((n) => parseInt(n, 10) || 0);
                        setFormData((prev) => ({
                          ...prev,
                          push_workout_hour: Math.min(23, Math.max(0, h)),
                          push_workout_minute: Math.min(59, Math.max(0, m)),
                        }));
                      }}
                    />
                    <p className="settings-biometric-hint">Это же время используется для напоминаний о тренировках в Telegram-боте.</p>
                  </div>
                )}
                <div className="form-group">
                  <label className="checkbox-label">
                    <input
                      type="checkbox"
                      checked={formData.push_chat_enabled === 1}
                      onChange={(e) => handleInputChange('push_chat_enabled', e.target.checked ? 1 : 0)}
                    />
                    <span>Сообщения в чате</span>
                  </label>
                </div>
              </div>
            )}

          </div>
        )}

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
                      ref={(el) => {
                        if (el && formData.race_distance) {
                          el.value = formData.race_distance;
                        }
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
                        Debug: race_distance = "{formData.race_distance}" (type: {typeof formData.race_distance})
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
                  ref={(el) => {
                    if (el && formData.experience_level) {
                      el.value = formData.experience_level;
                    }
                  }}
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
                    Debug: experience_level = "{formData.experience_level}" (type: {typeof formData.experience_level})
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
                  ref={(el) => {
                    if (el && formData.ofp_preference) {
                      el.value = formData.ofp_preference;
                    }
                  }}
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
                    Debug: ofp_preference = "{formData.ofp_preference}" (type: {typeof formData.ofp_preference})
                  </small>
                )}
              </div>

              <div className="form-group">
                <label>Предпочтительное время тренировок</label>
                <select
                  key={`training_time_pref-${formData.training_time_pref || 'empty'}`}
                  value={formData.training_time_pref || ''}
                  onChange={(e) => handleInputChange('training_time_pref', e.target.value || null)}
                  ref={(el) => {
                    if (el && formData.training_time_pref) {
                      el.value = formData.training_time_pref;
                    }
                  }}
                >
                  <option value="">Не указано</option>
                  <option value="morning">Утро</option>
                  <option value="day">День</option>
                  <option value="evening">Вечер</option>
                </select>
                {process.env.NODE_ENV === 'development' && (
                  <small style={{ color: 'gray', fontSize: '12px', display: 'block', marginTop: '4px' }}>
                    Debug: training_time_pref = "{formData.training_time_pref}" (type: {typeof formData.training_time_pref})
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
                  ref={(el) => {
                    if (el && formData.training_mode) {
                      el.value = formData.training_mode;
                    }
                  }}
                >
                  <option value="ai">AI план</option>
                  <option value="coach">С тренером</option>
                  <option value="both">AI + тренер</option>
                  <option value="self">Самостоятельно</option>
                </select>
                {process.env.NODE_ENV === 'development' && (
                  <small style={{ color: 'gray', fontSize: '12px', display: 'block', marginTop: '4px' }}>
                    Debug: training_mode = "{formData.training_mode}" (type: {typeof formData.training_mode})
                  </small>
                )}
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
                      ref={(el) => {
                        if (el && formData.health_program) {
                          el.value = formData.health_program;
                        }
                      }}
                    >
                      <option value="">Не указано</option>
                      <option value="start_running">Начать бегать</option>
                      <option value="couch_to_5k">Couch to 5K</option>
                      <option value="regular_running">Регулярный бег</option>
                      <option value="custom">Своя программа</option>
                    </select>
                    {process.env.NODE_ENV === 'development' && (
                      <small style={{ color: 'gray', fontSize: '12px', display: 'block', marginTop: '4px' }}>
                        Debug: health_program = "{formData.health_program}" (type: {typeof formData.health_program})
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
                    Debug: easy_pace_min = "{formData.easy_pace_min}", easy_pace_sec = "{formData.easy_pace_sec}"
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
                      ref={(el) => {
                        if (el && formData.last_race_distance) {
                          el.value = formData.last_race_distance;
                        }
                      }}
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
                        Debug: last_race_distance = "{formData.last_race_distance}" (type: {typeof formData.last_race_distance})
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
                      <strong>Email</strong>
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
                      <small>Блок «Тренер» и planRUN AI</small>
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
              {(formData.telegram_id || integrationsStatus.huawei || integrationsStatus.strava || integrationsStatus.polar) && (
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
                    {integrationsStatus.huawei && (
                      <div className="integration-connected-card">
                        <div className="integration-connected-card__logo">
                          <img src="/integrations/huawei.svg" alt="Huawei Health" />
                        </div>
                        <div className="integration-connected-card__actions">
                          <button type="button" className="btn btn-primary btn--sm" disabled={huaweiSyncing} onClick={async () => {
                            const currentApi = api || useAuthStore.getState().api;
                            if (!currentApi) return;
                            setHuaweiSyncing(true);
                            try {
                              const res = await currentApi.syncWorkouts('huawei');
                              setMessage({ type: 'success', text: `Синхронизировано: ${res?.data?.imported ?? res?.imported ?? 0} новых тренировок` });
                              useWorkoutRefreshStore.getState().triggerRefresh();
                              setTimeout(() => setMessage({ type: '', text: '' }), 3000);
                            } catch (err) {
                              setMessage({ type: 'error', text: 'Ошибка синхронизации: ' + (err?.message || '') });
                            } finally {
                              setHuaweiSyncing(false);
                            }
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
                  </div>
                </div>
              )}

              {/* Не подключено — логотипы-кнопки для подключения */}
              {(!formData.telegram_id || !integrationsStatus.huawei || !integrationsStatus.strava || !integrationsStatus.polar) && (
              <>
              {(formData.telegram_id || integrationsStatus.huawei || integrationsStatus.strava || integrationsStatus.polar) && (
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
                {!integrationsStatus.huawei && (
                  <div className="integration-logo-btn" role="button" tabIndex={0} onClick={async () => {
                    const currentApi = api || useAuthStore.getState().api;
                    if (!currentApi) return;
                    try {
                      const res = await currentApi.getIntegrationOAuthUrl('huawei');
                      const url = res?.data?.auth_url ?? res?.auth_url;
                      if (url) window.location.href = url;
                      else setMessage({ type: 'error', text: 'Провайдер не настроен' });
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
                        const pollStatus = setInterval(async () => {
                          try {
                            const statusRes = await currentApi.getIntegrationsStatus();
                            const isConnected = statusRes?.data?.integrations?.strava ?? statusRes?.integrations?.strava ?? false;
                            if (isConnected) {
                              clearInterval(pollStatus);
                              setIntegrationsStatus(prev => ({ ...prev, strava: true }));
                              runStravaSync(currentApi);
                            }
                          } catch {}
                        }, 3000);
                        setTimeout(() => clearInterval(pollStatus), 300000);
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
