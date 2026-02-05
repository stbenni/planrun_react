/**
 * Экран "Забыли пароль?" — отправка ссылки на email
 */

import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import ApiClient from '../api/ApiClient';
import './LoginScreen.css';

const ForgotPasswordScreen = () => {
  const [email, setEmail] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [sent, setSent] = useState(false);
  const [sentToEmail, setSentToEmail] = useState('');

  const handleSubmit = async (e) => {
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
        setSentToEmail(result.email || trimmed);
        setSent(true);
      } else {
        setError(result.message || 'Не удалось отправить ссылку для сброса пароля.');
      }
    } catch (err) {
      setError(err.message || 'Произошла ошибка. Попробуйте позже.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="login-container">
      <div className="login-content">
        <h1 className="login-title">PlanRun</h1>
        <p className="login-subtitle">Сброс пароля</p>

        {!sent ? (
          <form onSubmit={handleSubmit} className="login-form">
            <p className="forgot-hint" style={{ marginBottom: '16px', color: 'var(--text-secondary)', fontSize: '14px' }}>
              Введите email или логин, указанные при регистрации. Мы отправим ссылку для сброса пароля на email.
            </p>
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
            <p className="forgot-success" style={{ marginBottom: '16px', color: 'var(--primary-600)' }}>
              Письмо отправлено на <strong>{sentToEmail}</strong>. Проверьте почту и перейдите по ссылке для сброса пароля.
            </p>
            <p style={{ fontSize: '14px', color: 'var(--text-secondary)', marginBottom: '16px' }}>
              Ссылка действительна 1 час. Не забудьте проверить папку «Спам».
            </p>
          </div>
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

export default ForgotPasswordScreen;
