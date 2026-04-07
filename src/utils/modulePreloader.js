/**
 * Утилита для предзагрузки модулей экранов.
 *
 * Основные вкладки предзагружаются непосредственно в AppTabsContent при монтировании.
 * Здесь остаётся только legacy-API для обратной совместимости.
 */

function runWhenIdle(callback, timeout = 1200) {
  if (typeof window === 'undefined') {
    return;
  }

  if (typeof window.requestIdleCallback === 'function') {
    window.requestIdleCallback(callback, { timeout });
    return;
  }

  setTimeout(callback, Math.min(timeout, 400));
}

/**
 * Предзагружает вспомогательные экраны.
 * Основные вкладки уже предзагружены в AppTabsContent.
 */
export const preloadScreenModules = () => {
  // Основные экраны уже предзагружаются в AppTabsContent — здесь только вспомогательные
  runWhenIdle(() => {
    import('../screens/UserProfileScreen').catch(() => {});
  });
};

/**
 * Предзагружает модули с небольшой задержкой
 */
export const preloadScreenModulesDelayed = (delay = 300) => {
  if (typeof window === 'undefined') {
    return;
  }

  setTimeout(() => {
    preloadScreenModules();
  }, delay);
};

/**
 * Предзагружает модули для авторизованного пользователя.
 * Основные вкладки уже предзагружены в AppTabsContent.
 */
export const preloadAuthenticatedModules = () => {
  runWhenIdle(() => {
    // Только вспомогательные модули — основные уже предзагружены
    import('../screens/UserProfileScreen').catch(() => {});
  }, 1800);
};
