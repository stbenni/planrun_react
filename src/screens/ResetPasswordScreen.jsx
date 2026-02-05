/**
 * Экран смены пароля по токену
 */

import React, { useState, useEffect } from 'react';
import { useNavigate, useSearchParams, Link } from 'react-router-dom';
import ApiClient from '../api/ApiClient';
import './LoginScreen.css';

const ResetPasswordScreen = () => {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const token = searchParams.get('token') || '';

  const [password, setPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState(false);

  useEffect(() => {
    if (!token) {
      setError('Отсутствует ссылка для сброса. Запросите новую.');
    }
  }, [token]);

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!token) return;
    if (!password || password.length < 6) {
      setError('Пароль должен быть не менее 6 символов');
      return;
    }
    if (password !== confirmPassword) {
      setError('Пароли не совпадают');
      return;
    }
    setLoading(true);
    setError('');
    try {
      const api = new ApiClient();
      await api.confirmResetPassword(token, password);
      setSuccess(true);
      setTimeout(() => navigate('/landing', { state: { openLogin: true } }), 2000);
    } catch (err) {
      setError(err.message || 'Не удалось сменить пароль. Ссылка могла истечь.');
    } finally {
      setLoading(false);
    }
  };

  if (success) {
    return (
      <div className="login-container">
        <div className="login-content">
          <h1 className="login-title">PlanRun</h1>
          <p className="login-subtitle" style={{ color: 'var(--primary-600)' }}>
            Пароль успешно изменён. Перенаправление на страницу входа...
          </p>
          <Link to="/landing" state={{ openLogin: true }} className="login-button" style={{ display: 'inline-block', textAlign: 'center', textDecoration: 'none' }}>
            Войти
          </Link>
        </div>
      </div>
    );
  }

  return (
    <div className="login-container">
      <div className="login-content">
        <h1 className="login-title">PlanRun</h1>
        <p className="login-subtitle">Новый пароль</p>

        {!token ? (
          <div>
            <p className="login-error">Ссылка недействительна. Запросите новую.</p>
            <Link to="/forgot-password" className="login-button" style={{ display: 'inline-block', textAlign: 'center', textDecoration: 'none', marginTop: '16px' }}>
              Запросить сброс пароля
            </Link>
          </div>
        ) : (
          <form onSubmit={handleSubmit} className="login-form">
            <input
              type="password"
              className="login-input"
              placeholder="Новый пароль (минимум 6 символов)"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              minLength={6}
              disabled={loading}
            />
            <input
              type="password"
              className="login-input"
              placeholder="Повторите пароль"
              value={confirmPassword}
              onChange={(e) => setConfirmPassword(e.target.value)}
              minLength={6}
              disabled={loading}
            />
            {error && <div className="login-error">{error}</div>}
            <button type="submit" className="login-button" disabled={loading}>
              {loading ? 'Сохранение...' : 'Сменить пароль'}
            </button>
          </form>
        )}

        <div style={{ marginTop: '20px', textAlign: 'center' }}>
          <Link to="/landing" className="forgot-back-link" style={{ color: 'var(--primary-500)', fontSize: '14px' }}>
            ← Вернуться к входу
          </Link>
        </div>
      </div>
    </div>
  );
};

export default ResetPasswordScreen;
