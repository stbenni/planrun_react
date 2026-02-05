/**
 * Утилита для предзагрузки модулей экранов
 * Ускоряет навигацию между страницами
 */

/**
 * Предзагружает все основные экраны приложения
 * Вызывается сразу при загрузке приложения для мгновенных переходов
 */
export const preloadScreenModules = () => {
  if (typeof window === 'undefined') {
    return;
  }

  // Предзагружаем все экраны в фоне сразу
  Promise.all([
    import('../screens/DashboardScreen'),
    import('../screens/CalendarScreen'),
    import('../screens/SettingsScreen'),
    import('../screens/StatsScreen')
  ]).catch(err => {
    console.warn('Module preload failed:', err);
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
 * Предзагружает модули немедленно при загрузке приложения
 * Создает ощущение что приложение уже готово, просто подгружаются данные
 */
export const preloadAllModulesImmediate = () => {
  if (typeof window === 'undefined') {
    return;
  }

  // Загружаем сразу, без задержки
  preloadScreenModules();
  
  // Также предзагружаем общие компоненты
  Promise.all([
    import('../components/common/BottomNav'),
    import('../components/common/TopHeader'),
    import('../components/common/PageTransition'),
    import('../components/common/SkeletonScreen')
  ]).catch(err => {
    console.warn('Common components preload failed:', err);
  });
};
