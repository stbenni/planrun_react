import { MessageCircle } from 'lucide-react';
import { BotIcon, MailIcon, UsersIcon } from '../../components/common/Icons';

export const TAB_AI = 'ai';
export const TAB_ADMIN = 'admin';
export const TAB_ADMIN_MODE = 'admin_mode';
export const TAB_USER_DIALOG = 'user_dialog';

export const dialogId = (userId) => `dialog_${userId}`;

export const SYSTEM_CHATS = [
  { id: TAB_AI, label: 'AI-тренер', Icon: BotIcon, description: 'Персональные рекомендации по тренировкам' },
  { id: TAB_ADMIN, label: 'От администрации', Icon: MailIcon, description: 'Сообщения от администрации сайта' },
];

export const ADMIN_CHAT = {
  id: TAB_ADMIN_MODE,
  label: 'Администраторский',
  Icon: UsersIcon,
  description: 'Сообщения от пользователей',
};

export { MessageCircle, BotIcon, MailIcon, UsersIcon };
