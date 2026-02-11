/**
 * Layout для авторизованной зоны: хедер остаётся смонтированным,
 * при навигации меняется только контент (Outlet).
 */

import React from 'react';
import { Outlet, useLocation } from 'react-router-dom';
import TopHeader from './common/TopHeader';
import BottomNav from './common/BottomNav';
import Notifications from './common/Notifications';
import SpecializationModal from './SpecializationModal';
import PageTransition from './common/PageTransition';
import SkeletonScreen from './common/SkeletonScreen';
import useAuthStore from '../stores/useAuthStore';

const AppLayout = () => {
  const location = useLocation();
  const { api, user, showOnboardingModal, setShowOnboardingModal } = useAuthStore();
  const isAdmin = user?.role === 'admin';
  const needsOnboarding = !!(user && user.onboarding_completed === false);
  const showBottomNav = location.pathname !== '/admin';

  return (
    <>
      <TopHeader />
      <Notifications api={api} isAdmin={isAdmin} />
      {needsOnboarding && (
        <SpecializationModal
          isOpen={showOnboardingModal}
          onClose={() => setShowOnboardingModal(false)}
        />
      )}
      <PageTransition>
        <React.Suspense fallback={
          <div className="loading-container">
            <SkeletonScreen type="dashboard" />
          </div>
        }>
          <div key={location.pathname} className="page-transition-content">
            <Outlet />
          </div>
        </React.Suspense>
      </PageTransition>
      {showBottomNav && <BottomNav />}
    </>
  );
};

export default AppLayout;
