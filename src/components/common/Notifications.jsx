/**
 * Notifications - Уведомления о предстоящих тренировках и новых сообщениях от администрации
 * В стиле OMY! Sports. Закрытые уведомления сохраняются на сервере — синхронизация между устройствами.
 * Real-time: подписка на ChatSSE — новые сообщения появляются сразу.
 */

import React, { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { ChatSSE } from '../../services/ChatSSE';
import { BotIcon, MessageCircleIcon, BellIcon } from './Icons';
import './Notifications.css';

const userTimezone = (user) => user?.timezone || (typeof Intl !== 'undefined' && Intl.DateTimeFormat?.().resolvedOptions?.().timeZone) || 'Europe/Moscow';

const Notifications = ({ api, isAdmin, onWorkoutPress, user }) => {
  const tz = userTimezone(user);
  const navigate = useNavigate();
  const [upcomingWorkouts, setUpcomingWorkouts] = useState([]);
  const [adminMessages, setAdminMessages] = useState([]);
  const [aiMessages, setAiMessages] = useState([]);
  const [dismissed, setDismissed] = useState(() => new Set());
  const [dismissedLoaded, setDismissedLoaded] = useState(false);

  const loadDismissed = useCallback(async () => {
    if (!api) return;
    try {
      const ids = await api.getNotificationsDismissed();
      setDismissed(new Set(Array.isArray(ids) ? ids : []));
    } catch {
      setDismissed(new Set());
    } finally {
      setDismissedLoaded(true);
    }
  }, [api]);

  useEffect(() => {
    loadDismissed();
  }, [loadDismissed]);

  const handleDismiss = useCallback((id) => {
    setDismissed((prev) => new Set([...prev, id]));
    api?.dismissNotification(id).catch(() => {});
  }, [api]);

  const refresh = useCallback(async () => {
    if (!api) return;

    const loadUpcomingWorkouts = async () => {
      try {
        const plan = await api.getPlan();
        const weeksData = plan?.weeks_data;
        if (!plan || !Array.isArray(weeksData)) return;
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const tomorrow = new Date(today);
        tomorrow.setDate(tomorrow.getDate() + 1);
        const dayAfterTomorrow = new Date(today);
        dayAfterTomorrow.setDate(dayAfterTomorrow.getDate() + 2);
        const upcoming = [];
        for (const week of weeksData) {
            if (!week.start_date || !week.days) continue;
            const startDate = new Date(week.start_date);
            startDate.setHours(0, 0, 0, 0);
            const dayKeys = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
            for (let i = 0; i < 7; i++) {
              const workoutDate = new Date(startDate);
              workoutDate.setDate(startDate.getDate() + i);
              workoutDate.setHours(0, 0, 0, 0);
              if (workoutDate.getTime() === tomorrow.getTime() ||
                  workoutDate.getTime() === dayAfterTomorrow.getTime()) {
                const dayKey = dayKeys[i];
                const dayData = week.days[dayKey];
                if (dayData && dayData.type !== 'rest' && dayData.type !== 'free') {
                  upcoming.push({
                    type: 'workout',
                    id: `workout_${workoutDate.toISOString().split('T')[0]}`,
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
        setUpcomingWorkouts(upcoming.slice(0, 2));
      } catch (error) {
        console.error('Error loading upcoming workouts:', error);
      }
    };

    const loadAdminMessages = async () => {
      try {
        if (isAdmin) {
          const list = await api.chatAdminGetUnreadNotifications(10);
          setAdminMessages(list.map((m) => ({
            ...m,
            type: 'chat',
            id: `admin_chat_${m.user_id}_${m.id}`,
            fromUser: true
          })));
        } else {
          const data = await api.chatGetMessages('admin', 5, 0);
          const list = Array.isArray(data?.messages) ? data.messages : [];
          const unread = list.filter((m) => !m.read_at && (
            m.sender_type === 'admin' || (m.sender_type === 'user' && m.sender_id !== user?.id)
          ));
          setAdminMessages(unread.map((m) => ({
            ...m,
            type: 'chat',
            id: `chat_${m.id}`,
            fromUser: m.sender_type === 'user',
            username: m.sender_username,
            user_id: m.sender_id,
          })));
        }
      } catch {
        setAdminMessages([]);
      }
    };

    const loadAiMessages = async () => {
      if (isAdmin) {
        setAiMessages([]);
        return;
      }
      try {
        const data = await api.chatGetMessages('ai', 5, 0);
        const list = Array.isArray(data?.messages) ? data.messages : [];
        const unread = list.filter((m) => m.sender_type === 'ai' && !m.read_at);
        setAiMessages(unread.map((m) => ({ ...m, type: 'chat_ai', id: `ai_chat_${m.id}` })));
      } catch {
        setAiMessages([]);
      }
    };

    await Promise.all([loadUpcomingWorkouts(), loadAdminMessages(), loadAiMessages()]);
  }, [api, isAdmin, user?.id]);

  useEffect(() => {
    if (!api) return;
    refresh();

    const interval = setInterval(refresh, 60 * 1000);
    return () => clearInterval(interval);
  }, [api, refresh]);

  // Real-time: при новом сообщении SSE сразу обновляем список
  useEffect(() => {
    if (!api) return;
    ChatSSE.connect();
    const onUnread = () => refresh();
    ChatSSE.subscribe(onUnread);
    return () => ChatSSE.unsubscribe(onUnread);
  }, [api, refresh]);

  const workoutItems = upcomingWorkouts.filter((w) => !dismissed.has(w.id));
  const chatItems = adminMessages
    .filter((m) => !dismissed.has(m.id))
    .sort((a, b) => new Date(b.created_at || 0) - new Date(a.created_at || 0));
  const aiChatItems = aiMessages
    .filter((m) => !dismissed.has(m.id))
    .sort((a, b) => new Date(b.created_at || 0) - new Date(a.created_at || 0));
  const allItems = [...chatItems, ...aiChatItems, ...workoutItems];

  if (!dismissedLoaded || allItems.length === 0) {
    return null;
  }

  return (
    <div className="notifications-container">
      {allItems.map((item, index) => {
        if (item.type === 'chat_ai') {
          const timeStr = item.created_at
            ? new Date(item.created_at).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', second: '2-digit', timeZone: tz })
            : '';
          const content = (item.content || '').slice(0, 60);
          const truncated = content.length >= 60 ? content + '…' : content;
          return (
            <div key={item.id} className="notification-card notification-card--chat notification-card--ai" style={{ animationDelay: `${index * 100}ms` }}>
              <div className="notification-icon" aria-hidden><BotIcon size={24} /></div>
              <div className="notification-content">
                <div className="notification-title">Новое сообщение от AI-тренера</div>
                <div className="notification-date">{timeStr}</div>
                <div className="notification-workout">{truncated || 'Сообщение'}</div>
              </div>
              <div className="notification-actions">
                <button
                  className="notification-btn"
                  onClick={() => navigate('/chat', { state: { openAITab: true } })}
                >
                  Открыть
                </button>
                <button
                  className="notification-dismiss"
                  onClick={() => handleDismiss(item.id)}
                  aria-label="Закрыть"
                >
                  ×
                </button>
              </div>
            </div>
          );
        }

        if (item.type === 'chat') {
          const timeStr = item.created_at
            ? new Date(item.created_at).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', second: '2-digit', timeZone: tz })
            : '';
          const content = (item.content || '').slice(0, 60);
          const truncated = content.length >= 60 ? content + '…' : content;
          const title = item.fromUser
            ? `Написал(а) ${item.username || 'Пользователь'}`
            : 'Новое сообщение от администрации';
          return (
            <div key={item.id} className="notification-card notification-card--chat" style={{ animationDelay: `${index * 100}ms` }}>
              <div className="notification-icon" aria-hidden><MessageCircleIcon size={24} /></div>
              <div className="notification-content">
                <div className="notification-title">{title}</div>
                <div className="notification-date">{timeStr}</div>
                <div className="notification-workout">{truncated || 'Сообщение'}</div>
              </div>
              <div className="notification-actions">
                <button
                  className="notification-btn"
                  onClick={() => {
                    if (isAdmin && item.fromUser && item.user_id) {
                      navigate('/chat', { state: { openAdminMode: true, selectedUserId: item.user_id } });
                    } else {
                      const msgId = typeof item.id === 'number' ? item.id : parseInt(String(item.id).replace(/^chat_/, ''), 10);
                      navigate('/chat', { state: { openAdminTab: true, messageId: Number.isFinite(msgId) ? msgId : undefined } });
                    }
                  }}
                >
                  Открыть
                </button>
                <button
                  className="notification-dismiss"
                  onClick={() => handleDismiss(item.id)}
                  aria-label="Закрыть"
                >
                  ×
                </button>
              </div>
            </div>
          );
        }

        const workout = item;
        const isTomorrow = workout.dateObj.getTime() === new Date().setDate(new Date().getDate() + 1);
        const dayLabel = isTomorrow ? 'Завтра' : workout.dateObj.toLocaleDateString('ru-RU', {
          weekday: 'long',
          day: 'numeric',
          month: 'long'
        });

        return (
          <div key={workout.id} className="notification-card" style={{ animationDelay: `${index * 100}ms` }}>
            <div className="notification-icon" aria-hidden><BellIcon size={24} /></div>
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
                  const payload = { date: workout.date, weekNumber: workout.weekNumber, dayKey: workout.dayKey };
                  if (onWorkoutPress) {
                    onWorkoutPress(payload);
                  } else {
                    navigate('/calendar', { state: payload });
                  }
                }}
              >
                Открыть
              </button>
              <button
                className="notification-dismiss"
                onClick={() => handleDismiss(workout.id)}
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
