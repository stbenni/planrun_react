export const VALID_TABS = ['profile', 'training', 'social', 'integrations'];

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
}
