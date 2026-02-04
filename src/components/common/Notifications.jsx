/**
 * Notifications - Компонент для уведомлений о предстоящих тренировках
 * В стиле OMY! Sports (уведомления о тренировках)
 */

import React, { useState, useEffect } from 'react';
import './Notifications.css';

const Notifications = ({ api, onWorkoutPress }) => {
  const [upcomingWorkouts, setUpcomingWorkouts] = useState([]);
  const [dismissed, setDismissed] = useState(new Set());

  useEffect(() => {
    if (!api) return;
    
    const loadUpcomingWorkouts = async () => {
      try {
        const plan = await api.getPlan();
        if (!plan || !plan.phases) return;

        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const tomorrow = new Date(today);
        tomorrow.setDate(tomorrow.getDate() + 1);
        const dayAfterTomorrow = new Date(today);
        dayAfterTomorrow.setDate(dayAfterTomorrow.getDate() + 2);

        const upcoming = [];

        for (const phase of plan.phases) {
          if (!phase.weeks_data) continue;
          
          for (const week of phase.weeks_data) {
            if (!week.start_date || !week.days) continue;
            
            const startDate = new Date(week.start_date);
            startDate.setHours(0, 0, 0, 0);
            
            const dayKeys = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
            
            for (let i = 0; i < 7; i++) {
              const workoutDate = new Date(startDate);
              workoutDate.setDate(startDate.getDate() + i);
              workoutDate.setHours(0, 0, 0, 0);
              
              // Проверяем только завтра и послезавтра
              if (workoutDate.getTime() === tomorrow.getTime() || 
                  workoutDate.getTime() === dayAfterTomorrow.getTime()) {
                
                const dayKey = dayKeys[i];
                const dayData = week.days[dayKey];
                
                if (dayData && dayData.type !== 'rest') {
                  upcoming.push({
                    date: workoutDate.toISOString().split('T')[0],
                    dateObj: workoutDate,
                    dayData,
                    weekNumber: week.number,
                    dayKey
                  });
                }
              }
            }
          }
        }

        setUpcomingWorkouts(upcoming.slice(0, 2)); // Максимум 2 уведомления
      } catch (error) {
        console.error('Error loading upcoming workouts:', error);
      }
    };
    
    loadUpcomingWorkouts();
    
    // Проверяем каждые 30 минут
    const interval = setInterval(loadUpcomingWorkouts, 30 * 60 * 1000);
    
    return () => clearInterval(interval);
  }, [api]);

  const handleDismiss = (date) => {
    setDismissed(prev => new Set([...prev, date]));
  };

  const filteredWorkouts = upcomingWorkouts.filter(w => !dismissed.has(w.date));

  if (filteredWorkouts.length === 0) {
    return null;
  }

  return (
    <div className="notifications-container">
      {filteredWorkouts.map((workout, index) => {
        const isTomorrow = workout.dateObj.getTime() === new Date().setDate(new Date().getDate() + 1);
        const dayLabel = isTomorrow ? 'Завтра' : workout.dateObj.toLocaleDateString('ru-RU', { 
          weekday: 'long', 
          day: 'numeric', 
          month: 'long' 
        });

        return (
          <div key={workout.date} className="notification-card" style={{ animationDelay: `${index * 100}ms` }}>
            <div className="notification-icon">🔔</div>
            <div className="notification-content">
              <div className="notification-title">Предстоящая тренировка</div>
              <div className="notification-date">{dayLabel}</div>
              <div className="notification-workout">
                {workout.dayData.type === 'long-run' ? 'Длительный бег' :
                 workout.dayData.type === 'interval' ? 'Интервалы' :
                 workout.dayData.type === 'tempo' ? 'Темп' :
                 workout.dayData.type === 'easy' ? 'Легкий бег' : 'Тренировка'}
              </div>
            </div>
            <div className="notification-actions">
              <button 
                className="notification-btn"
                onClick={() => {
                  if (onWorkoutPress) {
                    onWorkoutPress({
                      date: workout.date,
                      weekNumber: workout.weekNumber,
                      dayKey: workout.dayKey
                    });
                  }
                }}
              >
                Открыть
              </button>
              <button 
                className="notification-dismiss"
                onClick={() => handleDismiss(workout.date)}
                aria-label="Закрыть"
              >
                ×
              </button>
            </div>
          </div>
        );
      })}
    </div>
  );
};

export default Notifications;
