/**
 * URL аватара: раздача статикой от корня сайта /uploads/avatars/имя_файла (nginx alias на каталог с файлами).
 * @param {string} avatarPath - путь из БД: '/uploads/avatars/avatar_123_456.jpg' или 'avatar_123_456.jpg'
 * @param {string} [baseUrl] - не используется, оставлен для совместимости
 */
export function getAvatarSrc(avatarPath, baseUrl = '/api') {
  if (!avatarPath || typeof avatarPath !== 'string') return '';
  const name = avatarPath.replace(/^.*\//, '').trim();
  if (!name) return '';
  if (!/^avatar_\d+_\d+\.(jpe?g|png|gif|webp)$/i.test(name)) return '';
  const origin = typeof window !== 'undefined' ? window.location.origin : '';
  const envBase = typeof import.meta !== 'undefined' && import.meta.env?.VITE_API_BASE_URL;
  const isCapacitor = typeof window !== 'undefined' && window.Capacitor?.isNativePlatform?.();
  let root = origin;
  if (isCapacitor && envBase) {
    root = String(envBase).replace(/\/api\/?$/, '');
  }
  return `${root.replace(/\/$/, '')}/uploads/avatars/${encodeURIComponent(name)}`;
}
