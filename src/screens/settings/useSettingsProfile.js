import { useCallback } from 'react';
import useAuthStore from '../../stores/useAuthStore';
import { mapProfileToFormData, normalizeValue } from './profileForm';

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
      setMessage({ type: 'error', text: 'API не инициализирован. Попробуйте обновить страницу.' });
      setLoading(false);
      return;
    }

    try {
      if (!silent) setLoading(true);

      const csrfResponse = await currentApi.request('get_csrf_token', {}, 'GET');
      if (csrfResponse && csrfResponse.csrf_token) {
        setCsrfToken(csrfResponse.csrf_token);
      }

      const userData = await currentApi.request('get_profile', {}, 'GET');
      if (userData && typeof userData === 'object') {
        const newFormData = mapProfileToFormData(userData);
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
        push_workouts_enabled: formData.push_workouts_enabled ? 1 : 0,
        push_chat_enabled: formData.push_chat_enabled ? 1 : 0,
        push_workout_hour: Math.min(23, Math.max(0, parseInt(formData.push_workout_hour, 10) || 20)),
        push_workout_minute: Math.min(59, Math.max(0, parseInt(formData.push_workout_minute, 10) || 0)),
      };

      const response = await currentApi.request('update_profile', dataToSend, 'POST');

      if (response && response.success !== false) {
        const authStore = useAuthStore.getState();
        if (response.user && typeof response.user === 'object' && typeof authStore.updateUser === 'function') {
          const normalizedFormData = mapProfileToFormData(response.user);
          setFormData((currentFormData) => {
            if (JSON.stringify(currentFormData) !== saveSnapshot) {
              return currentFormData;
            }
            skipNextAutoSaveRef.current = true;
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
