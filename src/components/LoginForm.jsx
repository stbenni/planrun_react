/**
 * Переиспользуемая форма входа (страница или модалка)
 * Включает встроенный сброс пароля по ссылке «Забыли пароль?»
 * На native: логин и пароль сохраняются для восстановления входа по PIN и биометрии.
 */

import React, { useState, useEffect } from 'react';
import useAuthStore from '../stores/useAuthStore';
import ApiClient from '../api/ApiClient';
import PinAuthService from '../services/PinAuthService';
import CredentialBackupService from '../services/CredentialBackupService';
import { isNativeCapacitor } from '../services/TokenStorageService';
import PinInput from './common/PinInput';
import '../screens/LoginScreen.css';

const LoginForm = ({ onSuccess, onLogin }) => {
  const [view, setView] = useState('login'); // 'login' | 'forgot'
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [email, setEmail] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [forgotSent, setForgotSent] = useState(false);
  const [forgotSentToEmail, setForgotSentToEmail] = useState('');
  const [showPinForRecovery, setShowPinForRecovery] = useState(false);
  const [pinForRecovery, setPinForRecovery] = useState('');
  const [canSaveRecovery, setCanSaveRecovery] = useState(false);

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
    setLoading(true);
    setError('');
    try {
      const useJwt = typeof window !== 'undefined' && window.Capacitor;
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
        setError(result?.error || 'Неверный логин или пароль');
      }
    } catch (err) {
      setError(err.message || 'Произошла ошибка при входе');
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
    const trimmed = email.trim();
    if (!trimmed) {
      setError('Введите email или логин');
      return;
    }
    setLoading(true);
    setError('');
    try {
      const api = new ApiClient();
      const result = await api.requestResetPassword(trimmed);
      if (result.sent) {
        setForgotSentToEmail(result.email || trimmed);
        setForgotSent(true);
      } else {
        setError(result.message || 'Не удалось отправить ссылку для сброса пароля.');
      }
    } catch (err) {
      setError(err.message || 'Произошла ошибка. Попробуйте позже.');
    } finally {
      setLoading(false);
    }
  };

  const goToForgot = () => {
    setView('forgot');
    setError('');
    setForgotSent(false);
    setEmail('');
  };

  const backToLogin = () => {
    setView('login');
    setError('');
    setForgotSent(false);
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
              disabled={loading}
            />
            {error && <div className="login-error">{error}</div>}
            <button type="submit" className="login-button" disabled={loading}>
              {loading ? 'Отправка...' : 'Отправить ссылку'}
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
        <button type="submit" className="login-button" disabled={loading}>
          {loading ? 'Вход...' : 'Войти'}
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
