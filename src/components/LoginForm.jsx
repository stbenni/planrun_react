/**
 * Переиспользуемая форма входа (страница или модалка)
 */

import React, { useState, useEffect } from 'react';
import useAuthStore from '../stores/useAuthStore';
import '../screens/LoginScreen.css';

const LoginForm = ({ onSuccess, onLogin }) => {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [biometricAvailable, setBiometricAvailable] = useState(false);
  const [biometricEnabled, setBiometricEnabled] = useState(false);
  const [biometricLoading, setBiometricLoading] = useState(false);

  const { login, biometricLogin, checkBiometricAvailability } = useAuthStore();

  useEffect(() => {
    checkBiometricAvailability().then((result) => {
      setBiometricAvailable(result?.available ?? false);
      setBiometricEnabled(result?.enabled ?? false);
    }).catch(() => {});
  }, [checkBiometricAvailability]);

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!username || !password) {
      setError('Введите логин и пароль');
      return;
    }
    setLoading(true);
    setError('');
    try {
      const useJwt = typeof window !== 'undefined' && window.Capacitor;
      const loginFn = onLogin || login;
      const result = await loginFn(username, password, useJwt);
      if (result?.success) {
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

  const handleBiometricLogin = async () => {
    if (!biometricAvailable || !biometricEnabled) {
      setError('Биометрическая аутентификация недоступна');
      return;
    }
    setBiometricLoading(true);
    setError('');
    try {
      const result = await biometricLogin();
      if (result?.success) {
        onSuccess?.();
      } else {
        setError(result?.error || 'Биометрическая аутентификация не прошла');
      }
    } catch (err) {
      setError(err.message || 'Ошибка биометрической аутентификации');
    } finally {
      setBiometricLoading(false);
    }
  };

  return (
    <div className="login-content login-content--inline">
      <h1 className="login-title">PlanRun</h1>
      <p className="login-subtitle">Вход в систему</p>

      <form onSubmit={handleSubmit} className="login-form">
        <input
          type="text"
          className="login-input"
          placeholder="Логин"
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
      </form>

      {biometricAvailable && biometricEnabled && (
        <div className="biometric-section">
          <div className="biometric-divider">
            <span>или</span>
          </div>
          <button
            type="button"
            className="biometric-button"
            onClick={handleBiometricLogin}
            disabled={biometricLoading || loading}
          >
            {biometricLoading ? (
              'Проверка...'
            ) : (
              <>
                <span className="biometric-icon">👆</span>
                <span>Войти через биометрию</span>
              </>
            )}
          </button>
        </div>
      )}
    </div>
  );
};

export default LoginForm;
