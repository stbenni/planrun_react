import { Capacitor } from '@capacitor/core';

let modPromise = null;

/** Лёгкий тактильный отклик (на нативе); на вебе — no-op. */
export function tapHaptic(style = 'light') {
  if (!Capacitor.isNativePlatform()) return;
  if (!modPromise) modPromise = import('@capacitor/haptics').catch(() => null);
  modPromise.then((mod) => {
    if (!mod) return;
    const { Haptics, ImpactStyle } = mod;
    Haptics.impact({ style: style === 'medium' ? ImpactStyle.Medium : ImpactStyle.Light }).catch(() => {});
  });
}

export default tapHaptic;
