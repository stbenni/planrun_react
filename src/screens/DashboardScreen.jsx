/**
 * Dashboard Screen - Главный экран приложения
 */

import React from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import useAuthStore from '../stores/useAuthStore';
import { useIsTabActive } from '../hooks/useIsTabActive';
import Dashboard from '../components/Dashboard/Dashboard';
import '../components/Dashboard/Dashboard.css';

const DashboardScreen = () => {
  const isTabActive = useIsTabActive('/');
  const navigate = useNavigate();
  const location = useLocation();
  const { api, user } = useAuthStore();

  const handleNavigate = (route, params) => {
    if (route === 'calendar') {
      navigate('/calendar', { state: params });
    } else {
      navigate(`/${route}`);
    }
  };

  // Сообщение о генерации плана: из state (полная регистрация) или из store (специализация)
  const registrationMessage = location.state?.planMessage;
  const isNewRegistration = location.state?.registrationSuccess;
  const planGenerationMessage = useAuthStore((s) => s.planGenerationMessage);

  return (
    <Dashboard
      api={api}
      user={user}
      isTabActive={isTabActive}
      onNavigate={handleNavigate}
      registrationMessage={registrationMessage || planGenerationMessage}
      isNewRegistration={isNewRegistration}
    />
  );
};

export default DashboardScreen;
