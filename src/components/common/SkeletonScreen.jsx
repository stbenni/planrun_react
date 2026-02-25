import React from 'react';
import './SkeletonScreen.css';

const SkeletonScreen = ({ type = 'default' }) => {
  if (type === 'dashboard') {
    return (
      <div className="skeleton-container skeleton-dashboard">
        {/* Greeting header */}
        <div className="skeleton-dash-header">
          <div>
            <div className="skeleton-line" style={{ width: '55%', height: 28, marginBottom: 8 }}></div>
            <div className="skeleton-line" style={{ width: '35%', height: 16 }}></div>
          </div>
          <div className="skeleton-line" style={{ width: 80, height: 36, borderRadius: 10 }}></div>
        </div>
        {/* Today workout card */}
        <div className="skeleton-section">
          <div className="skeleton-line" style={{ width: '50%', height: 18, marginBottom: 12 }}></div>
          <div className="skeleton-card skeleton-workout-card">
            <div className="skeleton-line" style={{ width: '40%', height: 14 }}></div>
            <div className="skeleton-line" style={{ width: '70%', height: 16, marginTop: 8 }}></div>
            <div className="skeleton-line" style={{ width: '55%', height: 14, marginTop: 6 }}></div>
            <div className="skeleton-line" style={{ width: '30%', height: 36, borderRadius: 10, marginTop: 12 }}></div>
          </div>
        </div>
        {/* Metrics grid */}
        <div className="skeleton-section">
          <div className="skeleton-line" style={{ width: '45%', height: 18, marginBottom: 12 }}></div>
          <div className="skeleton-card" style={{ padding: 16 }}>
            <div className="skeleton-metrics-row">
              {[1, 2, 3].map(i => (
                <div key={i} className="skeleton-metric-item">
                  <div className="skeleton-line" style={{ width: '60%', height: 12, marginBottom: 6 }}></div>
                  <div className="skeleton-line" style={{ width: '45%', height: 24 }}></div>
                </div>
              ))}
            </div>
          </div>
        </div>
        {/* Next workout */}
        <div className="skeleton-section">
          <div className="skeleton-line" style={{ width: '55%', height: 18, marginBottom: 12 }}></div>
          <div className="skeleton-card skeleton-workout-card">
            <div className="skeleton-line" style={{ width: '35%', height: 14 }}></div>
            <div className="skeleton-line" style={{ width: '65%', height: 16, marginTop: 8 }}></div>
            <div className="skeleton-line" style={{ width: '50%', height: 14, marginTop: 6 }}></div>
          </div>
        </div>
      </div>
    );
  }

  if (type === 'calendar') {
    return (
      <div className="skeleton-container skeleton-calendar">
        {/* View toggle */}
        <div className="skeleton-tabs-row" style={{ marginBottom: 16 }}>
          <div className="skeleton-tab" style={{ maxWidth: 100 }}></div>
          <div className="skeleton-tab" style={{ maxWidth: 120 }}></div>
        </div>
        {/* Week strip */}
        <div className="skeleton-week-strip">
          {[1, 2, 3, 4, 5, 6, 7].map(i => (
            <div key={i} className="skeleton-week-day">
              <div className="skeleton-line" style={{ width: '100%', height: 12, marginBottom: 6 }}></div>
              <div className="skeleton-day-circle"></div>
            </div>
          ))}
        </div>
        {/* Day content cards */}
        {[1, 2, 3].map(i => (
          <div key={i} className="skeleton-card" style={{ marginTop: 12, padding: 16 }}>
            <div className="skeleton-line" style={{ width: '30%', height: 12, marginBottom: 8 }}></div>
            <div className="skeleton-line" style={{ width: '70%', height: 16, marginBottom: 6 }}></div>
            <div className="skeleton-line" style={{ width: '50%', height: 14 }}></div>
          </div>
        ))}
      </div>
    );
  }

  if (type === 'stats') {
    return (
      <div className="skeleton-container skeleton-stats">
        {/* Tabs: Обзор / Прогресс / Достижения */}
        <div className="skeleton-tabs-row">
          {[1, 2, 3].map(i => (
            <div key={i} className="skeleton-tab"></div>
          ))}
        </div>
        {/* Time range buttons */}
        <div className="skeleton-tabs-row skeleton-time-range">
          {[1, 2, 3, 4].map(i => (
            <div key={i} className="skeleton-tab skeleton-tab--sm"></div>
          ))}
        </div>
        {/* 4 metric cards */}
        <div className="skeleton-stats-metrics">
          {[1, 2, 3, 4].map(i => (
            <div key={i} className="skeleton-card skeleton-stat-metric">
              <div className="skeleton-line" style={{ width: '60%', height: 12, marginBottom: 8 }}></div>
              <div className="skeleton-line" style={{ width: '50%', height: 26 }}></div>
            </div>
          ))}
        </div>
        {/* Chart */}
        <div className="skeleton-section">
          <div className="skeleton-line" style={{ width: '40%', height: 18, marginBottom: 12 }}></div>
          <div className="skeleton-card skeleton-chart-card">
            <div className="skeleton-chart-bars">
              {[40, 65, 30, 80, 55, 45, 70].map((h, i) => (
                <div key={i} className="skeleton-chart-bar" style={{ height: `${h}%` }}></div>
              ))}
            </div>
          </div>
        </div>
        {/* Recent workouts */}
        <div className="skeleton-section">
          <div className="skeleton-line" style={{ width: '50%', height: 18, marginBottom: 12 }}></div>
          {[1, 2, 3].map(i => (
            <div key={i} className="skeleton-card skeleton-workout-item">
              <div className="skeleton-line" style={{ width: '25%', height: 12 }}></div>
              <div className="skeleton-line" style={{ width: '60%', height: 16, marginTop: 6 }}></div>
              <div className="skeleton-line" style={{ width: '40%', height: 12, marginTop: 4 }}></div>
            </div>
          ))}
        </div>
      </div>
    );
  }

  if (type === 'chat') {
    return (
      <div className="skeleton-container skeleton-chat">
        {/* Chat list (contacts/conversations) */}
        <div className="skeleton-chat-list">
          {[1, 2, 3, 4].map(i => (
            <div key={i} className="skeleton-chat-item">
              <div className="skeleton-avatar"></div>
              <div className="skeleton-chat-item-text">
                <div className="skeleton-line" style={{ width: '50%', height: 14, marginBottom: 6 }}></div>
                <div className="skeleton-line" style={{ width: '80%', height: 12 }}></div>
              </div>
              <div className="skeleton-line" style={{ width: 40, height: 10, marginLeft: 'auto', flexShrink: 0 }}></div>
            </div>
          ))}
        </div>
        {/* Messages area (desktop) */}
        <div className="skeleton-chat-main">
          <div className="skeleton-chat-messages-area">
            {[1, 2, 3, 4].map(i => (
              <div key={i} className={`skeleton-message ${i % 2 === 0 ? 'skeleton-message--right' : ''}`}>
                {i % 2 !== 0 && <div className="skeleton-avatar-sm"></div>}
                <div className="skeleton-message-bubble">
                  <div className="skeleton-line" style={{ width: i % 2 === 0 ? '80%' : '100%', height: 14 }}></div>
                  <div className="skeleton-line" style={{ width: '60%', height: 12, marginTop: 4 }}></div>
                </div>
                {i % 2 === 0 && <div className="skeleton-avatar-sm"></div>}
              </div>
            ))}
          </div>
          <div className="skeleton-chat-input-bar">
            <div className="skeleton-line" style={{ flex: 1, height: 44, borderRadius: 22 }}></div>
            <div className="skeleton-line" style={{ width: 44, height: 44, borderRadius: '50%', flexShrink: 0 }}></div>
          </div>
        </div>
      </div>
    );
  }

  if (type === 'settings') {
    return (
      <div className="skeleton-container skeleton-settings">
        {/* Tabs */}
        <div className="skeleton-tabs-row">
          <div className="skeleton-tab" style={{ maxWidth: 100 }}></div>
          <div className="skeleton-tab" style={{ maxWidth: 110 }}></div>
          <div className="skeleton-tab" style={{ maxWidth: 140 }}></div>
          <div className="skeleton-tab" style={{ maxWidth: 120 }}></div>
        </div>
        {/* Section title */}
        <div className="skeleton-section">
          <div className="skeleton-line" style={{ width: '40%', height: 20, marginBottom: 4 }}></div>
          <div className="skeleton-line" style={{ width: '55%', height: 14, marginBottom: 20 }}></div>
        </div>
        {/* Avatar placeholder */}
        <div className="skeleton-avatar-upload">
          <div className="skeleton-avatar-lg"></div>
        </div>
        {/* Form fields */}
        <div className="skeleton-card" style={{ padding: 20 }}>
          {[1, 2].map(i => (
            <div key={i} className="skeleton-field">
              <div className="skeleton-line" style={{ width: '25%', height: 13, marginBottom: 6 }}></div>
              <div className="skeleton-line skeleton-input"></div>
            </div>
          ))}
          {/* Two-column row */}
          <div className="skeleton-form-row">
            <div className="skeleton-field" style={{ flex: 1 }}>
              <div className="skeleton-line" style={{ width: '40%', height: 13, marginBottom: 6 }}></div>
              <div className="skeleton-line skeleton-input"></div>
            </div>
            <div className="skeleton-field" style={{ flex: 1 }}>
              <div className="skeleton-line" style={{ width: '45%', height: 13, marginBottom: 6 }}></div>
              <div className="skeleton-line skeleton-input"></div>
            </div>
          </div>
          <div className="skeleton-form-row">
            <div className="skeleton-field" style={{ flex: 1 }}>
              <div className="skeleton-line" style={{ width: '35%', height: 13, marginBottom: 6 }}></div>
              <div className="skeleton-line skeleton-input"></div>
            </div>
            <div className="skeleton-field" style={{ flex: 1 }}>
              <div className="skeleton-line" style={{ width: '30%', height: 13, marginBottom: 6 }}></div>
              <div className="skeleton-line skeleton-input"></div>
            </div>
          </div>
          <div className="skeleton-field">
            <div className="skeleton-line" style={{ width: '30%', height: 13, marginBottom: 6 }}></div>
            <div className="skeleton-line skeleton-input"></div>
          </div>
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
