/**
 * Layout для авторизованной зоны: хедер остаётся смонтированным,
 * при навигации меняется только контент (Outlet).
 */

import { useEffect, useRef } from 'react';
import { useLocation } from 'react-router-dom';
import TopHeader from './common/TopHeader';
import BottomNav from './common/BottomNav';
import Notifications from './common/Notifications';
import PlanGeneratingBanner from './common/PlanGeneratingBanner';
import SpecializationModal from './SpecializationModal';
import PageTransition from './common/PageTransition';
import AppTabsContent from './AppTabsContent';
import useAuthStore from '../stores/useAuthStore';
import useWorkoutRefreshStore from '../stores/useWorkoutRefreshStore';
import useMobileKeyboardState from '../hooks/useMobileKeyboardState';

const AppLayout = ({ onLogout }) => {
  const location = useLocation();
  const { api, user, showOnboardingModal, setShowOnboardingModal } = useAuthStore();
  const isAdmin = user?.role === 'admin';
  const needsOnboarding = !!(user && !user.onboarding_completed);
  const showBottomNav = true;
  const isChatPage = location.pathname.startsWith('/chat');
  const {
    isKeyboardOpen,
    viewportHeight,
    keyboardInset,
    bottomSafeAreaInset,
  } = useMobileKeyboardState({ enabled: isChatPage });
  const keyboardSettlingInitializedRef = useRef(false);

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
      document.body.classList.remove('chat-keyboard-settling');
      keyboardSettlingInitializedRef.current = false;
      return undefined;
    }

    document.body.classList.toggle('chat-keyboard-open', isKeyboardOpen);
    if (!keyboardSettlingInitializedRef.current) {
      keyboardSettlingInitializedRef.current = true;
      return () => {
        document.body.classList.remove('chat-keyboard-open');
        document.body.classList.remove('chat-keyboard-settling');
      };
    }

    document.body.classList.add('chat-keyboard-settling');
    const settlingTimeoutId = window.setTimeout(() => {
      document.body.classList.remove('chat-keyboard-settling');
    }, 320);

    return () => {
      window.clearTimeout(settlingTimeoutId);
      document.body.classList.remove('chat-keyboard-open');
      document.body.classList.remove('chat-keyboard-settling');
    };
  }, [isChatPage, isKeyboardOpen]);

  useEffect(() => {
    if (typeof document === 'undefined') return undefined;

    const root = document.documentElement;

    if (!isChatPage) {
      root.style.removeProperty('--chat-runtime-viewport-height');
      root.style.removeProperty('--chat-runtime-keyboard-inset');
      root.style.removeProperty('--chat-runtime-bottom-safe-area-inset');
      return undefined;
    }

    if (viewportHeight) {
      root.style.setProperty('--chat-runtime-viewport-height', `${viewportHeight}px`);
    } else {
      root.style.removeProperty('--chat-runtime-viewport-height');
    }

    root.style.setProperty('--chat-runtime-keyboard-inset', `${Math.max(0, keyboardInset || 0)}px`);
    if (bottomSafeAreaInset === null || bottomSafeAreaInset === undefined) {
      root.style.removeProperty('--chat-runtime-bottom-safe-area-inset');
    } else {
      root.style.setProperty('--chat-runtime-bottom-safe-area-inset', `${Math.max(0, bottomSafeAreaInset)}px`);
    }

    return () => {
      root.style.removeProperty('--chat-runtime-viewport-height');
      root.style.removeProperty('--chat-runtime-keyboard-inset');
      root.style.removeProperty('--chat-runtime-bottom-safe-area-inset');
    };
  }, [bottomSafeAreaInset, isChatPage, keyboardInset, viewportHeight]);

  return (
    <>
      <TopHeader />
      <Notifications api={api} isAdmin={isAdmin} user={user} />
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
      {showBottomNav && <BottomNav />}
    </>
  );
};

export default AppLayout;
