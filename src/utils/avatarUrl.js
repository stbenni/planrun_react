/**
 * URL аватара: раздача идёт через API (get_avatar), т.к. файлы лежат вне public/
 * @param {string} avatarPath - путь из БД: '/uploads/avatars/avatar_123_456.jpg' или 'avatar_123_456.jpg'
 * @param {string} [baseUrl] - базовый URL API, по умолчанию '/api'
 * @returns {string} URL для <img src="...">
 */
export function getAvatarSrc(avatarPath, baseUrl = '/api') {
  if (!avatarPath || typeof avatarPath !== 'string') return '';
  const name = avatarPath.replace(/^.*\//, '').trim();
  if (!name) return '';
  const base = baseUrl.replace(/\/$/, '');
  return `${base}/api_wrapper.php?action=get_avatar&file=${encodeURIComponent(name)}`;
}
