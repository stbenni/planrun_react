/**
 * Экран входа в систему (веб-версия)
 * Поддерживает биометрическую аутентификацию для мобильных приложений
 */

import React, { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import useAuthStore from '../stores/useAuthStore';
import './LoginScreen.css';

const LoginScreen = ({ onLogin }) => {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [biometricAvailable, setBiometricAvailable] = useState(false);
  const [biometricEnabled, setBiometricEnabled] = useState(false);
  const [biometricLoading, setBiometricLoading] = useState(false);
  const navigate = useNavigate();
  
  const { login, biometricLogin, checkBiometricAvailability } = useAuthStore();

  useEffect(() => {
    checkBiometric();
  }, []);

  const checkBiometric = async () => {
    try {
      const result = await checkBiometricAvailability();
      setBiometricAvailable(result.available);
      setBiometricEnabled(result.enabled);
    } catch (error) {
      console.error('Failed to check biometric availability:', error);
    }
  };

  const handleLogin = async (e) => {
    e.preventDefault();
    
    if (!username || !password) {
      setError('Введите логин и пароль');
      return;
    }

    setLoading(true);
    setError('');
    
    try {
      // Используем JWT для мобильных приложений
      const useJwt = typeof window !== 'undefined' && window.Capacitor;
      
      // Используем onLogin из props, если передан, иначе из store
      const loginFn = onLogin || login;
      const result = await loginFn(username, password, useJwt);
      
      if (result.success) {
        // Если это мобильное приложение и биометрия доступна, токены уже сохранены в store
        if (useJwt && biometricAvailable && result.access_token && result.refresh_token) {
          setBiometricEnabled(true);
        }
        
        navigate('/');
      } else {
        setError(result.error || 'Неверный логин или пароль');
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

      if (result.success) {
        navigate('/');
      } else {
        setError(result.error || 'Биометрическая аутентификация не прошла');
      }
    } catch (err) {
      setError(err.message || 'Произошла ошибка при биометрической аутентификации');
    } finally {
      setBiometricLoading(false);
    }
  };

  return (
    <div className="login-container">
      <div className="login-content">
        <h1 className="login-title">PlanRun</h1>
        <p className="login-subtitle">Вход в систему</p>

        <form onSubmit={handleLogin} className="login-form">
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

          <button
            type="submit"
            className="login-button"
            disabled={loading}
          >
            {loading ? 'Вход...' : 'Войти'}
          </button>
        </form>

        {/* Биометрическая аутентификация */}
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
    </div>
  );
};

export default LoginScreen;
