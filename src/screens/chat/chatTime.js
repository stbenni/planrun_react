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
      second: '2-digit',
      ...timeZoneOptions,
    });
  }

  return date.toLocaleString('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    ...timeZoneOptions,
  });
}
