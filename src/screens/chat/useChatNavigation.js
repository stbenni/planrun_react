import { useCallback, useEffect, useMemo, useState } from 'react';
import { useLocation, useSearchParams } from 'react-router-dom';
import { ADMIN_CHAT, dialogId, MessageCircle, SYSTEM_CHATS, TAB_ADMIN, TAB_ADMIN_MODE, TAB_AI, TAB_USER_DIALOG } from './chatConstants';

export function useChatNavigation({
  api,
  chatUsers,
  chatUsersLoading,
  directDialogs,
  isAdmin,
  userTimezone,
}) {
  const location = useLocation();
  const [searchParams, setSearchParams] = useSearchParams();

  const openAdminModeFromState = location.state?.openAdminMode === true;
  const selectedUserIdFromState = location.state?.selectedUserId;
  const selectedUsernameFromState = location.state?.selectedUsername;
  const selectedUserEmailFromState = location.state?.selectedUserEmail;
  const openAdminTabFromState = location.state?.openAdminTab === true;
  const openAiTabFromState = location.state?.openAITab === true;
  const contactUserSlugFromState = location.state?.contactUserSlug;
  const contactUserFromState = location.state?.contactUser;
  const scrollToMessageId = location.state?.messageId;
  const contactSlugFromUrl = searchParams.get('contact');

  const [contactUser, setContactUser] = useState(() => contactUserFromState ?? null);
  const [contactUserLoading, setContactUserLoading] = useState(false);
  const [adminSection, setAdminSection] = useState(() => (openAdminModeFromState ? 'admin_mode' : 'personal'));
  const [selectedChat, setSelectedChat] = useState(() => {
    if (openAdminModeFromState) return TAB_ADMIN_MODE;
    if (contactUserFromState) return dialogId(contactUserFromState.id);
    if (contactSlugFromUrl) return TAB_USER_DIALOG;
    if (openAdminTabFromState) return TAB_ADMIN;
    if (location.state?.openAITab === true) return TAB_AI;
    return TAB_AI;
  });
  const [mobileListVisible, setMobileListVisible] = useState(
    !openAdminModeFromState && !openAdminTabFromState && !contactUserFromState && !contactSlugFromUrl
  );
  const [selectedChatUser, setSelectedChatUser] = useState(null);

  const clearContactSearchParam = useCallback(() => {
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev);
      next.delete('contact');
      return next;
    }, { replace: true });
  }, [setSearchParams]);

  useEffect(() => {
    if (contactUserFromState) {
      setContactUser(contactUserFromState);
      return;
    }
    if (!contactSlugFromUrl || !api) {
      if (!contactUserFromState) {
        setContactUser(null);
      }
      return;
    }

    setContactUserLoading(true);
    api.getUserBySlug(contactSlugFromUrl)
      .then((data) => {
        const nextUser = data?.data?.user ?? data?.user ?? data;
        if (nextUser?.id) {
          setContactUser({
            id: nextUser.id,
            username: nextUser.username ?? nextUser.username_slug ?? contactSlugFromUrl,
            username_slug: nextUser.username_slug ?? contactSlugFromUrl,
            avatar_path: nextUser.avatar_path,
          });
        } else {
          setContactUser(null);
        }
      })
      .catch(() => setContactUser(null))
      .finally(() => setContactUserLoading(false));
  }, [api, contactSlugFromUrl, contactUserFromState]);

  useEffect(() => {
    if (openAdminModeFromState) {
      setAdminSection('admin_mode');
      setSelectedChat(TAB_ADMIN_MODE);
      setSelectedChatUser(null);
      setMobileListVisible(false);
      clearContactSearchParam();
      return;
    }

    if (contactUserFromState?.id) {
      setAdminSection('personal');
      setContactUser(contactUserFromState);
      setSelectedChat(dialogId(contactUserFromState.id));
      setSelectedChatUser(null);
      setMobileListVisible(false);
      return;
    }

    if (contactSlugFromUrl) {
      setAdminSection('personal');
      setSelectedChat(contactUser?.id ? dialogId(contactUser.id) : TAB_USER_DIALOG);
      setSelectedChatUser(null);
      setMobileListVisible(false);
      return;
    }

    if (openAdminTabFromState) {
      setAdminSection('personal');
      setSelectedChat(TAB_ADMIN);
      setSelectedChatUser(null);
      setMobileListVisible(false);
      return;
    }

    if (openAiTabFromState) {
      setAdminSection('personal');
      setSelectedChat(TAB_AI);
      setSelectedChatUser(null);
      setMobileListVisible(false);
    }
  }, [
    clearContactSearchParam,
    contactSlugFromUrl,
    contactUser?.id,
    contactUserFromState,
    location.key,
    openAdminModeFromState,
    openAdminTabFromState,
    openAiTabFromState,
  ]);

  useEffect(() => {
    if (!contactUserLoading && contactSlugFromUrl && !contactUser) {
      setSelectedChat(TAB_AI);
      clearContactSearchParam();
    }
  }, [clearContactSearchParam, contactSlugFromUrl, contactUser, contactUserLoading]);

  useEffect(() => {
    if (contactUser && selectedChat === TAB_USER_DIALOG) {
      setSelectedChat(dialogId(contactUser.id));
    }
  }, [contactUser, selectedChat]);

  const contactUnreadCount = contactUser
    ? (directDialogs.find((dialog) => Number(dialog.user_id) === Number(contactUser.id))?.unread_count ?? 0)
    : 0;

  const userDialogChat = (contactUser || contactSlugFromUrl)
    ? {
      id: contactUser ? dialogId(contactUser.id) : TAB_USER_DIALOG,
      label: `Диалог с ${contactUser?.username || 'пользователем'}`,
      Icon: MessageCircle,
      description: 'Персональное сообщение',
      user: contactUser,
      unreadCount: contactUnreadCount,
    }
    : null;

  const directDialogChats = directDialogs.map((dialog) => ({
    id: dialogId(dialog.user_id),
    label: `Диалог с ${dialog.username || 'пользователем'}`,
    Icon: MessageCircle,
    description: dialog.last_message_at
      ? `Последнее: ${new Date(dialog.last_message_at).toLocaleString('ru-RU', {
        timeZone: userTimezone,
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
      })}`
      : '',
    user: {
      id: dialog.user_id,
      username: dialog.username,
      username_slug: dialog.username_slug,
      avatar_path: dialog.avatar_path,
    },
    unreadCount: dialog.unread_count ?? 0,
  }));

  const hasContactInDialogs = contactUser && directDialogs.some((dialog) => Number(dialog.user_id) === Number(contactUser.id));
  const personalChats = [...SYSTEM_CHATS, ...directDialogChats, ...(userDialogChat && !hasContactInDialogs ? [userDialogChat] : [])];
  const chats = isAdmin ? [...personalChats, ADMIN_CHAT] : personalChats;

  const dialogUserId = selectedChat?.startsWith?.('dialog_')
    ? parseInt(selectedChat.replace('dialog_', ''), 10)
    : (selectedChat === TAB_USER_DIALOG && contactUser?.id)
      ? Number(contactUser.id)
      : null;

  const dialogUser = dialogUserId ? directDialogs.find((dialog) => Number(dialog.user_id) === dialogUserId) : null;
  const contactUserForDialog = dialogUserId
    ? (
      dialogUser
        ? {
          id: dialogUser.user_id,
          username: dialogUser.username,
          username_slug: dialogUser.username_slug,
          avatar_path: dialogUser.avatar_path,
        }
        : Number(contactUser?.id) === dialogUserId
          ? contactUser
          : null
    )
    : contactUser;

  const isUserDialog = Boolean(dialogUserId && contactUserForDialog);

  useEffect(() => {
    if (!selectedUserIdFromState || adminSection !== 'admin_mode' || chatUsersLoading) return;
    const chatUser = chatUsers.find((user) => user.user_id === selectedUserIdFromState);
    if (chatUser) {
      setSelectedChatUser({
        id: chatUser.user_id,
        username: chatUser.username,
        email: chatUser.email,
        avatar_path: chatUser.avatar_path,
      });
      return;
    }
    setSelectedChatUser({
      id: selectedUserIdFromState,
      username: selectedUsernameFromState || 'Пользователь',
      email: selectedUserEmailFromState || '',
    });
  }, [
    adminSection,
    chatUsers,
    chatUsersLoading,
    selectedUserEmailFromState,
    selectedUserIdFromState,
    selectedUsernameFromState,
  ]);

  const handleSelectChat = (chatId) => {
    setSelectedChat(chatId);
    setMobileListVisible(false);

    if (chatId === TAB_ADMIN_MODE) {
      setAdminSection('admin_mode');
      setSelectedChatUser(null);
      setContactUser(null);
      clearContactSearchParam();
      return;
    }

    if (chatId?.startsWith?.('dialog_')) {
      const chat = chats.find((item) => item.id === chatId);
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
      return;
    }

    setAdminSection('personal');
    setContactUser(null);
    setSelectedChatUser(null);
    clearContactSearchParam();
  };

  const handleAdminSectionChange = (section) => {
    setAdminSection(section);
    setMobileListVisible(true);
    setSelectedChatUser(null);
    setSelectedChat(section === 'personal' ? TAB_AI : TAB_ADMIN_MODE);
    setContactUser(null);
    clearContactSearchParam();
  };

  const handleSelectChatUser = (nextUser) => {
    setSelectedChatUser({
      id: nextUser.user_id,
      username: nextUser.username,
      email: nextUser.email,
      avatar_path: nextUser.avatar_path,
    });
    setMobileListVisible(false);
  };

  const handleBackToList = () => {
    setMobileListVisible(true);
  };

  const currentChat = useMemo(
    () => chats.find((chat) => chat.id === selectedChat),
    [chats, selectedChat]
  );

  return {
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
    isAdminChat: selectedChat === TAB_ADMIN,
    isAdminMode: isAdmin && (selectedChat === TAB_ADMIN_MODE || adminSection === 'admin_mode'),
    isAiChat: selectedChat === TAB_AI,
    isUserDialog,
    mobileListVisible,
    personalChats,
    scrollToMessageId,
    selectedChat,
    selectedChatUser,
  };
}
