export function formatChatTime(createdAt, userTimezone) {
  if (!createdAt) return '';
  const date = new Date(createdAt);
  const now = new Date();
  const diff = now - date;
  const timeZoneOptions = { timeZone: userTimezone };

  if (diff < 86400000) {
    return date.toLocaleTimeString('ru-RU', {
      hour: '2-digit',
      minute: '2-digit',
      ...timeZoneOptions,
    });
  }

  return date.toLocaleString('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    ...timeZoneOptions,
  });
}

/** Компактное время для списка чатов: «14:18» сегодня, иначе «12.05». */
export function formatListTime(createdAt, userTimezone) {
  if (!createdAt) return '';
  const date = new Date(createdAt);
  const now = new Date();
  const tz = { timeZone: userTimezone };
  const sameDay = date.toLocaleDateString('ru-RU', tz) === now.toLocaleDateString('ru-RU', tz);
  if (sameDay) {
    return date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', ...tz });
  }
  return date.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', ...tz });
}
