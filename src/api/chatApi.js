import { ApiError } from './apiError';

export function chatGetMessages(client, type = 'ai', limit = 50, offset = 0) {
  return client.request('chat_get_messages', { type, limit, offset }, 'GET');
}

export function chatSendMessage(client, content) {
  return client.request('chat_send_message', { content: (content || '').trim() }, 'POST');
}

export async function chatSendMessageStream(client, content, onChunk, opts = {}) {
  const {
    onFirstChunk,
    onPlanUpdated,
    onPlanRecalculating,
    onPlanGeneratingNext,
    onToolExecuting,
    timeoutMs = 180000,
    signal: externalSignal,
  } = opts;

  const urlParams = new URLSearchParams({ action: 'chat_send_message_stream' });
  const url = `${client.baseUrl}/api_wrapper.php?${urlParams.toString()}`;
  const token = await client.getToken();
  const headers = { 'Content-Type': 'application/json' };
  if (token) headers.Authorization = `Bearer ${token}`;

  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), timeoutMs);
  const onExternalAbort = () => controller.abort();

  if (externalSignal) {
    if (externalSignal.aborted) {
      clearTimeout(timeoutId);
      throw new DOMException('Aborted', 'AbortError');
    }
    externalSignal.addEventListener('abort', onExternalAbort, { once: true });
  }

  try {
    const response = await fetch(url, {
      method: 'POST',
      headers,
      credentials: 'include',
      body: JSON.stringify({ content: (content || '').trim() }),
      signal: controller.signal,
    });

    if (!response.ok) {
      const err = await response.json().catch(() => ({}));
      throw new ApiError({ code: 'CHAT_FAILED', message: err.error || 'Ошибка чата' });
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    let firstChunkFired = false;
    let fullContent = '';

    try {
      while (true) {
        const { done, value } = await reader.read();
        if (done) break;
        buffer += decoder.decode(value, { stream: true });
        const lines = buffer.split('\n');
        buffer = lines.pop() || '';

        for (const line of lines) {
          const trimmed = line.trim();
          if (!trimmed) continue;

          try {
            const obj = JSON.parse(trimmed);
            if (obj.error) {
              throw new ApiError({ code: 'CHAT_FAILED', message: obj.error });
            }
            if (obj.chunk) {
              fullContent += obj.chunk;
              if (typeof onChunk === 'function') {
                if (!firstChunkFired && typeof onFirstChunk === 'function') {
                  firstChunkFired = true;
                  onFirstChunk();
                }
                onChunk(obj.chunk);
              }
            }
            if (obj.tool_executing && typeof onToolExecuting === 'function') onToolExecuting(obj.tool_executing);
            if (obj.plan_updated && typeof onPlanUpdated === 'function') onPlanUpdated();
            if (obj.plan_recalculating && typeof onPlanRecalculating === 'function') onPlanRecalculating();
            if (obj.plan_generating_next && typeof onPlanGeneratingNext === 'function') onPlanGeneratingNext();
          } catch (error) {
            if (error instanceof ApiError) throw error;
          }
        }
      }

      if (buffer.trim()) {
        const obj = JSON.parse(buffer.trim());
        if (obj.error) throw new ApiError({ code: 'CHAT_FAILED', message: obj.error });
        if (obj.chunk) {
          fullContent += obj.chunk;
          if (typeof onChunk === 'function') {
            if (!firstChunkFired && typeof onFirstChunk === 'function') onFirstChunk();
            onChunk(obj.chunk);
          }
        }
        if (obj.tool_executing && typeof onToolExecuting === 'function') onToolExecuting(obj.tool_executing);
        if (obj.plan_updated && typeof onPlanUpdated === 'function') onPlanUpdated();
        if (obj.plan_recalculating && typeof onPlanRecalculating === 'function') onPlanRecalculating();
        if (obj.plan_generating_next && typeof onPlanGeneratingNext === 'function') onPlanGeneratingNext();
      }

      return fullContent;
    } finally {
      reader.releaseLock?.();
    }
  } finally {
    clearTimeout(timeoutId);
    if (externalSignal) {
      externalSignal.removeEventListener('abort', onExternalAbort);
    }
  }
}

export function chatSendMessageToAdmin(client, content) {
  return client.request('chat_send_message_to_admin', { content: (content || '').trim() }, 'POST');
}

export async function chatGetDirectDialogs(client) {
  const res = await client.request('chat_get_direct_dialogs', {}, 'GET');
  return Array.isArray(res?.users) ? res.users : [];
}

export function chatGetDirectMessages(client, targetUserId, limit = 50, offset = 0) {
  return client.request('chat_get_direct_messages', { target_user_id: targetUserId, limit, offset }, 'GET');
}

export function chatSendMessageToUser(client, targetUserId, content) {
  return client.request('chat_send_message_to_user', { target_user_id: targetUserId, content: (content || '').trim() }, 'POST');
}

export function chatClearDirectDialog(client, targetUserId) {
  return client.request('chat_clear_direct_dialog', { target_user_id: targetUserId }, 'POST');
}

export function chatMarkRead(client, conversationId) {
  return client.request('chat_mark_read', { conversation_id: conversationId }, 'POST');
}

export function chatClearAi(client) {
  return client.request('chat_clear_ai', {}, 'POST');
}

export function chatMarkAllRead(client) {
  return client.request('chat_mark_all_read', {}, 'POST');
}

export function chatAdminMarkAllRead(client) {
  return client.request('chat_admin_mark_all_read', {}, 'POST');
}

export function chatAdminSendMessage(client, userId, content) {
  return client.request('chat_admin_send_message', { user_id: userId, content: (content || '').trim() }, 'POST');
}

export async function getAdminChatUsers(client) {
  const res = await client.request('chat_admin_chat_users', {}, 'GET');
  return Array.isArray(res?.users) ? res.users : [];
}

export function chatAdminGetMessages(client, userId, limit = 50, offset = 0) {
  return client.request('chat_admin_get_messages', { user_id: userId, limit, offset }, 'GET');
}

export function chatAdminMarkConversationRead(client, userId) {
  return client.request('chat_admin_mark_conversation_read', { user_id: userId }, 'POST');
}

export function chatAddAIMessage(client, userId, content) {
  return client.request('chat_add_ai_message', { user_id: userId, content: (content || '').trim() }, 'POST');
}

export async function chatAdminGetUnreadNotifications(client, limit = 10) {
  const res = await client.request('chat_admin_unread_notifications', { limit }, 'GET');
  return Array.isArray(res?.messages) ? res.messages : [];
}

export function chatAdminBroadcast(client, content, userIds = null) {
  const body = { content: (content || '').trim() };
  if (Array.isArray(userIds) && userIds.length > 0) {
    body.user_ids = userIds;
  }
  return client.request('chat_admin_broadcast', body, 'POST');
}

export async function getNotificationsDismissed(client) {
  const res = await client.request('notifications_dismissed', {}, 'GET');
  return Array.isArray(res?.dismissed) ? res.dismissed : [];
}

export function dismissNotification(client, notificationId) {
  return client.request('notifications_dismiss', { notification_id: String(notificationId || '') }, 'POST');
}
