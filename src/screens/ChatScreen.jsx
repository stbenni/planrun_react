/**
 * Экран чата — классический двухколоночный layout
 * Для пользователей: AI-тренер, От администрации
 * Для админов: + вкладка «Администраторский» — ответы пользователям
 */

import React, { useState, useEffect, useRef, useCallback } from 'react';
import { useLocation, useSearchParams } from 'react-router-dom';
import useAuthStore from '../stores/useAuthStore';
import usePlanStore from '../stores/usePlanStore';
import { useChatUnread } from '../hooks/useChatUnread';
import { useIsTabActive } from '../hooks/useIsTabActive';
import { ChatSSE } from '../services/ChatSSE';
import { getAvatarSrc } from '../utils/avatarUrl';
import SkeletonScreen from '../components/common/SkeletonScreen';
import { MessageCircle } from 'lucide-react';
import { BotIcon, MailIcon, UsersIcon } from '../components/common/Icons';
import './ChatScreen.css';

const TAB_AI = 'ai';
const TAB_ADMIN = 'admin';
const TAB_ADMIN_MODE = 'admin_mode';
const TAB_USER_DIALOG = 'user_dialog';
const dialogId = (userId) => `dialog_${userId}`;

const SYSTEM_CHATS = [
  { id: TAB_AI, label: 'AI-тренер', Icon: BotIcon, description: 'Персональные рекомендации по тренировкам' },
  { id: TAB_ADMIN, label: 'От администрации', Icon: MailIcon, description: 'Сообщения от администрации сайта' },
];

const ADMIN_CHAT = { id: TAB_ADMIN_MODE, label: 'Администраторский', Icon: UsersIcon, description: 'Сообщения от пользователей' };

const ChatScreen = () => {
  const isTabActive = useIsTabActive('/chat');
  const location = useLocation();
  const { api, user } = useAuthStore();
  const userTimezone = user?.timezone || (typeof Intl !== 'undefined' && Intl.DateTimeFormat?.().resolvedOptions?.().timeZone) || 'Europe/Moscow';
  const { total: unreadTotal = 0, by_type: unreadByType = {} } = useChatUnread();
  const adminUnreadCount = unreadByType.admin_mode ?? 0;
  const adminTabUnreadCount = unreadByType.admin ?? 0;
  const myUserId = Number(user?.user_id ?? user?.id) || 0;
  const isAdmin = user?.role === 'admin';

  const openAdminModeFromState = location.state?.openAdminMode === true;
  const selectedUserIdFromState = location.state?.selectedUserId;
  const selectedUsernameFromState = location.state?.selectedUsername;
  const selectedUserEmailFromState = location.state?.selectedUserEmail;
  const openAdminTabFromState = location.state?.openAdminTab === true;
  const contactUserSlugFromState = location.state?.contactUserSlug;
  const contactUserFromState = location.state?.contactUser;
  const scrollToMessageId = location.state?.messageId;
  const [searchParams, setSearchParams] = useSearchParams();
  const contactSlugFromUrl = searchParams.get('contact');

  const [contactUser, setContactUser] = useState(() => contactUserFromState ?? null);
  const [contactUserLoading, setContactUserLoading] = useState(false);

  useEffect(() => {
    if (contactUserFromState) {
      setContactUser(contactUserFromState);
      return;
    }
    if (!contactSlugFromUrl || !api) {
      if (!contactUserFromState) setContactUser(null);
      return;
    }
    setContactUserLoading(true);
    api.getUserBySlug(contactSlugFromUrl)
      .then((data) => {
        const u = data?.data?.user ?? data?.user ?? data;
        if (u?.id) {
          setContactUser({
            id: u.id,
            username: u.username ?? u.username_slug ?? contactSlugFromUrl,
            username_slug: u.username_slug ?? contactSlugFromUrl,
            avatar_path: u.avatar_path,
          });
        } else {
          setContactUser(null);
        }
      })
      .catch(() => setContactUser(null))
      .finally(() => setContactUserLoading(false));
  }, [contactUserFromState, contactSlugFromUrl, api]);

  useEffect(() => {
    if (!contactUserLoading && contactSlugFromUrl && !contactUser) {
      setSelectedChat(TAB_AI);
      setSearchParams((prev) => {
        const next = new URLSearchParams(prev);
        next.delete('contact');
        return next;
      }, { replace: true });
    }
  }, [contactUserLoading, contactSlugFromUrl, contactUser]);

  const [directDialogs, setDirectDialogs] = useState([]);
  const [directDialogsLoading, setDirectDialogsLoading] = useState(false);

  const contactUnreadCount = contactUser ? (directDialogs.find((d) => Number(d.user_id) === Number(contactUser.id))?.unread_count ?? 0) : 0;
  const userDialogChat = (contactUser || contactSlugFromUrl)
    ? { id: contactUser ? dialogId(contactUser.id) : TAB_USER_DIALOG, label: `Диалог с ${contactUser?.username || 'пользователем'}`, Icon: MessageCircle, description: 'Персональное сообщение', user: contactUser, unreadCount: contactUnreadCount }
    : null;
  const directDialogChats = directDialogs.map((u) => ({
    id: dialogId(u.user_id),
    label: `Диалог с ${u.username || 'пользователем'}`,
    Icon: MessageCircle,
    description: u.last_message_at ? `Последнее: ${new Date(u.last_message_at).toLocaleString('ru-RU', { timeZone: userTimezone, day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })}` : '',
    user: { id: u.user_id, username: u.username, username_slug: u.username_slug, avatar_path: u.avatar_path },
    unreadCount: u.unread_count ?? 0,
  }));
  const hasContactInDialogs = contactUser && directDialogs.some((d) => Number(d.user_id) === Number(contactUser.id));
  const personalChats = [...SYSTEM_CHATS, ...directDialogChats, ...(userDialogChat && !hasContactInDialogs ? [userDialogChat] : [])];
  const chats = isAdmin
    ? [...personalChats, ADMIN_CHAT]
    : personalChats;

  // Для админов: вкладки Личный | Администраторский
  const [adminSection, setAdminSection] = useState(() => (openAdminModeFromState ? 'admin_mode' : 'personal'));

  const [selectedChat, setSelectedChat] = useState(() => {
    if (openAdminModeFromState) return TAB_ADMIN_MODE;
    if (contactUserFromState) return dialogId(contactUserFromState.id);
    if (contactSlugFromUrl) return TAB_USER_DIALOG;
    if (openAdminTabFromState) return TAB_ADMIN;
    if (location.state?.openAITab === true) return TAB_AI;
    return TAB_AI;
  });

  useEffect(() => {
    if (contactUser && selectedChat === TAB_USER_DIALOG) {
      setSelectedChat(dialogId(contactUser.id));
    }
  }, [contactUser, selectedChat]);
  const [messages, setMessages] = useState([]);
  const [conversationId, setConversationId] = useState(null);
  const [input, setInput] = useState('');
  const [loading, setLoading] = useState(false);
  const [sending, setSending] = useState(false);
  const [streamPhase, setStreamPhase] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    setInput('');
    setError(null);
    streamAbortRef.current?.abort();
    setStreamPhase(null);
  }, [selectedChat]);
  const [recalcMessage, setRecalcMessage] = useState(null);
  const [nextPlanMessage, setNextPlanMessage] = useState(null);
  const [mobileListVisible, setMobileListVisible] = useState(!openAdminModeFromState && !openAdminTabFromState && !contactUserFromState && !contactSlugFromUrl);

  // Сообщения в диалоге с пользователем (загружаются с сервера)
  const [userDialogMessages, setUserDialogMessages] = useState([]);
  const [userDialogLoading, setUserDialogLoading] = useState(false);

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
  const streamAbortRef = useRef(null);
  const notificationTimersRef = useRef([]);
  const prevMessagesLenRef = useRef(0);
  useEffect(() => {
    isMountedRef.current = true;
    return () => {
      isMountedRef.current = false;
      streamAbortRef.current?.abort();
      notificationTimersRef.current.forEach(clearTimeout);
    };
  }, []);

  const scrollToBottom = () => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  };

  const loadMessages = useCallback(async () => {
    if (!api || selectedChat === TAB_ADMIN_MODE || selectedChat === TAB_USER_DIALOG || selectedChat?.startsWith?.('dialog_')) {
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

  const loadDirectDialogs = useCallback(async () => {
    if (!api) return;
    setDirectDialogsLoading(true);
    try {
      const list = await api.chatGetDirectDialogs();
      setDirectDialogs(Array.isArray(list) ? list : []);
    } catch {
      setDirectDialogs([]);
    } finally {
      setDirectDialogsLoading(false);
    }
  }, [api]);

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

  const loadUserDialogMessages = useCallback(async (targetUserId) => {
    if (!api || !targetUserId) return;
    setUserDialogLoading(true);
    try {
      const res = await api.chatGetDirectMessages(targetUserId, 100, 0);
      const list = Array.isArray(res?.messages) ? res.messages : [];
      setUserDialogMessages([...list].reverse());
      loadDirectDialogs();
    } catch {
      setUserDialogMessages([]);
    } finally {
      setUserDialogLoading(false);
    }
  }, [api, loadDirectDialogs]);

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
    loadDirectDialogs();
  }, [loadDirectDialogs]);

  useEffect(() => {
    if (selectedChat === TAB_ADMIN_MODE && selectedChatUser?.id) {
      loadChatAdminMessages(selectedChatUser.id);
    } else {
      setChatAdminMessages([]);
    }
  }, [selectedChat, selectedChatUser?.id, loadChatAdminMessages]);

  const dialogUserId = selectedChat?.startsWith?.('dialog_') ? parseInt(selectedChat.replace('dialog_', ''), 10) : (selectedChat === TAB_USER_DIALOG && contactUser?.id) ? Number(contactUser.id) : null;
  const dialogUser = dialogUserId ? directDialogs.find((d) => Number(d.user_id) === dialogUserId) : null;
  const contactUserForDialog = dialogUserId
    ? (dialogUser ? { id: dialogUser.user_id, username: dialogUser.username, username_slug: dialogUser.username_slug, avatar_path: dialogUser.avatar_path } : Number(contactUser?.id) === dialogUserId ? contactUser : null)
    : contactUser;
  const isUserDialog = !!dialogUserId && !!contactUserForDialog;

  useEffect(() => {
    if (isUserDialog && contactUserForDialog?.id) {
      loadUserDialogMessages(contactUserForDialog.id);
    } else {
      setUserDialogMessages([]);
    }
  }, [selectedChat, contactUserForDialog?.id, loadUserDialogMessages]);

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

  // Загрузка/перезагрузка сообщений при смене вкладки или при возврате в чат
  useEffect(() => {
    if (!isTabActive || !api) return;
    loadMessages();
    if (selectedChat?.startsWith?.('dialog_') && contactUserForDialog?.id) loadUserDialogMessages(contactUserForDialog.id);
  }, [isTabActive, api, loadMessages, selectedChat, contactUserForDialog?.id, loadUserDialogMessages]);

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
      const el = document.querySelector(`[data-message-id="${scrollToMessageId}"]`);
      el?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    } else if (messages.length !== prevMessagesLenRef.current) {
      scrollToBottom();
    }
    prevMessagesLenRef.current = messages.length;
  }, [messages.length, selectedChat, scrollToMessageId]);

  useEffect(() => {
    if (isUserDialog && userDialogMessages.length > 0) scrollToBottom();
  }, [isUserDialog, userDialogMessages.length]);

  const handleSelectChat = (chatId) => {
    setSelectedChat(chatId);
    setMobileListVisible(false);
    if (chatId === TAB_ADMIN_MODE) {
      setAdminSection('admin_mode');
      setSelectedChatUser(null);
    } else if (chatId?.startsWith?.('dialog_')) {
      const chat = chats.find((c) => c.id === chatId);
      if (chat?.user) {
        setContactUser(chat.user);
        const slug = chat.user.username_slug || chat.user.username;
        if (slug) {
          setSearchParams((prev) => {
            const next = new URLSearchParams(prev);
            next.set('contact', slug);
            return next;
          }, { replace: true });
        }
      }
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
      sender_id: user?.user_id ?? user?.id,
      content,
      created_at: new Date().toISOString(),
    };
    if (selectedChat !== TAB_USER_DIALOG && !selectedChat?.startsWith?.('dialog_')) {
      setMessages((prev) => [...prev, userMsg]);
    }

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

    if (selectedChat?.startsWith?.('dialog_') && contactUserForDialog?.id) {
      if (Number(contactUserForDialog.id) === myUserId) {
        setError('Нельзя отправить сообщение самому себе');
        setSending(false);
        return;
      }
      setUserDialogMessages((prev) => [...prev, userMsg]);
      try {
        const res = await api.chatSendMessageToUser(contactUserForDialog.id, content);
        setUserDialogMessages((prev) =>
          prev.map((m) =>
            m.id === userMsg.id ? { ...m, id: res?.message_id ?? m.id } : m
          )
        );
        loadDirectDialogs();
      } catch (e) {
        setError(e.message || 'Ошибка отправки');
        setUserDialogMessages((prev) => prev.filter((m) => m.id !== userMsg.id));
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
    setSending(false);

    const abortController = new AbortController();
    streamAbortRef.current = abortController;

    let accumulated = '';
    let flushScheduled = false;
    const flushToState = () => {
      if (!isMountedRef.current || abortController.signal.aborted) return;
      const text = accumulated;
      setMessages((prev) => {
        const last = prev[prev.length - 1];
        if (last?.id === aiPlaceholder.id) {
          const next = prev.slice();
          next[next.length - 1] = { ...last, content: text };
          return next;
        }
        return prev;
      });
    };
    api.chatSendMessageStream(
      content,
      (chunk) => {
        if (!isMountedRef.current || abortController.signal.aborted) return;
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
        signal: abortController.signal,
        onFirstChunk: () => !abortController.signal.aborted && setStreamPhase('streaming'),
        onPlanUpdated: () => usePlanStore.getState().loadPlan(),
        onPlanRecalculating: () => {
          if (!abortController.signal.aborted) {
            setRecalcMessage('Пересчёт плана запущен. Обновите календарь через 3–5 минут.');
            const t = setTimeout(() => setRecalcMessage(null), 8000);
            notificationTimersRef.current.push(t);
          }
        },
        onPlanGeneratingNext: () => {
          if (!abortController.signal.aborted) {
            setNextPlanMessage('Новый план генерируется. Обновите календарь через 3–5 минут.');
            const t = setTimeout(() => setNextPlanMessage(null), 8000);
            notificationTimersRef.current.push(t);
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
        if (!isMountedRef.current || abortController.signal.aborted) return;
        setMessages((prev) =>
          prev.map((m) =>
            m.id === aiPlaceholder.id ? { ...m, sender_type: 'ai', content: fullContent } : m
          )
        );
        if (!fullContent) setError('AI не вернул ответ. Попробуйте ещё раз.');
      })
      .catch((e) => {
        if (e?.name === 'AbortError') return;
        if (isMountedRef.current) {
          setError(e?.message || 'Ошибка отправки');
          setMessages((prev) => prev.filter((m) => m.id !== aiPlaceholder.id));
        }
      })
      .finally(() => {
        if (isMountedRef.current) {
          setStreamPhase(null);
          if (streamAbortRef.current === abortController) streamAbortRef.current = null;
        }
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

  const handleClearDirectDialog = useCallback(async () => {
    if (!api || !contactUserForDialog?.id) return;
    if (!window.confirm(`Очистить диалог с ${contactUserForDialog.username || 'пользователем'}? Это действие нельзя отменить.`)) return;
    try {
      await api.chatClearDirectDialog(contactUserForDialog.id);
      setUserDialogMessages([]);
      setError(null);
      loadDirectDialogs();
    } catch (e) {
      setError(e.message || 'Не удалось очистить диалог');
    }
  }, [api, contactUserForDialog?.id, contactUserForDialog?.username, loadDirectDialogs]);

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
    const tzOpt = { timeZone: userTimezone };
    if (diff < 86400000) return d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', second: '2-digit', ...tzOpt });
    return d.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', ...tzOpt });
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
                  <img src={getAvatarSrc(chat.user.avatar_path, api?.baseUrl || '/api')} alt="" className="chat-list-item-avatar-img" />
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
                      <span className="chat-avatar-icon" aria-hidden><MailIcon size={20} /></span>
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
      <div className="chat-main-header">
        <button type="button" className="chat-back-btn" onClick={handleBackToList} aria-label="Назад к списку чатов">←</button>
        <div className="chat-main-header-info">
          <span className="chat-main-header-avatar" aria-hidden="true">
            {contactUserForDialog?.avatar_path ? (
              <img src={getAvatarSrc(contactUserForDialog.avatar_path, api?.baseUrl || '/api')} alt="" className="chat-header-avatar-img" />
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
      )}
      {!contactUserLoading && (
      <div className="chat-messages">
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
                        <img src={getAvatarSrc(msg.sender_avatar_path, api?.baseUrl || '/api')} alt="" className="chat-avatar-img" />
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
                    {msg.created_at && <div className="chat-message-time">{formatTime(msg.created_at)}</div>}
                  </div>
                  {isFromMe && (
                    <div className="chat-message-avatar chat-message-avatar--user">
                      {user?.avatar_path ? (
                        <img src={getAvatarSrc(user.avatar_path, api?.baseUrl || '/api')} alt="" className="chat-avatar-img" />
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
      )}
      {error && (
        <div className="chat-error" role="alert">
          {error}
          <button type="button" onClick={() => setError(null)} aria-label="Закрыть">×</button>
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
            {isAdminChat && contactUserSlugFromState && (
              <p className="chat-empty-hint">Хотите связаться с пользователем @{contactUserSlugFromState}? Опишите это в сообщении ниже — администрация передаст вашу просьбу.</p>
            )}
            {!isAdminChat && <p className="chat-empty-hint">Например: «Что лучше делать завтра: отдых или лёгкий бег?»</p>}
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
                        <img src={getAvatarSrc(msg.sender_avatar_path, api?.baseUrl || '/api')} alt="" className="chat-avatar-img" />
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
                  {msg.created_at && <div className="chat-message-time">{formatTime(msg.created_at)}</div>}
                </div>
                {isFromMe && (
                  <div className="chat-message-avatar chat-message-avatar--user">
                    {user?.avatar_path ? (
                      <img src={getAvatarSrc(user.avatar_path, api?.baseUrl || '/api')} alt="" className="chat-avatar-img" />
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
