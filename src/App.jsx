/**
 * Главный компонент веб-приложения PlanRun
 * Использует Zustand для управления состоянием
 */

import React, { useEffect, useState, Suspense } from 'react';
import { BrowserRouter as Router, Routes, Route, Navigate, useLocation } from 'react-router-dom';
import useAuthStore from './stores/useAuthStore';
import { isNativeCapacitor } from './services/TokenStorageService';
import LandingScreen from './screens/LandingScreen';
import RegisterScreen from './screens/RegisterScreen';
import AppLayout from './components/AppLayout';
import LockScreen from './components/common/LockScreen';
import SkeletonScreen from './components/common/SkeletonScreen';
import LogoLoading from './components/common/LogoLoading';
import AppErrorBoundary from './components/common/AppErrorBoundary';
import { preloadAuthenticatedModules, preloadScreenModulesDelayed } from './utils/modulePreloader';
import { lazyWithRetry } from './utils/lazyWithRetry';
import { startAppUpdatePolling } from './utils/appUpdate';
import './App.css';

// Lazy для страниц вне основных вкладок
const UserProfileScreen = lazyWithRetry(() => import('./screens/UserProfileScreen'), 'UserProfileScreen');
const ForgotPasswordScreen = lazyWithRetry(() => import('./screens/ForgotPasswordScreen'), 'ForgotPasswordScreen');
const ResetPasswordScreen = lazyWithRetry(() => import('./screens/ResetPasswordScreen'), 'ResetPasswordScreen');

function ScrollToTop() {
  const { pathname, search } = useLocation();
  useEffect(() => {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }, [pathname, search]);
  return null;
}

function RoutedErrorBoundary({ children }) {
  const location = useLocation();
  return (
    <AppErrorBoundary resetKey={`${location.pathname}${location.search}`}>
      {children}
    </AppErrorBoundary>
  );
}

function App() {
  const { user, api, loading, isAuthenticated, isLocked, initialize, logout, updateUser } = useAuthStore();
  const isAdmin = user?.role === 'admin';
  const [siteSettings, setSiteSettings] = useState(null);

  useEffect(() => {
    initialize();
  }, [initialize]);

  useEffect(() => {
    return startAppUpdatePolling();
  }, []);

  useEffect(() => {
    if (!api) return;
    api.getSiteSettings?.()
      .then((res) => {
        const d = res?.data ?? res;
        if (d?.settings && typeof d.settings === 'object') setSiteSettings(d.settings);
      })
      .catch(() => {});
  }, [api]);

  // Предзагружаем модули после авторизации
  useEffect(() => {
    if (isAuthenticated && !loading) {
      preloadAuthenticatedModules(user?.role || 'user');
      preloadScreenModulesDelayed(500);
    }
  }, [isAuthenticated, loading, user?.role]);

  // Push-уведомления — только в Android/iOS приложении (Capacitor), не на вебе
  useEffect(() => {
    if (!isAuthenticated || !api || loading) return;
    if (!isNativeCapacitor()) return;
    import('./services/PushService').then(({ registerPushNotifications }) => {
      registerPushNotifications(api).catch(() => {});
    });
  }, [isAuthenticated, api, loading]);

  // Deep link: OAuth callback из In-App Browser (planrun://oauth-callback?connected=strava)
  useEffect(() => {
    if (!isNativeCapacitor()) return;
    let listenerHandle;
    import('@capacitor/app').then(({ App: CapApp }) => {
      CapApp.addListener('appUrlOpen', (event) => {
        const url = event.url || '';
        if (!url.startsWith('planrun://oauth-callback')) return;
        try {
          const params = new URL(url.replace('planrun://', 'https://dummy/')).searchParams;
          const connected = params.get('connected');
          const error = params.get('error');
          if (connected || error) {
            // Навигируем на Settings — существующий useEffect в SettingsScreen обработает
            window.location.href = `/settings?tab=integrations${connected ? '&connected=' + encodeURIComponent(connected) : ''}${error ? '&error=' + encodeURIComponent(error) : ''}`;
          }
        } catch {}
      }).then(h => { listenerHandle = h; });
    }).catch(() => {});
    return () => { listenerHandle?.remove?.(); };
  }, []);

  const maintenanceMode = siteSettings?.maintenance_mode === '1';
  const registrationEnabled = siteSettings?.registration_enabled !== '0';

  const handleLogin = async (username, password, useJwt = false) => {
    return await useAuthStore.getState().login(username, password, useJwt);
  };

  const handleLogout = async () => {
    await logout();
  };

  const handleRegister = async (userData) => {
    // После регистрации пользователь автоматически авторизован через сессию.
    // Один источник истины: updateUser выставляет и user, и isAuthenticated.
    updateUser(userData && typeof userData === 'object' ? { ...userData, authenticated: true } : { authenticated: true });
  };

  return (
    <Router>
      {loading || !api ? (
        <div className="loading-container">
          <LogoLoading />
        </div>
      ) : isLocked ? (
        <LockScreen />
      ) : maintenanceMode && !isAdmin ? (
        <div className="maintenance-overlay">
          <div className="maintenance-content">
            <h1>Режим обслуживания</h1>
            <p>Сайт временно недоступен. Попробуйте позже.</p>
            {siteSettings?.contact_email && (
              <p className="maintenance-contact">Контакты: {siteSettings.contact_email}</p>
            )}
          </div>
        </div>
      ) : (
      <>
      <ScrollToTop />
      <Suspense fallback={
        <div className="loading-container">
          <SkeletonScreen type="dashboard" />
        </div>
      }>
      <RoutedErrorBoundary>
      <Routes>
        <Route
          path="/landing"
          element={<LandingScreen onRegister={handleRegister} registrationEnabled={registrationEnabled} />}
        />
        <Route
          path="/register"
          element={
            !isAuthenticated ? (
              registrationEnabled ? (
                <RegisterScreen onRegister={handleRegister} minimalOnly />
              ) : (
                <Navigate to="/landing" replace state={{ registrationDisabled: true }} />
              )
            ) : (
              <Navigate to="/" replace />
            )
          }
        />
        <Route
          path="/login"
          element={
            !isAuthenticated ? (
              <Navigate to="/landing" replace state={{ openLogin: true }} />
            ) : (
              <Navigate to="/" replace />
            )
          }
        />
        <Route
          path="/forgot-password"
          element={
            !isAuthenticated ? (
              <ForgotPasswordScreen />
            ) : (
              <Navigate to="/" replace />
            )
          }
        />
        <Route
          path="/reset-password"
          element={
            !isAuthenticated ? (
              <ResetPasswordScreen />
            ) : (
              <Navigate to="/" replace />
            )
          }
        />
        <Route
          path="/dashboard"
          element={<Navigate to={isAuthenticated ? '/' : '/landing'} replace />}
        />
        {/* Авторизованная зона: вкладки (все экраны смонтированы, переключение без перезагрузки) */}
        <Route
          element={isAuthenticated ? <AppLayout onLogout={handleLogout} /> : <Navigate to="/landing" replace />}
          path="/"
        >
          <Route index element={null} />
          <Route path="calendar" element={null} />
          <Route path="stats" element={null} />
          <Route path="chat" element={null} />
          <Route path="trainers/*" element={null} />
          <Route path="settings" element={null} />
          <Route path="admin" element={isAdmin ? null : <Navigate to="/" replace />} />
        </Route>
        {/* Публичный маршрут профиля: доступен и залогиненным, и гостям. Не разлогинивает при переходе. */}
        <Route
          path="/:username"
          element={
            <Suspense fallback={
              <div className="loading-container">
                <LogoLoading />
              </div>
            }>
              <UserProfileScreen />
            </Suspense>
          }
        />
        <Route
          path="*"
          element={<Navigate to={isAuthenticated ? '/' : '/landing'} replace />}
        />
      </Routes>
      </RoutedErrorBoundary>
      </Suspense>
      </>
      )}
    </Router>
  );
}

export default App;
