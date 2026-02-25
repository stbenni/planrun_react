/**
 * Экран чата — классический двухколоночный layout
 * Для пользователей: AI-тренер, От администрации
 * Для админов: + вкладка «Администраторский» — ответы пользователям
 */

import React, { useState, useEffect, useRef, useCallback } from 'react';
import { useLocation } from 'react-router-dom';
import useAuthStore from '../stores/useAuthStore';
import usePlanStore from '../stores/usePlanStore';
import { useChatUnread } from '../hooks/useChatUnread';
import { useIsTabActive } from '../hooks/useIsTabActive';
import { ChatSSE } from '../services/ChatSSE';
import { getAvatarSrc } from '../utils/avatarUrl';
import SkeletonScreen from '../components/common/SkeletonScreen';
import './ChatScreen.css';

const TAB_AI = 'ai';
const TAB_ADMIN = 'admin';
const TAB_ADMIN_MODE = 'admin_mode';

const SYSTEM_CHATS = [
  { id: TAB_AI, label: 'AI-тренер', icon: '🤖', description: 'Персональные рекомендации по тренировкам' },
  { id: TAB_ADMIN, label: 'От администрации', icon: '📩', description: 'Сообщения от администрации сайта' },
];

const ADMIN_CHAT = { id: TAB_ADMIN_MODE, label: 'Администраторский', icon: '👥', description: 'Сообщения от пользователей' };

const ChatScreen = () => {
  const isTabActive = useIsTabActive('/chat');
  const location = useLocation();
  const { api, user } = useAuthStore();
  const { total: unreadTotal = 0, by_type: unreadByType = {} } = useChatUnread();
  const adminUnreadCount = unreadByType.admin ?? 0;
  const isAdmin = user?.role === 'admin';

  const openAdminModeFromState = location.state?.openAdminMode === true;
  const selectedUserIdFromState = location.state?.selectedUserId;
  const selectedUsernameFromState = location.state?.selectedUsername;
  const selectedUserEmailFromState = location.state?.selectedUserEmail;
  const openAdminTabFromState = location.state?.openAdminTab === true;
  const scrollToMessageId = location.state?.messageId;

  const chats = isAdmin ? [...SYSTEM_CHATS, ADMIN_CHAT] : SYSTEM_CHATS;

  // Для админов: вкладки Личный | Администраторский
  const [adminSection, setAdminSection] = useState(() => (openAdminModeFromState ? 'admin_mode' : 'personal'));

  const [selectedChat, setSelectedChat] = useState(() => {
    if (openAdminModeFromState) return TAB_ADMIN_MODE;
    if (openAdminTabFromState) return TAB_ADMIN;
    if (location.state?.openAITab === true) return TAB_AI;
    return TAB_AI;
  });
  const [messages, setMessages] = useState([]);
  const [conversationId, setConversationId] = useState(null);
  const [input, setInput] = useState('');
  const [loading, setLoading] = useState(false);
  const [sending, setSending] = useState(false);
  const [streamPhase, setStreamPhase] = useState(null);
  const [error, setError] = useState(null);
  const [recalcMessage, setRecalcMessage] = useState(null);
  const [nextPlanMessage, setNextPlanMessage] = useState(null);
  const [mobileListVisible, setMobileListVisible] = useState(!openAdminModeFromState && !openAdminTabFromState);

  // Admin mode: пользователи и сообщения
  const [chatUsers, setChatUsers] = useState([]);
  const [chatUsersLoading, setChatUsersLoading] = useState(false);
  const [selectedChatUser, setSelectedChatUser] = useState(null);
  const [chatAdminMessages, setChatAdminMessages] = useState([]);
  const [chatAdminLoading, setChatAdminLoading] = useState(false);
  const [chatAdminSending, setChatAdminSending] = useState(false);

  const messagesEndRef = useRef(null);
  const tabRef = useRef(selectedChat);
  tabRef.current = selectedChat;
  const isMountedRef = useRef(true);
  const isChatTabVisibleRef = useRef(isTabActive);
  isChatTabVisibleRef.current = isTabActive;
  useEffect(() => {
    isMountedRef.current = true;
    return () => { isMountedRef.current = false; };
  }, []);

  const scrollToBottom = () => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  };

  const loadMessages = useCallback(async () => {
    if (!api || selectedChat === TAB_ADMIN_MODE) {
      setLoading(false);
      return;
    }
    const loadingFor = selectedChat;
    const LOAD_TIMEOUT_MS = 15000;
    let timeoutId = null;
    try {
      setLoading(true);
      setError(null);
      const dataPromise = api.chatGetMessages(loadingFor, 50, 0);
      const timeoutPromise = new Promise((_, reject) => {
        timeoutId = window.setTimeout(() => reject(new Error('timeout')), LOAD_TIMEOUT_MS);
      });
      const data = await Promise.race([dataPromise, timeoutPromise]);
      if (timeoutId) clearTimeout(timeoutId);
      if (loadingFor !== tabRef.current) return;
      const list = Array.isArray(data?.messages) ? data.messages : [];
      setMessages([...list].reverse());
      if (data?.conversation_id) setConversationId(data.conversation_id);
    } catch (e) {
      if (timeoutId) clearTimeout(timeoutId);
      if (loadingFor !== tabRef.current) return;
      setError(e?.message === 'timeout' ? 'Загрузка сообщений заняла слишком много времени. Проверьте интернет.' : (e?.message || 'Ошибка загрузки сообщений'));
      setMessages([]);
    } finally {
      if (loadingFor === tabRef.current) setLoading(false);
    }
  }, [api, selectedChat]);

  const loadChatUsers = useCallback(async () => {
    if (!api || !isAdmin) return;
    setChatUsersLoading(true);
    try {
      const list = await api.getAdminChatUsers();
      setChatUsers(Array.isArray(list) ? list : []);
    } catch {
      setChatUsers([]);
    } finally {
      setChatUsersLoading(false);
    }
  }, [api, isAdmin]);

  const loadChatAdminMessages = useCallback(async (userId) => {
    if (!api || !userId) return;
    setChatAdminLoading(true);
    try {
      const res = await api.chatAdminGetMessages(userId, 100, 0);
      const list = Array.isArray(res?.messages) ? res.messages : [];
      setChatAdminMessages([...list].reverse());
    } catch {
      setChatAdminMessages([]);
    } finally {
      setChatAdminLoading(false);
    }
  }, [api]);

  useEffect(() => {
    if (selectedChat === TAB_ADMIN_MODE || adminSection === 'admin_mode') {
      loadChatUsers();
    }
  }, [selectedChat, adminSection, loadChatUsers]);

  useEffect(() => {
    if (selectedChat === TAB_ADMIN_MODE && selectedChatUser?.id) {
      loadChatAdminMessages(selectedChatUser.id);
    } else {
      setChatAdminMessages([]);
    }
  }, [selectedChat, selectedChatUser?.id, loadChatAdminMessages]);

  // Открыть чат с пользователем, переданным из админки (в т.ч. для инициации переписки — пользователь может ещё не быть в списке)
  useEffect(() => {
    if (!selectedUserIdFromState || adminSection !== 'admin_mode' || chatUsersLoading) return;
    const u = chatUsers.find((c) => c.user_id === selectedUserIdFromState);
    if (u) {
      setSelectedChatUser({ id: u.user_id, username: u.username, email: u.email, avatar_path: u.avatar_path });
    } else {
      setSelectedChatUser({
        id: selectedUserIdFromState,
        username: selectedUsernameFromState || 'Пользователь',
        email: selectedUserEmailFromState || '',
      });
    }
  }, [selectedUserIdFromState, selectedUsernameFromState, selectedUserEmailFromState, chatUsers, adminSection, chatUsersLoading]);

  useEffect(() => {
    loadMessages();
  }, [loadMessages]);

  // При возврате в чат — подгружаем свежие сообщения с сервера (ответ ИИ мог прийти в фоне)
  useEffect(() => {
    if (isTabActive && api) loadMessages();
  }, [isTabActive, api, loadMessages]);

  // При открытии чата (AI или «От администрации») помечаем сообщения прочитанными
  useEffect(() => {
    if (!isTabActive) return;
    if ((selectedChat === TAB_AI || selectedChat === TAB_ADMIN) && conversationId && api) {
      api.chatMarkRead(conversationId).catch(() => {});
    }
  }, [isTabActive, selectedChat, conversationId, api]);

  // Автопрочитывание: когда в открытом чате приходят новые сообщения (SSE), помечаем их прочитанными
  useEffect(() => {
    if (!api || !isTabActive) return;
    const onUnread = () => {
      if (selectedChat === TAB_AI && conversationId) {
        api.chatMarkRead(conversationId).catch(() => {});
      } else if (selectedChat === TAB_ADMIN && conversationId) {
        api.chatMarkRead(conversationId).catch(() => {});
      } else if (isAdmin && selectedChat === TAB_ADMIN_MODE && selectedChatUser?.id) {
        api.chatAdminMarkConversationRead(selectedChatUser.id).catch(() => {});
      }
    };
    ChatSSE.subscribe(onUnread);
    return () => ChatSSE.unsubscribe(onUnread);
  }, [api, isTabActive, selectedChat, conversationId, isAdmin, selectedChatUser?.id]);

  useEffect(() => {
    if (scrollToMessageId && messages.length > 0 && selectedChat === TAB_ADMIN) {
      const el = document.querySelector(`[data-message-id="${scrollToMessageId}"]`);
      el?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    } else {
      scrollToBottom();
    }
  }, [messages, selectedChat, scrollToMessageId]);

  const handleSelectChat = (chatId) => {
    setSelectedChat(chatId);
    setMobileListVisible(false);
    if (chatId === TAB_ADMIN_MODE) {
      setAdminSection('admin_mode');
      setSelectedChatUser(null);
    }
  };

  const handleAdminSectionChange = (section) => {
    setAdminSection(section);
    setMobileListVisible(true);
    if (section === 'personal') {
      setSelectedChat(TAB_AI);
      setSelectedChatUser(null);
    } else {
      setSelectedChat(TAB_ADMIN_MODE);
      setSelectedChatUser(null);
    }
  };

  const handleSelectChatUser = (u) => {
    setSelectedChatUser({ id: u.user_id, username: u.username, email: u.email, avatar_path: u.avatar_path });
    setMobileListVisible(false);
  };

  const handleBackToList = () => {
    setMobileListVisible(true);
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    const content = input.trim();
    if (!content || !api || sending) return;

    setInput('');
    setSending(true);
    setError(null);

    const userMsg = {
      id: 'temp-' + Date.now(),
      sender_type: 'user',
      content,
      created_at: new Date().toISOString(),
    };
    setMessages((prev) => [...prev, userMsg]);

    if (selectedChat === TAB_ADMIN) {
      try {
        const res = await api.chatSendMessageToAdmin(content);
        setMessages((prev) =>
          prev.map((m) =>
            m.id === userMsg.id ? { ...m, id: res?.message_id ?? m.id } : m
          )
        );
      } catch (e) {
        setError(e.message || 'Ошибка отправки');
        setMessages((prev) => prev.filter((m) => m.id !== userMsg.id));
      } finally {
        setSending(false);
      }
      return;
    }

    const aiPlaceholder = {
      id: 'temp-ai-' + Date.now(),
      sender_type: 'ai',
      content: '',
      created_at: null,
    };
    setMessages((prev) => [...prev, aiPlaceholder]);
    setStreamPhase('connecting');

    // Запрос в фоне: не блокируем UI; батчим обновления по кадрам, чтобы не грузить главный поток
    setSending(false);

    let accumulated = '';
    let flushScheduled = false;
    const flushToState = () => {
      if (!isMountedRef.current || !isChatTabVisibleRef.current) return;
      const text = accumulated;
      setMessages((prev) => {
        const next = [...prev];
        const idx = next.findIndex((m) => m.id === aiPlaceholder.id);
        if (idx >= 0) next[idx] = { ...next[idx], content: text };
        return next;
      });
    };
    api.chatSendMessageStream(
      content,
      (chunk) => {
        if (!isMountedRef.current || !isChatTabVisibleRef.current) return;
        accumulated += chunk;
        if (!flushScheduled) {
          flushScheduled = true;
          requestAnimationFrame(() => {
            flushScheduled = false;
            flushToState();
          });
        }
      },
      {
        onFirstChunk: () => isChatTabVisibleRef.current && setStreamPhase('streaming'),
        onPlanUpdated: () => usePlanStore.getState().loadPlan(),
        onPlanRecalculating: () => {
          if (isChatTabVisibleRef.current) {
            setRecalcMessage('Пересчёт плана запущен. Обновите календарь через 3–5 минут.');
            setTimeout(() => setRecalcMessage(null), 8000);
          }
        },
        onPlanGeneratingNext: () => {
          if (isChatTabVisibleRef.current) {
            setNextPlanMessage('Новый план генерируется. Обновите календарь через 3–5 минут.');
            setTimeout(() => setNextPlanMessage(null), 8000);
          }
        },
        timeoutMs: 180000,
      }
    )
      .then((fullContent) => {
        if (!fullContent) {
          return api.chatSendMessage(content).then((r) => r?.content ?? '');
        }
        return fullContent;
      })
      .then((fullContent) => {
        if (!isMountedRef.current || !isChatTabVisibleRef.current) return;
        setMessages((prev) =>
          prev.map((m) =>
            m.id === aiPlaceholder.id ? { ...m, sender_type: 'ai', content: fullContent } : m
          )
        );
        setStreamPhase('done');
        if (!fullContent) setError('AI не вернул ответ. Попробуйте ещё раз.');
      })
      .catch((e) => {
        if (isMountedRef.current && isChatTabVisibleRef.current) {
          setError(e?.message || 'Ошибка отправки');
          setMessages((prev) => prev.filter((m) => m.id !== aiPlaceholder.id));
        }
      })
      .finally(() => {
        if (isMountedRef.current && isChatTabVisibleRef.current) setStreamPhase(null);
      });
  };

  const handleClearAiChat = useCallback(async () => {
    if (!api || !window.confirm('Очистить историю чата с AI? Это действие нельзя отменить.')) return;
    try {
      await api.chatClearAi();
      setMessages([]);
      setError(null);
    } catch (e) {
      setError(e.message || 'Не удалось очистить чат');
    }
  }, [api]);

  const [markAllReadLoading, setMarkAllReadLoading] = useState(false);
  const handleMarkAllRead = useCallback(async () => {
    if (!api) return;
    setMarkAllReadLoading(true);
    try {
      if (isAdmin) {
        await Promise.all([api.chatMarkAllRead(), api.chatAdminMarkAllRead()]);
      } else {
        await api.chatMarkAllRead();
      }
      ChatSSE.setUnreadData({ total: 0, by_type: {} });
    } catch (_) {
    } finally {
      setMarkAllReadLoading(false);
    }
  }, [api, isAdmin]);

  const handleAdminChatSend = async (e) => {
    e.preventDefault();
    if (!api || !selectedChatUser || !input.trim() || chatAdminSending) return;
    const content = input.trim();
    setInput('');
    setChatAdminSending(true);
    setError(null);
    try {
      await api.chatAdminSendMessage(selectedChatUser.id, content);
      await loadChatAdminMessages(selectedChatUser.id);
    } catch (e) {
      setError(e.message || 'Ошибка отправки');
    } finally {
      setChatAdminSending(false);
    }
  };

  const formatTime = (createdAt) => {
    if (!createdAt) return '';
    const d = new Date(createdAt);
    const now = new Date();
    const diff = now - d;
    if (diff < 86400000) return d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
    return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
  };

  const isAdminMode = isAdmin && (selectedChat === TAB_ADMIN_MODE || adminSection === 'admin_mode');
  const isAdminChat = selectedChat === TAB_ADMIN;
  const isAiChat = selectedChat === TAB_AI;
  const currentChat = chats.find((c) => c.id === selectedChat);

  // Для админов: вкладки Личный | Администраторский в header
  const adminSectionTabs = isAdmin && (
    <div className="chat-sidebar-tabs">
      <button
        type="button"
        className={`chat-sidebar-tab ${adminSection === 'personal' ? 'active' : ''}`}
        onClick={() => handleAdminSectionChange('personal')}
      >
        Личный
      </button>
      <button
        type="button"
        className={`chat-sidebar-tab ${adminSection === 'admin_mode' ? 'active' : ''}`}
        onClick={() => handleAdminSectionChange('admin_mode')}
      >
        Администраторский
        {adminUnreadCount > 0 && (
          <span className="chat-sidebar-tab-badge">{adminUnreadCount > 99 ? '99+' : adminUnreadCount}</span>
        )}
      </button>
    </div>
  );

  // Для админ-режима: sidebar = список пользователей
  const sidebarContent = isAdminMode ? (
    <>
      <div className="chat-sidebar-header">
        <div className="chat-sidebar-header-row">
          {adminSectionTabs}
        </div>
        {unreadTotal > 0 && (
          <div className="chat-sidebar-header-row">
            <button
              type="button"
              className="chat-sidebar-tab chat-mark-all-read-btn"
              onClick={handleMarkAllRead}
              disabled={markAllReadLoading}
              title="Прочитать все"
            >
              {markAllReadLoading ? '…' : 'Прочитать все'}
            </button>
          </div>
        )}
      </div>
      {chatUsersLoading ? (
        <div className="chat-loading" style={{ padding: 'var(--space-4)' }}>
          <div className="skeleton-line" style={{ width: '60%', height: 14 }}></div>
          <div className="skeleton-line" style={{ width: '40%', height: 14 }}></div>
        </div>
      ) : chatUsers.length === 0 ? (
        <div className="chat-empty" style={{ padding: 'var(--space-4)' }}>Пока никто не написал</div>
      ) : (
        <ul className="chat-admin-user-list">
          {chatUsers.map((u) => (
            <li key={u.user_id}>
              <button
                type="button"
                className={`chat-admin-user-btn ${selectedChatUser?.id === u.user_id ? 'active' : ''}`}
                onClick={() => handleSelectChatUser(u)}
              >
                <span className="chat-admin-user-icon">
                  {u.avatar_path ? (
                    <img src={getAvatarSrc(u.avatar_path, api?.baseUrl || '/api')} alt="" className="chat-admin-user-avatar-img" />
                  ) : (
                    <span className="chat-admin-user-initials">{u.username ? u.username.slice(0, 2).toUpperCase() : '?'}</span>
                  )}
                </span>
                <div className="chat-admin-user-info">
                  <span className="chat-admin-user-name">{u.username}</span>
                  {u.email && <span className="chat-admin-user-email">{u.email}</span>}
                </div>
              </button>
            </li>
          ))}
        </ul>
      )}
    </>
  ) : (
    <>
      <div className="chat-sidebar-header">
        <div className="chat-sidebar-header-row">
          {isAdmin ? adminSectionTabs : <h2 className="chat-sidebar-title">Чаты</h2>}
        </div>
        {unreadTotal > 0 && (
          <div className="chat-sidebar-header-row">
            <button
              type="button"
              className="chat-sidebar-tab chat-mark-all-read-btn"
              onClick={handleMarkAllRead}
              disabled={markAllReadLoading}
              title="Прочитать все"
            >
              {markAllReadLoading ? '…' : 'Прочитать все'}
            </button>
          </div>
        )}
      </div>
      <nav className="chat-list">
        {(isAdmin && adminSection === 'personal' ? SYSTEM_CHATS : chats).map((chat) => (
          <button
            key={chat.id}
            type="button"
            className={`chat-list-item ${selectedChat === chat.id ? 'chat-list-item--active' : ''}`}
            onClick={() => handleSelectChat(chat.id)}
            aria-pressed={selectedChat === chat.id}
          >
            <span className="chat-list-item-icon" aria-hidden="true">{chat.icon}</span>
            <div className="chat-list-item-content">
              <span className="chat-list-item-label">{chat.label}</span>
              <span className="chat-list-item-desc">{chat.description}</span>
            </div>
            {chat.id === TAB_ADMIN && adminUnreadCount > 0 && (
              <span className="chat-list-item-badge" aria-hidden="true">
                {adminUnreadCount > 99 ? '99+' : adminUnreadCount}
              </span>
            )}
            {chat.id === TAB_ADMIN_MODE && adminUnreadCount > 0 && (
              <span className="chat-list-item-badge" aria-hidden="true">
                {adminUnreadCount > 99 ? '99+' : adminUnreadCount}
              </span>
            )}
          </button>
        ))}
      </nav>
    </>
  );

  // Main content: для admin_mode — другой layout
  const mainContent = isAdminMode ? (
    selectedChatUser ? (
      <>
        <div className="chat-main-header">
          <button type="button" className="chat-back-btn" onClick={handleBackToList} aria-label="Назад к списку">
            ←
          </button>
          <div className="chat-main-header-info">
            <span className="chat-main-header-icon" aria-hidden="true">👥</span>
            <div>
              <h3 className="chat-main-header-title">Чат с {selectedChatUser.username}</h3>
              <p className="chat-main-header-subtitle">Ответ от администрации</p>
            </div>
          </div>
          <button
            type="button"
            className="chat-refresh-btn"
            onClick={() => loadChatAdminMessages(selectedChatUser.id)}
            disabled={chatAdminLoading}
            title="Обновить"
          >
            {chatAdminLoading ? '…' : '↻'}
          </button>
        </div>
        <div className="chat-messages">
          {chatAdminLoading ? (
            <div className="chat-loading">
              <div className="skeleton-line" style={{ width: '70%', height: 14 }}></div>
              <div className="skeleton-line" style={{ width: '50%', height: 14 }}></div>
              <div className="skeleton-line" style={{ width: '60%', height: 14, marginLeft: 'auto' }}></div>
            </div>
          ) : chatAdminMessages.length === 0 ? (
            <div className="chat-empty">Сообщений пока нет. Напишите первым.</div>
          ) : (
            <>
              {chatAdminMessages.map((msg) => (
                <div key={msg.id} className={`chat-message chat-message--${msg.sender_type} chat-admin-message`}>
                  {msg.sender_type === 'user' && (
                    <div className="chat-message-avatar chat-message-avatar--other">
                      {selectedChatUser?.avatar_path ? (
                        <img src={getAvatarSrc(selectedChatUser.avatar_path, api?.baseUrl || '/api')} alt="" className="chat-avatar-img" />
                      ) : (
                        <span className="chat-avatar-initials">{selectedChatUser?.username?.slice(0, 2).toUpperCase() || '?'}</span>
                      )}
                    </div>
                  )}
                  <div className="chat-message-bubble">
                    <div className="chat-message-content">{msg.content || ''}</div>
                    {msg.created_at && <div className="chat-message-time">{formatTime(msg.created_at)}</div>}
                  </div>
                  {msg.sender_type === 'admin' && (
                    <div className="chat-message-avatar chat-message-avatar--user">
                      <span className="chat-avatar-icon">📩</span>
                    </div>
                  )}
                </div>
              ))}
              <div ref={messagesEndRef} />
            </>
          )}
        </div>
        {error && (
          <div className="chat-error" role="alert">
            {error}
            <button type="button" onClick={() => setError(null)} aria-label="Закрыть">×</button>
          </div>
        )}
        <form className="chat-input-form" onSubmit={handleAdminChatSend}>
          <input
            type="text"
            className="chat-input"
            placeholder="Напишите сообщение..."
            value={input}
            onChange={(e) => setInput(e.target.value)}
            disabled={chatAdminSending || chatAdminLoading}
            maxLength={4000}
          />
          <button type="submit" className="chat-send-btn" disabled={chatAdminSending || chatAdminLoading || !input.trim()}>
            {chatAdminSending ? '…' : '➤'}
          </button>
        </form>
      </>
    ) : (
      <div className="chat-select-prompt">
        <p>Выберите пользователя для просмотра чата</p>
      </div>
    )
  ) : selectedChat ? (
    <>
      <div className="chat-main-header">
        <button type="button" className="chat-back-btn" onClick={handleBackToList} aria-label="Назад к списку чатов">←</button>
        <div className="chat-main-header-info">
          <span className="chat-main-header-icon" aria-hidden="true">{currentChat?.icon}</span>
          <div>
            <h3 className="chat-main-header-title">{currentChat?.label}</h3>
            <p className="chat-main-header-subtitle">{currentChat?.description}</p>
          </div>
        </div>
        {isAiChat && (
          <button type="button" className="chat-clear-btn" onClick={handleClearAiChat} disabled={sending} title="Очистить чат">
            Очистить
          </button>
        )}
        {isAdminChat && (
          <button type="button" className="chat-refresh-btn" onClick={loadMessages} disabled={loading} title="Обновить">
            {loading ? '…' : '↻'}
          </button>
        )}
      </div>
      <div className="chat-messages">
        {loading ? (
          <div className="chat-loading">
            <div className="skeleton-line" style={{ width: '70%', height: 14 }}></div>
            <div className="skeleton-line" style={{ width: '45%', height: 14 }}></div>
            <div className="skeleton-line" style={{ width: '60%', height: 14, marginLeft: 'auto' }}></div>
          </div>
        ) : messages.length === 0 ? (
          <div className="chat-empty">
            <p>
              {isAdminChat ? 'Сообщений от администрации пока нет.' : 'Напишите сообщение — AI-тренер ответит на основе вашего профиля, плана и статистики.'}
            </p>
            {!isAdminChat && <p className="chat-empty-hint">Например: «Что лучше делать завтра: отдых или лёгкий бег?»</p>}
          </div>
        ) : (
          <>
            {messages.map((msg) => (
              <div key={msg.id} data-message-id={msg.id} className={`chat-message chat-message--${msg.sender_type}`}>
                {msg.sender_type !== 'user' && (
                  <div className="chat-message-avatar chat-message-avatar--other">
                    {msg.sender_type === 'ai' ? (
                      <span className="chat-avatar-icon">🤖</span>
                    ) : (
                      <span className="chat-avatar-icon">📩</span>
                    )}
                  </div>
                )}
                <div className="chat-message-bubble">
                  <div className="chat-message-content">
                    {msg.content ? (
                      msg.content
                    ) : sending && msg.id?.startsWith('temp-ai-') ? (
                      <span className="chat-message-status">
                        {streamPhase === 'connecting' && (
                          <span className="chat-typing-dots" aria-hidden="true">
                            <span /><span /><span />
                          </span>
                        )}
                        {streamPhase === 'streaming' && (
                          <>
                            <span className="chat-typing-dots" aria-hidden="true">
                              <span /><span /><span />
                            </span>
                            Печатает…
                          </>
                        )}
                        {!streamPhase && (
                          <span className="chat-message-error-text">Ошибка</span>
                        )}
                      </span>
                    ) : (
                      '…'
                    )}
                  </div>
                  {msg.created_at && <div className="chat-message-time">{formatTime(msg.created_at)}</div>}
                </div>
                {msg.sender_type === 'user' && (
                  <div className="chat-message-avatar chat-message-avatar--user">
                    {user?.avatar_path ? (
                      <img src={getAvatarSrc(user.avatar_path, api?.baseUrl || '/api')} alt="" className="chat-avatar-img" />
                    ) : (
                      <span className="chat-avatar-initials">{user?.username ? user.username.slice(0, 2).toUpperCase() : '?'}</span>
                    )}
                  </div>
                )}
              </div>
            ))}
            <div ref={messagesEndRef} />
          </>
        )}
      </div>
      {error && (
        <div className="chat-error" role="alert">
          {error}
          <button type="button" onClick={() => setError(null)} aria-label="Закрыть">×</button>
        </div>
      )}
      {(recalcMessage || nextPlanMessage) && isAiChat && (
        <div className="chat-info" role="status">
          {recalcMessage || nextPlanMessage}
          <button type="button" onClick={() => { setRecalcMessage(null); setNextPlanMessage(null); }} aria-label="Закрыть">×</button>
        </div>
      )}
      <form className="chat-input-form" onSubmit={handleSubmit}>
        <input
          type="text"
          className="chat-input"
          placeholder="Напишите сообщение..."
          value={input}
          onChange={(e) => setInput(e.target.value)}
          disabled={sending || loading}
          maxLength={4000}
        />
        <button type="submit" className="chat-send-btn" disabled={sending || loading || !input.trim()} title={sending ? 'Отправка…' : 'Отправить'}>
          {sending ? '…' : '➤'}
        </button>
      </form>
    </>
  ) : (
    <div className="chat-select-prompt">
      <p>Выберите чат из списка слева</p>
    </div>
  );

  return (
    <div className="container chat-page">
      <div className="chat-layout">
        <aside className={`chat-sidebar ${!mobileListVisible ? 'chat-sidebar--hidden-mobile' : ''}`} aria-label="Список чатов">
          {sidebarContent}
        </aside>
        <main className={`chat-main ${mobileListVisible ? 'chat-main--hidden-mobile' : ''}`} aria-label={currentChat ? `Чат: ${currentChat.label}` : 'Выберите чат'}>
          {mainContent}
        </main>
      </div>
    </div>
  );
};

export default ChatScreen;
