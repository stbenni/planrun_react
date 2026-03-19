import { useCallback, useState } from 'react';
import { getAuthErrorMessage, getAuthRetryAfter } from '../utils/authError';
import { useRetryCooldown } from './useRetryCooldown';

function useVerificationCodeFlow({ onError } = {}) {
  const [verificationStep, setVerificationStep] = useState('form');
  const [verificationCode, setVerificationCode] = useState('');
  const [codeAttemptsLeft, setCodeAttemptsLeft] = useState(3);
  const cooldown = useRetryCooldown();

  const setFlowError = useCallback((message) => {
    onError?.(message);
  }, [onError]);

  const applyRetryAfter = useCallback((err) => {
    const retryAfter = getAuthRetryAfter(err);
    if (retryAfter > 0) cooldown.startCooldown(retryAfter);
  }, [cooldown]);

  const handleRequestError = useCallback((err, fallbackMessage = 'Не удалось отправить код') => {
    setFlowError(getAuthErrorMessage(err, fallbackMessage));
    applyRetryAfter(err);
  }, [applyRetryAfter, setFlowError]);

  const handleConfirmError = useCallback((err, fallbackMessage = 'Ошибка регистрации') => {
    setFlowError(getAuthErrorMessage(err, fallbackMessage));
    if (typeof err?.attempts_left === 'number') {
      setCodeAttemptsLeft(err.attempts_left);
    }
    applyRetryAfter(err);
  }, [applyRetryAfter, setFlowError]);

  const markCodeSent = useCallback(() => {
    setVerificationStep('code');
    setVerificationCode('');
    setCodeAttemptsLeft(3);
    setFlowError('');
  }, [setFlowError]);

  const resetFlow = useCallback(() => {
    setVerificationStep('form');
    setVerificationCode('');
    setCodeAttemptsLeft(3);
  }, []);

  return {
    verificationStep,
    setVerificationStep,
    verificationCode,
    setVerificationCode,
    codeAttemptsLeft,
    setCodeAttemptsLeft,
    isCoolingDown: cooldown.isCoolingDown,
    secondsLeft: cooldown.secondsLeft,
    startCooldown: cooldown.startCooldown,
    handleRequestError,
    handleConfirmError,
    markCodeSent,
    resetFlow,
  };
}

export { useVerificationCodeFlow };
