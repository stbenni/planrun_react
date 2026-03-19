/**
 * Утилита для предзагрузки модулей экранов
 * Ускоряет навигацию между страницами
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
 * Предзагружает только вторичные экраны, а не весь основной shell.
 */
export const preloadScreenModules = () => {
  runWhenIdle(() => {
    Promise.all([
      import('../screens/CalendarScreen'),
      import('../screens/StatsScreen'),
      import('../screens/ChatScreen'),
    ]).catch((err) => {
      console.warn('Module preload failed:', err);
    });
  });
};

/**
 * Предзагружает модули с небольшой задержкой
 * Используется чтобы не мешать первоначальной загрузке
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
 * Предзагружает модули для авторизованного пользователя в фоне без давления на initial load.
 */
export const preloadAuthenticatedModules = (role = 'user') => {
  runWhenIdle(() => {
    const imports = [
      import('../screens/CalendarScreen'),
      import('../screens/StatsScreen'),
    ];

    if (role === 'coach') {
      imports.push(import('../screens/TrainersScreen'));
    } else {
      imports.push(import('../screens/ChatScreen'));
      imports.push(import('../screens/SettingsScreen'));
    }

    Promise.all(imports).catch((err) => {
      console.warn('Authenticated module preload failed:', err);
    });
  }, 1800);
};
