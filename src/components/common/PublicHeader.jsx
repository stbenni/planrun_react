/**
 * PublicHeader - Header для публичных страниц (профили пользователей)
 * С логотипом и кнопкой регистрации
 */

import React from 'react';
import { useNavigate } from 'react-router-dom';
import './PublicHeader.css';

const PublicHeader = () => {
  const navigate = useNavigate();

  return (
    <header className="public-header">
      <div className="public-header-container">
        {/* Логотип */}
        <div className="public-header-logo" onClick={() => navigate('/landing')}>
          <span className="logo-icon">🏃</span>
          <span className="logo-text">PlanRun</span>
        </div>

        {/* Кнопка регистрации */}
        <button 
          className="public-header-register-btn"
          onClick={() => navigate('/register')}
        >
          Зарегистрироваться
        </button>
      </div>
    </header>
  );
};

export default PublicHeader;
