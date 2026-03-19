/**
 * Экран чата — классический двухколоночный layout
 * Для пользователей: AI-тренер, От администрации
 * Для админов: + вкладка «Администраторский» — ответы пользователям
 */

import { useState, useEffect, useRef, useCallback } from 'react';
import useAuthStore from '../stores/useAuthStore';
import { useChatUnread } from '../hooks/useChatUnread';
import { useIsTabActive } from '../hooks/useIsTabActive';
import { ChatSSE } from '../services/ChatSSE';
import { getAvatarSrc } from '../utils/avatarUrl';
import { useChatDirectories } from './chat/useChatDirectories';
import { useChatMessageLists } from './chat/useChatMessageLists';
import { useChatSubmitHandlers } from './chat/useChatSubmitHandlers';
import { useChatNavigation } from './chat/useChatNavigation';
import { formatChatTime } from './chat/chatTime';
import { BotIcon, MailIcon, TAB_ADMIN, TAB_ADMIN_MODE, TAB_AI, UsersIcon } from './chat/chatConstants';
import { CloseIcon } from '../components/common/Icons';
import { getQuickReplies, SUGGESTED_PROMPTS } from './chat/chatQuickReplies';
import './ChatScreen.css';

const ChatScreen = () => {
  const isTabActive = useIsTabActive('/chat');
  const { api, user } = useAuthStore();
  const userTimezone = user?.timezone || (typeof Intl !== 'undefined' && Intl.DateTimeFormat?.().resolvedOptions?.().timeZone) || 'Europe/Moscow';
  const { total: unreadTotal = 0, by_type: unreadByType = {} } = useChatUnread();
  const adminUnreadCount = unreadByType.admin_mode ?? 0;
  const adminTabUnreadCount = unreadByType.admin ?? 0;
  const myUserId = Number(user?.user_id ?? user?.id) || 0;
  const isAdmin = user?.role === 'admin';

  const {
    directDialogs,
    chatUsers,
    chatUsersLoading,
    loadDirectDialogs,
    loadChatUsers,
  } = useChatDirectories(api, isAdmin);
  const [input, setInput] = useState('');
  const [sending, setSending] = useState(false);
  const [streamPhase, setStreamPhase] = useState(null);
  const [recalcMessage, setRecalcMessage] = useState(null);
  const [nextPlanMessage, setNextPlanMessage] = useState(null);
  const [chatAdminSending, setChatAdminSending] = useState(false);

  const messagesEndRef = useRef(null);
  const scrollAreaRef = useRef(null);
  const isMountedRef = useRef(true);
  const isChatTabVisibleRef = useRef(isTabActive);
  isChatTabVisibleRef.current = isTabActive;
  const streamAbortRef = useRef(null);
  const notificationTimersRef = useRef([]);
  const prevMessagesLenRef = useRef(0);
  const prevSelectedChatRef = useRef(null);
  const {
    adminSection,
    contactUserForDialog,
    contactUserLoading,
    contactUserSlugFromState,
    currentChat,
    chats,
    handleAdminSectionChange,
    handleBackToList,
    handleSelectChat,
    handleSelectChatUser,
    isAdminChat,
    isAdminMode,
    isAiChat,
    isUserDialog,
    mobileListVisible,
    personalChats,
    scrollToMessageId,
    selectedChat,
    selectedChatUser,
  } = useChatNavigation({
    api,
    chatUsers,
    chatUsersLoading,
    directDialogs,
    isAdmin,
    userTimezone,
  });
  const {
    messages,
    setMessages,
    conversationId,
    error,
    setError,
    loading,
    userDialogMessages,
    setUserDialogMessages,
    userDialogLoading,
    chatAdminMessages,
    setChatAdminMessages,
    chatAdminLoading,
    loadMessages,
    loadUserDialogMessages,
    loadChatAdminMessages,
  } = useChatMessageLists(api, selectedChat, loadDirectDialogs);
  const {
    handleSubmit,
    sendDirect,
    handleAdminChatSend,
    handleClearAiChat,
    handleClearDirectDialog,
    handleMarkAllRead: performMarkAllRead,
  } = useChatSubmitHandlers({
    api,
    user,
    myUserId,
    selectedChat,
    contactUserForDialog,
    selectedChatUser,
    sending,
    chatAdminSending,
    input,
    setInput,
    setSending,
    setChatAdminSending,
    setError,
    setMessages,
    setUserDialogMessages,
    setStreamPhase,
    streamAbortRef,
    isMountedRef,
    notificationTimersRef,
    setRecalcMessage,
    setNextPlanMessage,
    loadDirectDialogs,
    loadChatAdminMessages,
  });

  useEffect(() => {
    setInput('');
    setError(null);
    streamAbortRef.current?.abort();
    setStreamPhase(null);
  }, [selectedChat, setError]);

  const cleanupPendingAsync = useCallback(() => {
    streamAbortRef.current?.abort();
    streamAbortRef.current = null;
    notificationTimersRef.current.forEach(clearTimeout);
    notificationTimersRef.current = [];
  }, []);

  useEffect(() => {
    isMountedRef.current = true;
    return () => {
      isMountedRef.current = false;
      cleanupPendingAsync();
    };
  }, [cleanupPendingAsync]);

  const scrollToBottom = useCallback((behavior = 'auto') => {
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        const el = scrollAreaRef.current;
        if (el) {
          el.scrollTo({ top: el.scrollHeight, behavior });
        } else {
          messagesEndRef.current?.scrollIntoView({ behavior, block: 'end' });
        }
      });
    });
  }, []);

  const handleMessagesAreaClick = useCallback((event) => {
    const interactiveTarget = typeof event.target?.closest === 'function'
      ? event.target.closest('button, a, input, textarea, label, select')
      : null;
    if (interactiveTarget) return;
    scrollToBottom('smooth');
  }, [scrollToBottom]);

  useEffect(() => {
    if (selectedChat === TAB_ADMIN_MODE || adminSection === 'admin_mode') {
      loadChatUsers();
    }
  }, [selectedChat, adminSection, loadChatUsers]);

  useEffect(() => {
    if (!isTabActive) return;
    if (selectedChat === TAB_ADMIN_MODE && selectedChatUser?.id) {
      loadChatAdminMessages(selectedChatUser.id);
    } else {
      setChatAdminMessages([]);
    }
  }, [isTabActive, selectedChat, selectedChatUser?.id, loadChatAdminMessages, setChatAdminMessages]);

  useEffect(() => {
    if (!isTabActive) return;
    if (isUserDialog && contactUserForDialog?.id) {
      loadUserDialogMessages(contactUserForDialog.id);
    } else {
      setUserDialogMessages([]);
    }
  }, [isTabActive, selectedChat, isUserDialog, contactUserForDialog?.id, loadUserDialogMessages, setUserDialogMessages]);

  // Загрузка/перезагрузка сообщений при смене вкладки или при возврате в чат
  useEffect(() => {
    if (!isTabActive || !api) return;
    loadMessages();
  }, [isTabActive, api, loadMessages]);

  // При открытии чата (AI или «От администрации») помечаем сообщения прочитанными
  useEffect(() => {
    if (!isTabActive) return;
    if ((selectedChat === TAB_AI || selectedChat === TAB_ADMIN) && conversationId && api) {
      api.chatMarkRead(conversationId).catch(() => {});
    }
  }, [isTabActive, selectedChat, conversationId, api]);

  // SSE: перезагружать только когда данные непрочитанных реально изменились
  const sseSnapshotRef = useRef(null);
  useEffect(() => {
    if (!api || !isTabActive) return;
    const onSSE = (data) => {
      const key = `${data?.total ?? 0}|${JSON.stringify(data?.by_type ?? {})}`;
      if (key === sseSnapshotRef.current) return;
      sseSnapshotRef.current = key;

      if (selectedChat === TAB_AI && conversationId) {
        api.chatMarkRead(conversationId).catch(() => {});
      } else if (selectedChat === TAB_ADMIN && conversationId) {
        api.chatMarkRead(conversationId).catch(() => {});
        loadMessages();
      } else if (selectedChat?.startsWith?.('dialog_') && contactUserForDialog?.id) {
        loadUserDialogMessages(contactUserForDialog.id);
      } else if (isAdmin && selectedChat === TAB_ADMIN_MODE && selectedChatUser?.id) {
        api.chatAdminMarkConversationRead(selectedChatUser.id).catch(() => {});
        loadChatAdminMessages(selectedChatUser.id);
      }
      loadDirectDialogs();
    };
    ChatSSE.subscribe(onSSE);
    return () => ChatSSE.unsubscribe(onSSE);
  }, [api, isTabActive, selectedChat, conversationId, isAdmin, selectedChatUser?.id, contactUserForDialog?.id, loadMessages, loadUserDialogMessages, loadChatAdminMessages, loadDirectDialogs]);

  useEffect(() => {
    if (scrollToMessageId && messages.length > 0 && selectedChat === TAB_ADMIN) {
      const msgEl = document.querySelector(`[data-message-id="${scrollToMessageId}"]`);
      const scrollEl = scrollAreaRef.current;
      if (msgEl && scrollEl) {
        const rect = msgEl.getBoundingClientRect();
        const scrollRect = scrollEl.getBoundingClientRect();
        const targetTop = scrollEl.scrollTop + (rect.top - scrollRect.top) - scrollEl.clientHeight / 2 + rect.height / 2;
        scrollEl.scrollTop = Math.max(0, targetTop);
      } else {
        msgEl?.scrollIntoView({ behavior: 'auto', block: 'center' });
      }
    } else if (selectedChat !== prevSelectedChatRef.current || messages.length !== prevMessagesLenRef.current) {
      scrollToBottom();
    }
    prevSelectedChatRef.current = selectedChat;
    prevMessagesLenRef.current = messages.length;
  }, [messages.length, scrollToBottom, selectedChat, scrollToMessageId]);

  useEffect(() => {
    if (isUserDialog && userDialogMessages.length > 0) scrollToBottom();
  }, [isUserDialog, contactUserForDialog?.id, userDialogMessages.length, scrollToBottom]);

  useEffect(() => {
    if (isAdminMode && selectedChatUser?.id && chatAdminMessages.length > 0) scrollToBottom();
  }, [isAdminMode, selectedChatUser?.id, chatAdminMessages.length, scrollToBottom]);

  const [markAllReadLoading, setMarkAllReadLoading] = useState(false);
  const handleMarkAllRead = useCallback(async () => {
    if (!api) return;
    setMarkAllReadLoading(true);
    try {
      await performMarkAllRead(isAdmin);
    } catch (_error) {
      // Ignore mark-all-read failures and keep the current unread state.
    } finally {
      setMarkAllReadLoading(false);
    }
  }, [api, isAdmin, performMarkAllRead]);

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
                    <img src={getAvatarSrc(u.avatar_path, api?.baseUrl || '/api', 'sm')} alt="" className="chat-admin-user-avatar-img" />
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
        {(isAdmin && adminSection === 'personal' ? personalChats : chats).map((chat) => (
          <button
            key={chat.id}
            type="button"
            className={`chat-list-item ${selectedChat === chat.id ? 'chat-list-item--active' : ''}`}
            onClick={() => handleSelectChat(chat.id)}
            aria-pressed={selectedChat === chat.id}
          >
            <span className="chat-list-item-icon" aria-hidden="true">
              {chat.user ? (
                chat.user.avatar_path ? (
                  <img src={getAvatarSrc(chat.user.avatar_path, api?.baseUrl || '/api', 'sm')} alt="" className="chat-list-item-avatar-img" />
                ) : (
                  <span className="chat-list-item-avatar-initials">{chat.user.username ? chat.user.username.slice(0, 2).toUpperCase() : '?'}</span>
                )
              ) : (
                chat.Icon && <chat.Icon size={20} />
              )}
            </span>
            <div className="chat-list-item-content">
              <span className="chat-list-item-label">{chat.label}</span>
              <span className="chat-list-item-desc">{chat.description}</span>
            </div>
            {chat.id === TAB_ADMIN && adminTabUnreadCount > 0 && (
              <span className="chat-list-item-badge" aria-hidden="true">
                {adminTabUnreadCount > 99 ? '99+' : adminTabUnreadCount}
              </span>
            )}
            {chat.id === TAB_ADMIN_MODE && adminUnreadCount > 0 && (
              <span className="chat-list-item-badge" aria-hidden="true">
                {adminUnreadCount > 99 ? '99+' : adminUnreadCount}
              </span>
            )}
            {chat.unreadCount > 0 && (
              <span className="chat-list-item-badge" aria-hidden="true">
                {chat.unreadCount > 99 ? '99+' : chat.unreadCount}
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
        <div ref={scrollAreaRef} className="chat-main-scroll-area">
          <div className="chat-main-header">
            <button type="button" className="chat-back-btn" onClick={handleBackToList} aria-label="Назад к списку">
              ←
            </button>
            <div className="chat-main-header-info">
              <span className="chat-main-header-icon" aria-hidden="true"><UsersIcon size={20} /></span>
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
          <div className="chat-messages" onClick={handleMessagesAreaClick}>
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
                        <img src={getAvatarSrc(selectedChatUser.avatar_path, api?.baseUrl || '/api', 'sm')} alt="" className="chat-avatar-img" />
                      ) : (
                        <span className="chat-avatar-initials">{selectedChatUser?.username?.slice(0, 2).toUpperCase() || '?'}</span>
                      )}
                    </div>
                  )}
                  <div className="chat-message-bubble">
                    <div className="chat-message-content">{msg.content || ''}</div>
                    {msg.created_at && <div className="chat-message-time">{formatChatTime(msg.created_at, userTimezone)}</div>}
                  </div>
                  {msg.sender_type === 'admin' && (
                    <div className="chat-message-avatar chat-message-avatar--user">
                      <span className="chat-avatar-icon" aria-hidden><MailIcon size={20} /></span>
                    </div>
                  )}
                </div>
              ))}
              <div ref={messagesEndRef} />
            </>
          )}
          </div>
        </div>
        {error && (
          <div className="chat-error" role="alert">
            {error}
            <button type="button" onClick={() => setError(null)} aria-label="Закрыть">
              <CloseIcon className="modal-close-icon" />
            </button>
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
  ) : isUserDialog ? (
    <>
      {contactUserLoading && (
        <div className="chat-loading" style={{ padding: 'var(--space-4)' }}>
          <div className="skeleton-line" style={{ width: '60%', height: 14 }}></div>
          <div className="skeleton-line" style={{ width: '40%', height: 14 }}></div>
        </div>
      )}
      {!contactUserLoading && Number(contactUserForDialog?.id) === myUserId && (
        <div className="chat-error" role="alert">
          Вы не можете написать себе. Перейдите в другой чат.
        </div>
      )}
      {!contactUserLoading && (
      <div ref={scrollAreaRef} className="chat-main-scroll-area">
        <div className="chat-main-header">
          <button type="button" className="chat-back-btn" onClick={handleBackToList} aria-label="Назад к списку чатов">←</button>
          <div className="chat-main-header-info">
            <span className="chat-main-header-avatar" aria-hidden="true">
              {contactUserForDialog?.avatar_path ? (
                <img src={getAvatarSrc(contactUserForDialog.avatar_path, api?.baseUrl || '/api', 'sm')} alt="" className="chat-header-avatar-img" />
              ) : (
                <span className="chat-header-avatar-initials">{contactUserForDialog?.username ? contactUserForDialog.username.slice(0, 2).toUpperCase() : '?'}</span>
              )}
            </span>
            <div>
              <h3 className="chat-main-header-title">Диалог с {contactUserForDialog?.username || 'пользователем'}</h3>
              <p className="chat-main-header-subtitle">Личное сообщение</p>
            </div>
          </div>
          {userDialogMessages.length > 0 && (
            <button type="button" className="chat-clear-btn" onClick={handleClearDirectDialog} disabled={sending || userDialogLoading} title="Очистить диалог">
              Очистить
            </button>
          )}
        </div>
        <div className="chat-messages" onClick={handleMessagesAreaClick}>
        {userDialogLoading ? (
          <div className="chat-loading">
            <div className="skeleton-line" style={{ width: '70%', height: 14 }}></div>
            <div className="skeleton-line" style={{ width: '50%', height: 14 }}></div>
            <div className="skeleton-line" style={{ width: '60%', height: 14, marginLeft: 'auto' }}></div>
          </div>
        ) : userDialogMessages.length === 0 ? (
          <div className="chat-empty">
            <p>Напишите сообщение пользователю {contactUserForDialog?.username || 'пользователю'}. Оно будет доставлено в его чат.</p>
          </div>
        ) : (
          <>
            {userDialogMessages.map((msg) => {
              const isFromMe = msg.sender_type === 'user' && Number(msg.sender_id) === myUserId;
              const isFromOtherUser = msg.sender_type === 'user' && Number(msg.sender_id) !== myUserId;
              return (
                <div key={msg.id} className={`chat-message chat-message--${isFromMe ? 'user' : isFromOtherUser ? 'other-user' : msg.sender_type}`}>
                  {!isFromMe && (
                    <div className="chat-message-avatar chat-message-avatar--other">
                      {msg.sender_type === 'admin' ? (
                        <span className="chat-avatar-icon" aria-hidden><MailIcon size={20} /></span>
                      ) : msg.sender_avatar_path ? (
                        <img src={getAvatarSrc(msg.sender_avatar_path, api?.baseUrl || '/api', 'sm')} alt="" className="chat-avatar-img" />
                      ) : (
                        <span className="chat-avatar-initials">{msg.sender_username ? msg.sender_username.slice(0, 2).toUpperCase() : '?'}</span>
                      )}
                    </div>
                  )}
                  <div className="chat-message-bubble">
                    {isFromOtherUser && msg.sender_username && (
                      <div className="chat-message-sender-name">{msg.sender_username}</div>
                    )}
                    {msg.sender_type === 'admin' && (
                      <div className="chat-message-sender-name">Администрация</div>
                    )}
                    <div className="chat-message-content">{msg.content || ''}</div>
                    {msg.created_at && <div className="chat-message-time">{formatChatTime(msg.created_at, userTimezone)}</div>}
                  </div>
                  {isFromMe && (
                    <div className="chat-message-avatar chat-message-avatar--user">
                      {user?.avatar_path ? (
                        <img src={getAvatarSrc(user.avatar_path, api?.baseUrl || '/api', 'sm')} alt="" className="chat-avatar-img" />
                      ) : (
                        <span className="chat-avatar-initials">{user?.username ? user.username.slice(0, 2).toUpperCase() : '?'}</span>
                      )}
                    </div>
                  )}
                </div>
              );
            })}
            <div ref={messagesEndRef} />
          </>
        )}
        </div>
      </div>
      )}
      {error && (
        <div className="chat-error" role="alert">
          {error}
          <button type="button" onClick={() => setError(null)} aria-label="Закрыть">
            <CloseIcon className="modal-close-icon" />
          </button>
        </div>
      )}
      {!contactUserLoading && (
      <form className="chat-input-form" onSubmit={handleSubmit}>
        <input
          type="text"
          className="chat-input"
          placeholder={Number(contactUserForDialog?.id) === myUserId ? 'Нельзя написать себе' : `Напишите ${contactUserForDialog?.username || 'пользователю'}...`}
          value={input}
          onChange={(e) => setInput(e.target.value)}
          disabled={sending || userDialogLoading || Number(contactUserForDialog?.id) === myUserId}
          maxLength={4000}
        />
        <button type="submit" className="chat-send-btn" disabled={sending || userDialogLoading || !input.trim() || Number(contactUserForDialog?.id) === myUserId} title={sending ? 'Отправка…' : 'Отправить'}>
          {sending ? '…' : '➤'}
        </button>
      </form>
      )}
    </>
  ) : selectedChat ? (
    <>
      <div ref={scrollAreaRef} className="chat-main-scroll-area">
        <div className="chat-main-header">
          <button type="button" className="chat-back-btn" onClick={handleBackToList} aria-label="Назад к списку чатов">←</button>
          <div className="chat-main-header-info">
            <span className="chat-main-header-icon" aria-hidden="true">{currentChat?.Icon && <currentChat.Icon size={20} />}</span>
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
        <div className="chat-messages" onClick={handleMessagesAreaClick}>
        {loading ? (
          <div className="chat-loading">
            <div className="skeleton-line" style={{ width: '70%', height: 14 }}></div>
            <div className="skeleton-line" style={{ width: '45%', height: 14 }}></div>
            <div className="skeleton-line" style={{ width: '60%', height: 14, marginLeft: 'auto' }}></div>
          </div>
        ) : messages.length === 0 ? (
          <div className="chat-empty">
            {isAdminChat ? (
              <>
                <p>Сообщений от администрации пока нет.</p>
                {contactUserSlugFromState && (
                  <p className="chat-empty-hint">Хотите связаться с пользователем @{contactUserSlugFromState}? Опишите это в сообщении ниже — администрация передаст вашу просьбу.</p>
                )}
              </>
            ) : (
              <>
                <div className="chat-empty-icon"><BotIcon size={40} /></div>
                <p className="chat-empty-title">AI-тренер</p>
                <p className="chat-empty-hint">Спросите о плане, тренировках или попросите скорректировать расписание</p>
                <div className="chat-suggested-prompts">
                  {SUGGESTED_PROMPTS.map((prompt) => (
                    <button
                      key={prompt.text}
                      type="button"
                      className="chat-suggested-btn"
                      onClick={() => sendDirect(prompt.text)}
                    >
                      {prompt.text}
                    </button>
                  ))}
                </div>
              </>
            )}
          </div>
        ) : (
          <>
            {messages.map((msg) => {
              const isFromMe = msg.sender_type === 'user' && Number(msg.sender_id) === myUserId;
              const isFromOtherUser = msg.sender_type === 'user' && Number(msg.sender_id) !== myUserId;
              return (
              <div key={msg.id} data-message-id={msg.id} className={`chat-message chat-message--${isFromMe ? 'user' : isFromOtherUser ? 'other-user' : msg.sender_type}`}>
                {!isFromMe && (
                  <div className="chat-message-avatar chat-message-avatar--other">
                    {msg.sender_type === 'ai' ? (
                      <span className="chat-avatar-icon" aria-hidden><BotIcon size={20} /></span>
                    ) : isFromOtherUser && (msg.sender_avatar_path || msg.sender_username) ? (
                      msg.sender_avatar_path ? (
                        <img src={getAvatarSrc(msg.sender_avatar_path, api?.baseUrl || '/api', 'sm')} alt="" className="chat-avatar-img" />
                      ) : (
                        <span className="chat-avatar-initials">{msg.sender_username ? msg.sender_username.slice(0, 2).toUpperCase() : '?'}</span>
                      )
                    ) : (
                      <span className="chat-avatar-icon" aria-hidden><MailIcon size={20} /></span>
                    )}
                  </div>
                )}
                <div className="chat-message-bubble">
                  {isFromOtherUser && msg.sender_username && (
                    <div className="chat-message-sender-name">{msg.sender_username}</div>
                  )}
                  <div className="chat-message-content">
                    {msg.content ? (
                      msg.content
                    ) : streamPhase && msg.id?.startsWith('temp-ai-') ? (
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
                  {msg.created_at && <div className="chat-message-time">{formatChatTime(msg.created_at, userTimezone)}</div>}
                </div>
                {isFromMe && (
                  <div className="chat-message-avatar chat-message-avatar--user">
                    {user?.avatar_path ? (
                      <img src={getAvatarSrc(user.avatar_path, api?.baseUrl || '/api', 'sm')} alt="" className="chat-avatar-img" />
                    ) : (
                      <span className="chat-avatar-initials">{user?.username ? user.username.slice(0, 2).toUpperCase() : '?'}</span>
                    )}
                  </div>
                )}
              </div>
            );
            })}
            {isAiChat && !streamPhase && !sending && messages.length > 0 && (() => {
              const lastMsg = messages[messages.length - 1];
              if (lastMsg?.sender_type !== 'ai' || !lastMsg?.content) return null;
              const replies = getQuickReplies(lastMsg.content);
              if (!replies.length) return null;
              return (
                <div className="chat-quick-replies">
                  {replies.map((text) => (
                    <button
                      key={text}
                      type="button"
                      className="chat-quick-reply-btn"
                      onClick={() => sendDirect(text)}
                    >
                      {text}
                    </button>
                  ))}
                </div>
              );
            })()}
            <div ref={messagesEndRef} />
          </>
        )}
        </div>
      </div>
      {error && (
        <div className="chat-error" role="alert">
          {error}
          <button type="button" onClick={() => setError(null)} aria-label="Закрыть">
            <CloseIcon className="modal-close-icon" />
          </button>
        </div>
      )}
      {(recalcMessage || nextPlanMessage) && isAiChat && (
        <div className="chat-info" role="status">
          {recalcMessage || nextPlanMessage}
          <button type="button" onClick={() => { setRecalcMessage(null); setNextPlanMessage(null); }} aria-label="Закрыть">
            <CloseIcon className="modal-close-icon" />
          </button>
        </div>
      )}
      <form className="chat-input-form" onSubmit={handleSubmit}>
        <input
          type="text"
          className="chat-input"
          placeholder="Напишите сообщение..."
          value={input}
          onChange={(e) => setInput(e.target.value)}
          disabled={sending || loading || !!streamPhase}
          maxLength={4000}
        />
        <button type="submit" className="chat-send-btn" disabled={sending || loading || !!streamPhase || !input.trim()} title={sending || streamPhase ? 'Отправка…' : 'Отправить'}>
          {sending || streamPhase ? '…' : '➤'}
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
