/**
 * Layout для авторизованной зоны: хедер остаётся смонтированным,
 * при навигации меняется только контент (Outlet).
 */

import { useEffect } from 'react';
import { useLocation } from 'react-router-dom';
import TopHeader from './common/TopHeader';
import BottomNav from './common/BottomNav';
import UserDrawer from './common/UserDrawer';
import PlanGeneratingBanner from './common/PlanGeneratingBanner';
import SpecializationModal from './SpecializationModal';
import PageTransition from './common/PageTransition';
import AppTabsContent from './AppTabsContent';
import ViewportDebug from './common/ViewportDebug';
import useAuthStore from '../stores/useAuthStore';
import useWorkoutRefreshStore from '../stores/useWorkoutRefreshStore';
import useMobileKeyboardState from '../hooks/useMobileKeyboardState';

const AppLayout = ({ onLogout }) => {
  const location = useLocation();
  const { api, user, showOnboardingModal, setShowOnboardingModal } = useAuthStore();
  const needsOnboarding = !!(user && !user.onboarding_completed);
  const showBottomNav = true;
  const isChatPage = location.pathname.startsWith('/chat');
  const { isKeyboardOpen } = useMobileKeyboardState({ enabled: isChatPage });
  const shouldShowTopHeader = !(isChatPage && isKeyboardOpen);
  const shouldShowBottomNav = showBottomNav && !(isChatPage && isKeyboardOpen);

  // Детект in-app браузера Telegram. На Android UA НЕ содержит «Telegram» — ловим по
  // инжектированному объекту window.TelegramWebviewProxy; на iOS — по UA. У Telegram
  // нижняя панель перекрывает низ webview и env(safe-area-inset-bottom) не работает,
  // поэтому в его среде резервируем нижний отступ через класс body.tg-webview (см. CSS).
  useEffect(() => {
    if (typeof window === 'undefined') return undefined;
    const isTelegram = !!window.TelegramWebviewProxy
      || !!window.TelegramWebviewProxyProto
      || /Telegram/i.test(navigator.userAgent || '');
    document.body.classList.toggle('tg-webview', isTelegram);
    return () => document.body.classList.remove('tg-webview');
  }, []);

  // Автоматическое обновление данных (Strava webhook, Telegram и т.д.)
  // Браузер: polling каждые 30 сек. Мобилка: проверка при resume + push.
  useEffect(() => {
    if (!api) return;
    useWorkoutRefreshStore.getState().startAutoRefresh();
    return () => useWorkoutRefreshStore.getState().stopAutoRefresh();
  }, [api]);

  useEffect(() => {
    if (!isChatPage) return;
    document.body.classList.add('chat-page-active');
    return () => document.body.classList.remove('chat-page-active');
  }, [isChatPage]);

  useEffect(() => {
    if (!isChatPage) {
      document.body.classList.remove('chat-keyboard-open');
      return undefined;
    }

    document.body.classList.toggle('chat-keyboard-open', isKeyboardOpen);

    return () => {
      document.body.classList.remove('chat-keyboard-open');
    };
  }, [isChatPage, isKeyboardOpen]);

  // Высоту чата держит CSS (100dvh) синхронно с анимацией клавиатуры — JS её НЕ трогает.
  // Здесь только смещения top/bottom при открытой клавиатуре (используем full-height без зазора под навбар).
  useEffect(() => {
    if (typeof document === 'undefined') return undefined;

    const root = document.documentElement;

    if (!isChatPage) {
      root.style.removeProperty('--chat-runtime-top-offset');
      root.style.removeProperty('--chat-runtime-bottom-clearance');
      return undefined;
    }

    if (isKeyboardOpen) {
      root.style.setProperty('--chat-runtime-top-offset', 'env(safe-area-inset-top, 0px)');
      root.style.setProperty('--chat-runtime-bottom-clearance', '0px');
    } else {
      root.style.removeProperty('--chat-runtime-top-offset');
      root.style.removeProperty('--chat-runtime-bottom-clearance');
    }

    return () => {
      root.style.removeProperty('--chat-runtime-top-offset');
      root.style.removeProperty('--chat-runtime-bottom-clearance');
    };
  }, [isChatPage, isKeyboardOpen]);

  // Высота чата = реальная высота visualViewport, записанная НАПРЯМУЮ (без React) в rAF
  // по событию resize — синхронно с анимацией клавиатуры. Лечит first-open glitch Chromium
  // (на первом показе клавиатуры dvh/resizes-content применяются с задержкой → страница
  // кратко скроллится, шапка уезжает). Заодно гасим паразитный скролл окна, если он возник.
  useEffect(() => {
    if (!isChatPage || typeof window === 'undefined') return undefined;
    const vv = window.visualViewport;
    if (!vv) return undefined; // нет API → CSS fallback на 100dvh/100vh

    const root = document.documentElement;
    let rafId = 0;
    const apply = () => {
      cancelAnimationFrame(rafId);
      rafId = window.requestAnimationFrame(() => {
        // min с innerHeight — защита от webview (напр. Telegram in-app), который может
        // отдавать visualViewport.height больше реальной layout-высоты.
        const layoutH = window.innerHeight || vv.height;
        const visibleH = Math.round(Math.min(vv.height, layoutH));
        // offsetTop — верхний отступ видимой области (панель Telegram in-app браузера,
        // клавиатура). Сдвигаем чат под него → виден верхний header, и высота попадает в
        // видимую зону → композер встаёт на реальный низ.
        const offsetTop = Math.round(vv.offsetTop || 0);
        root.style.setProperty('--chat-vvh', `${visibleH}px`);
        root.style.setProperty('--chat-vvtop', `${offsetTop}px`);
        // Паразитный скролл документа (браузер тащит поле в зону видимости) — гасим.
        if (window.scrollY !== 0) window.scrollTo(0, 0);
      });
    };

    apply();
    vv.addEventListener('resize', apply);
    vv.addEventListener('scroll', apply);

    return () => {
      cancelAnimationFrame(rafId);
      vv.removeEventListener('resize', apply);
      vv.removeEventListener('scroll', apply);
      root.style.removeProperty('--chat-vvh');
      root.style.removeProperty('--chat-vvtop');
    };
  }, [isChatPage]);

  return (
    <>
      {shouldShowTopHeader && <TopHeader />}
      <UserDrawer />
      <PlanGeneratingBanner />
      {needsOnboarding && (
        <SpecializationModal
          isOpen={showOnboardingModal}
          onClose={() => setShowOnboardingModal(false)}
        />
      )}
      <PageTransition>
        <div className={`page-transition-content ${location.pathname.startsWith('/chat') ? 'page-transition-content--chat' : ''}`}>
          <AppTabsContent onLogout={onLogout} />
        </div>
      </PageTransition>
      {shouldShowBottomNav && <BottomNav />}
      <ViewportDebug />
    </>
  );
};

export default AppLayout;
