/**
 * Store для фоновой предзагрузки вкладок (native app).
 * После загрузки Dashboard триггерит предзагрузку Calendar и Stats,
 * чтобы при переключении вкладок контент появлялся мгновенно.
 */

import { create } from 'zustand';

const usePreloadStore = create((set) => ({
  preloadTriggered: false,

  triggerPreload: () => {
    set({ preloadTriggered: true });
  },
}));

export default usePreloadStore;
