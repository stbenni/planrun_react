import { SystemBars, SystemBarsStyle, SystemBarType } from '@capacitor/core';
import { isNativeCapacitor } from '../../services/TokenStorageService';

export const VALID_TABS = ['profile', 'training', 'notifications', 'social', 'integrations'];

function syncNativeStatusBar(theme) {
  if (!isNativeCapacitor()) return;

  const style = theme === 'dark' ? SystemBarsStyle.Dark : SystemBarsStyle.Light;

  SystemBars.show({ bar: SystemBarType.StatusBar }).catch(() => {});
  SystemBars.setStyle({ bar: SystemBarType.StatusBar, style }).catch(() => {});
}

export function getSystemTheme() {
  return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

export function getThemePreference() {
  const saved = localStorage.getItem('theme');
  return saved === 'dark' || saved === 'light' ? saved : 'system';
}

export function applyTheme(theme) {
  document.documentElement.setAttribute('data-theme', theme);
  document.body.setAttribute('data-theme', theme);
  const meta = document.getElementById('theme-color-meta');
  if (meta) meta.setAttribute('content', theme === 'dark' ? '#1A1A1A' : '#FFFFFF');
  const manifestLink = document.querySelector('link[rel="manifest"]');
  if (manifestLink) manifestLink.href = theme === 'dark' ? '/site.webmanifest.dark' : '/site.webmanifest';
  syncNativeStatusBar(theme);
}
