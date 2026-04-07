import { useCallback, useRef } from 'react';
import usePlanStore from '../../stores/usePlanStore';
import { ChatSSE } from '../../services/ChatSSE';
import { TAB_ADMIN, TAB_USER_DIALOG } from './chatConstants';

export function useChatSubmitHandlers({
  api,
  user,
  myUserId,
  selectedChat,
  contactUserForDialog,
  selectedChatUser,
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
  onBeforeSend,
}) {
  // Ref чтобы sendContent не зависел от sending/input (избегаем stale closure)
  const sendingRef = useRef(false);

  /**
   * Ядро отправки — принимает текст напрямую.
   * Используется и handleSubmit, и sendDirect.
   */
  const sendContent = useCallback(async (content) => {
    if (!content || !api || sendingRef.current) return;

    onBeforeSend?.();
    sendingRef.current = true;
    clearInputValue();
    setSending(true);
    setError(null);

    const userMsg = {
      id: `temp-${Date.now()}`,
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
        setMessages((prev) => prev.map((message) => (
          message.id === userMsg.id ? { ...message, id: res?.message_id ?? message.id } : message
        )));
      } catch (error) {
        setError(error.message || 'Ошибка отправки');
        setMessages((prev) => prev.filter((message) => message.id !== userMsg.id));
      } finally {
        setSending(false);
        sendingRef.current = false;
      }
      return;
    }

    if (selectedChat?.startsWith?.('dialog_') && contactUserForDialog?.id) {
      if (Number(contactUserForDialog.id) === myUserId) {
        setError('Нельзя отправить сообщение самому себе');
        setSending(false);
        sendingRef.current = false;
        return;
      }
      setUserDialogMessages((prev) => [...prev, userMsg]);
      try {
        const res = await api.chatSendMessageToUser(contactUserForDialog.id, content);
        setUserDialogMessages((prev) => prev.map((message) => (
          message.id === userMsg.id ? { ...message, id: res?.message_id ?? message.id } : message
        )));
        loadDirectDialogs();
      } catch (error) {
        setError(error.message || 'Ошибка отправки');
        setUserDialogMessages((prev) => prev.filter((message) => message.id !== userMsg.id));
      } finally {
        setSending(false);
        sendingRef.current = false;
      }
      return;
    }

    const aiPlaceholder = {
      id: `temp-ai-${Date.now()}`,
      sender_type: 'ai',
      content: '',
      created_at: null,
    };
    setMessages((prev) => [...prev, aiPlaceholder]);
    setStreamPhase('connecting');
    setSending(false);
    sendingRef.current = false;

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
        onToolExecuting: (toolName) => {
          if (!abortController.signal.aborted) setStreamPhase(`tool:${toolName}`);
        },
        onPlanUpdated: () => usePlanStore.getState().loadPlan(),
        onPlanRecalculating: () => {
          if (!abortController.signal.aborted) {
            setRecalcMessage('Пересчёт плана запущен. Обновите календарь через 3–5 минут.');
            const timer = setTimeout(() => setRecalcMessage(null), 8000);
            notificationTimersRef.current.push(timer);
          }
        },
        onPlanGeneratingNext: () => {
          if (!abortController.signal.aborted) {
            setNextPlanMessage('Новый план генерируется. Обновите календарь через 3–5 минут.');
            const timer = setTimeout(() => setNextPlanMessage(null), 8000);
            notificationTimersRef.current.push(timer);
          }
        },
        timeoutMs: 180000,
      }
    )
      .then((fullContent) => {
        if (!fullContent) {
          return api.chatSendMessage(content).then((result) => result?.content ?? '');
        }
        return fullContent;
      })
      .then((fullContent) => {
        if (!isMountedRef.current || abortController.signal.aborted) return;
        setMessages((prev) => prev.map((message) => (
          message.id === aiPlaceholder.id ? { ...message, sender_type: 'ai', content: fullContent } : message
        )));
        if (!fullContent) setError('ИИ не вернул ответ. Попробуйте ещё раз.');
      })
      .catch((error) => {
        if (error?.name === 'AbortError') return;
        if (isMountedRef.current) {
          setError(error?.message || 'Ошибка отправки');
          setMessages((prev) => prev.filter((message) => message.id !== aiPlaceholder.id));
        }
      })
      .finally(() => {
        if (isMountedRef.current) {
          setStreamPhase(null);
          if (streamAbortRef.current === abortController) {
            streamAbortRef.current = null;
          }
        }
      });
  }, [
    api,
    contactUserForDialog,
    isMountedRef,
    loadDirectDialogs,
    myUserId,
    notificationTimersRef,
    onBeforeSend,
    selectedChat,
    clearInputValue,
    setError,
    setMessages,
    setNextPlanMessage,
    setRecalcMessage,
    setSending,
    setStreamPhase,
    setUserDialogMessages,
    streamAbortRef,
    user,
  ]);

  /** Submit from composer or imperative send */
  const handleSubmit = useCallback((rawContent) => {
    const content = String(rawContent || '').trim();
    if (content) sendContent(content);
  }, [sendContent]);

  /** Прямая отправка по клику (quick-reply pills, suggested prompts) */
  const sendDirect = useCallback((text) => {
    const content = text?.trim();
    if (content) sendContent(content);
  }, [sendContent]);

  const handleAdminChatSend = useCallback(async (rawContent) => {
    const content = String(rawContent || '').trim();
    if (!api || !selectedChatUser || !content || chatAdminSending) return;
    onBeforeSend?.();
    clearInputValue();
    setChatAdminSending(true);
    setError(null);
    try {
      await api.chatAdminSendMessage(selectedChatUser.id, content);
      await loadChatAdminMessages(selectedChatUser.id);
    } catch (error) {
      setError(error.message || 'Ошибка отправки');
    } finally {
      setChatAdminSending(false);
    }
  }, [
    api,
    chatAdminSending,
    clearInputValue,
    loadChatAdminMessages,
    onBeforeSend,
    selectedChatUser,
    setChatAdminSending,
    setError,
  ]);

  const handleClearAiChat = useCallback(async () => {
    if (!api || !window.confirm('Очистить историю чата с ИИ? Это действие нельзя отменить.')) return;
    try {
      await api.chatClearAi();
      setMessages([]);
      setError(null);
    } catch (error) {
      setError(error.message || 'Не удалось очистить чат');
    }
  }, [api, setError, setMessages]);

  const handleClearAdminDialog = useCallback(async () => {
    if (!api || !window.confirm('Очистить диалог с администрацией? Это действие нельзя отменить.')) return;
    try {
      await api.chatClearAdminDialog();
      setMessages([]);
      setError(null);
    } catch (error) {
      setError(error.message || 'Не удалось очистить диалог');
    }
  }, [api, setError, setMessages]);

  const handleClearDirectDialog = useCallback(async () => {
    if (!api || !contactUserForDialog?.id) return;
    if (!window.confirm(`Очистить диалог с ${contactUserForDialog.username || 'пользователем'}? Это действие нельзя отменить.`)) return;
    try {
      await api.chatClearDirectDialog(contactUserForDialog.id);
      setUserDialogMessages([]);
      setError(null);
      loadDirectDialogs();
    } catch (error) {
      setError(error.message || 'Не удалось очистить диалог');
    }
  }, [api, contactUserForDialog, loadDirectDialogs, setError, setUserDialogMessages]);

  const handleClearAdminConversation = useCallback(async () => {
    if (!api || !selectedChatUser?.id) return;
    if (!window.confirm(`Очистить диалог с ${selectedChatUser.username || 'пользователем'}? Это действие нельзя отменить.`)) return;
    try {
      await api.chatAdminClearConversation(selectedChatUser.id);
      setChatAdminSending(false);
      await loadChatAdminMessages(selectedChatUser.id);
      await loadChatUsers?.();
      setError(null);
    } catch (error) {
      setError(error.message || 'Не удалось очистить диалог');
    }
  }, [api, loadChatAdminMessages, loadChatUsers, selectedChatUser, setChatAdminSending, setError]);

  const handleMarkAllRead = useCallback(async (isAdmin) => {
    if (!api) return;
    try {
      if (isAdmin) {
        await Promise.all([api.chatMarkAllRead(), api.chatAdminMarkAllRead()]);
      } else {
        await api.chatMarkAllRead();
      }
      ChatSSE.setUnreadData({ total: 0, by_type: {} });
    } catch (_error) {
      // Ignore mark-all-read failures and keep the current unread state.
    }
  }, [api]);

  return {
    handleSubmit,
    sendDirect,
    handleAdminChatSend,
    handleClearAiChat,
    handleClearAdminDialog,
    handleClearDirectDialog,
    handleClearAdminConversation,
    handleMarkAllRead,
  };
}
