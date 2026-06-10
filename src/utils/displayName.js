/**
 * Единый источник отображаемого имени пользователя.
 *
 * username стал техническим slug (генерится автоматически, юзер его не вводит).
 * Показываем человеку имя: first_name + last_name → name → username (фолбэк для старых
 * юзеров без имени). Принимает любой объект пользователя/атлета с этими полями.
 */

/** Отображаемое имя: «Имя Фамилия» → name → username → ''. */
export function getDisplayName(u) {
  if (!u || typeof u !== 'object') return '';
  const full = [u.first_name, u.last_name]
    .filter((p) => typeof p === 'string' && p.trim())
    .join(' ')
    .trim();
  if (full) return full;
  if (typeof u.name === 'string' && u.name.trim()) return u.name.trim();
  if (typeof u.username === 'string' && u.username.trim()) return u.username.trim();
  return '';
}

/** Только имя (первое слово отображаемого имени) — для приветствий «Привет, Иван». */
export function getFirstName(u) {
  if (u && typeof u.first_name === 'string' && u.first_name.trim()) return u.first_name.trim();
  const name = getDisplayName(u);
  return name ? name.split(/\s+/)[0] : '';
}

/** Инициалы (1-2 буквы) для плейсхолдеров аватара. */
export function getInitials(u) {
  const name = getDisplayName(u);
  if (!name) return '?';
  const parts = name.split(/\s+/).filter(Boolean);
  if (parts.length >= 2) {
    return (parts[0][0] + parts[1][0]).toUpperCase();
  }
  return name.slice(0, 2).toUpperCase();
}
