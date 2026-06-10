/**
 * Telegram Mini App: определение контекста, ожидание SDK и инициализация UI.
 * SDK (telegram-web-app.js) подключается в index.html (async). Здесь — безопасные обёртки.
 */

import { applyTheme, getSystemTheme, getThemePreference } from '../screens/settings/settingsUtils';

const SDK_WAIT_TIMEOUT_MS = 1500;

/**
 * Открыты ли мы внутри Telegram (Mini App)?
 * Детект работает даже до загрузки SDK: Telegram добавляет tgWebAppData в URL.
 */
export function isTelegramContext() {
  if (typeof window === 'undefined') return false;
  try {
    if (window.Telegram?.WebApp?.initData) return true;
    const hash = window.location.hash || '';
    const search = window.location.search || '';
    return hash.includes('tgWebAppData=') || search.includes('tgWebAppData=');
  } catch {
    return false;
  }
}

/** Дождаться готовности window.Telegram.WebApp (SDK грузится async). */
function waitForTelegramSdk(timeoutMs = SDK_WAIT_TIMEOUT_MS) {
  return new Promise((resolve) => {
    if (typeof window === 'undefined') return resolve(null);
    if (window.Telegram?.WebApp) return resolve(window.Telegram.WebApp);

    const start = Date.now();
    const interval = setInterval(() => {
      if (window.Telegram?.WebApp) {
        clearInterval(interval);
        resolve(window.Telegram.WebApp);
      } else if (Date.now() - start > timeoutMs) {
        clearInterval(interval);
        resolve(null);
      }
    }, 50);
  });
}

/**
 * Платформа клиента Telegram: 'android' | 'ios' | 'tdesktop' | 'macos' |
 * 'unigram' | 'web' | 'weba' | 'webk' | 'unknown' и т.п. (null вне Telegram).
 */
export function getTelegramPlatform() {
  try {
    return window.Telegram?.WebApp?.platform || null;
  } catch {
    return null;
  }
}

/** Запущено в мобильном клиенте Telegram (телефон/планшет: Android/iOS)? */
export function isTelegramMobile() {
  const platform = getTelegramPlatform();
  return platform === 'android' || platform === 'ios';
}

/** Запущено в десктопном/веб-клиенте Telegram (не телефон)? */
export function isTelegramDesktop() {
  const platform = getTelegramPlatform();
  if (!platform) return false;
  return ['tdesktop', 'macos', 'unigram', 'web', 'weba', 'webk'].includes(platform);
}

/** Подписанная строка initData для проверки на бэкенде (пустая вне Telegram). */
export function getInitData() {
  try {
    return window.Telegram?.WebApp?.initData || '';
  } catch {
    return '';
  }
}

/**
 * Прокидывает safe-area Telegram в CSS-переменные и помечает <html>.tg-fullscreen.
 * В fullscreen нативный env(safe-area-inset-*) = 0 (Telegram рисует поверх статус-бара),
 * поэтому верхний отступ берём из safeAreaInset/contentSafeAreaInset самого Telegram.
 */
function applyTelegramInsets(webApp) {
  if (typeof document === 'undefined') return;
  try {
    const root = document.documentElement;
    const safe = webApp.safeAreaInset || {};
    const content = webApp.contentSafeAreaInset || {};
    const statusBar = Number(safe.top) || 0;        // статус-бар (время/батарея)
    const buttonsBand = Number(content.top) || 0;   // полоса кнопок Telegram (Закрыть, ⌄, ⋯)
    // contentSafeAreaInset задаётся ОТНОСИТЕЛЬНО safeAreaInset, поэтому полный отступ — сумма.
    const top = statusBar + buttonsBand;
    const bottom = (Number(safe.bottom) || 0) + (Number(content.bottom) || 0);
    root.style.setProperty('--tg-safe-area-inset-top', `${top}px`);
    root.style.setProperty('--tg-safe-area-inset-bottom', `${bottom}px`);
    // Раздельно — чтобы разместить логотип в полосе кнопок (ниже статус-бара).
    root.style.setProperty('--tg-status-bar-height', `${statusBar}px`);
    root.style.setProperty('--tg-buttons-band-height', `${buttonsBand}px`);
    root.classList.toggle('tg-fullscreen', Boolean(webApp.isFullscreen));
  } catch {
    /* инсеты — best-effort */
  }
}

/**
 * Синхронизирует тему приложения с темой клиента Telegram.
 * Тема Telegram может не совпадать с темой ОС (prefers-color-scheme): пользователь
 * вправе включить тёмный Telegram на светлой системе и наоборот, поэтому внутри
 * Mini App источник истины — Telegram. Ручной выбор пользователя (light/dark в
 * настройках) имеет приоритет — трогаем тему только в режиме «как в системе».
 */
function syncThemeWithTelegram(webApp) {
  const apply = () => {
    if (getThemePreference() !== 'system') return; // уважаем ручной выбор
    const scheme = webApp.colorScheme === 'dark' || webApp.colorScheme === 'light'
      ? webApp.colorScheme
      : getSystemTheme();
    applyTheme(scheme);
  };
  apply();
  // Реакция на смену темы прямо внутри Telegram (matchMedia такое событие не ловит).
  try { webApp.onEvent?.('themeChanged', apply); } catch { /* событие не поддержано */ }
}

/**
 * Инициализация Mini App: ready() + expand() + полноэкранный режим (Bot API 8.0+).
 * На старых клиентах тихий фолбэк на expand(). Возвращает WebApp или null.
 */
export async function initTelegramMiniApp() {
  const webApp = await waitForTelegramSdk();
  if (!webApp) return null;
  try { webApp.ready(); } catch { /* SDK без ready — игнор */ }
  try { webApp.expand(); } catch { /* expand необязателен */ }
  syncThemeWithTelegram(webApp);

  try {
    // Fullscreen на всех клиентах с Bot API 8.0+. Верхнюю safe-area-полосу оформляем
    // как фикс-хедер (.tg-topbar), контент скроллится между ней и нижним навбаром.
    const supportsFullscreen = typeof webApp.isVersionAtLeast === 'function'
      && webApp.isVersionAtLeast('8.0');
    if (supportsFullscreen) {
      if (typeof webApp.requestFullscreen === 'function') {
        webApp.requestFullscreen();
      }
      // Чтобы свайп вниз не сворачивал приложение вместо скролла контента.
      if (typeof webApp.disableVerticalSwipes === 'function') {
        webApp.disableVerticalSwipes();
      }
      applyTelegramInsets(webApp);
      const onChange = () => applyTelegramInsets(webApp);
      ['fullscreenChanged', 'safeAreaChanged', 'contentSafeAreaChanged', 'viewportChanged']
        .forEach((evt) => { try { webApp.onEvent?.(evt, onChange); } catch { /* событие не поддержано */ } });
    }
  } catch {
    /* fullscreen необязателен — продолжаем в обычном режиме */
  }

  return webApp;
}
