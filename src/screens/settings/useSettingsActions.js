import { useCallback } from 'react';
import useAuthStore from '../../stores/useAuthStore';
import useWorkoutRefreshStore from '../../stores/useWorkoutRefreshStore';
import BiometricService from '../../services/BiometricService';
import PinAuthService from '../../services/PinAuthService';
import { isNativeCapacitor } from '../../services/TokenStorageService';

export function useSettingsActions({
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
}) {
  const runStravaSync = useCallback(async (apiClient) => {
    setMessage({ type: 'success', text: 'Strava успешно подключен. Синхронизация...' });
    setStravaSyncing(true);

    try {
      const response = await apiClient.syncWorkouts('strava');
      const imported = response?.data?.imported ?? response?.imported ?? 0;
      setMessage({ type: 'success', text: `Strava подключен. Синхронизировано: ${imported} тренировок` });
      useWorkoutRefreshStore.getState().triggerRefresh();
      setTimeout(() => setMessage({ type: '', text: '' }), 4000);
    } catch (error) {
      setMessage({ type: 'error', text: `Strava подключен, но ошибка синхронизации: ${error?.message || ''}` });
    } finally {
      setStravaSyncing(false);
    }
  }, [setMessage, setStravaSyncing]);

  const runHuaweiSync = useCallback(async (apiClient, announceConnected = false) => {
    setMessage({
      type: 'success',
      text: announceConnected ? 'Huawei Health успешно подключен. Синхронизация...' : 'Синхронизация Huawei Health...',
    });
    setHuaweiSyncing(true);

    try {
      const response = await apiClient.syncWorkouts('huawei');
      const imported = response?.data?.imported ?? response?.imported ?? 0;
      setMessage({
        type: 'success',
        text: announceConnected
          ? `Huawei Health подключен. Синхронизировано: ${imported} тренировок`
          : `Синхронизировано из Huawei Health: ${imported} тренировок`,
      });
      useWorkoutRefreshStore.getState().triggerRefresh();
      setTimeout(() => setMessage({ type: '', text: '' }), 4000);
    } catch (error) {
      setMessage({
        type: 'error',
        text: announceConnected
          ? `Huawei Health подключен, но ошибка синхронизации: ${error?.message || ''}`
          : `Ошибка синхронизации Huawei Health: ${error?.message || ''}`,
      });
    } finally {
      setHuaweiSyncing(false);
    }
  }, [setHuaweiSyncing, setMessage]);

  const handleEnableLock = useCallback(async () => {
    const currentApi = api || useAuthStore.getState().api;
    if (!currentApi) {
      setMessage({ type: 'error', text: 'Войдите в аккаунт, затем включите блокировку' });
      return;
    }

    const pinAvailable = await PinAuthService.isAvailable();
    if (!pinAvailable) {
      setMessage({ type: 'error', text: 'Блокировка доступна только в мобильном приложении (Android/iOS)' });
      return;
    }

    const accessToken = await currentApi.getToken();
    const refreshToken = await currentApi.getRefreshToken();
    if (!accessToken || !refreshToken) {
      setMessage({ type: 'error', text: 'Нет сохранённой сессии. Войдите по паролю.' });
      return;
    }

    setPinSetupTokens({ accessToken, refreshToken });
    setShowPinSetupModal(true);
  }, [api, setMessage, setPinSetupTokens, setShowPinSetupModal]);

  const handlePinSetupSuccess = useCallback(() => {
    setPinEnabled(true);
    setShowPinSetupModal(false);
    setPinSetupTokens(null);
    setMessage({ type: 'success', text: 'Блокировка включена. При желании добавьте отпечаток для быстрого входа.' });
  }, [setMessage, setPinEnabled, setPinSetupTokens, setShowPinSetupModal]);

  const handleAddFingerprint = useCallback(async () => {
    if (!isNativeCapacitor()) return;

    const currentApi = api || useAuthStore.getState().api;
    if (!currentApi) return;

    setBiometricEnabling(true);
    setMessage({ type: '', text: '' });

    try {
      const availability = await BiometricService.checkAvailability();
      if (!availability.available) {
        setMessage({ type: 'error', text: availability.reason || availability.error || 'Добавьте отпечаток в настройках устройства' });
        return;
      }

      const authResult = await Promise.race([
        BiometricService.authenticate('Подтвердите отпечаток для входа в PlanRun'),
        new Promise((_, reject) => setTimeout(() => reject(new Error('Таймаут')), 15000)),
      ]);

      if (!authResult?.success) {
        const errorText = authResult?.error || '';
        if (errorText.includes('cancel') || errorText.includes('Cancel')) {
          setMessage({ type: 'error', text: 'Проверка отпечатка отменена' });
        } else {
          setMessage({ type: 'error', text: errorText || 'Не удалось проверить отпечаток' });
        }
        return;
      }

      const accessToken = await currentApi.getToken();
      const refreshToken = await currentApi.getRefreshToken();
      if (!accessToken || !refreshToken) {
        setMessage({ type: 'error', text: 'Нет сохранённой сессии.' });
        return;
      }

      const saved = await BiometricService.saveTokens(accessToken, refreshToken);
      if (!saved) {
        setMessage({ type: 'error', text: 'Не удалось сохранить. Попробуйте снова.' });
        return;
      }

      setBiometricEnabled(true);
      setBiometricAvailable(true);
      setMessage({ type: 'success', text: 'Вход по отпечатку добавлен' });
    } catch (error) {
      setMessage({ type: 'error', text: error?.message || 'Не удалось добавить отпечаток' });
    } finally {
      setBiometricEnabling(false);
    }
  }, [api, setBiometricAvailable, setBiometricEnabled, setBiometricEnabling, setMessage]);

  const handleDisableLock = useCallback(async () => {
    setPinDisabling(true);
    try {
      await PinAuthService.clearPin();
      await BiometricService.clearTokens();
      setPinEnabled(false);
      setBiometricEnabled(false);
      setMessage({ type: 'success', text: 'Блокировка отключена' });
    } catch {
      setMessage({ type: 'error', text: 'Не удалось отключить блокировку' });
    } finally {
      setPinDisabling(false);
    }
  }, [setBiometricEnabled, setMessage, setPinDisabling, setPinEnabled]);

  const ensureCsrfToken = useCallback(async (apiClient) => {
    if (csrfToken) return csrfToken;
    const csrfResponse = await apiClient.request('get_csrf_token', {}, 'GET');
    const nextCsrfToken = csrfResponse?.csrf_token || '';
    if (nextCsrfToken) {
      setCsrfToken(nextCsrfToken);
    }
    return nextCsrfToken;
  }, [csrfToken, setCsrfToken]);

  const handleAvatarUpload = useCallback(async (event) => {
    const file = event.target.files?.[0];
    if (!file) return;

    const currentApi = api || useAuthStore.getState().api;
    if (!currentApi) {
      setMessage({ type: 'error', text: 'API не инициализирован' });
      return;
    }

    try {
      const nextCsrfToken = await ensureCsrfToken(currentApi);
      const uploadFormData = new FormData();
      uploadFormData.append('avatar', file);
      if (nextCsrfToken) {
        uploadFormData.append('csrf_token', nextCsrfToken);
      }

      const token = await currentApi.getToken();
      const uploadUrl = `${currentApi.baseUrl}/api_wrapper.php?action=upload_avatar`;
      const response = await fetch(uploadUrl, {
        method: 'POST',
        headers: {
          ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
        credentials: 'include',
        body: uploadFormData,
      });

      const text = await response.text();
      let data;
      try {
        data = JSON.parse(text);
      } catch {
        throw new Error(
          response.status === 405
            ? 'Метод не разрешён. Проверьте настройки сервера.'
            : (text.slice(0, 100) || 'Ошибка загрузки аватара')
        );
      }

      if (!data.success || !data.data) {
        throw new Error(data.error || 'Ошибка загрузки аватара');
      }

      const userData = data.data.user || data.data;
      const newAvatarPath = userData.avatar_path || data.data.avatar_path;
      setFormData((prev) => ({ ...prev, avatar_path: newAvatarPath }));
      const currentUser = useAuthStore.getState().user;
      if (currentUser && typeof updateUser === 'function') {
        updateUser({ ...currentUser, avatar_path: newAvatarPath });
      }
      setMessage({ type: 'success', text: 'Аватар успешно загружен' });
      setTimeout(() => setMessage({ type: '', text: '' }), 3000);
    } catch (error) {
      console.error('Error uploading avatar:', error);
      setMessage({ type: 'error', text: `Ошибка загрузки аватара: ${error.message || 'Неизвестная ошибка'}` });
    }
  }, [api, ensureCsrfToken, setFormData, setMessage, updateUser]);

  const handleRemoveAvatar = useCallback(async () => {
    const currentApi = api || useAuthStore.getState().api;
    if (!currentApi) {
      setMessage({ type: 'error', text: 'API не инициализирован' });
      return;
    }

    try {
      const nextCsrfToken = await ensureCsrfToken(currentApi);
      const response = await currentApi.request('remove_avatar', { csrf_token: nextCsrfToken }, 'POST');
      if (response && response.success !== false) {
        setFormData((prev) => ({ ...prev, avatar_path: null }));
        const currentUser = useAuthStore.getState().user;
        if (currentUser && typeof updateUser === 'function') {
          updateUser({ ...currentUser, avatar_path: null });
        }
        setMessage({ type: 'success', text: 'Аватар успешно удален' });
        setTimeout(() => setMessage({ type: '', text: '' }), 3000);
        return;
      }
      throw new Error(response?.error || 'Ошибка удаления аватара');
    } catch (error) {
      console.error('Error removing avatar:', error);
      setMessage({ type: 'error', text: `Ошибка удаления аватара: ${error.message || 'Неизвестная ошибка'}` });
    }
  }, [api, ensureCsrfToken, setFormData, setMessage, updateUser]);

  const handleUnlinkTelegram = useCallback(async () => {
    const currentApi = api || useAuthStore.getState().api;
    if (!currentApi) {
      setMessage({ type: 'error', text: 'API не инициализирован' });
      return;
    }

    if (!window.confirm('Вы уверены, что хотите отвязать Telegram?')) {
      return;
    }

    try {
      const nextCsrfToken = await ensureCsrfToken(currentApi);
      const response = await currentApi.request('unlink_telegram', { csrf_token: nextCsrfToken }, 'POST');
      if (response && response.success !== false) {
        setFormData((prev) => ({ ...prev, telegram_id: null }));
        setMessage({ type: 'success', text: 'Telegram успешно отвязан' });
        setTimeout(() => setMessage({ type: '', text: '' }), 3000);
        return;
      }
      throw new Error(response?.error || 'Ошибка отвязки Telegram');
    } catch (error) {
      console.error('Error unlinking Telegram:', error);
      setMessage({ type: 'error', text: `Ошибка отвязки Telegram: ${error.message || 'Неизвестная ошибка'}` });
    }
  }, [api, ensureCsrfToken, setFormData, setMessage]);

  const handleStartTelegramLogin = useCallback(async (options = {}) => {
    const currentApi = api || useAuthStore.getState().api;
    if (!currentApi) {
      setMessage({ type: 'error', text: 'API не инициализирован' });
      return null;
    }

    try {
      const response = await currentApi.request('telegram_login_url', options.fromApp ? { from_app: '1' } : {}, 'GET');
      const data = response?.data ?? response;
      const authUrl = data?.auth_url;

      if (!authUrl) {
        throw new Error('Сервер не вернул Telegram auth URL');
      }

      return { authUrl };
    } catch (error) {
      console.error('Error starting Telegram Login:', error);
      setMessage({ type: 'error', text: `Ошибка подключения Telegram: ${error.message || 'Неизвестная ошибка'}` });
      return null;
    }
  }, [api, setMessage]);

  const handleGenerateTelegramLinkCode = useCallback(async () => {
    const currentApi = api || useAuthStore.getState().api;
    if (!currentApi) {
      setMessage({ type: 'error', text: 'API не инициализирован' });
      return null;
    }

    try {
      const nextCsrfToken = await ensureCsrfToken(currentApi);
      const response = await currentApi.request('generate_telegram_link_code', { csrf_token: nextCsrfToken }, 'POST');
      const data = response?.data ?? response;
      const code = data?.code;
      const expiresAt = data?.expires_at;

      if (!code || !expiresAt) {
        throw new Error('Сервер не вернул код привязки');
      }

      setMessage({ type: 'success', text: 'Код привязки Telegram обновлён' });
      setTimeout(() => setMessage({ type: '', text: '' }), 3000);

      return { code, expiresAt };
    } catch (error) {
      console.error('Error generating Telegram link code:', error);
      setMessage({ type: 'error', text: `Ошибка генерации кода: ${error.message || 'Неизвестная ошибка'}` });
      return null;
    }
  }, [api, ensureCsrfToken, setMessage]);

  return {
    handleAddFingerprint,
    handleAvatarUpload,
    handleDisableLock,
    handleEnableLock,
    handleGenerateTelegramLinkCode,
    handlePinSetupSuccess,
    handleRemoveAvatar,
    handleStartTelegramLogin,
    handleUnlinkTelegram,
    runHuaweiSync,
    runStravaSync,
  };
}
