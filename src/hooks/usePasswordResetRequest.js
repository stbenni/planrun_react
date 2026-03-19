import { useCallback, useState } from 'react';
import getAuthClient from '../api/getAuthClient';
import { getAuthErrorMessage, getAuthRetryAfter } from '../utils/authError';
import { useRetryCooldown } from './useRetryCooldown';

function usePasswordResetRequest() {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [sent, setSent] = useState(false);
  const [sentToEmail, setSentToEmail] = useState('');
  const cooldown = useRetryCooldown();

  const resetState = useCallback(() => {
    setError('');
    setSent(false);
    setSentToEmail('');
  }, []);

  const requestReset = useCallback(async (identifier) => {
    const trimmed = String(identifier || '').trim();
    if (!trimmed) {
      setError('Введите email или логин');
      return { success: false, validationError: true };
    }
    if (cooldown.isCoolingDown) {
      return { success: false, cooldown: true };
    }

    setLoading(true);
    setError('');

    try {
      const api = getAuthClient();
      const result = await api.requestResetPassword(trimmed);
      if (result.sent) {
        setSentToEmail(result.email || trimmed);
        setSent(true);
        return { success: true, result };
      }

      const message = result.message || 'Не удалось отправить ссылку для сброса пароля.';
      setError(message);
      return { success: false, result };
    } catch (err) {
      setError(getAuthErrorMessage(err));
      const retryAfter = getAuthRetryAfter(err);
      if (retryAfter > 0) cooldown.startCooldown(retryAfter);
      return { success: false, error: err };
    } finally {
      setLoading(false);
    }
  }, [cooldown]);

  return {
    loading,
    error,
    setError,
    sent,
    sentToEmail,
    isCoolingDown: cooldown.isCoolingDown,
    secondsLeft: cooldown.secondsLeft,
    requestReset,
    resetState,
  };
}

export { usePasswordResetRequest };
