import { useCallback, useRef, useState } from 'react';
import { TAB_ADMIN_MODE, TAB_USER_DIALOG } from './chatConstants';

export function useChatMessageLists(api, selectedChat, loadDirectDialogs) {
  const [messages, setMessages] = useState([]);
  const [conversationId, setConversationId] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const [userDialogMessages, setUserDialogMessages] = useState([]);
  const [userDialogLoading, setUserDialogLoading] = useState(false);
  const [chatAdminMessages, setChatAdminMessages] = useState([]);
  const [chatAdminLoading, setChatAdminLoading] = useState(false);

  const selectedChatRef = useRef(selectedChat);
  selectedChatRef.current = selectedChat;

  const loadMessages = useCallback(async () => {
    if (!api || selectedChat === TAB_ADMIN_MODE || selectedChat === TAB_USER_DIALOG || selectedChat?.startsWith?.('dialog_')) {
      setLoading(false);
      return;
    }

    const loadingFor = selectedChat;
    const loadTimeoutMs = 15000;
    let timeoutId = null;

    try {
      setLoading(true);
      setError(null);
      const dataPromise = api.chatGetMessages(loadingFor, 50, 0);
      const timeoutPromise = new Promise((_, reject) => {
        timeoutId = window.setTimeout(() => reject(new Error('timeout')), loadTimeoutMs);
      });
      const data = await Promise.race([dataPromise, timeoutPromise]);
      if (timeoutId) clearTimeout(timeoutId);
      if (loadingFor !== selectedChatRef.current) return;
      const list = Array.isArray(data?.messages) ? data.messages : [];
      setMessages([...list].reverse());
      if (data?.conversation_id) setConversationId(data.conversation_id);
    } catch (error) {
      if (timeoutId) clearTimeout(timeoutId);
      if (loadingFor !== selectedChatRef.current) return;
      setError(error?.message === 'timeout'
        ? 'Загрузка сообщений заняла слишком много времени. Проверьте интернет.'
        : (error?.message || 'Ошибка загрузки сообщений'));
      setMessages([]);
    } finally {
      if (loadingFor === selectedChatRef.current) setLoading(false);
    }
  }, [api, selectedChat]);

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

  return {
    messages,
    setMessages,
    conversationId,
    setConversationId,
    loading,
    setLoading,
    error,
    setError,
    userDialogMessages,
    setUserDialogMessages,
    userDialogLoading,
    chatAdminMessages,
    setChatAdminMessages,
    chatAdminLoading,
    loadMessages,
    loadUserDialogMessages,
    loadChatAdminMessages,
  };
}
