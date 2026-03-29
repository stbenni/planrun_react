/**
 * Переиспользуемая форма входа (страница или модалка)
 * Включает встроенный сброс пароля по ссылке «Забыли пароль?»
 * На native: recovery-данные обновляются только для явно включённых способов входа.
 */

import React, { useState, useEffect } from 'react';
import useAuthStore from '../stores/useAuthStore';
import PinAuthService from '../services/PinAuthService';
import CredentialBackupService from '../services/CredentialBackupService';
import { isNativeCapacitor } from '../services/TokenStorageService';
import { getAuthErrorMessage, getAuthRetryAfter } from '../utils/authError';
import { useRetryCooldown } from '../hooks/useRetryCooldown';
import { usePasswordResetRequest } from '../hooks/usePasswordResetRequest';
import PinInput from './common/PinInput';
import '../screens/LoginScreen.css';

const LoginForm = ({ onSuccess, onLogin }) => {
  const [view, setView] = useState('login'); // 'login' | 'forgot'
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [email, setEmail] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [showPinForRecovery, setShowPinForRecovery] = useState(false);
  const [pinForRecovery, setPinForRecovery] = useState('');
  const [canSaveRecovery, setCanSaveRecovery] = useState(false);
  const loginCooldown = useRetryCooldown();
  const {
    loading: forgotLoading,
    error: forgotError,
    sent: forgotSent,
    sentToEmail: forgotSentToEmail,
    isCoolingDown: forgotCoolingDown,
    secondsLeft: forgotSecondsLeft,
    requestReset,
    resetState: resetForgotState,
  } = usePasswordResetRequest();

  const { login } = useAuthStore();

  useEffect(() => {
    if (!isNativeCapacitor()) return;
    PinAuthService.isPinEnabled().then(setCanSaveRecovery).catch(() => {});
  }, []);

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!username || !password) {
      setError('Введите логин или email и пароль');
      return;
    }
    if (loginCooldown.isCoolingDown) return;
    setLoading(true);
    setError('');
    try {
      const useJwt = isNativeCapacitor();
      const loginFn = onLogin || login;
      const result = await loginFn(username, password, useJwt);
      if (result?.success) {
        if (isNativeCapacitor()) {
          CredentialBackupService.saveCredentialsSecure(username, password).catch(() => {});
          if (canSaveRecovery) {
            setLoading(false);
            setShowPinForRecovery(true);
            return;
          }
        }
        onSuccess?.();
      } else {
        setError(getAuthErrorMessage(result, result?.error || 'Неверный логин или пароль'));
        const retryAfter = getAuthRetryAfter(result);
        if (retryAfter > 0) loginCooldown.startCooldown(retryAfter);
      }
    } catch (err) {
      setError(getAuthErrorMessage(err, 'Произошла ошибка при входе'));
      const retryAfter = getAuthRetryAfter(err);
      if (retryAfter > 0) loginCooldown.startCooldown(retryAfter);
    } finally {
      setLoading(false);
    }
  };

  const handlePinForRecoveryComplete = async (pin) => {
    if (!pin || pin.length !== 4) return;
    setError('');
    try {
      await CredentialBackupService.saveCredentials(pin, username, password);
      setPassword('');
      setPinForRecovery('');
      setShowPinForRecovery(false);
      onSuccess?.();
    } catch {
      setShowPinForRecovery(false);
      onSuccess?.();
    }
  };

  const handleForgotSubmit = async (e) => {
    e.preventDefault();
    await requestReset(email);
  };

  const goToForgot = () => {
    setView('forgot');
    resetForgotState();
    setEmail('');
  };

  const backToLogin = () => {
    setView('login');
    resetForgotState();
  };

  // Встроенный сброс пароля — тот же контейнер, только содержимое формы другое
  if (view === 'forgot') {
    return (
      <div className="login-content login-content--inline login-content--forgot">
        <h1 className="login-title">PlanRun</h1>
        <p className="login-subtitle">Сброс пароля</p>
        {!forgotSent ? (
          <form onSubmit={handleForgotSubmit} className="login-form">
            <p className="login-forgot-hint">Введите email или логин, указанные при регистрации. Отправим ссылку на email.</p>
            <input
              type="text"
              className="login-input"
              placeholder="Email или логин"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              autoComplete="email"
              disabled={forgotLoading}
            />
            {forgotError && <div className="login-error">{forgotError}</div>}
            <button type="submit" className="login-button" disabled={forgotLoading || forgotCoolingDown}>
              {forgotLoading ? 'Отправка...' : forgotCoolingDown ? `Подождите ${forgotSecondsLeft} сек` : 'Отправить ссылку'}
            </button>
          </form>
        ) : (
          <div className="login-form">
            <p className="login-forgot-success">
              Письмо отправлено на <strong>{forgotSentToEmail}</strong>. Проверьте почту и перейдите по ссылке.
            </p>
            <p className="login-forgot-note">Ссылка действительна 1 час. Проверьте папку «Спам».</p>
          </div>
        )}
        <button
          type="button"
          className="login-back-link"
          onClick={backToLogin}
        >
          ← Вернуться к входу
        </button>
      </div>
    );
  }

  if (showPinForRecovery) {
    return (
      <div className="login-content login-content--inline login-content--login">
        <h1 className="login-title">PlanRun</h1>
        <p className="login-subtitle">Введите PIN для сохранения восстановления входа</p>
        <div className="login-form">
          <PinInput
            length={4}
            value={pinForRecovery}
            onChange={setPinForRecovery}
            onComplete={handlePinForRecoveryComplete}
            showKeypad
          />
        </div>
      </div>
    );
  }

  return (
    <div className="login-content login-content--inline login-content--login">
      <h1 className="login-title">PlanRun</h1>
      <p className="login-subtitle">Вход в систему</p>

      <form onSubmit={handleSubmit} className="login-form">
        <input
          type="text"
          className="login-input"
          placeholder="Логин или email"
          value={username}
          onChange={(e) => setUsername(e.target.value)}
          autoCapitalize="none"
          autoCorrect="off"
          disabled={loading}
        />
        <input
          type="password"
          className="login-input"
          placeholder="Пароль"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          autoCapitalize="none"
          autoCorrect="off"
          disabled={loading}
        />
        {error && <div className="login-error">{error}</div>}
        <button type="submit" className="login-button" disabled={loading || loginCooldown.isCoolingDown}>
          {loading ? 'Вход...' : loginCooldown.isCoolingDown ? `Подождите ${loginCooldown.secondsLeft} сек` : 'Войти'}
        </button>
        <button
          type="button"
          className="login-forgot-link"
          onClick={goToForgot}
        >
          Забыли пароль?
        </button>
      </form>
    </div>
  );
};

export default LoginForm;
