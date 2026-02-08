/**
 * Админ-панель: управление пользователями и настройками сайта
 * Доступ только для пользователей с role === 'admin'
 */

import React, { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import useAuthStore from '../stores/useAuthStore';
import './AdminScreen.css';

const TAB_USERS = 'users';
const TAB_SETTINGS = 'settings';
const ROLES = [{ value: 'user', label: 'Пользователь' }, { value: 'coach', label: 'Тренер' }, { value: 'admin', label: 'Администратор' }];

const GOAL_TYPE_LABELS = {
  health: 'Здоровье',
  race: 'Забег',
  weight_loss: 'Снижение веса',
  time_improvement: 'Улучшить время',
};

const TRAINING_MODE_LABELS = {
  ai: 'AI',
  self: 'Самостоятельно',
  coach: 'Живой тренер',
  both: 'AI + тренер',
};

export default function AdminScreen() {
  const navigate = useNavigate();
  const { user, api } = useAuthStore();
  const [tab, setTab] = useState(TAB_USERS);
  const [csrfToken, setCsrfToken] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  // Пользователи
  const [users, setUsers] = useState([]);
  const [usersTotal, setUsersTotal] = useState(0);
  const [usersPage, setUsersPage] = useState(1);
  const [usersSearch, setUsersSearch] = useState('');
  const [usersSearchDebounced, setUsersSearchDebounced] = useState('');
  const perPage = 20;
  const [updatingUserId, setUpdatingUserId] = useState(null);
  const [deletingUserId, setDeletingUserId] = useState(null);

  // Массовая рассылка
  const [broadcastModalOpen, setBroadcastModalOpen] = useState(false);
  const [broadcastContent, setBroadcastContent] = useState('');
  const [broadcastSending, setBroadcastSending] = useState(false);
  const [broadcastTarget, setBroadcastTarget] = useState('all'); // 'all' | 'page'

  // Настройки сайта
  const [settings, setSettings] = useState({
    site_name: '',
    site_description: '',
    maintenance_mode: '0',
    registration_enabled: '1',
    contact_email: '',
  });
  const [savingSettings, setSavingSettings] = useState(false);

  const isAdmin = user?.role === 'admin';

  const fetchCsrf = useCallback(async () => {
    if (!api) return;
    try {
      const res = await api.request('get_csrf_token', {}, 'GET');
      const token = res?.csrf_token ?? res?.data?.csrf_token;
      if (token) setCsrfToken(token);
    } catch (_) {}
  }, [api]);

  const loadUsers = useCallback(async () => {
    if (!api) return;
    setLoading(true);
    setError('');
    try {
      const res = await api.getAdminUsers({
        page: usersPage,
        per_page: perPage,
        search: usersSearchDebounced || undefined,
      });
      const data = res?.data ?? res;
      setUsers(Array.isArray(data?.users) ? data.users : []);
      setUsersTotal(data?.total ?? 0);
    } catch (e) {
      setError(e.message || 'Ошибка загрузки пользователей');
      setUsers([]);
    } finally {
      setLoading(false);
    }
  }, [api, usersPage, usersSearchDebounced]);

  const normOnOff = (v) => (v === true || v === '1' || v === 1 ? '1' : '0');

  const loadSettings = useCallback(async () => {
    if (!api) return;
    setError('');
    try {
      const res = await api.getAdminSettings();
      const data = res?.data ?? res;
      const s = data?.settings ?? {};
      setSettings(prev => ({
        site_name: s.site_name ?? prev.site_name,
        site_description: s.site_description ?? prev.site_description,
        maintenance_mode: normOnOff(s.maintenance_mode),
        registration_enabled: normOnOff(s.registration_enabled),
        contact_email: s.contact_email ?? prev.contact_email,
      }));
    } catch (e) {
      setError(e.message || 'Ошибка загрузки настроек');
    }
  }, [api]);

  useEffect(() => {
    if (!isAdmin) {
      navigate('/', { replace: true });
      return;
    }
    fetchCsrf();
  }, [isAdmin, navigate, fetchCsrf]);

  useEffect(() => {
    if (!isAdmin || !api) return;
    if (tab === TAB_USERS) loadUsers();
    if (tab === TAB_SETTINGS) loadSettings();
  }, [isAdmin, api, tab, loadUsers, loadSettings]);

  useEffect(() => {
    const t = setTimeout(() => setUsersSearchDebounced(usersSearch), 400);
    return () => clearTimeout(t);
  }, [usersSearch]);

  useEffect(() => {
    if (tab === TAB_USERS && api) loadUsers();
  }, [usersPage, usersSearchDebounced]);

  const handleUpdateUserRole = async (userId, newRole) => {
    if (!api || !csrfToken) return;
    setUpdatingUserId(userId);
    setError('');
    try {
      await api.updateAdminUser({ user_id: userId, role: newRole, csrf_token: csrfToken });
      await loadUsers();
    } catch (e) {
      setError(e.message || 'Ошибка обновления');
    } finally {
      setUpdatingUserId(null);
    }
  };

  const handleBroadcast = async (e) => {
    e.preventDefault();
    if (!api || !broadcastContent.trim() || broadcastSending) return;
    setBroadcastSending(true);
    setError('');
    try {
      const userIds = broadcastTarget === 'page' ? users.map((u) => u.id).filter((id) => id !== user?.user_id) : null;
      const res = await api.chatAdminBroadcast(broadcastContent.trim(), userIds);
      const sent = res?.sent ?? 0;
      setBroadcastModalOpen(false);
      setBroadcastContent('');
      setBroadcastTarget('all');
      if (sent > 0) {
        setError('');
      }
    } catch (e) {
      setError(e.message || 'Ошибка рассылки');
    } finally {
      setBroadcastSending(false);
    }
  };

  const handleDeleteUser = async (userId, username) => {
    if (!api || !csrfToken) return;
    if (!window.confirm(`Удалить пользователя "${username}" и все его данные?`)) return;
    setDeletingUserId(userId);
    setError('');
    try {
      await api.deleteUser({ user_id: userId, csrf_token: csrfToken });
      await loadUsers();
    } catch (e) {
      setError(e.message || 'Ошибка удаления');
    } finally {
      setDeletingUserId(null);
    }
  };

  const handleSaveSettings = async (e) => {
    e.preventDefault();
    if (!api || !csrfToken) return;
    setSavingSettings(true);
    setError('');
    try {
      await api.updateAdminSettings({
        csrf_token: csrfToken,
        settings: {
          site_name: settings.site_name,
          site_description: settings.site_description,
          maintenance_mode: settings.maintenance_mode === '1' ? '1' : '0',
          registration_enabled: settings.registration_enabled === '1' ? '1' : '0',
          contact_email: settings.contact_email,
        },
      });
      await loadSettings();
    } catch (e) {
      setError(e.message || 'Ошибка сохранения настроек');
    } finally {
      setSavingSettings(false);
    }
  };

  if (!user) return null;
  if (!isAdmin) return null;

  const totalPages = Math.max(1, Math.ceil(usersTotal / perPage));

  return (
    <div className="admin-screen">
      <nav className="admin-tabs" role="tablist">
        <button
          type="button"
          role="tab"
          aria-selected={tab === TAB_USERS}
          className={`admin-tab ${tab === TAB_USERS ? 'active' : ''}`}
          onClick={() => setTab(TAB_USERS)}
        >
          Пользователи
        </button>
        <button
          type="button"
          role="tab"
          aria-selected={tab === TAB_SETTINGS}
          className={`admin-tab ${tab === TAB_SETTINGS ? 'active' : ''}`}
          onClick={() => setTab(TAB_SETTINGS)}
        >
          Настройки сайта
        </button>
      </nav>

      {error && (
        <div className="admin-error" role="alert">
          {error}
        </div>
      )}

      {tab === TAB_USERS && (
        <section className="admin-section admin-users" aria-label="Пользователи">
          <div className="admin-users-toolbar">
            <button
              type="button"
              className="admin-btn-broadcast"
              onClick={() => setBroadcastModalOpen(true)}
              title="Массовая рассылка"
            >
              📢 Массовая рассылка
            </button>
            <input
              type="search"
              className="admin-search"
              placeholder="Поиск по логину или email..."
              value={usersSearch}
              onChange={(e) => setUsersSearch(e.target.value)}
            />
          </div>
          {loading ? (
            <p className="admin-loading">Загрузка...</p>
          ) : (
            <>
              <div className="admin-table-wrap">
                <table className="admin-table">
                  <thead>
                    <tr>
                      <th>ID</th>
                      <th>Логин</th>
                      <th>Email</th>
                      <th>Роль</th>
                      <th>Режим</th>
                      <th>Цель</th>
                      <th>Регистрация</th>
                      <th>Действия</th>
                    </tr>
                  </thead>
                  <tbody>
                    {users.map((u) => (
                      <tr key={u.id}>
                        <td>{u.id}</td>
                        <td>{u.username}</td>
                        <td>{u.email || '—'}</td>
                        <td>
                          <select
                            value={u.role || 'user'}
                            onChange={(e) => handleUpdateUserRole(u.id, e.target.value)}
                            disabled={updatingUserId === u.id || u.id === user.user_id}
                            className="admin-role-select"
                          >
                            {ROLES.map((r) => (
                              <option key={r.value} value={r.value}>{r.label}</option>
                            ))}
                          </select>
                        </td>
                        <td>{u.training_mode ? (TRAINING_MODE_LABELS[u.training_mode] ?? u.training_mode) : '—'}</td>
                        <td>{u.goal_type ? (GOAL_TYPE_LABELS[u.goal_type] ?? u.goal_type) : '—'}</td>
                        <td>{u.created_at ? new Date(u.created_at).toLocaleDateString('ru') : '—'}</td>
                        <td>
                          <div className="admin-user-actions">
                            <button
                              type="button"
                              className="admin-btn-message"
                              onClick={() => navigate('/chat', { state: { openAdminMode: true, selectedUserId: u.id, selectedUsername: u.username, selectedUserEmail: u.email || '' } })}
                              title="Открыть чат"
                            >
                              💬
                            </button>
                            {u.id !== user.user_id && (
                              <button
                                type="button"
                                className="admin-btn-delete"
                                onClick={() => handleDeleteUser(u.id, u.username)}
                                disabled={deletingUserId === u.id}
                              >
                                {deletingUserId === u.id ? '…' : 'Удалить'}
                              </button>
                            )}
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {usersTotal > perPage && (
                <div className="admin-pagination">
                  <button
                    type="button"
                    disabled={usersPage <= 1}
                    onClick={() => setUsersPage((p) => Math.max(1, p - 1))}
                  >
                    Назад
                  </button>
                  <span className="admin-pagination-info">
                    {usersPage} / {totalPages} (всего {usersTotal})
                  </span>
                  <button
                    type="button"
                    disabled={usersPage >= totalPages}
                    onClick={() => setUsersPage((p) => p + 1)}
                  >
                    Вперёд
                  </button>
                </div>
              )}
            </>
          )}
        </section>
      )}

      {tab === TAB_SETTINGS && (
        <section className="admin-section" aria-label="Настройки сайта">
        <form className="admin-settings-form" onSubmit={handleSaveSettings}>
          <div className="admin-form-group">
            <label>Название сайта</label>
            <input
              type="text"
              value={settings.site_name}
              onChange={(e) => setSettings((s) => ({ ...s, site_name: e.target.value }))}
              placeholder="PlanRun"
            />
          </div>
          <div className="admin-form-group">
            <label>Описание сайта</label>
            <textarea
              value={settings.site_description}
              onChange={(e) => setSettings((s) => ({ ...s, site_description: e.target.value }))}
              placeholder="Краткое описание"
              rows={2}
            />
          </div>
          <div className="admin-form-group">
            <label className="admin-checkbox-label">
              <input
                type="checkbox"
                checked={settings.maintenance_mode === '1'}
                onChange={(e) => setSettings((s) => ({ ...s, maintenance_mode: e.target.checked ? '1' : '0' }))}
              />
              Режим обслуживания (сайт недоступен для обычных пользователей)
            </label>
          </div>
          <div className="admin-form-group">
            <label className="admin-checkbox-label">
              <input
                type="checkbox"
                checked={settings.registration_enabled === '1'}
                onChange={(e) => setSettings((s) => ({ ...s, registration_enabled: e.target.checked ? '1' : '0' }))}
              />
              Регистрация включена
            </label>
          </div>
          <div className="admin-form-group">
            <label>Email для связи</label>
            <input
              type="email"
              value={settings.contact_email}
              onChange={(e) => setSettings((s) => ({ ...s, contact_email: e.target.value }))}
              placeholder="admin@example.com"
            />
          </div>
          <button type="submit" className="btn btn-primary admin-btn-save" disabled={savingSettings}>
            {savingSettings ? 'Сохранение…' : 'Сохранить настройки'}
          </button>
        </form>
        </section>
      )}

      {broadcastModalOpen && (
        <div className="admin-modal-overlay" onClick={() => !broadcastSending && setBroadcastModalOpen(false)}>
          <div className="admin-modal" onClick={(e) => e.stopPropagation()}>
            <h3>Массовая рассылка</h3>
            <form onSubmit={handleBroadcast}>
              <textarea
                value={broadcastContent}
                onChange={(e) => setBroadcastContent(e.target.value)}
                placeholder="Текст сообщения для всех пользователей..."
                rows={5}
                className="admin-message-textarea"
                maxLength={4000}
              />
              <div className="admin-broadcast-target">
                <label className="admin-radio-label">
                  <input
                    type="radio"
                    name="broadcastTarget"
                    checked={broadcastTarget === 'all'}
                    onChange={() => setBroadcastTarget('all')}
                  />
                  Всем пользователям ({usersTotal} чел.)
                </label>
                <label className="admin-radio-label">
                  <input
                    type="radio"
                    name="broadcastTarget"
                    checked={broadcastTarget === 'page'}
                    onChange={() => setBroadcastTarget('page')}
                  />
                  Только на этой странице ({users.length} чел.)
                </label>
              </div>
              <div className="admin-modal-actions">
                <button type="button" onClick={() => setBroadcastModalOpen(false)} disabled={broadcastSending}>
                  Отмена
                </button>
                <button type="submit" className="btn btn-primary" disabled={broadcastSending || !broadcastContent.trim()}>
                  {broadcastSending ? 'Отправка…' : 'Отправить'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
