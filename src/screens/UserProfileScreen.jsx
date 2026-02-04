/**
 * UserProfileScreen - Экран профиля пользователя
 * Отображает профиль пользователя и его календарь
 */

import React, { useState, useEffect } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import useAuthStore from '../stores/useAuthStore';
import PublicHeader from '../components/common/PublicHeader';
import CalendarScreen from './CalendarScreen';
import './UserProfileScreen.css';

const UserProfileScreen = () => {
  const { username } = useParams();
  const navigate = useNavigate();
  const { api, user: currentUser } = useAuthStore();
  const [profileUser, setProfileUser] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [access, setAccess] = useState({ can_edit: false, can_view: false, is_owner: false });
  const [recentWorkouts, setRecentWorkouts] = useState([]);
  const [workoutsLoading, setWorkoutsLoading] = useState(false);

  useEffect(() => {
    const loadUserProfile = async () => {
      if (!username) {
        setLoading(false);
        return;
      }

      let currentApi = api;
      if (!currentApi) {
        currentApi = useAuthStore.getState().api;
      }

      if (!currentApi) {
        let attempts = 0;
        const maxAttempts = 50;
        
        const checkInterval = setInterval(() => {
          attempts++;
          const storeApi = useAuthStore.getState().api;
          if (storeApi) {
            clearInterval(checkInterval);
            loadUserProfile();
          } else if (attempts >= maxAttempts) {
            clearInterval(checkInterval);
            setError('API не инициализирован. Попробуйте обновить страницу.');
            setLoading(false);
          }
        }, 100);
        
        return;
      }

      try {
        const slug = username.startsWith('@') ? username.slice(1) : username;
        const response = await currentApi.request('get_user_by_slug', { slug }, 'GET');
        
        if (response?.success && response?.data) {
          setProfileUser(response.data.user);
          setAccess(response.data.access || {});
        } else if (response?.user) {
          setProfileUser(response.user);
          setAccess(response.access || {});
        } else {
          const errorMsg = response?.error || response?.message || 'Пользователь не найден';
          setError(errorMsg);
        }
      } catch (err) {
        setError('Ошибка загрузки профиля: ' + (err.message || 'Неизвестная ошибка'));
      } finally {
        setLoading(false);
      }
    };

    loadUserProfile();
  }, [api, username]);

  useEffect(() => {
    const loadRecentWorkouts = async () => {
      if (!profileUser || !api) return;

      setWorkoutsLoading(true);
      try {
        const workoutsSummary = await api.getAllWorkoutsSummary(profileUser.id);
        let workouts = [];
        let workoutsData = workoutsSummary?.data || workoutsSummary;
        
        if (workoutsData && typeof workoutsData === 'object') {
          workouts = Object.entries(workoutsData)
            .map(([date, data]) => ({
              date,
              ...(typeof data === 'object' ? data : {})
            }))
            .sort((a, b) => new Date(b.date) - new Date(a.date))
            .slice(0, 10);
        }
        
        setRecentWorkouts(workouts);
      } catch (err) {
        console.error('Error loading recent workouts:', err);
      } finally {
        setWorkoutsLoading(false);
      }
    };

    if (profileUser && access.can_view) {
      loadRecentWorkouts();
    }
  }, [profileUser, api, access.can_view]);

  if (loading) {
    return (
      <div className="user-profile-screen">
        <PublicHeader />
        <div className="profile-loading">Загрузка профиля...</div>
      </div>
    );
  }

  if (error && !profileUser) {
    return (
      <div className="user-profile-screen">
        <PublicHeader />
        <div className="profile-error">
          <h2>Профиль не найден</h2>
          <p>{error || 'Пользователь с таким именем не существует'}</p>
          <Link to="/landing" className="btn">Вернуться на главную</Link>
        </div>
      </div>
    );
  }

  if (!profileUser) {
    return (
      <div className="user-profile-screen">
        <PublicHeader />
        <div className="profile-loading">Загрузка...</div>
      </div>
    );
  }

  const isOwner = access.is_owner;
  const canView = access.can_view;
  const canEdit = access.can_edit;

  return (
    <div className="user-profile-screen">
      <PublicHeader />
      
      <div className="profile-header">
        <div className="profile-avatar">
          {profileUser.avatar_path ? (
            <img 
              src={profileUser.avatar_path} 
              alt={profileUser.username}
              className="avatar-large"
            />
          ) : (
            <div className="avatar-large avatar-placeholder">
              {profileUser.username ? profileUser.username.charAt(0).toUpperCase() : 'U'}
            </div>
          )}
        </div>
        
        <div className="profile-info">
          <h1 className="profile-username">{profileUser.username}</h1>
          {profileUser.email && (isOwner || canView) && (
            <p className="profile-email">{profileUser.email}</p>
          )}
          
          {isOwner && (
            <Link to="/settings" className="btn btn-primary">
              ⚙️ Настройки профиля
            </Link>
          )}
          
          {access.is_coach && (
            <div className="coach-badge">
              👨‍🏫 Вы тренер этого спортсмена
            </div>
          )}
        </div>
      </div>

      {canView ? (
        <>
          <div className="profile-calendar">
            <CalendarScreen 
              targetUserId={profileUser.id}
              canEdit={canEdit}
              isOwner={isOwner}
              hideHeader={true}
              viewMode="full"
            />
          </div>

          {recentWorkouts.length > 0 && (
            <div className="recent-workouts-section">
              <h2 className="recent-workouts-title">Последние тренировки</h2>
              <div className="recent-workouts-list">
                {recentWorkouts.map((workout, index) => {
                  const workoutDate = new Date(workout.date + 'T00:00:00');
                  return (
                    <div key={index} className="recent-workout-item">
                      <div className="recent-workout-date">
                        {workoutDate.toLocaleDateString('ru-RU', { 
                          day: 'numeric', 
                          month: 'short',
                          year: 'numeric'
                        })}
                      </div>
                      <div className="recent-workout-metrics">
                        {workout.distance && (
                          <span className="workout-metric">🏃 {typeof workout.distance === 'number' ? workout.distance.toFixed(1) : workout.distance} км</span>
                        )}
                        {workout.duration && (
                          <span className="workout-metric">⏱️ {typeof workout.duration === 'number' ? Math.round(workout.duration / 60) : workout.duration} мин</span>
                        )}
                        {workout.pace && (
                          <span className="workout-metric">📍 {workout.pace} /км</span>
                        )}
                        {workout.count && workout.count > 1 && (
                          <span className="workout-metric">📊 {workout.count} тренировок</span>
                        )}
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          )}
        </>
      ) : (
        <div className="profile-access-denied">
          <h2>Доступ ограничен</h2>
          <p>
            {profileUser.privacy_level === 'private' 
              ? 'Этот календарь доступен только тренерам и владельцу.'
              : 'Для доступа к этому календарю нужна специальная ссылка с токеном.'}
          </p>
          {!currentUser && (
            <Link to="/login" className="btn">
              Войти для доступа
            </Link>
          )}
        </div>
      )}
    </div>
  );
};

export default UserProfileScreen;
