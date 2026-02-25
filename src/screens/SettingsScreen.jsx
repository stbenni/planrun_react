/**
 * Экран настроек профиля пользователя
 * Полная реализация с вкладками и всеми полями профиля
 */

import React, { useState, useEffect, useRef } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import useAuthStore from '../stores/useAuthStore';
import { useIsTabActive } from '../hooks/useIsTabActive';
import BiometricService from '../services/BiometricService';
import SkeletonScreen from '../components/common/SkeletonScreen';
import { getAvatarSrc } from '../utils/avatarUrl';
import './SettingsScreen.css';

function getSystemTheme() {
  return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

function getThemePreference() {
  const saved = localStorage.getItem('theme');
  return (saved === 'dark' || saved === 'light') ? saved : 'system';
}

function applyTheme(theme) {
  document.documentElement.setAttribute('data-theme', theme);
  document.body.setAttribute('data-theme', theme);
}

const VALID_TABS = ['profile', 'training', 'social', 'integrations'];

const SettingsScreen = ({ onLogout }) => {
  const isTabActive = useIsTabActive('/settings');
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const { api, updateUser } = useAuthStore();
  const tabFromUrl = searchParams.get('tab');
  const initialTab = tabFromUrl && VALID_TABS.includes(tabFromUrl) ? tabFromUrl : 'profile';
  const [activeTab, setActiveTab] = useState(initialTab);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState({ type: '', text: '' });
  const [csrfToken, setCsrfToken] = useState('');
  const skipNextAutoSaveRef = useRef(true); // не сохранять при первой установке formData из loadProfile
  const [themePreference, setThemePreference] = useState(getThemePreference);
  const [showBiometricSection, setShowBiometricSection] = useState(false);
  const [biometricAvailable, setBiometricAvailable] = useState(false);
  const [biometricEnabled, setBiometricEnabled] = useState(false);
  const [biometricDisabling, setBiometricDisabling] = useState(false);
  const [biometricEnabling, setBiometricEnabling] = useState(false);

  // Вспомогательная функция для нормализации значений
  const normalizeValue = (value) => {
    if (value === null || value === undefined || value === '' || value === 'null') {
      return null;
    }
    return value;
  };

  // Состояние формы со всеми полями
  // ВАЖНО: Все поля должны быть инициализированы как строки/массивы, а не null
  // чтобы React правильно обрабатывал контролируемые компоненты
  const [formData, setFormData] = useState({
    // Профиль
    username: '',
    email: '',
    gender: '',
    birth_year: '',
    height_cm: '',
    weight_kg: '',
    timezone: 'Europe/Moscow',
    
    // Цель
    goal_type: 'health',
    race_distance: '',
    race_date: '',
    race_target_time: '',
    target_marathon_date: '',
    target_marathon_time: '',
    weight_goal_kg: '',
    weight_goal_date: '',
    
    // Тренировки
    experience_level: 'novice',
    weekly_base_km: '',
    sessions_per_week: '',
    preferred_days: [],
    preferred_ofp_days: [],
    has_treadmill: false,
    training_time_pref: '',
    ofp_preference: '',
    training_mode: 'ai',
    training_start_date: '',
    
    // Здоровье
    health_notes: '',
    device_type: '',
    health_program: '',
    health_plan_weeks: '',
    easy_pace_min: '', // формат MM:SS
    easy_pace_sec: '', // для совместимости с бэкендом
    is_first_race_at_distance: false,
    last_race_distance: '',
    last_race_distance_km: '',
    last_race_time: '',
    last_race_date: '',
    
    // Аватар
    avatar_path: '',
    
    // Приватность
    privacy_level: 'public',
    
    // Telegram
    telegram_id: '',
  });

  // Синхронизация вкладки с URL (при переходе по ссылке с ?tab=)
  useEffect(() => {
    const tabFromUrl = searchParams.get('tab');
    if (tabFromUrl && VALID_TABS.includes(tabFromUrl)) setActiveTab(tabFromUrl);
  }, [searchParams]);

  // Статус биометрии (только на Android/iOS в Capacitor; блок показываем всегда, при недоступности — подсказка)
  useEffect(() => {
    const platform = typeof window !== 'undefined' && window.Capacitor?.getPlatform?.();
    if (!platform || !['android', 'ios'].includes(platform)) return;
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

  const loadProfile = async (apiClient = null, options = {}) => {
    const currentApi = apiClient || api || useAuthStore.getState().api;
    const silent = options.silent === true;

    if (!currentApi) {
      console.error('API client not initialized');
      setMessage({ type: 'error', text: 'API не инициализирован. Попробуйте обновить страницу.' });
      setLoading(false);
      return;
    }

    try {
      if (!silent) setLoading(true);

      // Получаем CSRF токен
      const csrfResponse = await currentApi.request('get_csrf_token', {}, 'GET');
      if (csrfResponse && csrfResponse.csrf_token) {
        setCsrfToken(csrfResponse.csrf_token);
      }
      
      // Получаем профиль
      const userData = await currentApi.request('get_profile', {}, 'GET');
      if (userData && typeof userData === 'object') {
        // Парсим preferred_days - может быть объект {run: [...], ofp: [...]} или массив
        let preferredDays = [];
        if (userData.preferred_days) {
          if (typeof userData.preferred_days === 'string') {
            try {
              const parsed = JSON.parse(userData.preferred_days);
              preferredDays = Array.isArray(parsed) ? parsed : (parsed?.run || []);
            } catch (e) {
              console.warn('Failed to parse preferred_days:', e);
            }
          } else if (Array.isArray(userData.preferred_days)) {
            preferredDays = userData.preferred_days;
          } else if (userData.preferred_days.run) {
            preferredDays = Array.isArray(userData.preferred_days.run) ? userData.preferred_days.run : [];
          }
        }
        
        let preferredOfpDays = [];
        if (userData.preferred_ofp_days) {
          if (typeof userData.preferred_ofp_days === 'string') {
            try {
              const parsed = JSON.parse(userData.preferred_ofp_days);
              preferredOfpDays = Array.isArray(parsed) ? parsed : (parsed?.ofp || []);
            } catch (e) {
              console.warn('Failed to parse preferred_ofp_days:', e);
            }
          } else if (Array.isArray(userData.preferred_ofp_days)) {
            preferredOfpDays = userData.preferred_ofp_days;
          } else if (userData.preferred_ofp_days.ofp) {
            preferredOfpDays = Array.isArray(userData.preferred_ofp_days.ofp) ? userData.preferred_ofp_days.ofp : [];
          }
        }
        
        // Форматирование времени HH:MM:SS -> HH:MM
        const formatTime = (time) => {
          if (!time) return '';
          const str = String(time);
          return str.length >= 5 ? str.substring(0, 5) : str;
        };
        
        // Форматирование даты
        const formatDate = (date) => {
          if (!date) return '';
          const str = String(date);
          return str.match(/^\d{4}-\d{2}-\d{2}$/) ? str : '';
        };
        
        // Нормализация race_distance
        const normalizeRaceDistance = (dist) => {
          if (!dist) return '';
          const d = String(dist).toLowerCase().trim();
          // Проверяем точные совпадения сначала
          if (d === 'marathon' || d === '5k' || d === '10k' || d === 'half') return d;
          // Затем проверяем по содержимому (сначала более специфичные)
          if (d.includes('марафон') || d.includes('42.2') || d.includes('42')) return 'marathon';
          if (d.includes('полумарафон') || d.includes('21.1') || d.includes('21')) return 'half';
          if (d.includes('10') && !d.includes('5') && !d.includes('42')) return '10k';
          if (d.includes('5') && !d.includes('10') && !d.includes('42')) return '5k';
          console.warn('Could not normalize race_distance:', dist);
          return '';
        };
        
        // Простое преобразование данных в формат формы
        const newFormData = {
          username: String(userData.username || ''),
          email: String(userData.email || ''),
          gender: String(userData.gender || ''),
          birth_year: userData.birth_year ? String(userData.birth_year) : '',
          height_cm: userData.height_cm ? String(userData.height_cm) : '',
          weight_kg: userData.weight_kg ? String(userData.weight_kg) : '',
          timezone: String(userData.timezone || 'Europe/Moscow'),
          goal_type: String(userData.goal_type || 'health'),
          race_distance: normalizeRaceDistance(userData.race_distance),
          race_date: formatDate(userData.race_date),
          race_target_time: formatTime(userData.race_target_time),
          target_marathon_date: formatDate(userData.target_marathon_date),
          target_marathon_time: formatTime(userData.target_marathon_time),
          weight_goal_kg: userData.weight_goal_kg ? String(userData.weight_goal_kg) : '',
          weight_goal_date: formatDate(userData.weight_goal_date),
          // Маппинг старых значений experience_level на новые
          experience_level: (() => {
            const oldLevel = String(userData.experience_level || 'beginner');
            
            // Маппинг старых значений на новые детальные
            if (oldLevel === 'beginner') return 'beginner';
            if (oldLevel === 'intermediate') return 'intermediate';
            if (oldLevel === 'advanced') return 'advanced';
            
            // Если значение уже новое (novice, expert), оставляем как есть
            if (['novice', 'expert'].includes(oldLevel)) return oldLevel;
            
            return 'novice'; // по умолчанию для новых пользователей
          })(),
          weekly_base_km: userData.weekly_base_km ? String(userData.weekly_base_km) : '',
          sessions_per_week: userData.sessions_per_week ? String(userData.sessions_per_week) : '',
          preferred_days: preferredDays,
          preferred_ofp_days: preferredOfpDays,
          has_treadmill: Boolean(userData.has_treadmill === 1 || userData.has_treadmill === true),
          training_time_pref: String(userData.training_time_pref || ''),
          ofp_preference: String(userData.ofp_preference || ''),
          training_mode: String(userData.training_mode || 'ai'),
          training_start_date: formatDate(userData.training_start_date),
          health_notes: String(userData.health_notes || ''),
          device_type: String(userData.device_type || ''),
          health_program: String(userData.health_program || ''),
          health_plan_weeks: userData.health_plan_weeks ? String(userData.health_plan_weeks) : '',
          // Конвертируем секунды в формат MM:SS для отображения
          easy_pace_sec: (userData.easy_pace_sec !== null && userData.easy_pace_sec !== undefined && userData.easy_pace_sec !== '') ? String(userData.easy_pace_sec) : '',
          easy_pace_min: (() => {
            if (userData.easy_pace_sec !== null && userData.easy_pace_sec !== undefined && userData.easy_pace_sec !== '') {
              const sec = parseInt(userData.easy_pace_sec);
              if (!isNaN(sec)) {
                const minutes = Math.floor(sec / 60);
                const seconds = sec % 60;
                return `${minutes}:${seconds.toString().padStart(2, '0')}`;
              }
            }
            return '';
          })(),
          is_first_race_at_distance: Boolean(userData.is_first_race_at_distance === 1 || userData.is_first_race_at_distance === true),
          last_race_distance: String(userData.last_race_distance || ''),
          last_race_distance_km: userData.last_race_distance_km ? String(userData.last_race_distance_km) : '',
          last_race_time: formatTime(userData.last_race_time),
          last_race_date: formatDate(userData.last_race_date),
          avatar_path: String(userData.avatar_path || ''),
          privacy_level: String(userData.privacy_level || 'public'),
          telegram_id: userData.telegram_id ? String(userData.telegram_id) : '',
        };
        skipNextAutoSaveRef.current = true;
        setFormData(newFormData);
      } else {
        console.error('Invalid user data:', userData);
        setMessage({ type: 'error', text: 'Неверный формат данных профиля' });
      }
    } catch (error) {
      console.error('Error loading profile:', error);
      if (!silent) setMessage({ type: 'error', text: 'Ошибка загрузки профиля: ' + (error.message || 'Неизвестная ошибка') });
    } finally {
      if (!silent) setLoading(false);
    }
  };

  const handleInputChange = (field, value) => {
    setFormData(prev => ({
      ...prev,
      [field]: value === null || value === undefined ? '' : value
    }));
  };

  const handleTabChange = (tab) => {
    setActiveTab(tab);
    setSearchParams({ tab });
    if (window.innerWidth <= 768) {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
  };

  // Автосохранение при изменении полей (debounce 800 ms), без сохранения при загрузке профиля
  useEffect(() => {
    if (skipNextAutoSaveRef.current) {
      skipNextAutoSaveRef.current = false;
      return;
    }
    if (loading) return;
    const timerId = setTimeout(() => {
      handleSave();
    }, 800);
    return () => clearTimeout(timerId);
  }, [formData]);

  const handleSave = async () => {
    const currentApi = api || useAuthStore.getState().api;
    
    if (!currentApi) {
      setMessage({ type: 'error', text: 'API не инициализирован. Попробуйте обновить страницу.' });
      return;
    }

    const emailVal = String(formData.email || '').trim();
    if (!emailVal) {
      setMessage({ type: 'error', text: 'Email обязателен' });
      return;
    }
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(emailVal)) {
      setMessage({ type: 'error', text: 'Некорректный формат email' });
      return;
    }

    try {
      setSaving(true);
      setMessage({ type: '', text: '' });
      
      // Получаем CSRF токен если его нет
      if (!csrfToken) {
        const csrfResponse = await currentApi.request('get_csrf_token', {}, 'GET');
        if (csrfResponse && csrfResponse.csrf_token) {
          setCsrfToken(csrfResponse.csrf_token);
        }
      }
      
      // Подготавливаем данные для отправки
      // ВАЖНО: Отправляем все поля, включая null, чтобы сервер мог их обработать
      const dataToSend = {
        csrf_token: csrfToken,
        username: formData.username,
        email: normalizeValue(formData.email),
        gender: normalizeValue(formData.gender),
        birth_year: normalizeValue(formData.birth_year),
        height_cm: normalizeValue(formData.height_cm),
        weight_kg: normalizeValue(formData.weight_kg),
        timezone: formData.timezone,
        goal_type: formData.goal_type,
        race_distance: normalizeValue(formData.race_distance),
        race_date: normalizeValue(formData.race_date),
        race_target_time: normalizeValue(formData.race_target_time),
        target_marathon_date: normalizeValue(formData.target_marathon_date),
        target_marathon_time: normalizeValue(formData.target_marathon_time),
        weight_goal_kg: normalizeValue(formData.weight_goal_kg),
        weight_goal_date: normalizeValue(formData.weight_goal_date),
        experience_level: formData.experience_level,
        weekly_base_km: normalizeValue(formData.weekly_base_km),
        sessions_per_week: normalizeValue(formData.sessions_per_week),
        preferred_days: Array.isArray(formData.preferred_days) ? formData.preferred_days : [],
        preferred_ofp_days: Array.isArray(formData.preferred_ofp_days) ? formData.preferred_ofp_days : [],
        has_treadmill: formData.has_treadmill,
        training_time_pref: normalizeValue(formData.training_time_pref),
        ofp_preference: normalizeValue(formData.ofp_preference),
        training_mode: formData.training_mode,
        training_start_date: normalizeValue(formData.training_start_date),
        health_notes: normalizeValue(formData.health_notes),
        device_type: normalizeValue(formData.device_type),
        health_program: normalizeValue(formData.health_program),
        health_plan_weeks: normalizeValue(formData.health_plan_weeks),
        easy_pace_sec: normalizeValue(formData.easy_pace_sec),
        is_first_race_at_distance: formData.is_first_race_at_distance,
        last_race_distance: normalizeValue(formData.last_race_distance),
        last_race_distance_km: normalizeValue(formData.last_race_distance_km),
        last_race_time: normalizeValue(formData.last_race_time),
        last_race_date: normalizeValue(formData.last_race_date),
        avatar_path: normalizeValue(formData.avatar_path),
        privacy_level: formData.privacy_level,
      };
      
      console.log('=== SAVING PROFILE ===');
      console.log('Data to send:', {
        race_distance: dataToSend.race_distance,
        experience_level: dataToSend.experience_level,
        training_mode: dataToSend.training_mode,
        ofp_preference: dataToSend.ofp_preference,
        training_time_pref: dataToSend.training_time_pref,
        health_program: dataToSend.health_program,
        last_race_distance: dataToSend.last_race_distance,
        training_start_date: dataToSend.training_start_date,
      });
      
      const response = await currentApi.request('update_profile', dataToSend, 'POST');
      
      console.log('=== SAVE RESPONSE ===');
      console.log('Response:', response);
      
      if (response && response.success !== false) {
        skipNextAutoSaveRef.current = true;
        await loadProfile(currentApi, { silent: true });
      } else {
        throw new Error(response?.error || 'Ошибка обновления профиля');
      }
    } catch (error) {
      console.error('Error saving profile:', error);
      setMessage({ type: 'error', text: 'Ошибка обновления настроек: ' + (error.message || 'Неизвестная ошибка') });
    } finally {
      setSaving(false);
    }
  };

  const handleLogout = async () => {
    await onLogout();
    navigate('/login');
  };

  const handleEnableBiometric = async () => {
    const currentApi = api || useAuthStore.getState().api;
    if (!currentApi) {
      setMessage({ type: 'error', text: 'Войдите в аккаунт, затем включите вход по отпечатку' });
      return;
    }
    setBiometricEnabling(true);
    setMessage({ type: '', text: '' });
    try {
      const authPromise = BiometricService.authenticate('Подтвердите отпечаток для входа в PlanRun');
      const timeoutMs = 15000;
      const authResult = await Promise.race([
        authPromise,
        new Promise((_, reject) => setTimeout(() => reject(new Error('Таймаут: диалог отпечатка не открылся')), timeoutMs))
      ]);
      if (!authResult?.success) {
        const err = authResult?.error || '';
        if (err.includes('enrolled') || err.includes('none') || err.includes('not enrolled')) {
          setMessage({ type: 'error', text: 'Добавьте отпечаток в настройках устройства' });
        } else if (err.includes('cancel') || err.includes('Cancel') || err.includes('user')) {
          setMessage({ type: 'error', text: 'Проверка отпечатка отменена' });
        } else {
          setMessage({ type: 'error', text: err || 'Не удалось проверить отпечаток' });
        }
        return;
      }
      const accessToken = await currentApi.getToken();
      const refreshToken = await currentApi.getRefreshToken();
      if (!accessToken || !refreshToken) {
        setMessage({ type: 'error', text: 'Нет сохранённой сессии. Войдите по паролю, затем включите отпечаток.' });
        return;
      }
      await BiometricService.saveTokens(accessToken, refreshToken);
      setBiometricEnabled(true);
      setBiometricAvailable(true);
      setMessage({ type: 'success', text: 'Вход по отпечатку включён' });
    } catch (e) {
      const msg = e?.message || String(e);
      if (msg.includes('enrolled') || msg.includes('none') || msg.includes('not enrolled')) {
        setMessage({ type: 'error', text: 'Добавьте отпечаток в настройках устройства' });
      } else {
        setMessage({ type: 'error', text: msg || 'Не удалось включить вход по отпечатку' });
      }
    } finally {
      setBiometricEnabling(false);
    }
  };

  const handleDisableBiometric = async () => {
    setBiometricDisabling(true);
    try {
      await BiometricService.clearTokens();
      setBiometricEnabled(false);
      setMessage({ type: 'success', text: 'Вход по отпечатку отключён' });
    } catch (e) {
      setMessage({ type: 'error', text: 'Не удалось отключить биометрию' });
    } finally {
      setBiometricDisabling(false);
    }
  };

  const handleAvatarUpload = async (e) => {
    const file = e.target.files?.[0];
    if (!file) return;

    const currentApi = api || useAuthStore.getState().api;
    if (!currentApi) {
      setMessage({ type: 'error', text: 'API не инициализирован' });
      return;
    }

    try {
      // Получаем CSRF токен если его нет
      if (!csrfToken) {
        const csrfResponse = await currentApi.request('get_csrf_token', {}, 'GET');
        if (csrfResponse && csrfResponse.csrf_token) {
          setCsrfToken(csrfResponse.csrf_token);
        }
      }

      // Формируем FormData для загрузки файла
      const formData = new FormData();
      formData.append('avatar', file);
      if (csrfToken) {
        formData.append('csrf_token', csrfToken);
      }

      // Получаем токен авторизации
      const token = await currentApi.getToken();
      // Тот же endpoint, что и у остального API (api_wrapper → api_v2), иначе 405 и HTML вместо JSON
      const uploadUrl = `${currentApi.baseUrl}/api_wrapper.php?action=upload_avatar`;

      const response = await fetch(uploadUrl, {
        method: 'POST',
        headers: {
          ...(token ? { 'Authorization': `Bearer ${token}` } : {}),
        },
        credentials: 'include',
        body: formData,
      });

      const text = await response.text();
      let data;
      try {
        data = JSON.parse(text);
      } catch {
        throw new Error(response.status === 405 ? 'Метод не разрешён. Проверьте настройки сервера.' : (text.slice(0, 100) || 'Ошибка загрузки аватара'));
      }
      
      if (data.success && data.data) {
        const userData = data.data.user || data.data;
        const newAvatarPath = userData.avatar_path || data.data.avatar_path;
        setFormData(prev => ({ ...prev, avatar_path: newAvatarPath }));
        const currentUser = useAuthStore.getState().user;
        if (currentUser && typeof updateUser === 'function') {
          updateUser({ ...currentUser, avatar_path: newAvatarPath });
        }
        setMessage({ type: 'success', text: 'Аватар успешно загружен' });
        setTimeout(() => setMessage({ type: '', text: '' }), 3000);
      } else {
        throw new Error(data.error || 'Ошибка загрузки аватара');
      }
    } catch (error) {
      console.error('Error uploading avatar:', error);
      setMessage({ type: 'error', text: 'Ошибка загрузки аватара: ' + (error.message || 'Неизвестная ошибка') });
    }
  };

  const handleRemoveAvatar = async () => {
    const currentApi = api || useAuthStore.getState().api;
    if (!currentApi) {
      setMessage({ type: 'error', text: 'API не инициализирован' });
      return;
    }

    try {
      // Получаем CSRF токен если его нет
      if (!csrfToken) {
        const csrfResponse = await currentApi.request('get_csrf_token', {}, 'GET');
        if (csrfResponse && csrfResponse.csrf_token) {
          setCsrfToken(csrfResponse.csrf_token);
        }
      }

      const response = await currentApi.request('remove_avatar', { csrf_token: csrfToken }, 'POST');
      
      if (response && response.success !== false) {
        setFormData(prev => ({ ...prev, avatar_path: null }));
        const currentUser = useAuthStore.getState().user;
        if (currentUser && typeof updateUser === 'function') {
          updateUser({ ...currentUser, avatar_path: null });
        }
        setMessage({ type: 'success', text: 'Аватар успешно удален' });
        setTimeout(() => setMessage({ type: '', text: '' }), 3000);
      } else {
        throw new Error(response?.error || 'Ошибка удаления аватара');
      }
    } catch (error) {
      console.error('Error removing avatar:', error);
      setMessage({ type: 'error', text: 'Ошибка удаления аватара: ' + (error.message || 'Неизвестная ошибка') });
    }
  };

  const handleUnlinkTelegram = async () => {
    const currentApi = api || useAuthStore.getState().api;
    if (!currentApi) {
      setMessage({ type: 'error', text: 'API не инициализирован' });
      return;
    }

    if (!window.confirm('Вы уверены, что хотите отвязать Telegram?')) {
      return;
    }

    try {
      // Получаем CSRF токен если его нет
      if (!csrfToken) {
        const csrfResponse = await currentApi.request('get_csrf_token', {}, 'GET');
        if (csrfResponse && csrfResponse.csrf_token) {
          setCsrfToken(csrfResponse.csrf_token);
        }
      }

      const response = await currentApi.request('unlink_telegram', { csrf_token: csrfToken }, 'POST');
      
      if (response && response.success !== false) {
        setFormData(prev => ({
          ...prev,
          telegram_id: null
        }));
        setMessage({ type: 'success', text: 'Telegram успешно отвязан' });
        setTimeout(() => setMessage({ type: '', text: '' }), 3000);
      } else {
        throw new Error(response?.error || 'Ошибка отвязки Telegram');
      }
    } catch (error) {
      console.error('Error unlinking Telegram:', error);
      setMessage({ type: 'error', text: 'Ошибка отвязки Telegram: ' + (error.message || 'Неизвестная ошибка') });
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

  const daysOfWeek = [
    { value: 'mon', label: 'Пн' },
    { value: 'tue', label: 'Вт' },
    { value: 'wed', label: 'Ср' },
    { value: 'thu', label: 'Чт' },
    { value: 'fri', label: 'Пт' },
    { value: 'sat', label: 'Сб' },
    { value: 'sun', label: 'Вс' },
  ];

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
              onClick={() => setMessage({ type: '', text: '' })}
              aria-label="Закрыть"
            >
              ×
            </button>
          </div>
        )}
        <div className="settings-tabs">
          <button
            className={`tab-button ${activeTab === 'profile' ? 'active' : ''}`}
            onClick={() => handleTabChange('profile')}
          >
            👤 Профиль
          </button>
          <button
            className={`tab-button ${activeTab === 'training' ? 'active' : ''}`}
            onClick={() => handleTabChange('training')}
          >
            🏃 Тренировки
          </button>
          <button
            className={`tab-button ${activeTab === 'social' ? 'active' : ''}`}
            onClick={() => handleTabChange('social')}
          >
            🔒 Конфиденциальность
          </button>
          <button
            className={`tab-button ${activeTab === 'integrations' ? 'active' : ''}`}
            onClick={() => handleTabChange('integrations')}
          >
            🔗 Интеграции
          </button>
        </div>

        {/* Вкладка Профиль */}
        {activeTab === 'profile' && (
          <div className="tab-content active">
            <div className="settings-section">
              <h2>👤 Личная информация</h2>
              <p>Основные данные вашего профиля</p>

              {/* Аватар */}
              <div className="form-group">
                <label>Аватар</label>
                <div className="avatar-upload-section">
                  {formData.avatar_path ? (
                    <div className="avatar-preview-container">
                      <img
                        src={getAvatarSrc(formData.avatar_path, api?.baseUrl || '/api')}
                        alt="Аватар"
                        className="avatar-preview"
                        style={{ width: '120px', height: '120px', borderRadius: '50%', objectFit: 'cover', border: '3px solid var(--primary-500)' }}
                      />
                      <button
                        type="button"
                        className="btn btn-secondary btn-sm"
                        onClick={handleRemoveAvatar}
                        style={{ marginTop: '10px' }}
                      >
                        Удалить аватар
                      </button>
                    </div>
                  ) : (
                    <div className="avatar-upload-area">
                      <input
                        type="file"
                        id="avatar-upload"
                        accept="image/jpeg,image/jpg,image/png,image/gif,image/webp"
                        onChange={handleAvatarUpload}
                        style={{ display: 'none' }}
                      />
                      <label htmlFor="avatar-upload" className="avatar-upload-label" style={{ 
                        display: 'flex', 
                        flexDirection: 'column', 
                        alignItems: 'center', 
                        padding: '20px', 
                        border: '2px dashed var(--gray-300)', 
                        borderRadius: '8px', 
                        cursor: 'pointer',
                        transition: 'border-color 0.2s'
                      }}>
                        <span style={{ fontSize: '32px', marginBottom: '8px' }}>📷</span>
                        <span style={{ fontWeight: '600', marginBottom: '4px' }}>Загрузить аватар</span>
                        <small style={{ color: 'var(--gray-600)' }}>JPEG, PNG, GIF, WebP (макс. 5MB)</small>
                      </label>
                    </div>
                  )}
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
              <h2>🎨 Внешний вид</h2>
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
              <div className="settings-section settings-biometric-section">
                <h2>Отпечаток пальца</h2>
                <div className="settings-biometric-row">
                  <p>Вход по отпечатку: {biometricEnabled ? 'включён' : 'выключен'}</p>
                  {biometricEnabled ? (
                    <button
                      type="button"
                      className="btn btn-secondary btn-sm"
                      onClick={handleDisableBiometric}
                      disabled={biometricDisabling}
                    >
                      {biometricDisabling ? '…' : 'Отключить'}
                    </button>
                  ) : (
                    <button
                      type="button"
                      className="btn btn-primary btn-sm"
                      onClick={handleEnableBiometric}
                      disabled={biometricEnabling}
                    >
                      {biometricEnabling ? '…' : 'Включить'}
                    </button>
                  )}
                </div>
                {!biometricAvailable && !biometricEnabled && (
                  <p className="settings-biometric-hint">На некоторых устройствах проверка может не срабатывать — нажмите «Включить» и подтвердите отпечаток.</p>
                )}
              </div>
            )}
          </div>
        )}

        {/* Вкладка Тренировки (объединены Цели, Тренировки и Здоровье) */}
        {activeTab === 'training' && (
          <div className="tab-content active" key={`training-${formData.weekly_base_km}-${formData.preferred_days?.length}`}>
            {/* Секция: Цели */}
            <div className="settings-section">
              <h2>🎯 Мои цели</h2>
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
              <h2>🏃 Настройки тренировок</h2>
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
              <h2>💪 Здоровье и опыт</h2>
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
              <h2>👥 Конфиденциальность</h2>
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
            </div>
          </div>
        )}

        {/* Вкладка Интеграции */}
        {activeTab === 'integrations' && (
          <div className="tab-content active">
            <div className="settings-section">
              <h2>🔗 Интеграции</h2>
              <p>Подключите внешние сервисы для расширения возможностей</p>

              <div className="form-group">
                <label>Telegram бот</label>
                {formData.telegram_id ? (
                  <div className="telegram-status">
                    <div className="status-success">
                      <strong>✅ Telegram подключен</strong>
                      <small>ID: <code>{formData.telegram_id}</code></small>
                      <button
                        type="button"
                        className="btn btn-secondary btn-sm"
                        onClick={handleUnlinkTelegram}
                        style={{ marginTop: '10px' }}
                      >
                        Отвязать Telegram
                      </button>
                    </div>
                  </div>
                ) : (
                  <div className="integration-not-connected">
                    <p>Telegram бот не подключен</p>
                    <p className="text-muted">Для подключения Telegram бота отправьте команду /start боту</p>
                  </div>
                )}
              </div>

              <div className="form-group">
                <label>Тип спортивного устройства</label>
                <input
                  type="text"
                  value={formData.device_type || ''}
                  onChange={(e) => handleInputChange('device_type', e.target.value || null)}
                  placeholder="Garmin, Apple Watch, Polar и т.д."
                />
                <small style={{ color: 'var(--gray-600)', fontSize: '12px', display: 'block', marginTop: '4px' }}>
                  Укажите тип вашего спортивного устройства для синхронизации данных тренировок
                </small>
              </div>
            </div>
          </div>
        )}

      </div>
    </div>
  );
};

export default SettingsScreen;
