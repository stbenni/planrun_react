/**
 * Экран чата — классический двухколоночный layout
 * Для пользователей: AI-тренер, От администрации
 * Для админов: + вкладка «Администраторский» — ответы пользователям
 */

import { useState, useEffect, useRef, useCallback, useLayoutEffect, useMemo, lazy, Suspense } from 'react';
import useAuthStore from '../stores/useAuthStore';
import { useChatUnread } from '../hooks/useChatUnread';
import { useIsTabActive } from '../hooks/useIsTabActive';
import { ChatSSE } from '../services/ChatSSE';
import { getAvatarSrc } from '../utils/avatarUrl';
import { useChatDirectories } from './chat/useChatDirectories';
import { useChatMessageLists } from './chat/useChatMessageLists';
import { useChatSubmitHandlers } from './chat/useChatSubmitHandlers';
import { useChatNavigation } from './chat/useChatNavigation';
import ChatComposer from './chat/ChatComposer';
import { formatChatTime } from './chat/chatTime';
import { BotIcon, MailIcon, TAB_ADMIN, TAB_ADMIN_MODE, TAB_AI, UsersIcon } from './chat/chatConstants';
import { CloseIcon, ThumbsUpIcon, ThumbsDownIcon, SearchIcon, EllipsisVerticalIcon } from '../components/common/Icons';
import { getQuickReplies, SUGGESTED_PROMPTS } from './chat/chatQuickReplies';
import { getProactiveTypeLabel } from '../utils/proactiveMessages';
import './ChatScreen.css';

const ReactMarkdown = lazy(() => import('react-markdown'));

const TOOL_LABELS = {
  get_training_day: 'Смотрю план…',
  get_week_plan: 'Проверяю неделю…',
  get_plan_overview: 'Анализирую план…',
  get_plan: 'Загружаю план…',
  get_day_details: 'Смотрю день…',
  get_workouts: 'Загружаю тренировки…',
  get_stats: 'Загружаю статистику…',
  get_profile: 'Смотрю профиль…',
  get_training_load: 'Считаю нагрузку…',
  race_prediction: 'Прогнозирую результат…',
  update_training_day: 'Обновляю тренировку…',
  add_training_day: 'Добавляю тренировку…',
  delete_training_day: 'Удаляю тренировку…',
  swap_training_days: 'Меняю дни местами…',
  move_training_day: 'Перемещаю тренировку…',
  copy_day: 'Копирую тренировку…',
  log_workout: 'Записываю результат…',
  recalculate_plan: 'Пересчитываю план…',
  generate_next_plan: 'Генерирую новый план…',
  get_date: 'Проверяю дату…',
  analyze_workout: 'Анализирую тренировку…',
  get_training_trends: 'Загружаю тренды…',
  compare_periods: 'Сравниваю периоды…',
  get_weekly_review: 'Обзор недели…',
  get_goal_progress: 'Проверяю прогресс…',
  get_race_strategy: 'Готовлю стратегию…',
  explain_plan_logic: 'Объясняю план…',
  report_health_issue: 'Обрабатываю…',
  update_profile: 'Обновляю профиль…',
};
const getToolLabel = (name) => TOOL_LABELS[name] || 'Работаю…';

const FEEDBACK_KEY = 'planrun_chat_feedback';
function getFeedback(msgId) {
  try { const data = JSON.parse(localStorage.getItem(FEEDBACK_KEY) || '{}'); return data[msgId] || null; } catch { return null; }
}
function setFeedbackStorage(msgId, value) {
  try {
    const data = JSON.parse(localStorage.getItem(FEEDBACK_KEY) || '{}');
    data[msgId] = value;
    localStorage.setItem(FEEDBACK_KEY, JSON.stringify(data));
  } catch { /* ignore */ }
}

function AiMessageContent({ content }) {
  if (!content) return null;
  const hasMarkdown = /[*_#`|>-]{2,}|\[[^\]]+\]|\n[-*] |\n\d+\. |\n#{1,3} /.test(content);
  if (!hasMarkdown) return content;
  return (
    <Suspense fallback={content}>
      <ReactMarkdown
        components={{
          p: ({ children }) => <p>{children}</p>,
          a: ({ href, children }) => <a href={href} target="_blank" rel="noopener noreferrer">{children}</a>,
        }}
      >
        {content}
      </ReactMarkdown>
    </Suspense>
  );
}

function MessageFeedback({ msgId }) {
  const [feedback, setFeedback] = useState(() => getFeedback(msgId));
  const handleFeedback = useCallback((val) => {
    const next = feedback === val ? null : val;
    setFeedback(next);
    setFeedbackStorage(msgId, next);
  }, [feedback, msgId]);
  return (
    <span className="chat-feedback">
      <button type="button" className={`chat-feedback-btn${feedback === 'up' ? ' chat-feedback-btn--active' : ''}`} onClick={() => handleFeedback('up')} aria-label="Полезно" title="Полезно">
        <ThumbsUpIcon size={14} />
      </button>
      <button type="button" className={`chat-feedback-btn${feedback === 'down' ? ' chat-feedback-btn--active' : ''}`} onClick={() => handleFeedback('down')} aria-label="Не полезно" title="Не полезно">
        <ThumbsDownIcon size={14} />
      </button>
    </span>
  );
}

function ChatHeaderOverflowMenu({ items, label = 'Действия чата' }) {
  const [open, setOpen] = useState(false);
  const triggerRef = useRef(null);
  const menuRef = useRef(null);
  const visibleItems = items.filter(Boolean);

  useEffect(() => {
    if (!open) return undefined;

    const handleClickOutside = (event) => {
      const target = event.target;
      if (menuRef.current?.contains(target) || triggerRef.current?.contains(target)) return;
      setOpen(false);
    };

    const handleEscape = (event) => {
      if (event.key === 'Escape') {
        setOpen(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    document.addEventListener('touchstart', handleClickOutside, { passive: true });
    document.addEventListener('keydown', handleEscape);

    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
      document.removeEventListener('touchstart', handleClickOutside);
      document.removeEventListener('keydown', handleEscape);
    };
  }, [open]);

  if (!visibleItems.length) return null;

  const handleSelect = (item) => {
    if (item.disabled) return;
    setOpen(false);
    item.onSelect?.();
  };

  return (
    <div className={`chat-header-menu${open ? ' is-open' : ''}`}>
      <button
        ref={triggerRef}
        type="button"
        className="chat-header-action-btn chat-header-menu-trigger"
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label={label}
        title={label}
        onClick={() => setOpen((prev) => !prev)}
      >
        <EllipsisVerticalIcon size={18} />
      </button>
      {open && (
        <div ref={menuRef} className="chat-header-menu-dropdown" role="menu" aria-label={label}>
          {visibleItems.map((item) => (
            <button
              key={item.id}
              type="button"
              role="menuitem"
              className={`chat-header-menu-item${item.danger ? ' chat-header-menu-item--danger' : ''}`}
              onClick={() => handleSelect(item)}
              disabled={item.disabled}
            >
              {item.label}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}

const ChatScreen = () => {
  const AUTO_SCROLL_BOTTOM_THRESHOLD = 80;
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
  const [sending, setSending] = useState(false);
  const [streamPhase, setStreamPhase] = useState(null);
  const [recalcMessage, setRecalcMessage] = useState(null);
  const [nextPlanMessage, setNextPlanMessage] = useState(null);
  const [chatAdminSending, setChatAdminSending] = useState(false);
  const [isPinnedToBottom, setIsPinnedToBottom] = useState(true);
  const [searchQuery, setSearchQuery] = useState('');
  const [searchOpen, setSearchOpen] = useState(false);

  const composerRef = useRef(null);
  const messagesEndRef = useRef(null);
  const messagesContainerRef = useRef(null);
  const scrollAreaRef = useRef(null);
  const isMountedRef = useRef(true);
  const isChatTabVisibleRef = useRef(isTabActive);
  isChatTabVisibleRef.current = isTabActive;
  const streamAbortRef = useRef(null);
  const notificationTimersRef = useRef([]);
  const scrollRafRef = useRef(0);
  const prevMessagesLenRef = useRef(0);
  const prevSelectedChatRef = useRef(null);
  const prevTabActiveRef = useRef(isTabActive);
  const shouldStickToBottomRef = useRef(true);
  const forceScrollOnNextChangeRef = useRef(false);
  const writeInputValue = useCallback((nextValue, { moveCaretToEnd = false } = {}) => {
    composerRef.current?.setValue?.(nextValue, { moveCaretToEnd });
  }, []);
  const clearInputValue = useCallback(() => {
    composerRef.current?.clear?.();
  }, []);
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
    contextFromUrl,
    contextDateFromUrl,
  } = useChatNavigation({
    api,
    chatUsers,
    chatUsersLoading,
    directDialogs,
    isAdmin,
    userTimezone,
  });
  const prevMobileListVisibleRef = useRef(mobileListVisible);
  const isComposerVisible = isTabActive && !mobileListVisible;
  const isUserDialogPending = isUserDialog && !contactUserForDialog;
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
  const activeMessages = isAdminMode
    ? chatAdminMessages
    : isUserDialog
      ? userDialogMessages
      : messages;
  const isMobileConversationView = !mobileListVisible && (!isAdminMode || Boolean(selectedChatUser));
  const handleBeforeSend = useCallback(() => {
    forceScrollOnNextChangeRef.current = true;
    shouldStickToBottomRef.current = true;
    setIsPinnedToBottom(true);
  }, []);
  const {
    handleSubmit,
    sendDirect,
    handleAdminChatSend,
    handleClearAiChat,
    handleClearAdminDialog,
    handleClearDirectDialog,
    handleClearAdminConversation,
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
    clearInputValue,
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
    loadChatUsers,
    loadChatAdminMessages,
    onBeforeSend: handleBeforeSend,
  });
  const hasSearchQuery = searchQuery.trim().length > 0;
  const toggleSearch = useCallback(() => {
    setSearchOpen((prev) => !prev);
  }, []);
  const filteredActiveMessages = useMemo(() => {
    if (!hasSearchQuery) return activeMessages;
    const normalizedQuery = searchQuery.toLowerCase();
    return activeMessages.filter((message) => (message.content || '').toLowerCase().includes(normalizedQuery));
  }, [activeMessages, hasSearchQuery, searchQuery]);
  const visibleMessages = hasSearchQuery ? filteredActiveMessages : activeMessages;
  const hasSearchResults = visibleMessages.length > 0;
  const hasSearchMiss = hasSearchQuery && activeMessages.length > 0 && !hasSearchResults;
  const commonSearchMenuItem = {
    id: 'toggle-search',
    label: searchOpen ? 'Закрыть поиск' : 'Поиск по сообщениям',
    onSelect: toggleSearch,
  };
  const adminModeHeaderMenuItems = selectedChatUser?.id ? [
    commonSearchMenuItem,
    {
      id: 'clear-admin-thread',
      label: 'Очистить диалог',
      onSelect: handleClearAdminConversation,
      disabled: chatAdminLoading || chatAdminMessages.length === 0,
      danger: true,
    },
  ] : [];
  const directDialogHeaderMenuItems = [
    commonSearchMenuItem,
    {
      id: 'clear-direct-dialog',
      label: 'Очистить диалог',
      onSelect: handleClearDirectDialog,
      disabled: sending || userDialogLoading || userDialogMessages.length === 0,
      danger: true,
    },
  ];
  const aiHeaderMenuItems = [
    commonSearchMenuItem,
    {
      id: 'clear-ai-chat',
      label: 'Очистить диалог',
      onSelect: handleClearAiChat,
      disabled: sending || messages.length === 0,
      danger: true,
    },
  ];
  const adminHeaderMenuItems = [
    commonSearchMenuItem,
    {
      id: 'clear-admin-chat',
      label: 'Очистить диалог',
      onSelect: handleClearAdminDialog,
      disabled: loading || messages.length === 0,
      danger: true,
    },
  ];

  const renderHeaderActions = useCallback((clearHandler, clearDisabled, clearTitle = 'Очистить диалог') => (
    <div className="chat-header-actions">
      <button type="button" className="chat-header-action-btn" onClick={toggleSearch} title="Поиск по сообщениям" aria-label="Поиск по сообщениям">
        <SearchIcon size={18} />
      </button>
      <button type="button" className="chat-clear-btn" onClick={clearHandler} disabled={clearDisabled} title={clearTitle}>
        Очистить диалог
      </button>
    </div>
  ), [toggleSearch]);

  const renderSearchBar = useCallback(() => {
    if (!searchOpen) return null;

    return (
      <div className="chat-search-bar">
        <SearchIcon size={16} />
        <input
          type="text"
          className="chat-search-input"
          placeholder="Поиск по сообщениям…"
          value={searchQuery}
          onChange={(event) => setSearchQuery(event.target.value)}
          autoFocus
        />
        {hasSearchQuery && (
          <span className="chat-search-count">{visibleMessages.length} из {activeMessages.length}</span>
        )}
        <button type="button" className="chat-search-close" onClick={() => { setSearchOpen(false); setSearchQuery(''); }} aria-label="Закрыть поиск">
          <CloseIcon size={16} />
        </button>
      </div>
    );
  }, [activeMessages.length, hasSearchQuery, searchOpen, searchQuery, visibleMessages.length]);

  useEffect(() => {
    clearInputValue();
    setError(null);
    setSearchQuery('');
    setSearchOpen(false);
    shouldStickToBottomRef.current = true;
    forceScrollOnNextChangeRef.current = false;
    setIsPinnedToBottom(true);
    streamAbortRef.current?.abort();
    setStreamPhase(null);
  }, [clearInputValue, selectedChat, setError]);

  useEffect(() => {
    document.body.classList.toggle('chat-conversation-active', isMobileConversationView);

    return () => {
      document.body.classList.remove('chat-conversation-active');
    };
  }, [isMobileConversationView]);

  useEffect(() => {
    if (mobileListVisible && searchOpen) {
      setSearchOpen(false);
      setSearchQuery('');
    }
  }, [mobileListVisible, searchOpen]);

  const cleanupPendingAsync = useCallback(() => {
    cancelAnimationFrame(scrollRafRef.current);
    scrollRafRef.current = 0;
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

  const isNearBottom = useCallback(() => {
    const el = scrollAreaRef.current;
    if (!el) return true;

    const remainingDistance = el.scrollHeight - el.scrollTop - el.clientHeight;
    return remainingDistance <= AUTO_SCROLL_BOTTOM_THRESHOLD;
  }, [AUTO_SCROLL_BOTTOM_THRESHOLD]);

  const updateShouldStickToBottom = useCallback(() => {
    const nextPinnedState = isNearBottom();
    shouldStickToBottomRef.current = nextPinnedState;
    setIsPinnedToBottom((prevState) => (prevState === nextPinnedState ? prevState : nextPinnedState));
  }, [isNearBottom]);

  const scrollToBottom = useCallback((behavior = 'auto', { force = false } = {}) => {
    if (!force && !shouldStickToBottomRef.current) return;

    shouldStickToBottomRef.current = true;
    setIsPinnedToBottom(true);
    cancelAnimationFrame(scrollRafRef.current);

    const scrollScrollAreaToLatest = (scrollBehavior) => {
      const el = scrollAreaRef.current;
      if (el) {
        const targetTop = Math.max(0, el.scrollHeight - el.clientHeight);
        el.scrollTo({ top: targetTop, behavior: scrollBehavior });
        return true;
      }

      const endMarker = messagesEndRef.current;
      if (endMarker) {
        endMarker.scrollIntoView({ behavior: scrollBehavior, block: 'end', inline: 'nearest' });
        return true;
      }

      return false;
    };

    scrollRafRef.current = requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        const didScroll = scrollScrollAreaToLatest(behavior);
        if (didScroll && behavior === 'auto') {
          requestAnimationFrame(() => {
            scrollScrollAreaToLatest('auto');
          });
        }
      });
    });
  }, []);

  const handleScrollAreaScroll = useCallback(() => {
    updateShouldStickToBottom();
  }, [updateShouldStickToBottom]);

  const handleJumpToLatest = useCallback(() => {
    forceScrollOnNextChangeRef.current = false;
    scrollToBottom('smooth', { force: true });
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

  useLayoutEffect(() => {
    if (scrollToMessageId && messages.length > 0 && selectedChat === TAB_ADMIN) {
      shouldStickToBottomRef.current = false;
      setIsPinnedToBottom(false);
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
      const shouldForceScroll = selectedChat !== prevSelectedChatRef.current || forceScrollOnNextChangeRef.current;
      scrollToBottom('auto', { force: shouldForceScroll });
      forceScrollOnNextChangeRef.current = false;
    }
    prevSelectedChatRef.current = selectedChat;
    prevMessagesLenRef.current = messages.length;
  }, [messages.length, scrollToBottom, selectedChat, scrollToMessageId]);

  useLayoutEffect(() => {
    const wasTabActive = prevTabActiveRef.current;
    prevTabActiveRef.current = isTabActive;

    if (!isTabActive || wasTabActive || mobileListVisible) return;
    if (scrollToMessageId && selectedChat === TAB_ADMIN) return;

    scrollToBottom('auto', { force: true });
  }, [isTabActive, mobileListVisible, scrollToBottom, scrollToMessageId, selectedChat]);

  useLayoutEffect(() => {
    const wasMobileListVisible = prevMobileListVisibleRef.current;
    prevMobileListVisibleRef.current = mobileListVisible;

    if (!isTabActive || mobileListVisible || !wasMobileListVisible) return;
    if (scrollToMessageId && selectedChat === TAB_ADMIN) return;

    scrollToBottom('auto', { force: true });
  }, [isTabActive, mobileListVisible, scrollToBottom, scrollToMessageId, selectedChat]);

  useLayoutEffect(() => {
    if (isUserDialog && userDialogMessages.length > 0) {
      scrollToBottom('auto', { force: forceScrollOnNextChangeRef.current });
      forceScrollOnNextChangeRef.current = false;
    }
  }, [isUserDialog, contactUserForDialog?.id, userDialogMessages.length, scrollToBottom]);

  useLayoutEffect(() => {
    if (isAdminMode && selectedChatUser?.id && chatAdminMessages.length > 0) {
      scrollToBottom('auto', { force: forceScrollOnNextChangeRef.current });
      forceScrollOnNextChangeRef.current = false;
    }
  }, [isAdminMode, selectedChatUser?.id, chatAdminMessages.length, scrollToBottom]);

  useEffect(() => {
    if (typeof ResizeObserver === 'undefined') return undefined;

    const messagesEl = messagesContainerRef.current;
    if (!messagesEl) return undefined;

    let resizeRafId = 0;
    const scheduleAutoScroll = () => {
      cancelAnimationFrame(resizeRafId);
      resizeRafId = requestAnimationFrame(() => {
        if (document.body.classList.contains('chat-keyboard-settling')) {
          return;
        }

        if (shouldStickToBottomRef.current) {
          scrollToBottom();
        }
      });
    };

    const resizeObserver = new ResizeObserver(() => {
      scheduleAutoScroll();
    });

    resizeObserver.observe(messagesEl);

    return () => {
      cancelAnimationFrame(resizeRafId);
      resizeObserver.disconnect();
    };
  }, [
    isAdminMode,
    isUserDialog,
    scrollToBottom,
    selectedChat,
    selectedChatUser?.id,
    contactUserForDialog?.id,
  ]);

  // Auto-send contextual message from DayModal / WorkoutDetailsModal
  const contextHandledRef = useRef(false);
  useEffect(() => {
    if (!contextFromUrl || !contextDateFromUrl || !isAiChat || !api || contextHandledRef.current || !isTabActive) return;
    if (sending || messages.length === 0) return;
    contextHandledRef.current = true;
    const msg = contextFromUrl === 'workout'
      ? `Как прошла тренировка ${contextDateFromUrl}?`
      : `Расскажи о тренировке на ${contextDateFromUrl}`;
    writeInputValue(msg, { moveCaretToEnd: true });
  }, [contextFromUrl, contextDateFromUrl, isAiChat, api, isTabActive, sending, messages.length, writeInputValue]);

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
        <div ref={scrollAreaRef} className="chat-main-scroll-area" onScroll={handleScrollAreaScroll}>
          <div className="chat-main-header">
            <button type="button" className="chat-back-btn" onClick={handleBackToList} aria-label="Назад к списку">
              ←
            </button>
            <div className="chat-main-header-info">
              <span className="chat-main-header-icon" aria-hidden="true"><UsersIcon size={20} /></span>
              <div>
                <h3 className="chat-main-header-title">Чат с {selectedChatUser.username}</h3>
              </div>
            </div>
            {isMobileConversationView ? (
              <ChatHeaderOverflowMenu items={adminModeHeaderMenuItems} />
            ) : (
              renderHeaderActions(handleClearAdminConversation, chatAdminLoading || chatAdminMessages.length === 0)
            )}
          </div>
          {renderSearchBar()}
          <div ref={messagesContainerRef} className="chat-messages">
          {chatAdminLoading ? (
            <div className="chat-loading">
              <div className="skeleton-line" style={{ width: '70%', height: 14 }}></div>
              <div className="skeleton-line" style={{ width: '50%', height: 14 }}></div>
              <div className="skeleton-line" style={{ width: '60%', height: 14, marginLeft: 'auto' }}></div>
            </div>
          ) : chatAdminMessages.length === 0 ? (
            <div className="chat-empty">Сообщений пока нет. Напишите первым.</div>
          ) : hasSearchMiss ? (
            <div className="chat-empty">
              <p>Ничего не найдено.</p>
              <p className="chat-empty-hint">Попробуйте изменить запрос.</p>
            </div>
          ) : (
            <>
              {visibleMessages.map((msg) => (
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
        <ChatComposer
          ref={composerRef}
          placeholder="Напишите сообщение..."
          disabled={chatAdminSending || chatAdminLoading}
          submitting={chatAdminSending}
          visible={isComposerVisible}
          onSubmitText={handleAdminChatSend}
        />
      </>
    ) : (
      <div className="chat-select-prompt">
        <p>Выберите пользователя для просмотра чата</p>
      </div>
    )
  ) : isUserDialog ? (
    <>
      {(contactUserLoading || isUserDialogPending) && (
        <div className="chat-loading" style={{ padding: 'var(--space-4)' }}>
          <div className="skeleton-line" style={{ width: '60%', height: 14 }}></div>
          <div className="skeleton-line" style={{ width: '40%', height: 14 }}></div>
        </div>
      )}
      {!contactUserLoading && !isUserDialogPending && Number(contactUserForDialog?.id) === myUserId && (
        <div className="chat-error" role="alert">
          Вы не можете написать себе. Перейдите в другой чат.
        </div>
      )}
      {!contactUserLoading && !isUserDialogPending && (
      <div ref={scrollAreaRef} className="chat-main-scroll-area" onScroll={handleScrollAreaScroll}>
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
            </div>
          </div>
          {isMobileConversationView ? (
            <ChatHeaderOverflowMenu items={directDialogHeaderMenuItems} />
          ) : (
            renderHeaderActions(handleClearDirectDialog, sending || userDialogLoading || userDialogMessages.length === 0)
          )}
        </div>
        {renderSearchBar()}
        <div ref={messagesContainerRef} className="chat-messages">
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
        ) : hasSearchMiss ? (
          <div className="chat-empty">
            <p>Ничего не найдено.</p>
            <p className="chat-empty-hint">Попробуйте изменить запрос.</p>
          </div>
        ) : (
          <>
            {visibleMessages.map((msg) => {
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
      {!contactUserLoading && !isUserDialogPending && (
      <ChatComposer
        ref={composerRef}
        placeholder={Number(contactUserForDialog?.id) === myUserId ? 'Нельзя написать себе' : `Напишите ${contactUserForDialog?.username || 'пользователю'}...`}
        disabled={sending || userDialogLoading || Number(contactUserForDialog?.id) === myUserId}
        submitting={sending}
        visible={isComposerVisible}
        onSubmitText={handleSubmit}
      />
      )}
    </>
  ) : selectedChat ? (
    <>
      <div ref={scrollAreaRef} className="chat-main-scroll-area" onScroll={handleScrollAreaScroll}>
        <div className="chat-main-header">
          <button type="button" className="chat-back-btn" onClick={handleBackToList} aria-label="Назад к списку чатов">←</button>
          <div className="chat-main-header-info">
            <span className="chat-main-header-icon" aria-hidden="true">{currentChat?.Icon && <currentChat.Icon size={20} />}</span>
            <div>
              <h3 className="chat-main-header-title">{currentChat?.label}</h3>
            </div>
          </div>
          {isAiChat && !isMobileConversationView && renderHeaderActions(handleClearAiChat, sending || messages.length === 0)}
          {isAdminChat && !isMobileConversationView && renderHeaderActions(handleClearAdminDialog, loading || messages.length === 0)}
          {isMobileConversationView && (isAiChat || isAdminChat) && (
            <ChatHeaderOverflowMenu items={isAiChat ? aiHeaderMenuItems : adminHeaderMenuItems} />
          )}
        </div>
        {renderSearchBar()}
        <div ref={messagesContainerRef} className="chat-messages">
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
                <p className="chat-empty-title">ИИ-тренер</p>
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
        ) : hasSearchMiss ? (
          <div className="chat-empty">
            <p>Ничего не найдено.</p>
            <p className="chat-empty-hint">Попробуйте изменить запрос.</p>
          </div>
        ) : (
          <>
            {visibleMessages.map((msg) => {
              const isFromMe = msg.sender_type === 'user' && Number(msg.sender_id) === myUserId;
              const isFromOtherUser = msg.sender_type === 'user' && Number(msg.sender_id) !== myUserId;
              const msgMeta = typeof msg.metadata === 'string' ? (() => { try { return JSON.parse(msg.metadata); } catch { return null; } })() : msg.metadata;
              const isProactive = msgMeta?.proactive_type;
              return (
              <div key={msg.id} data-message-id={msg.id} className={`chat-message chat-message--${isFromMe ? 'user' : isFromOtherUser ? 'other-user' : msg.sender_type}${isProactive ? ' chat-message--proactive' : ''}`}>
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
                  {isProactive && (
                    <div className="chat-proactive-label">{getProactiveTypeLabel(msgMeta?.proactive_type, 'Тренер обратил внимание')}</div>
                  )}
                  {isFromOtherUser && msg.sender_username && (
                    <div className="chat-message-sender-name">{msg.sender_username}</div>
                  )}
                  <div className={`chat-message-content${msg.sender_type === 'ai' && msg.content ? ' chat-message-content--md' : ''}`}>
                    {msg.content ? (
                      msg.sender_type === 'ai' ? <AiMessageContent content={msg.content} /> : msg.content
                    ) : streamPhase && String(msg.id ?? '').startsWith('temp-ai-') ? (
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
                        {streamPhase?.startsWith('tool:') && (
                          <span className="chat-tool-indicator">
                            <span className="chat-tool-spinner" aria-hidden="true" />
                            {getToolLabel(streamPhase.slice(5))}
                          </span>
                        )}
                        {!streamPhase && (
                          <span className="chat-message-error-text">Ошибка</span>
                        )}
                      </span>
                    ) : (
                      '…'
                    )}
                  </div>
                  <div className="chat-message-footer">
                    {msg.created_at && <div className="chat-message-time">{formatChatTime(msg.created_at, userTimezone)}</div>}
                    {msg.sender_type === 'ai' && msg.content && !String(msg.id ?? '').startsWith('temp-ai-') && (
                      <MessageFeedback msgId={msg.id} />
                    )}
                  </div>
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
            {isAiChat && !hasSearchQuery && !streamPhase && !sending && messages.length > 0 && (() => {
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
      <ChatComposer
        ref={composerRef}
        placeholder="Напишите сообщение..."
        disabled={sending || loading}
        submitting={sending}
        showStopButton={Boolean(streamPhase)}
        visible={isComposerVisible}
        onStop={() => streamAbortRef.current?.abort()}
        onSubmitText={handleSubmit}
      />
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
          {!mobileListVisible && !isPinnedToBottom && activeMessages.length > 0 && (
            <button
              type="button"
              className="chat-jump-latest-btn"
              onClick={handleJumpToLatest}
              aria-label="Прокрутить к последним сообщениям"
            >
              <span aria-hidden="true">↓</span>
              <span>К последним</span>
            </button>
          )}
        </main>
      </div>
    </div>
  );
};

export default ChatScreen;
