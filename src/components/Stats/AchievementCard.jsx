/**
 * Компонент карточки достижения
 */

import React from 'react';

const AchievementCard = ({ icon, Icon, title, description, achieved }) => {
  return (
    <div className={`achievement-card ${achieved ? 'achieved' : ''}`}>
      <div className="achievement-icon">{Icon ? <Icon size={32} aria-hidden /> : icon}</div>
      <div className="achievement-content">
        <div className="achievement-title">{title}</div>
        <div className="achievement-description">{description}</div>
      </div>
      {achieved && <div className="achievement-badge">✓</div>}
    </div>
  );
};

export default AchievementCard;
