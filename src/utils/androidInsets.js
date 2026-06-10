export function detectAndroidEdgeToEdge() {
  if (typeof window === 'undefined' || typeof document === 'undefined') return;
  const ua = navigator.userAgent || '';
  if (!/Android/i.test(ua)) return;
  const versionMatch = ua.match(/Android\s+(\d+)/);
  const major = versionMatch ? parseInt(versionMatch[1], 10) : 0;
  const root = document.documentElement;

  const apply = () => {
    let envTop = 0;
    try {
      const probe = document.createElement('div');
      probe.style.cssText = 'position:fixed;top:0;left:0;width:0;height:env(safe-area-inset-top,0px);opacity:0;pointer-events:none;';
      document.body.appendChild(probe);
      envTop = probe.getBoundingClientRect().height;
      document.body.removeChild(probe);
    } catch { void 0; }

    const screenH = (window.screen && window.screen.height) || 0;
    const viewH = window.innerHeight || 0;
    const looksEdgeToEdge = screenH > 0 && viewH > 0 && (screenH - viewH) < 40;
    const needsInset = envTop < 1 && (major >= 13 || looksEdgeToEdge);
    root.classList.toggle('android-e2e', needsInset);
  };

  if (document.body) apply();
  else window.addEventListener('DOMContentLoaded', apply, { once: true });
  window.addEventListener('orientationchange', () => setTimeout(apply, 250));
}

export default detectAndroidEdgeToEdge;
