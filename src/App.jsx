/**
 * Главный компонент веб-приложения PlanRun
 * Использует Zustand для управления состоянием
 */

import React, { useEffect, lazy, Suspense } from 'react';
import { BrowserRouter as Router, Routes, Route, Navigate } from 'react-router-dom';
import useAuthStore from './stores/useAuthStore';
import LandingScreen from './screens/LandingScreen';
import RegisterScreen from './screens/RegisterScreen';
import BottomNav from './components/common/BottomNav';
import TopHeader from './components/common/TopHeader';
import PageTransition from './components/common/PageTransition';
import ThemeToggle from './components/common/ThemeToggle';
import SkeletonScreen from './components/common/SkeletonScreen';
import { preloadAllModulesImmediate, preloadScreenModulesDelayed } from './utils/modulePreloader';
import './App.css';

// Lazy loading для тяжелых компонентов
const DashboardScreen = lazy(() => import('./screens/DashboardScreen'));
const CalendarScreen = lazy(() => import('./screens/CalendarScreen'));
const SettingsScreen = lazy(() => import('./screens/SettingsScreen'));
const StatsScreen = lazy(() => import('./screens/StatsScreen'));
const UserProfileScreen = lazy(() => import('./screens/UserProfileScreen'));

function App() {
  const { user, api, loading, isAuthenticated, initialize, logout, updateUser } = useAuthStore();

  useEffect(() => {
    initialize();
    // Предзагружаем модули сразу при загрузке приложения
    preloadAllModulesImmediate();
  }, [initialize]);

  // Предзагружаем модули после авторизации
  useEffect(() => {
    if (isAuthenticated && !loading) {
      // Небольшая задержка, чтобы не мешать первоначальной загрузке
      preloadScreenModulesDelayed(500);
    }
  }, [isAuthenticated, loading]);

  // Для публичных страниц (профили пользователей) не требуем авторизацию
  const knownRoutes = ['/landing', '/login', '/register', '/', '/calendar', '/settings', '/stats'];
  const isPublicRoute = typeof window !== 'undefined' && 
    !knownRoutes.includes(window.location.pathname);

  // Для публичных маршрутов не блокируем рендеринг даже если API еще не готов
  if ((loading || !api) && !isPublicRoute) {
    return (
      <div className="loading-container">
        <div className="spinner">Загрузка...</div>
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
      {isAuthenticated && <TopHeader />}
      <PageTransition>
        <Suspense fallback={
          <div className="loading-container">
            <SkeletonScreen type="dashboard" />
          </div>
        }>
          <Routes>
        <Route
          path="/landing"
          element={<LandingScreen onRegister={handleRegister} />}
        />
        <Route
          path="/register"
          element={
            !isAuthenticated ? (
              <RegisterScreen onRegister={handleRegister} />
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
      <ThemeToggle />
    </Router>
  );
}

export default App;
