/**
 * Экран входа в систему (веб-версия)
 * PIN и отпечаток — на отдельном LockScreen после входа
 */

import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import useAuthStore from '../stores/useAuthStore';
import './LoginScreen.css';

const LoginScreen = ({ onLogin }) => {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const navigate = useNavigate();

  const { login } = useAuthStore();

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
      </div>
    </div>
  );
};

export default LoginScreen;
