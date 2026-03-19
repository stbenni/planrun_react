/**
 * Notifications - Уведомления о предстоящих тренировках и новых сообщениях от администрации
 * В стиле OMY! Sports. Закрытые уведомления сохраняются на сервере — синхронизация между устройствами.
 * Real-time: подписка на ChatSSE — новые сообщения появляются сразу.
 */

import { useState, useEffect, useCallback, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import { ChatSSE } from '../../services/ChatSSE';
import { BotIcon, MessageCircleIcon, BellIcon, CloseIcon } from './Icons';
import './Notifications.css';

const userTimezone = (user) => user?.timezone || (typeof Intl !== 'undefined' && Intl.DateTimeFormat?.().resolvedOptions?.().timeZone) || 'Europe/Moscow';

const Notifications = ({ api, isAdmin, onWorkoutPress, user }) => {
  const tz = userTimezone(user);
  const navigate = useNavigate();
  const [upcomingWorkouts, setUpcomingWorkouts] = useState([]);
  const [adminMessages, setAdminMessages] = useState([]);
  const [aiMessages, setAiMessages] = useState([]);
  const [planNotifications, setPlanNotifications] = useState([]);
  const [dismissed, setDismissed] = useState(() => new Set());
  const [dismissedLoaded, setDismissedLoaded] = useState(false);
  const planNotificationsCooldownUntilRef = useRef(0);

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

  const loadUpcomingWorkouts = useCallback(async () => {
    if (!api) return;
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
  }, [api]);

  const loadAdminMessages = useCallback(async () => {
    if (!api) return;
    try {
      if (isAdmin) {
        const list = await api.chatAdminGetUnreadNotifications(10);
        setAdminMessages(list.map((m) => ({
          ...m,
          type: 'chat',
          id: `admin_chat_${m.user_id}_${m.id}`,
          fromUser: true
        })));
        return;
      }

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
    } catch {
      setAdminMessages([]);
    }
  }, [api, isAdmin, user?.id]);

  const loadAiMessages = useCallback(async () => {
    if (!api) return;
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
  }, [api, isAdmin]);

  const loadPlanNotifications = useCallback(async () => {
    if (!api) return;
    if (Date.now() < planNotificationsCooldownUntilRef.current) return;
    try {
      const res = await api.getPlanNotifications();
      planNotificationsCooldownUntilRef.current = 0;
      const list = res?.data?.notifications ?? res?.notifications ?? [];
      setPlanNotifications(list.map((n) => ({
        ...n,
        type: 'plan_notif',
        id: `plan_notif_${n.id}`,
        _id: n.id,
      })));
    } catch (error) {
      if (error?.status === 429) {
        const retryAfterSeconds = Number(error?.retry_after) || 60;
        planNotificationsCooldownUntilRef.current = Date.now() + (retryAfterSeconds * 1000);
        return;
      }
      setPlanNotifications([]);
    }
  }, [api]);

  const refreshPlanData = useCallback(async () => {
    await Promise.all([loadUpcomingWorkouts(), loadPlanNotifications()]);
  }, [loadPlanNotifications, loadUpcomingWorkouts]);

  const refreshChatData = useCallback(async () => {
    await Promise.all([loadAdminMessages(), loadAiMessages()]);
  }, [loadAdminMessages, loadAiMessages]);

  const refresh = useCallback(async () => {
    if (!api) return;
    await Promise.all([refreshPlanData(), refreshChatData()]);
  }, [api, refreshChatData, refreshPlanData]);

  useEffect(() => {
    if (!api) return;
    refresh();

    const interval = setInterval(refresh, 60 * 1000);
    return () => clearInterval(interval);
  }, [api, refresh]);

  // Real-time: при новом сообщении SSE обновляем список (с debounce, чтобы не спамить API)
  const SSE_REFRESH_DEBOUNCE_MS = 15000; // не чаще раза в 15 сек от SSE
  const lastSseRefreshRef = useRef(0);
  useEffect(() => {
    if (!api) return;
    ChatSSE.connect();
    const onUnread = () => {
      const now = Date.now();
      if (now - lastSseRefreshRef.current < SSE_REFRESH_DEBOUNCE_MS) return;
      lastSseRefreshRef.current = now;
      refreshChatData();
    };
    ChatSSE.subscribe(onUnread);
    return () => ChatSSE.unsubscribe(onUnread);
  }, [api, refreshChatData]);

  const workoutItems = upcomingWorkouts.filter((w) => !dismissed.has(w.id));
  const chatItems = adminMessages
    .filter((m) => !dismissed.has(m.id))
    .sort((a, b) => new Date(b.created_at || 0) - new Date(a.created_at || 0));
  const aiChatItems = aiMessages
    .filter((m) => !dismissed.has(m.id))
    .sort((a, b) => new Date(b.created_at || 0) - new Date(a.created_at || 0));
  const planNotifItems = planNotifications
    .filter((n) => !dismissed.has(n.id))
    .sort((a, b) => new Date(b.created_at || 0) - new Date(a.created_at || 0));
  const allItems = [...planNotifItems, ...chatItems, ...aiChatItems, ...workoutItems];

  if (!dismissedLoaded || allItems.length === 0) {
    return null;
  }

  return (
    <div className="notifications-container">
      {allItems.map((item, index) => {
        if (item.type === 'plan_notif') {
          const timeStr = item.created_at
            ? new Date(item.created_at).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', timeZone: tz })
            : '';
          const meta = item.metadata || {};
          const isCoachUpdate = (item.type === 'plan_notif' && meta.coach_id);
          return (
            <div key={item.id} className="notification-card notification-card--plan" style={{ animationDelay: `${index * 100}ms` }}>
              <div className="notification-icon" aria-hidden><BellIcon size={24} /></div>
              <div className="notification-content">
                <div className="notification-title">{item.message}</div>
                <div className="notification-date">{timeStr}</div>
              </div>
              <div className="notification-actions">
                <button
                  className="notification-btn"
                  onClick={() => {
                    api?.markPlanNotificationRead(item._id).catch(() => {});
                    const dateParam = meta.date ? `?date=${meta.date}` : '';
                    if (isCoachUpdate) {
                      navigate(`/calendar${dateParam}`);
                    } else if (meta.athlete_id) {
                      // Coach viewing athlete's result
                      const slug = meta.athlete_slug;
                      navigate(slug ? `/calendar?athlete=${slug}${meta.date ? '&date=' + meta.date : ''}` : `/calendar${dateParam}`);
                    } else {
                      navigate(`/calendar${dateParam}`);
                    }
                  }}
                >
                  Открыть
                </button>
                <button
                  type="button"
                  className="notification-dismiss"
                  onClick={() => {
                    handleDismiss(item.id);
                    api?.markPlanNotificationRead(item._id).catch(() => {});
                  }}
                  aria-label="Закрыть"
                >
                  <CloseIcon className="modal-close-icon" />
                </button>
              </div>
            </div>
          );
        }

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
                  type="button"
                  className="notification-dismiss"
                  onClick={() => handleDismiss(item.id)}
                  aria-label="Закрыть"
                >
                  <CloseIcon className="modal-close-icon" />
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
                  type="button"
                  className="notification-dismiss"
                  onClick={() => handleDismiss(item.id)}
                  aria-label="Закрыть"
                >
                  <CloseIcon className="modal-close-icon" />
                </button>
              </div>
            </div>
          );
        }

        const workout = item;
        const tomorrowDate = new Date();
        tomorrowDate.setHours(0, 0, 0, 0);
        tomorrowDate.setDate(tomorrowDate.getDate() + 1);
        const isTomorrow = workout.dateObj.getTime() === tomorrowDate.getTime();
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
                type="button"
                className="notification-dismiss"
                onClick={() => handleDismiss(workout.id)}
                aria-label="Закрыть"
              >
                <CloseIcon className="modal-close-icon" />
              </button>
            </div>
          </div>
        );
      })}
    </div>
  );
};

export default Notifications;
