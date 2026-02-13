/**
 * Контент как вкладки: все экраны смонтированы, при переключении только меняется видимость.
 * Нет перезагрузки и «загрузки» при смене вкладки — как в браузере.
 */

import React from 'react';
import { useLocation } from 'react-router-dom';
import DashboardScreen from '../screens/DashboardScreen';
import CalendarScreen from '../screens/CalendarScreen';
import StatsScreen from '../screens/StatsScreen';
import ChatScreen from '../screens/ChatScreen';
import SettingsScreen from '../screens/SettingsScreen';
import useAuthStore from '../stores/useAuthStore';

const AppTabsContent = ({ onLogout }) => {
  const location = useLocation();
  const { user } = useAuthStore();
  const pathname = location.pathname;
  const isAdmin = user?.role === 'admin';

  const isActive = (path) => {
    if (path === '/') return pathname === '/' || pathname === '/dashboard';
    return pathname.startsWith(path);
  };

  return (
    <div className="app-tabs-content">
      <div className={`app-tab-pane ${isActive('/') ? 'app-tab-pane--active' : ''}`} aria-hidden={!isActive('/')}>
        <DashboardScreen />
      </div>
      <div className={`app-tab-pane ${isActive('/calendar') ? 'app-tab-pane--active' : ''}`} aria-hidden={!isActive('/calendar')}>
        <CalendarScreen />
      </div>
      <div className={`app-tab-pane ${isActive('/stats') ? 'app-tab-pane--active' : ''}`} aria-hidden={!isActive('/stats')}>
        <StatsScreen />
      </div>
      <div className={`app-tab-pane ${isActive('/chat') ? 'app-tab-pane--active' : ''}`} aria-hidden={!isActive('/chat')}>
        <ChatScreen />
      </div>
      <div className={`app-tab-pane ${isActive('/settings') ? 'app-tab-pane--active' : ''}`} aria-hidden={!isActive('/settings')}>
        <SettingsScreen onLogout={onLogout} />
      </div>
      {isAdmin && (
        <div className={`app-tab-pane ${isActive('/admin') ? 'app-tab-pane--active' : ''}`} aria-hidden={!isActive('/admin')}>
          <AdminTabPlaceholder />
        </div>
      )}
    </div>
  );
};

const AdminTabPlaceholder = () => {
  const [AdminScreen, setAdminScreen] = React.useState(null);
  React.useEffect(() => {
    import('../screens/AdminScreen').then((m) => setAdminScreen(() => m.default));
  }, []);
  if (!AdminScreen) return <div className="loading-container"><div className="spinner">Загрузка...</div></div>;
  return <AdminScreen />;
};

export default AppTabsContent;
