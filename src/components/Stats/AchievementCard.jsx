/**
 * Компонент карточки достижения
 */

import React from 'react';

const AchievementCard = ({ icon, title, description, achieved }) => {
  return (
    <div className={`achievement-card ${achieved ? 'achieved' : ''}`}>
      <div className="achievement-icon">{icon}</div>
      <div className="achievement-content">
        <div className="achievement-title">{title}</div>
        <div className="achievement-description">{description}</div>
      </div>
      {achieved && <div className="achievement-badge">✓</div>}
    </div>
  );
};

export default AchievementCard;
