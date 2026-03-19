import { useCallback, useEffect, useState } from 'react';

export function useChatDirectories(api, isAdmin) {
  const [directDialogs, setDirectDialogs] = useState([]);
  const [directDialogsLoading, setDirectDialogsLoading] = useState(false);
  const [chatUsers, setChatUsers] = useState([]);
  const [chatUsersLoading, setChatUsersLoading] = useState(false);

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

  useEffect(() => {
    loadDirectDialogs();
  }, [loadDirectDialogs]);

  return {
    directDialogs,
    directDialogsLoading,
    chatUsers,
    chatUsersLoading,
    loadDirectDialogs,
    loadChatUsers,
  };
}
