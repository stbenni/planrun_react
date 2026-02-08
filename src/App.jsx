/**
 * Главный компонент веб-приложения PlanRun
 * Использует Zustand для управления состоянием
 */

import React, { useEffect, useState, lazy, Suspense } from 'react';
import { BrowserRouter as Router, Routes, Route, Navigate } from 'react-router-dom';
import useAuthStore from './stores/useAuthStore';
import LandingScreen from './screens/LandingScreen';
import RegisterScreen from './screens/RegisterScreen';
import BottomNav from './components/common/BottomNav';
import TopHeader from './components/common/TopHeader';
import Notifications from './components/common/Notifications';
import PageTransition from './components/common/PageTransition';
import SkeletonScreen from './components/common/SkeletonScreen';
import { preloadAllModulesImmediate, preloadScreenModulesDelayed } from './utils/modulePreloader';
import './App.css';

// Lazy loading для тяжелых компонентов
const DashboardScreen = lazy(() => import('./screens/DashboardScreen'));
const CalendarScreen = lazy(() => import('./screens/CalendarScreen'));
const SettingsScreen = lazy(() => import('./screens/SettingsScreen'));
const StatsScreen = lazy(() => import('./screens/StatsScreen'));
const UserProfileScreen = lazy(() => import('./screens/UserProfileScreen'));
const ForgotPasswordScreen = lazy(() => import('./screens/ForgotPasswordScreen'));
const ResetPasswordScreen = lazy(() => import('./screens/ResetPasswordScreen'));
const AdminScreen = lazy(() => import('./screens/AdminScreen'));
const ChatScreen = lazy(() => import('./screens/ChatScreen'));

function App() {
  const { user, api, loading, isAuthenticated, initialize, logout, updateUser } = useAuthStore();
  const isAdmin = user?.role === 'admin';
  const [siteSettings, setSiteSettings] = useState(null);

  useEffect(() => {
    initialize();
    preloadAllModulesImmediate();
  }, [initialize]);

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
      // Небольшая задержка, чтобы не мешать первоначальной загрузке
      preloadScreenModulesDelayed(500);
    }
  }, [isAuthenticated, loading]);

  // Показываем загрузку до проверки авторизации, чтобы не редиректить с /admin и др. на лендинг при F5
  if (loading || !api) {
    return (
      <div className="loading-container">
        <div className="spinner">Загрузка...</div>
      </div>
    );
  }

  const maintenanceMode = siteSettings?.maintenance_mode === '1';
  const registrationEnabled = siteSettings?.registration_enabled !== '0';
  if (maintenanceMode && !isAdmin) {
    return (
      <div className="maintenance-overlay">
        <div className="maintenance-content">
          <h1>Режим обслуживания</h1>
          <p>Сайт временно недоступен. Попробуйте позже.</p>
          {siteSettings?.contact_email && (
            <p className="maintenance-contact">Контакты: {siteSettings.contact_email}</p>
          )}
        </div>
      </div>
    );
  }

  const handleLogin = async (username, password, useJwt = false) => {
    return await useAuthStore.getState().login(username, password, useJwt);
  };

  const handleLogout = async () => {
    await logout();
  };

  const handleRegister = async (userData) => {
    // После регистрации пользователь автоматически авторизован через сессию
    updateUser(userData || { authenticated: true });
    // Убеждаемся что isAuthenticated установлен
    useAuthStore.setState({ isAuthenticated: true });
  };

  return (
    <Router>
      {isAuthenticated && (
        <>
          <TopHeader />
          <Notifications api={api} isAdmin={isAdmin} />
        </>
      )}
      <PageTransition>
        <Suspense fallback={
          <div className="loading-container">
            <SkeletonScreen type="dashboard" />
          </div>
        }>
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
                <RegisterScreen onRegister={handleRegister} />
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
          path="/"
          element={
            isAuthenticated ? (
              <DashboardScreen />
            ) : (
              <Navigate to="/landing" replace />
            )
          }
        />
        <Route
          path="/calendar"
          element={
            isAuthenticated ? (
              <>
                <CalendarScreen />
                <BottomNav />
              </>
            ) : (
              <Navigate to="/landing" replace state={{ openLogin: true }} />
            )
          }
        />
        <Route
          path="/settings"
          element={
            isAuthenticated ? (
              <>
                <SettingsScreen onLogout={handleLogout} />
                <BottomNav />
              </>
            ) : (
              <Navigate to="/landing" replace state={{ openLogin: true }} />
            )
          }
        />
        <Route
          path="/stats"
          element={
            isAuthenticated ? (
              <>
                <StatsScreen />
                <BottomNav />
              </>
            ) : (
              <Navigate to="/landing" replace state={{ openLogin: true }} />
            )
          }
        />
        <Route
          path="/chat"
          element={
            isAuthenticated ? (
              <>
                <ChatScreen />
                <BottomNav />
              </>
            ) : (
              <Navigate to="/landing" replace state={{ openLogin: true }} />
            )
          }
        />
        <Route
          path="/admin"
          element={
            isAuthenticated && isAdmin ? (
              <AdminScreen />
            ) : isAuthenticated ? (
              <Navigate to="/" replace />
            ) : (
              <Navigate to="/landing" replace state={{ openLogin: true }} />
            )
          }
        />
        
        {/* Публичный маршрут профиля пользователя - должен быть последним */}
        <Route
          path="/:username"
          element={
            <Suspense fallback={
              <div className="loading-container">
                <div className="spinner">Загрузка...</div>
              </div>
            }>
              <UserProfileScreen />
            </Suspense>
          }
        />
        </Routes>
        </Suspense>
      </PageTransition>
    </Router>
  );
}

export default App;
