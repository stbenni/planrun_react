import { useCallback } from 'react';
import useAuthStore from '../../stores/useAuthStore';
import { mapProfileToFormData, normalizeValue } from './profileForm';
import { ensureNotificationChannelsEnabled, normalizeNotificationSettings } from './notificationSettings';

export function useSettingsProfile({
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
}) {
  const loadProfile = useCallback(async (apiClient = null, options = {}) => {
    const currentApi = apiClient || api || useAuthStore.getState().api;
    const silent = options.silent === true;

    if (!currentApi) {
      console.error('API client not initialized');
      setMessage({ type: 'error', text: 'Клиент сервиса не готов. Попробуйте обновить страницу.' });
      setLoading(false);
      return;
    }

    try {
      if (!silent) setLoading(true);

      const [csrfResponse, userData, notificationData] = await Promise.all([
        currentApi.request('get_csrf_token', {}, 'GET'),
        currentApi.request('get_profile', {}, 'GET'),
        currentApi.request('get_notification_settings', {}, 'GET').catch(() => null),
      ]);
      if (csrfResponse && csrfResponse.csrf_token) {
        setCsrfToken(csrfResponse.csrf_token);
      }

      if (userData && typeof userData === 'object') {
        const mappedProfile = mapProfileToFormData(userData);
        const newFormData = {
          ...mappedProfile,
          hr_zones_data: userData.hr_zones_data || null,
          notification_settings: ensureNotificationChannelsEnabled(
            normalizeNotificationSettings(notificationData, mappedProfile.timezone)
          ),
        };
        skipNextAutoSaveRef.current = true;
        setFormData(newFormData);
        if (typeof setHasUnsavedChanges === 'function') {
          setHasUnsavedChanges(false);
        }
        return newFormData;
      } else {
        console.error('Invalid user data:', userData);
        setMessage({ type: 'error', text: 'Неверный формат данных профиля' });
      }
    } catch (error) {
      console.error('Error loading profile:', error);
      if (!silent) {
        setMessage({ type: 'error', text: 'Ошибка загрузки профиля: ' + (error.message || 'Неизвестная ошибка') });
      }
    } finally {
      if (!silent) setLoading(false);
    }

    return null;
  }, [api, setCsrfToken, setFormData, setLoading, setMessage, skipNextAutoSaveRef]);

  const handleInputChange = useCallback((field, value) => {
    if (typeof setHasUnsavedChanges === 'function') {
      setHasUnsavedChanges(true);
    }
    setFormData((prev) => ({
      ...prev,
      [field]: value === null || value === undefined ? '' : value,
    }));
  }, [setFormData, setHasUnsavedChanges]);

  const handleSave = useCallback(async () => {
    const currentApi = api || useAuthStore.getState().api;

    if (!currentApi) {
      setMessage({ type: 'error', text: 'Клиент сервиса не готов. Попробуйте обновить страницу.' });
      return;
    }

    const emailVal = String(formData.email || '').trim();
    if (!emailVal) {
      setMessage({ type: 'error', text: 'Эл. почта обязательна' });
      return;
    }
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(emailVal)) {
      setMessage({ type: 'error', text: 'Некорректный адрес эл. почты' });
      return;
    }

    try {
      setSaving(true);
      setMessage({ type: '', text: '' });

      let activeCsrfToken = csrfToken;
      if (!csrfToken) {
        const csrfResponse = await currentApi.request('get_csrf_token', {}, 'GET');
        if (csrfResponse && csrfResponse.csrf_token) {
          activeCsrfToken = csrfResponse.csrf_token;
          setCsrfToken(csrfResponse.csrf_token);
        }
      }

      const saveSnapshot = JSON.stringify(formData);

      const dataToSend = {
        csrf_token: activeCsrfToken,
        username: formData.username,
        email: normalizeValue(formData.email),
        gender: normalizeValue(formData.gender),
        birth_year: normalizeValue(formData.birth_year),
        height_cm: normalizeValue(formData.height_cm),
        weight_kg: normalizeValue(formData.weight_kg),
        max_hr: normalizeValue(formData.max_hr),
        rest_hr: normalizeValue(formData.rest_hr),
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
        coach_style: formData.coach_style || 'motivational',
        training_start_date: normalizeValue(formData.training_start_date),
        health_notes: normalizeValue(formData.health_notes),
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
        privacy_show_email: formData.privacy_show_email ? 1 : 0,
        privacy_show_trainer: formData.privacy_show_trainer ? 1 : 0,
        privacy_show_calendar: formData.privacy_show_calendar ? 1 : 0,
        privacy_show_metrics: formData.privacy_show_metrics ? 1 : 0,
        privacy_show_workouts: formData.privacy_show_workouts ? 1 : 0,
      };

      const response = await currentApi.request('update_profile', dataToSend, 'POST');
      let notificationResponse = null;
      try {
        notificationResponse = await currentApi.request('update_notification_settings', {
          csrf_token: activeCsrfToken,
          ...(ensureNotificationChannelsEnabled(formData.notification_settings || {})),
        }, 'POST');
      } catch (notificationError) {
        const authStore = useAuthStore.getState();
        if (response?.user && typeof response.user === 'object' && typeof authStore.updateUser === 'function') {
          authStore.updateUser({
            ...(authStore.user || {}),
            ...response.user,
            authenticated: authStore.user?.authenticated ?? true,
          });
        }
        const mergedPartialFormData = {
          ...(response?.user ? mapProfileToFormData(response.user) : formData),
          hr_zones_data: response?.user?.hr_zones_data || formData.hr_zones_data || null,
          notification_settings: ensureNotificationChannelsEnabled(
            normalizeNotificationSettings(formData.notification_settings, response?.user?.timezone || formData.timezone)
          ),
        };
        setFormData((currentFormData) => {
          if (JSON.stringify(currentFormData) !== saveSnapshot) {
            return currentFormData;
          }
          skipNextAutoSaveRef.current = true;
          return mergedPartialFormData;
        });
        if (typeof setHasUnsavedChanges === 'function') {
          setHasUnsavedChanges(false);
        }
        setMessage({ type: 'error', text: 'Профиль сохранен, но настройки уведомлений не обновились: ' + (notificationError.message || 'Неизвестная ошибка') });
        return;
      }

      if (response && response.success !== false) {
        const authStore = useAuthStore.getState();
        if (response.user && typeof response.user === 'object' && typeof authStore.updateUser === 'function') {
          const normalizedFormData = {
            ...mapProfileToFormData(response.user),
            hr_zones_data: response.user.hr_zones_data || null,
            notification_settings: ensureNotificationChannelsEnabled(
              normalizeNotificationSettings(notificationResponse, response.user.timezone || formData.timezone)
            ),
          };
          setFormData((currentFormData) => {
            if (JSON.stringify(currentFormData) !== saveSnapshot) {
              return currentFormData;
            }
            skipNextAutoSaveRef.current = true;
            // Preserve hr_zones_data if backend didn't return it
            if (!normalizedFormData.hr_zones_data && currentFormData.hr_zones_data) {
              normalizedFormData.hr_zones_data = currentFormData.hr_zones_data;
            }
            return normalizedFormData;
          });

          authStore.updateUser({
            ...(authStore.user || {}),
            ...response.user,
            authenticated: authStore.user?.authenticated ?? true,
          });
        }
        if (typeof setHasUnsavedChanges === 'function') {
          setHasUnsavedChanges(false);
        }
        setMessage({ type: 'success', text: 'Изменения сохранены' });
      } else {
        throw new Error(response?.error || 'Ошибка обновления профиля');
      }
    } catch (error) {
      console.error('Error saving profile:', error);
      setMessage({ type: 'error', text: 'Ошибка обновления настроек: ' + (error.message || 'Неизвестная ошибка') });
    } finally {
      setSaving(false);
    }
  }, [
    api,
    csrfToken,
    formData,
    setFormData,
    setHasUnsavedChanges,
    setCsrfToken,
    setMessage,
    setSaving,
    skipNextAutoSaveRef,
  ]);

  return {
    loadProfile,
    handleInputChange,
    handleSave,
  };
}
