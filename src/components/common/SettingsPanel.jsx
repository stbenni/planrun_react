/**
 * SettingsPanel — выезжающая панель настроек.
 * Десктоп: slide-in справа (как drill-in тренера). Мобайл: фуллскрин.
 * Внутри — SettingsScreen в режиме inPanel (одноколоночный drill-in).
 * Открывается флагом settingsPanelOpen из useAuthStore (шестерёнка на профиле и пр.).
 */

import { lazy, Suspense, useEffect } from 'react';
import { createPortal } from 'react-dom';
import { useLocation } from 'react-router-dom';
import useAuthStore from '../../stores/useAuthStore';
import { CloseIcon } from './Icons';
import './SettingsPanel.css';

const SettingsScreen = lazy(() => import('../../screens/SettingsScreen'));

export default function SettingsPanel() {
  const open = useAuthStore((s) => s.settingsPanelOpen);
  const setOpen = useAuthStore((s) => s.setSettingsPanelOpen);
  const location = useLocation();

  // Закрываем при смене страницы.
  useEffect(() => {
    if (open) setOpen(false);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [location.pathname]);

  // Esc + блокировка скролла под панелью.
  useEffect(() => {
    if (!open) return undefined;
    const onKey = (e) => { if (e.key === 'Escape') setOpen(false); };
    window.addEventListener('keydown', onKey);
    document.body.style.overflow = 'hidden';
    return () => {
      window.removeEventListener('keydown', onKey);
      document.body.style.overflow = '';
    };
  }, [open, setOpen]);

  if (!open) return null;

  return createPortal(
    <div className="settings-panel-root">
      <div className="settings-panel-scrim" onClick={() => setOpen(false)} />
      <aside className="settings-panel" role="dialog" aria-modal="true" aria-label="Настройки">
        <button type="button" className="settings-panel-close" onClick={() => setOpen(false)} aria-label="Закрыть">
          <CloseIcon size={20} />
        </button>
        <div className="settings-panel-body">
          <Suspense fallback={<div className="settings-panel-loading">Загрузка…</div>}>
            <SettingsScreen inPanel />
          </Suspense>
        </div>
      </aside>
    </div>,
    document.body,
  );
}
