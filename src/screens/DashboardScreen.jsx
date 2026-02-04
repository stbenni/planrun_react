/**
 * Dashboard Screen - Главный экран приложения
 */

import React from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import useAuthStore from '../stores/useAuthStore';
import Dashboard from '../components/Dashboard/Dashboard';
import BottomNav from '../components/common/BottomNav';
import '../components/Dashboard/Dashboard.css';

const DashboardScreen = () => {
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

  // Передаем сообщение о регистрации в Dashboard
  const registrationMessage = location.state?.planMessage;
  const isNewRegistration = location.state?.registrationSuccess;

  return (
    <>
      <Dashboard 
        api={api} 
        user={user} 
        onNavigate={handleNavigate}
        registrationMessage={registrationMessage}
        isNewRegistration={isNewRegistration}
      />
      <BottomNav />
    </>
  );
};

export default DashboardScreen;
