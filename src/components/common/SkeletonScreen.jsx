/**
 * Компонент для отображения skeleton screens во время загрузки
 * Создает ощущение что приложение уже загружено, просто подгружаются данные
 */

import React from 'react';
import './SkeletonScreen.css';

const SkeletonScreen = ({ type = 'default' }) => {
  if (type === 'dashboard') {
    return (
      <div className="skeleton-container skeleton-dashboard">
        <div className="skeleton-header">
          <div className="skeleton-line skeleton-title"></div>
          <div className="skeleton-line skeleton-subtitle"></div>
        </div>
        <div className="skeleton-grid">
          {[1, 2, 3].map(i => (
            <div key={i} className="skeleton-card">
              <div className="skeleton-line"></div>
              <div className="skeleton-line skeleton-short"></div>
            </div>
          ))}
        </div>
      </div>
    );
  }

  if (type === 'calendar') {
    return (
      <div className="skeleton-container skeleton-calendar">
        <div className="skeleton-header">
          <div className="skeleton-line skeleton-title"></div>
        </div>
        <div className="skeleton-calendar-grid">
          {[1, 2, 3, 4, 5, 6, 7].map(i => (
            <div key={i} className="skeleton-day"></div>
          ))}
        </div>
      </div>
    );
  }

  return (
    <div className="skeleton-container">
      <div className="skeleton-line"></div>
      <div className="skeleton-line skeleton-short"></div>
      <div className="skeleton-line"></div>
    </div>
  );
};

export default SkeletonScreen;
