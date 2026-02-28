/**
 * Кнопка чата в TopHeader — квадратик как иконка профиля
 * При клике — переход в /chat
 * Бейдж с количеством непрочитанных сообщений (SSE real-time)
 * Для админов с непрочитанными — открывает вкладку «Администраторский»
 */

import React from 'react';
import { useNavigate } from 'react-router-dom';
import { useChatUnread } from '../../hooks/useChatUnread';
import useAuthStore from '../../stores/useAuthStore';
import { MessageCircleIcon } from './Icons';
import './ChatNotificationButton.css';

const ChatNotificationButton = () => {
  const navigate = useNavigate();
  const { user } = useAuthStore();
  const { total = 0 } = useChatUnread();
  const isAdmin = user?.role === 'admin';

  const handleClick = () => {
    const state = total > 0 ? (isAdmin ? { openAdminMode: true } : { openAdminTab: true }) : undefined;
    navigate('/chat', { state });
  };

  return (
    <button
      type="button"
      className="header-chat-btn"
      onClick={handleClick}
      aria-label={total > 0 ? `Чат: ${total} непрочитанных` : 'Чат'}
    >
      <span className="header-chat-icon" aria-hidden><MessageCircleIcon size={20} /></span>
      {total > 0 && (
        <span className="header-chat-badge" aria-hidden="true">
          {total > 99 ? '99+' : total}
        </span>
      )}
    </button>
  );
};

export default ChatNotificationButton;
