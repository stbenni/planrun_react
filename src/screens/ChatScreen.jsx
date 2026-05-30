/**
 * Экран чата — классический двухколоночный layout
 * Для пользователей: AI-тренер, От администрации
 * Для админов: + вкладка «Администраторский» — ответы пользователям
 */

import { useState, useEffect, useRef, useCallback, useLayoutEffect, useMemo, Fragment } from 'react';
import { useNavigate } from 'react-router-dom';
import useAuthStore from '../stores/useAuthStore';
import usePlanStore from '../stores/usePlanStore';
import { getPlanDayForDate } from '../utils/calendarHelpers';
import { useChatUnread } from '../hooks/useChatUnread';
import { useIsTabActive } from '../hooks/useIsTabActive';
import { ChatSSE } from '../services/ChatSSE';
import { getAvatarSrc } from '../utils/avatarUrl';
import { useChatDirectories } from './chat/useChatDirectories';
import { useChatMessageLists } from './chat/useChatMessageLists';
import { useChatSubmitHandlers } from './chat/useChatSubmitHandlers';
import { useChatNavigation } from './chat/useChatNavigation';
import { formatChatTime } from './chat/chatTime';
import { MailIcon, TAB_ADMIN, TAB_ADMIN_MODE, TAB_AI, UsersIcon } from './chat/chatConstants';
import { CloseIcon, SendIcon, ImageIcon, MicIcon } from '../components/common/Icons';
import ChatEmojiPicker from '../components/common/ChatEmojiPicker';
import ChatComposerInput from '../components/common/ChatComposerInput';
import EmojiText from '../components/common/EmojiText';
import VoiceMessage from '../components/common/VoiceMessage';
import { useVoiceRecorder } from './chat/useVoiceRecorder';
import { getQuickReplies, SUGGESTED_PROMPTS } from './chat/chatQuickReplies';
import ToolResultCard from '../components/chat/ToolResultCard';
import CapabilitiesBanner from '../components/chat/CapabilitiesBanner';
import ChatHeaderMenu from '../components/chat/ChatHeaderMenu';
import './ChatScreen.css';

const TOOL_LABELS = {
  get_training_day: 'Смотрю план…',
  get_week_plan: 'Проверяю неделю…',
  get_plan_overview: 'Анализирую план…',
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
  get_user_profile: 'Смотрю профиль…',
  get_stats_summary: 'Загружаю статистику…',
  get_recent_workouts: 'Смотрю тренировки…',
  get_goal_info: 'Проверяю цель…',
  search_exercises: 'Ищу упражнения…',
};
const getToolLabel = (name) => TOOL_LABELS[name] || 'Работаю…';

const ChatAiStatus = ({ phase }) => {
  const currentPhase = phase;

  return (
    <span className="chat-message-status">
      {currentPhase === 'connecting' && (
        <span className="chat-typing-dots" aria-hidden="true">
          <span /><span /><span />
        </span>
      )}
      {(currentPhase === 'streaming' || currentPhase === 'pending') && (
        <>
          <span className="chat-typing-dots" aria-hidden="true">
            <span /><span /><span />
          </span>
          Печатает…
        </>
      )}
      {currentPhase?.startsWith?.('tool:') && (
        <span className="chat-tool-indicator">
          <span className="chat-tool-spinner" aria-hidden="true" />
          {getToolLabel(currentPhase.slice(5))}
        </span>
      )}
      {!currentPhase && (
        <span className="chat-message-error-text">Ошибка</span>
      )}
    </span>
  );
};

// Парсит вложение-картинку из metadata сообщения (приходит строкой JSON с сервера или объектом для оптимистичных).
function getMessageAttachment(msg) {
  let meta = msg?.metadata;
  if (!meta) return null;
  if (typeof meta === 'string') {
    try { meta = JSON.parse(meta); } catch { return null; }
  }
  const a = meta?.attachment;
  return a && (a.kind === 'image' || a.kind === 'audio') && a.file ? a : null;
}

// Список сработавших инструментов из metadata сообщения (персистится бэкендом
// либо прицепляется во время стрима) — для зелёной карточки-результата.
function getMessageToolsUsed(msg) {
  let meta = msg?.metadata;
  if (!meta) return null;
  if (typeof meta === 'string') {
    try { meta = JSON.parse(meta); } catch { return null; }
  }
  const t = meta?.tools_used;
  return Array.isArray(t) && t.length ? t : null;
}

function deriveChatKind(chat) {
  if (!chat) return 'dialog';
  if (chat.id === TAB_AI) return 'ai';
  if (chat.id === TAB_ADMIN) return 'admin';
  if (chat.id === TAB_ADMIN_MODE) return 'admin_mode';
  return 'dialog';
}

// Аватар сущности чата: AI-градиент, иконка администрации/пользователей, фото/инициалы юзера.
function ChatEntityAvatar({ chat, size = 44, avatarBase = '/api', withOnline = false }) {
  const kind = deriveChatKind(chat);
  const dim = { width: size, height: size };
  if (kind === 'ai') {
    return (
      <span className="chat-entity-avatar chat-entity-avatar--ai" style={dim} aria-hidden="true">
        AI
        {withOnline && <span className="chat-online-dot" />}
      </span>
    );
  }
  if (kind === 'admin' || kind === 'admin_mode') {
    return (
      <span className="chat-entity-avatar chat-entity-avatar--system" style={dim} aria-hidden="true">
        {kind === 'admin_mode' ? <UsersIcon size={Math.round(size * 0.46)} /> : <MailIcon size={Math.round(size * 0.46)} />}
      </span>
    );
  }
  const user = chat?.user;
  return (
    <span className="chat-entity-avatar chat-entity-avatar--user" style={dim} aria-hidden="true">
      {user?.avatar_path ? (
        <img src={getAvatarSrc(user.avatar_path, avatarBase, 'sm')} alt="" className="chat-entity-avatar__img" />
      ) : (
        <span className="chat-entity-avatar__initials">{user?.username ? user.username.slice(0, 2).toUpperCase() : '?'}</span>
      )}
    </span>
  );
}

function dayKeyOf(createdAt, tz) {
  if (!createdAt) return null;
  return new Date(createdAt).toLocaleDateString('en-CA', { timeZone: tz });
}

function formatDateSeparator(createdAt, tz) {
  if (!createdAt) return '';
  const d = new Date(createdAt);
  const now = new Date();
  const key = dayKeyOf(createdAt, tz);
  const todayKey = dayKeyOf(now.toISOString(), tz);
  const yest = new Date(now);
  yest.setDate(yest.getDate() - 1);
  const yestKey = dayKeyOf(yest.toISOString(), tz);
  if (key === todayKey) return 'СЕГОДНЯ';
  if (key === yestKey) return 'ВЧЕРА';
  return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', timeZone: tz }).toUpperCase();
}

const WORKOUT_TYPE_LABEL = {
  easy: 'Лёгкий бег',
  run: 'Бег',
  running: 'Бег',
  long: 'Длительный бег',
  'long-run': 'Длительный бег',
  tempo: 'Темповая тренировка',
  interval: 'Интервалы',
  fartlek: 'Фартлек',
  control: 'Контрольная тренировка',
  race: 'Забег',
  sbu: 'СБУ',
  strength: 'ОФП',
  ofp: 'ОФП',
  cross: 'Кросс-тренинг',
  cycling: 'Велотренировка',
  swimming: 'Плавание',
  walking: 'Ходьба',
  hiking: 'Поход',
};

const ChatScreen = () => {
  const AUTO_SCROLL_BOTTOM_THRESHOLD = 80;
  const isTabActive = useIsTabActive('/chat');
  const navigate = useNavigate();
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
  const [isPinnedToBottom, setIsPinnedToBottom] = useState(true);

  const inputRef = useRef(null);
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
  const prevAiPendingResponseRef = useRef(false);
  const prevTabActiveRef = useRef(isTabActive);
  const shouldStickToBottomRef = useRef(true);
  const forceScrollOnNextChangeRef = useRef(false);
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
  const prevMobileListVisibleRef = useRef(mobileListVisible);

  // План для контекст-стрипа в личных диалогах (сегодняшняя тренировка по плану)
  const plan = usePlanStore((s) => s.plan);
  const loadPlan = usePlanStore((s) => s.loadPlan);
  useEffect(() => {
    if (isUserDialog && !plan) loadPlan?.();
  }, [isUserDialog, plan, loadPlan]);
  const todayWorkout = useMemo(() => {
    if (!plan) return null;
    const todayStr = new Date().toLocaleDateString('en-CA', { timeZone: userTimezone });
    const day = getPlanDayForDate(todayStr, plan);
    const type = day?.type ? String(day.type).toLowerCase() : null;
    if (!type || type === 'rest') return null;
    return { label: WORKOUT_TYPE_LABEL[type] || 'Тренировка' };
  }, [plan, userTimezone]);

  const {
    messages,
    setMessages,
    conversationId,
    aiPendingResponse,
    setAiPendingResponse,
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

  // Контекстные quick-replies (AI) — пин-бар над композером
  const aiQuickReplies = useMemo(() => {
    if (!isAiChat || streamPhase || aiPendingResponse || sending || messages.length === 0) return [];
    const last = messages[messages.length - 1];
    if (last?.sender_type !== 'ai' || !last?.content) return [];
    return getQuickReplies(last.content);
  }, [isAiChat, streamPhase, aiPendingResponse, sending, messages]);

  // На мобиле в открытом диалоге прячем BottomNav, композер занимает низ (как в Telegram).
  // Класс снимается при выходе из списка/со вкладки чата — чтобы навбар вернулся на других экранах.
  useEffect(() => {
    document.body.classList.toggle('chat-conversation-open', isTabActive && !mobileListVisible);
    return () => document.body.classList.remove('chat-conversation-open');
  }, [isTabActive, mobileListVisible]);

  const handleBeforeSend = useCallback(() => {
    forceScrollOnNextChangeRef.current = true;
    shouldStickToBottomRef.current = true;
    setIsPinnedToBottom(true);
  }, []);
  const {
    sendContent,
    sendDirect,
    handleAdminChatSend,
    handleClearAiChat,
    handleClearAdminChat,
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
    inputRef,
    setInput,
    setSending,
    setChatAdminSending,
    setError,
    setMessages,
    setUserDialogMessages,
    setStreamPhase,
    setAiPendingResponse,
    streamAbortRef,
    isMountedRef,
    notificationTimersRef,
    setRecalcMessage,
    setNextPlanMessage,
    loadDirectDialogs,
    loadChatAdminMessages,
    onBeforeSend: handleBeforeSend,
  });

  useEffect(() => {
    setInput('');
    setError(null);
    shouldStickToBottomRef.current = true;
    forceScrollOnNextChangeRef.current = false;
    setIsPinnedToBottom(true);
    streamAbortRef.current?.abort();
    setStreamPhase(null);
    if (inputRef.current) {
      inputRef.current.value = '';
    }
    requestAnimationFrame(() => {
      if (inputRef.current) {
        inputRef.current.value = '';
      }
    });
  }, [selectedChat, setError]);

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
    scrollRafRef.current = requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        const endMarker = messagesEndRef.current;
        if (endMarker) {
          endMarker.scrollIntoView({ behavior, block: 'end', inline: 'nearest' });
          return;
        }

        const el = scrollAreaRef.current;
        if (el) {
          el.scrollTo({ top: el.scrollHeight, behavior });
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

  useEffect(() => {
    if (!isTabActive || !api || selectedChat !== TAB_AI || !aiPendingResponse || streamPhase) {
      return undefined;
    }

    const refresh = () => loadMessages({ silent: true });
    const firstRefreshId = window.setTimeout(refresh, 1200);
    const intervalId = window.setInterval(refresh, 3000);

    return () => {
      window.clearTimeout(firstRefreshId);
      window.clearInterval(intervalId);
    };
  }, [aiPendingResponse, api, isTabActive, loadMessages, selectedChat, streamPhase]);

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
    } else if (selectedChat !== prevSelectedChatRef.current || messages.length !== prevMessagesLenRef.current || aiPendingResponse !== prevAiPendingResponseRef.current) {
      const shouldForceScroll = selectedChat !== prevSelectedChatRef.current
        || forceScrollOnNextChangeRef.current
        || (aiPendingResponse && aiPendingResponse !== prevAiPendingResponseRef.current);
      scrollToBottom('auto', { force: shouldForceScroll });
      forceScrollOnNextChangeRef.current = false;
    }
    prevSelectedChatRef.current = selectedChat;
    prevMessagesLenRef.current = messages.length;
    prevAiPendingResponseRef.current = aiPendingResponse;
  }, [aiPendingResponse, messages.length, scrollToBottom, selectedChat, scrollToMessageId]);

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

    const scrollEl = scrollAreaRef.current;
    const messagesEl = messagesContainerRef.current;
    if (!scrollEl || !messagesEl) return undefined;

    let resizeRafId = 0;
    const scheduleAutoScroll = () => {
      cancelAnimationFrame(resizeRafId);
      resizeRafId = requestAnimationFrame(() => {
        if (shouldStickToBottomRef.current) {
          scrollToBottom();
        }
      });
    };

    const resizeObserver = new ResizeObserver(() => {
      scheduleAutoScroll();
    });

    resizeObserver.observe(scrollEl);
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

  // Вставка эмодзи: rich-инпут сам вставит <img> Apple-эмодзи в позицию курсора.
  const insertEmoji = useCallback((emoji) => {
    inputRef.current?.insertEmoji?.(emoji);
  }, []);

  // ── Фото-вложения (для человеческих чатов: поддержка + личный диалог) ──
  const [pendingImage, setPendingImage] = useState(null); // { file, previewUrl }
  const [imageUploading, setImageUploading] = useState(false);
  const [lightboxUrl, setLightboxUrl] = useState(null);
  const fileInputRef = useRef(null);

  const chatMediaUrl = useCallback((file) => {
    if (!file) return '';
    const base = api?.baseUrl || '/api';
    return `${base}/api_wrapper.php?action=get_chat_media&file=${encodeURIComponent(file)}`;
  }, [api]);

  const onPickImageFile = useCallback((e) => {
    const file = e.target.files?.[0];
    if (e.target) e.target.value = '';
    if (!file) return;
    if (!file.type.startsWith('image/')) { setError('Можно прикрепить только изображение'); return; }
    if (file.size > 8 * 1024 * 1024) { setError('Файл больше 8 МБ'); return; }
    setPendingImage((prev) => {
      if (prev?.previewUrl) URL.revokeObjectURL(prev.previewUrl);
      return { file, previewUrl: URL.createObjectURL(file) };
    });
  }, [setError]);

  const removePendingImage = useCallback(() => {
    setPendingImage((prev) => {
      if (prev?.previewUrl) URL.revokeObjectURL(prev.previewUrl);
      return null;
    });
  }, []);

  const handleComposerSubmit = useCallback(async (e) => {
    e.preventDefault();
    const text = (inputRef.current?.value || '').trim();
    if (!pendingImage && !text) return;
    let attachment = null;
    if (pendingImage) {
      setImageUploading(true);
      try {
        attachment = await api.uploadChatMedia(pendingImage.file);
      } catch (err) {
        setError(err?.message || 'Ошибка загрузки фото');
        setImageUploading(false);
        return;
      }
      setImageUploading(false);
      removePendingImage();
    }
    sendContent(text, attachment);
  }, [pendingImage, api, sendContent, setError, removePendingImage]);

  // Превью + кнопка вложения + скрытый file-input (для человеческих чатов)
  const composerAttach = (
    <>
      {pendingImage && (
        <div className="chat-attach-preview">
          <img src={pendingImage.previewUrl} alt="" className="chat-attach-preview__img" />
          {imageUploading && <span className="chat-attach-preview__uploading">Загрузка…</span>}
          <button type="button" className="chat-attach-preview__remove" onClick={removePendingImage} aria-label="Убрать фото">
            <CloseIcon size={14} />
          </button>
        </div>
      )}
      <input ref={fileInputRef} type="file" accept="image/*" hidden onChange={onPickImageFile} />
      <button
        type="button"
        className="chat-attach-btn"
        onClick={() => fileInputRef.current?.click()}
        aria-label="Прикрепить фото"
        disabled={imageUploading}
      >
        <ImageIcon size={20} />
      </button>
    </>
  );

  // ── Голосовые сообщения ──────────────────────────────────
  const voice = useVoiceRecorder();
  const [voiceUploading, setVoiceUploading] = useState(false);

  const handleStartVoice = useCallback(async () => {
    try {
      await voice.start();
    } catch (err) {
      setError(err?.message || 'Не удалось получить доступ к микрофону');
    }
  }, [voice, setError]);

  const handleCancelVoice = useCallback(() => voice.cancel(), [voice]);

  const handleSendVoice = useCallback(async () => {
    const res = await voice.stop();
    if (!res) return;
    setVoiceUploading(true);
    try {
      const uploaded = await api.uploadChatMedia(res.file);
      setVoiceUploading(false);
      sendContent('', { kind: 'audio', file: uploaded.file, duration: res.duration });
    } catch (err) {
      setError(err?.message || 'Ошибка отправки голосового');
      setVoiceUploading(false);
    }
  }, [voice, api, sendContent, setError]);

  const renderAttachment = useCallback((att) => {
    if (!att) return null;
    const url = chatMediaUrl(att.file);
    if (att.kind === 'audio') {
      return <VoiceMessage src={url} duration={att.duration || 0} />;
    }
    return (
      <img
        className="chat-message-image"
        src={url}
        alt=""
        loading="lazy"
        onClick={() => setLightboxUrl(url)}
      />
    );
  }, [chatMediaUrl]);

  const fmtRec = (s) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;

  // Полоса записи голосового (заменяет содержимое композера во время записи)
  const composerRecordingBar = (
    <div className="chat-recording">
      <button type="button" className="chat-recording__cancel" onClick={handleCancelVoice} aria-label="Отменить запись">
        <CloseIcon size={20} />
      </button>
      <span className="chat-recording__dot" aria-hidden />
      <span className="chat-recording__time">{fmtRec(voice.seconds)}</span>
      <span className="chat-recording__hint">Идёт запись…</span>
      <span className="chat-recording__spacer" />
      <button type="button" className="chat-send-btn" onClick={handleSendVoice} disabled={voiceUploading} aria-label="Отправить голосовое">
        {voiceUploading ? '…' : <SendIcon size={20} />}
      </button>
    </div>
  );

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
        {(isAdmin && adminSection === 'personal' ? personalChats : chats).map((chat) => {
          const badgeCount = chat.id === TAB_ADMIN
            ? adminTabUnreadCount
            : chat.id === TAB_ADMIN_MODE
              ? adminUnreadCount
              : (chat.unreadCount || 0);
          return (
          <button
            key={chat.id}
            type="button"
            className={`chat-list-item ${selectedChat === chat.id ? 'chat-list-item--active' : ''}`}
            onClick={() => handleSelectChat(chat.id)}
            aria-pressed={selectedChat === chat.id}
          >
            <ChatEntityAvatar chat={chat} size={44} avatarBase={api?.baseUrl || '/api'} withOnline={chat.id === TAB_AI} />
            <div className="chat-list-item-content">
              <div className="chat-list-item-row">
                <span className="chat-list-item-label">{chat.name || chat.label}</span>
                {chat.time && <span className="chat-list-item-time">{chat.time}</span>}
              </div>
              <div className="chat-list-item-row">
                <span className={`chat-list-item-desc${chat.id === TAB_AI ? ' chat-list-item-desc--ai' : ''}`}>{chat.description}</span>
                {badgeCount > 0 && (
                  <span className="chat-list-item-badge" aria-hidden="true">{badgeCount > 99 ? '99+' : badgeCount}</span>
                )}
              </div>
            </div>
          </button>
          );
        })}
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
          <div ref={messagesContainerRef} className="chat-messages">
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
              {chatAdminMessages.map((msg, idx) => {
                const prevMsg = chatAdminMessages[idx - 1];
                const showDate = msg.created_at && dayKeyOf(msg.created_at, userTimezone) !== dayKeyOf(prevMsg?.created_at, userTimezone);
                return (
                <Fragment key={msg.id}>
                  {showDate && <div className="chat-date-label">{formatDateSeparator(msg.created_at, userTimezone)}</div>}
                  <div className={`chat-message chat-message--${msg.sender_type} chat-admin-message`}>
                    <div className="chat-message-bubble">
                      <div className="chat-message-content">
                        {renderAttachment(getMessageAttachment(msg))}
                        {msg.content && <EmojiText text={msg.content} />}
                      </div>
                      {msg.created_at && <div className="chat-message-time">{formatChatTime(msg.created_at, userTimezone)}</div>}
                    </div>
                  </div>
                </Fragment>
              );
              })}
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
          <ChatComposerInput
            ref={inputRef}
            placeholder="Напишите сообщение..."
            onChange={setInput}
            disabled={chatAdminSending || chatAdminLoading}
          />
          <ChatEmojiPicker onPick={insertEmoji} />
          <button type="submit" className="chat-send-btn" disabled={chatAdminSending || chatAdminLoading || !input.trim()}>
            {chatAdminSending ? '…' : <SendIcon size={20} />}
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
      <div ref={scrollAreaRef} className="chat-main-scroll-area" onScroll={handleScrollAreaScroll}>
        <div className="chat-main-header">
          <button type="button" className="chat-back-btn" onClick={handleBackToList} aria-label="Назад к списку чатов">←</button>
          <div className="chat-main-header-info">
            <ChatEntityAvatar chat={{ user: contactUserForDialog }} size={40} avatarBase={api?.baseUrl || '/api'} />
            <div className="chat-main-header-text">
              <h3 className="chat-main-header-title">{contactUserForDialog?.username || 'Пользователь'}</h3>
              <p className="chat-main-header-subtitle">Личный диалог</p>
            </div>
          </div>
          <ChatHeaderMenu items={[{
            key: 'clear',
            label: 'Очистить чат',
            tone: 'danger',
            disabled: sending || userDialogLoading || userDialogMessages.length === 0,
            onClick: handleClearDirectDialog,
          }]} />
        </div>
        {todayWorkout && (
          <div className="chat-context-strip">
            <span className="chat-context-strip__bar" aria-hidden="true" />
            <div className="chat-context-strip__body">
              <div className="chat-context-strip__label">СЕГОДНЯ ПО ПЛАНУ</div>
              <div className="chat-context-strip__workout">{todayWorkout.label}</div>
            </div>
            <button type="button" className="chat-context-strip__btn" onClick={() => navigate('/calendar')}>Открыть →</button>
          </div>
        )}
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
        ) : (
          <>
            {userDialogMessages.map((msg, idx) => {
              const isFromMe = msg.sender_type === 'user' && Number(msg.sender_id) === myUserId;
              const isFromOtherUser = msg.sender_type === 'user' && Number(msg.sender_id) !== myUserId;
              const prevMsg = userDialogMessages[idx - 1];
              const showDate = msg.created_at && dayKeyOf(msg.created_at, userTimezone) !== dayKeyOf(prevMsg?.created_at, userTimezone);
              return (
                <Fragment key={msg.id}>
                  {showDate && <div className="chat-date-label">{formatDateSeparator(msg.created_at, userTimezone)}</div>}
                  <div className={`chat-message chat-message--${isFromMe ? 'user' : isFromOtherUser ? 'other-user' : msg.sender_type}`}>
                    <div className="chat-message-bubble">
                      {isFromOtherUser && msg.sender_username && (
                        <div className="chat-message-sender-name">{msg.sender_username}</div>
                      )}
                      {msg.sender_type === 'admin' && (
                        <div className="chat-message-sender-name">Администрация</div>
                      )}
                      <div className="chat-message-content">
                        {renderAttachment(getMessageAttachment(msg))}
                        {msg.content && <EmojiText text={msg.content} />}
                      </div>
                      {msg.created_at && <div className="chat-message-time">{formatChatTime(msg.created_at, userTimezone)}</div>}
                    </div>
                  </div>
                </Fragment>
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
      <form className="chat-input-form" onSubmit={handleComposerSubmit}>
        {voice.recording || voiceUploading ? composerRecordingBar : (
          <>
            {composerAttach}
            <ChatComposerInput
              ref={inputRef}
              placeholder={Number(contactUserForDialog?.id) === myUserId ? 'Нельзя написать себе' : `Напишите ${contactUserForDialog?.username || 'пользователю'}...`}
              onChange={setInput}
              disabled={sending || userDialogLoading || Number(contactUserForDialog?.id) === myUserId}
            />
            <ChatEmojiPicker onPick={insertEmoji} />
            {(input.trim() || pendingImage || Number(contactUserForDialog?.id) === myUserId) ? (
              <button type="submit" className="chat-send-btn" disabled={sending || imageUploading || userDialogLoading || (!input.trim() && !pendingImage) || Number(contactUserForDialog?.id) === myUserId} title={sending ? 'Отправка…' : 'Отправить'}>
                {sending || imageUploading ? '…' : <SendIcon size={20} />}
              </button>
            ) : (
              <button type="button" className="chat-mic-btn" onClick={handleStartVoice} aria-label="Записать голосовое">
                <MicIcon size={20} />
              </button>
            )}
          </>
        )}
      </form>
      )}
    </>
  ) : selectedChat ? (
    <>
      <div ref={scrollAreaRef} className="chat-main-scroll-area" onScroll={handleScrollAreaScroll}>
        <div className="chat-main-header">
          <button type="button" className="chat-back-btn" onClick={handleBackToList} aria-label="Назад к списку чатов">←</button>
          <div className="chat-main-header-info">
            <ChatEntityAvatar chat={currentChat} size={40} avatarBase={api?.baseUrl || '/api'} withOnline={isAiChat} />
            <div className="chat-main-header-text">
              <h3 className="chat-main-header-title">{currentChat?.label}</h3>
              {isAiChat ? (
                <p className="chat-main-header-status">
                  <span className="chat-online-dot chat-online-dot--inline" aria-hidden="true" />
                  Всегда онлайн · отвечает мгновенно
                </p>
              ) : (
                <p className="chat-main-header-subtitle">{currentChat?.description}</p>
              )}
            </div>
          </div>
          {(isAiChat || isAdminChat) && (
            <ChatHeaderMenu items={[{
              key: 'clear',
              label: 'Очистить чат',
              tone: 'danger',
              disabled: sending || loading || messages.length === 0,
              onClick: isAiChat ? handleClearAiChat : handleClearAdminChat,
            }]} />
          )}
        </div>
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
              <div className="chat-ai-empty">
                <div className="chat-ai-empty__badge" aria-hidden="true">AI</div>
                <h2 className="chat-ai-empty__title">AI-тренер на связи</h2>
                <p className="chat-ai-empty__subtitle">Спрашивай про тренировки, проси перенести или пересчитать план — отвечу мгновенно.</p>
                <div className="chat-suggested-cards">
                  {SUGGESTED_PROMPTS.map((prompt) => (
                    <button
                      key={prompt.text}
                      type="button"
                      className="chat-suggested-card"
                      onClick={() => sendDirect(prompt.text)}
                    >
                      {prompt.icon && <span className="chat-suggested-card__icon" aria-hidden="true">{prompt.icon}</span>}
                      <span className="chat-suggested-card__text">{prompt.text}</span>
                      <span className="chat-suggested-card__arrow" aria-hidden="true">→</span>
                    </button>
                  ))}
                </div>
              </div>
            )}
          </div>
        ) : (
          <>
            {isAiChat && <CapabilitiesBanner />}
            {messages.map((msg, idx) => {
              const isFromMe = msg.sender_type === 'user' && Number(msg.sender_id) === myUserId;
              const isFromOtherUser = msg.sender_type === 'user' && Number(msg.sender_id) !== myUserId;
              const msgMeta = typeof msg.metadata === 'string' ? (() => { try { return JSON.parse(msg.metadata); } catch { return null; } })() : msg.metadata;
              const isProactive = msgMeta?.proactive_type;
              const showingTool = !!streamPhase && streamPhase.startsWith('tool:') && msg.id?.startsWith?.('temp-ai-');
              const prevMsg = messages[idx - 1];
              const showDate = msg.created_at && dayKeyOf(msg.created_at, userTimezone) !== dayKeyOf(prevMsg?.created_at, userTimezone);
              return (
              <Fragment key={msg.id}>
                {showDate && <div className="chat-date-label">{formatDateSeparator(msg.created_at, userTimezone)}</div>}
                <div data-message-id={msg.id} className={`chat-message chat-message--${isFromMe ? 'user' : isFromOtherUser ? 'other-user' : msg.sender_type}${isProactive ? ' chat-message--proactive' : ''}`}>
                  <div className={`chat-message-bubble${showingTool ? ' chat-message-bubble--tool' : ''}`}>
                    {isProactive && (
                      <div className="chat-proactive-label">Тренер обратил внимание</div>
                    )}
                    {isFromOtherUser && msg.sender_username && (
                      <div className="chat-message-sender-name">{msg.sender_username}</div>
                    )}
                    <div className="chat-message-content">
                      {renderAttachment(getMessageAttachment(msg))}
                      {msg.content ? (
                        <EmojiText text={msg.content} />
                      ) : getMessageAttachment(msg) ? null : streamPhase && msg.id?.startsWith('temp-ai-') ? (
                        <ChatAiStatus phase={streamPhase} />
                      ) : (
                        '…'
                      )}
                    </div>
                    {getMessageToolsUsed(msg) && (
                      <ToolResultCard tools={getMessageToolsUsed(msg)} onOpen={() => navigate('/calendar')} />
                    )}
                    {msg.created_at && <div className="chat-message-time">{formatChatTime(msg.created_at, userTimezone)}</div>}
                  </div>
                </div>
              </Fragment>
            );
            })}
            {isAiChat && aiPendingResponse && !streamPhase && (
              <div data-message-id="pending-ai-response" className="chat-message chat-message--ai chat-message--pending">
                <div className="chat-message-bubble">
                  <div className="chat-message-content">
                    <ChatAiStatus phase="pending" />
                  </div>
                </div>
              </div>
            )}
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
      {aiQuickReplies.length > 0 && (
        <div className="chat-quick-replies chat-quick-replies--bar">
          {aiQuickReplies.map((text) => (
            <button key={text} type="button" className="chat-quick-reply-btn" onClick={() => sendDirect(text)}>
              {text}
            </button>
          ))}
        </div>
      )}
      <form className="chat-input-form" onSubmit={handleComposerSubmit}>
        {(voice.recording || voiceUploading) && !isAiChat ? composerRecordingBar : (
          <>
            {composerAttach}
            <ChatComposerInput
              ref={inputRef}
              placeholder={isAiChat ? 'Спроси что угодно про тренировки…' : 'Напишите сообщение...'}
              onChange={setInput}
              disabled={sending || loading || !!streamPhase || aiPendingResponse}
            />
            <ChatEmojiPicker onPick={insertEmoji} />
            {(isAiChat || input.trim() || pendingImage) ? (
              <button type="submit" className="chat-send-btn" disabled={sending || loading || !!streamPhase || aiPendingResponse || (!input.trim() && !pendingImage)} title={sending || streamPhase || aiPendingResponse ? 'Отправка…' : 'Отправить'}>
                {sending || streamPhase || aiPendingResponse || imageUploading ? '…' : <SendIcon size={20} />}
              </button>
            ) : (
              <button type="button" className="chat-mic-btn" onClick={handleStartVoice} aria-label="Записать голосовое">
                <MicIcon size={20} />
              </button>
            )}
          </>
        )}
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
      {lightboxUrl && (
        <div className="chat-lightbox" onClick={() => setLightboxUrl(null)} role="dialog" aria-label="Просмотр фото">
          <button type="button" className="chat-lightbox__close" onClick={() => setLightboxUrl(null)} aria-label="Закрыть">
            <CloseIcon size={22} />
          </button>
          <img className="chat-lightbox__img" src={lightboxUrl} alt="" onClick={(e) => e.stopPropagation()} />
        </div>
      )}
    </div>
  );
};

export default ChatScreen;
