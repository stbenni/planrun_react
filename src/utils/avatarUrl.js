/**
 * URL аватара.
 * Для локальных файлов идём через get_avatar, чтобы можно было:
 * - запрашивать варианты (`sm`, `md`, `lg`)
 * - не ломать старые аватары без заранее созданных миниатюр
 * Поддерживает внешние URL (https://...) — возвращает как есть.
 *
 * @param {string} avatarPath - путь из БД: '/uploads/avatars/avatar_123_456.jpg', 'avatar_123_456.jpg' или 'https://...'
 * @param {string} [baseUrl] - базовый URL API, обычно '/api'
 * @param {'full'|'sm'|'md'|'lg'} [variant] - вариант изображения
 */
export function getAvatarSrc(avatarPath, baseUrl = '/api', variant = 'full') {
  if (!avatarPath || typeof avatarPath !== 'string') return '';
  const trimmed = avatarPath.trim();
  if (!trimmed) return '';
  if (trimmed.startsWith('http://') || trimmed.startsWith('https://')) return trimmed;
  const name = trimmed.replace(/^.*\//, '').trim();
  if (!name) return '';
  if (!/^avatar_\d+_\d+(?:_[a-f0-9]{8})?\.(jpe?g|png|gif|webp)$/i.test(name)) return '';

  let apiRoot = typeof baseUrl === 'string' && baseUrl.trim() ? baseUrl.trim() : '/api';
  if (!/^https?:\/\//i.test(apiRoot) && apiRoot !== '/api' && !apiRoot.startsWith('/')) {
    apiRoot = `/${apiRoot}`;
  }
  apiRoot = apiRoot.replace(/\/$/, '');

  // На Capacitor (Android/iOS) WebView origin может быть capacitor://, file:// или
  // https://localhost (зависит от androidScheme/iosScheme). Относительный /api ведёт в никуда.
  // Перепишем на абсолютный backend URL из VITE_API_BASE_URL или fallback на planrun.ru.
  if (apiRoot.startsWith('/')) {
    const origin = typeof window !== 'undefined' ? window.location.origin : '';
    const isNativeOrigin = /^(capacitor|file):\/\//i.test(origin) || /^https?:\/\/localhost(?::\d+)?$/i.test(origin);
    const isNativePlatform = typeof window !== 'undefined'
      && typeof window.Capacitor?.isNativePlatform === 'function'
      && window.Capacitor.isNativePlatform();
    if (isNativeOrigin || isNativePlatform) {
      const envBase = typeof import.meta !== 'undefined' && import.meta.env && import.meta.env.VITE_API_BASE_URL;
      const backend = (envBase && envBase.trim()) ? envBase.replace(/\/$/, '') : 'https://planrun.ru';
      apiRoot = backend.endsWith('/api') ? backend : `${backend}/api`;
    }
  }

  const params = new URLSearchParams({
    action: 'get_avatar',
    file: name,
  });
  if (variant && variant !== 'full') {
    params.set('variant', variant);
  }

  return `${apiRoot}/api_wrapper.php?${params.toString()}`;
}
