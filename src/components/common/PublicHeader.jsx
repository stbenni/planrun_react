/**
 * PublicHeader - Header для публичных страниц (профили пользователей)
 * С логотипом и кнопками «Вход» и «Регистрация»
 */

import React from 'react';
import { useNavigate } from 'react-router-dom';
import './PublicHeader.css';

const PublicHeader = ({ onLoginClick, onRegisterClick, registrationEnabled = true }) => {
  const navigate = useNavigate();

  const handleLogin = () => {
    if (onLoginClick) onLoginClick();
    else navigate('/login');
  };

  const handleRegister = () => {
    if (onRegisterClick) onRegisterClick();
    else navigate('/register');
  };

  return (
    <header className="public-header">
      <div className="public-header-container">
        {/* Логотип */}
        <div className="public-header-logo" onClick={() => navigate('/landing')}>
          <span className="logo-text"><span className="logo-plan">plan</span><span className="logo-run">RUN</span></span>
        </div>

        {/* Кнопки Вход и Регистрация */}
        <div className="public-header-auth">
          {registrationEnabled && (
            <button
              type="button"
              className="public-header-register-btn"
              onClick={handleRegister}
            >
              Регистрация
            </button>
          )}
          <button
            type="button"
            className="public-header-login-btn"
            onClick={handleLogin}
          >
            Вход
          </button>
        </div>
      </div>
    </header>
  );
};

export default PublicHeader;
