/**
 * Layout для авторизованной зоны: хедер остаётся смонтированным,
 * при навигации меняется только контент (Outlet).
 */

import React, { useEffect } from 'react';
import { useLocation } from 'react-router-dom';
import TopHeader from './common/TopHeader';
import BottomNav from './common/BottomNav';
import Notifications from './common/Notifications';
import SpecializationModal from './SpecializationModal';
import PageTransition from './common/PageTransition';
import AppTabsContent from './AppTabsContent';
import useAuthStore from '../stores/useAuthStore';

const AppLayout = ({ onLogout }) => {
  const location = useLocation();
  const { api, user, showOnboardingModal, setShowOnboardingModal } = useAuthStore();
  const isAdmin = user?.role === 'admin';
  const needsOnboarding = !!(user && !user.onboarding_completed);
  const showBottomNav = true;
  const isChatPage = location.pathname.startsWith('/chat');

  useEffect(() => {
    if (!isChatPage) return;
    document.body.classList.add('chat-page-active');
    return () => document.body.classList.remove('chat-page-active');
  }, [isChatPage]);

  return (
    <>
      <TopHeader />
      <Notifications api={api} isAdmin={isAdmin} user={user} />
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
