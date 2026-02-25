/**
 * Главный компонент веб-приложения PlanRun
 * Использует Zustand для управления состоянием
 */

import React, { useEffect, useState, lazy, Suspense } from 'react';
import { BrowserRouter as Router, Routes, Route, Navigate, useLocation } from 'react-router-dom';
import useAuthStore from './stores/useAuthStore';
import LandingScreen from './screens/LandingScreen';
import RegisterScreen from './screens/RegisterScreen';
import AppLayout from './components/AppLayout';
import SkeletonScreen from './components/common/SkeletonScreen';
import { preloadAllModulesImmediate, preloadScreenModulesDelayed } from './utils/modulePreloader';
import './App.css';

// Lazy для страниц вне основных вкладок
const UserProfileScreen = lazy(() => import('./screens/UserProfileScreen'));
const ForgotPasswordScreen = lazy(() => import('./screens/ForgotPasswordScreen'));
const ResetPasswordScreen = lazy(() => import('./screens/ResetPasswordScreen'));

function ScrollToTop() {
  const { pathname } = useLocation();
  useEffect(() => {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }, [pathname]);
  return null;
}

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
    // После регистрации пользователь автоматически авторизован через сессию.
    // Один источник истины: updateUser выставляет и user, и isAuthenticated.
    updateUser(userData && typeof userData === 'object' ? { ...userData, authenticated: true } : { authenticated: true });
  };

  return (
    <Router>
      <ScrollToTop />
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
        {/* Авторизованная зона: вкладки (все экраны смонтированы, переключение без перезагрузки) */}
        <Route
          element={isAuthenticated ? <AppLayout onLogout={handleLogout} /> : <Navigate to="/landing" replace />}
          path="/"
        >
          <Route index element={null} />
          <Route path="calendar" element={null} />
          <Route path="stats" element={null} />
          <Route path="chat" element={null} />
          <Route path="trainers" element={null} />
          <Route path="settings" element={null} />
          <Route path="admin" element={isAdmin ? null : <Navigate to="/" replace />} />
        </Route>
        {/* Публичный маршрут профиля пользователя — без хедера */}
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
    </Router>
  );
}

export default App;
