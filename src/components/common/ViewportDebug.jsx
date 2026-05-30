/**
 * Временный диагностический оверлей вьюпорта — только когда в URL есть ?vpdebug=1.
 * Показывает реальные размеры окна (особенно важно в in-app браузере Telegram, где
 * нижняя панель перекрывает контент). По этим числам подбираем нижний отступ.
 */

import { useEffect, useState } from 'react';

export default function ViewportDebug() {
  const enabled = typeof window !== 'undefined' && /\bvpdebug=1\b/.test(window.location.search);
  const [m, setM] = useState({});

  useEffect(() => {
    if (!enabled) return undefined;
    const vv = window.visualViewport;
    const probeB = document.createElement('div');
    probeB.style.cssText = 'position:fixed;bottom:0;left:0;height:env(safe-area-inset-bottom,0px);width:0';
    const probeT = document.createElement('div');
    probeT.style.cssText = 'position:fixed;top:0;left:0;height:env(safe-area-inset-top,0px);width:0';
    document.body.appendChild(probeB);
    document.body.appendChild(probeT);
    const read = () => setM({
      iw: window.innerWidth,
      ih: window.innerHeight,
      vh: vv ? Math.round(vv.height) : '—',
      vtop: vv ? Math.round(vv.offsetTop) : '—',
      sat: Math.round(probeT.getBoundingClientRect().height),
      sab: Math.round(probeB.getBoundingClientRect().height),
      sh: window.screen?.height,
      tg: !!window.TelegramWebviewProxy,
      tgUA: /Telegram/i.test(navigator.userAgent),
      dpr: window.devicePixelRatio,
    });
    read();
    vv?.addEventListener('resize', read);
    vv?.addEventListener('scroll', read);
    window.addEventListener('resize', read);
    return () => {
      vv?.removeEventListener('resize', read);
      vv?.removeEventListener('scroll', read);
      window.removeEventListener('resize', read);
      probeB.remove();
      probeT.remove();
    };
  }, [enabled]);

  if (!enabled) return null;

  return (
    <div style={{
      position: 'fixed', top: 0, left: 0, zIndex: 99999,
      background: 'rgba(0,0,0,0.82)', color: '#0f0', font: '11px/1.4 monospace',
      padding: '6px 8px', maxWidth: '60vw', pointerEvents: 'none', whiteSpace: 'pre',
    }}>
      {`innerW/H: ${m.iw}×${m.ih}
vv.height: ${m.vh}  vv.top: ${m.vtop}
safe-top: ${m.sat}  safe-bottom: ${m.sab}
screen.h: ${m.sh}  dpr: ${m.dpr}
TgProxy: ${String(m.tg)}  TgUA: ${String(m.tgUA)}`}
    </div>
  );
}
