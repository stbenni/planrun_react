/**
 * Админ-панель: управление пользователями и настройками сайта
 * Доступ только для пользователей с role === 'admin'
 */

import React, { useState, useEffect, useCallback, useRef } from 'react';
import { Navigate, useNavigate } from 'react-router-dom';
import useAuthStore from '../stores/useAuthStore';
import LogoLoading from '../components/common/LogoLoading';
import { useIsTabActive } from '../hooks/useIsTabActive';
import { useSwipeableTabs } from '../hooks/useSwipeableTabs';
import './AdminScreen.css';

const TAB_USERS = 'users';
const TAB_SETTINGS = 'settings';
const TAB_COACH_APPS = 'coach_apps';
const ADMIN_TABS = [TAB_USERS, TAB_SETTINGS, TAB_COACH_APPS];
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

const COACH_SPECIALIZATION_LABELS = {
  marathon: 'Марафон',
  half_marathon: 'Полумарафон',
  '5k_10k': '5/10 км',
  ultra: 'Ультра',
  trail: 'Трейл',
  beginner: 'Начинающие',
  injury_recovery: 'Травмы и восстановление',
  nutrition: 'Питание',
  mental: 'Ментальные навыки',
};

const COACH_PRICING_TYPE_LABELS = {
  individual: 'Индивидуальные тренировки',
  group: 'Групповые тренировки',
  consultation: 'Разовая консультация',
  custom: 'Другое',
};

const COACH_PRICING_PERIOD_LABELS = {
  month: 'в месяц',
  week: 'в неделю',
  one_time: 'разово',
};

function formatCoachSpecialization(value) {
  return COACH_SPECIALIZATION_LABELS[value] || value;
}

function formatPricingType(value) {
  return COACH_PRICING_TYPE_LABELS[value] || value || 'Услуга';
}

function formatPricingPeriod(value) {
  return COACH_PRICING_PERIOD_LABELS[value] || value || '';
}

function formatPricingValue(item) {
  if (item?.price === null || item?.price === undefined || item?.price === '') {
    return 'Цена не указана';
  }

  const amount = Number(item.price);
  if (!Number.isFinite(amount)) {
    return 'Цена не указана';
  }

  const currency = item.currency || 'RUB';
  const formatted = new Intl.NumberFormat('ru-RU').format(amount);
  const suffix = currency === 'RUB' ? '₽' : currency;
  const period = formatPricingPeriod(item.period);

  return period ? `${formatted} ${suffix} ${period}` : `${formatted} ${suffix}`;
}

function renderApplicationField(label, value, className = '') {
  return (
    <div className={`admin-coach-app-section ${className}`.trim()}>
      <div className="admin-coach-app-section-label">{label}</div>
      <div className="admin-coach-app-section-value">
        {value && String(value).trim() ? value : 'Не указано'}
      </div>
    </div>
  );
}

export default function AdminScreen() {
  const navigate = useNavigate();
  const { user, api } = useAuthStore();
  const isAdminRouteActive = useIsTabActive('/admin');
  const adminPanelsRef = useRef(null);
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

  useSwipeableTabs({
    containerRef: adminPanelsRef,
    tabs: ADMIN_TABS,
    activeTab: tab,
    onTabChange: setTab,
    enabled: true,
    ignoreSelector: '.admin-table-wrap, [data-swipe-lock="true"], input, textarea, select, [contenteditable="true"]',
  });
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

  // Заявки тренеров
  const [coachApps, setCoachApps] = useState([]);
  const [coachAppsLoading, setCoachAppsLoading] = useState(false);

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

  const loadCoachApps = useCallback(async () => {
    if (!api) return;
    setCoachAppsLoading(true);
    try {
      const res = await api.getCoachApplications({ status: 'pending' });
      const data = res?.data ?? res;
      setCoachApps(Array.isArray(data?.applications) ? data.applications : []);
    } catch (e) {
      setError(e.message || 'Ошибка загрузки заявок');
    } finally {
      setCoachAppsLoading(false);
    }
  }, [api]);

  const handleApproveCoach = async (appId) => {
    if (!api) return;
    try {
      await api.approveCoachApplication(appId);
      await loadCoachApps();
    } catch (e) { setError(e.message); }
  };

  const handleRejectCoach = async (appId) => {
    if (!api) return;
    if (!window.confirm('Отклонить заявку?')) return;
    try {
      await api.rejectCoachApplication(appId);
      await loadCoachApps();
    } catch (e) { setError(e.message); }
  };

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
    if (tab === TAB_COACH_APPS) loadCoachApps();
  }, [isAdmin, api, tab, loadUsers, loadSettings, loadCoachApps]);

  useEffect(() => {
    if (!isAdmin || !api || !isAdminRouteActive || tab !== TAB_COACH_APPS) {
      return;
    }

    loadCoachApps();

    const refreshCoachApps = () => {
      loadCoachApps();
    };

    const handleVisibilityChange = () => {
      if (document.visibilityState === 'visible') {
        refreshCoachApps();
      }
    };

    const intervalId = window.setInterval(refreshCoachApps, 15000);
    window.addEventListener('focus', refreshCoachApps);
    document.addEventListener('visibilitychange', handleVisibilityChange);

    return () => {
      window.clearInterval(intervalId);
      window.removeEventListener('focus', refreshCoachApps);
      document.removeEventListener('visibilitychange', handleVisibilityChange);
    };
  }, [isAdmin, api, isAdminRouteActive, tab, loadCoachApps]);

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

  if (!user) {
    return (
      <div className="loading-container">
        <LogoLoading />
      </div>
    );
  }

  if (!isAdmin) return <Navigate to="/" replace />;

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
        <button
          type="button"
          role="tab"
          aria-selected={tab === TAB_COACH_APPS}
          className={`admin-tab ${tab === TAB_COACH_APPS ? 'active' : ''}`}
          onClick={() => setTab(TAB_COACH_APPS)}
        >
          Заявки тренеров
          {coachApps.length > 0 && <span className="admin-badge">{coachApps.length}</span>}
        </button>
      </nav>

      {error && (
        <div className="admin-error" role="alert">
          {error}
        </div>
      )}

      <div ref={adminPanelsRef} className="admin-tab-panels">
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
            <div className="admin-loading"><LogoLoading size="sm" /></div>
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

      {tab === TAB_COACH_APPS && (
        <section className="admin-section" aria-label="Заявки тренеров">
          {coachAppsLoading ? (
            <div className="admin-loading"><LogoLoading size="sm" /></div>
          ) : coachApps.length === 0 ? (
            <p className="admin-empty">Нет заявок на рассмотрении</p>
          ) : (
            <div className="admin-coach-apps-list">
              {coachApps.map((app) => {
                const specs = Array.isArray(app.coach_specialization)
                  ? app.coach_specialization
                  : (() => { try { return JSON.parse(app.coach_specialization || '[]'); } catch { return []; } })();
                const pricingItems = Array.isArray(app.coach_pricing_json)
                  ? app.coach_pricing_json
                  : (() => { try { return JSON.parse(app.coach_pricing_json || '[]'); } catch { return []; } })();

                return (
                  <div key={app.id} className="admin-coach-app-card">
                    <div className="admin-coach-app-header">
                      <div className="admin-coach-app-header-main">
                        <strong>{app.username || `User #${app.user_id}`}</strong>
                        <div className="admin-coach-app-subline">
                          <span>ID {app.user_id}</span>
                          {app.email && <span>{app.email}</span>}
                          {app.username_slug && <span>@{app.username_slug}</span>}
                        </div>
                      </div>
                      <span className="admin-coach-app-date">
                        {app.created_at ? new Date(app.created_at).toLocaleString('ru-RU') : ''}
                      </span>
                    </div>

                    <div className="admin-coach-app-meta">
                      <span className={`admin-coach-app-pill ${app.coach_accepts_new ? 'admin-coach-app-pill--success' : ''}`}>
                        {app.coach_accepts_new ? 'Принимает новых учеников' : 'Не принимает новых учеников'}
                      </span>
                      <span className="admin-coach-app-pill">
                        {app.coach_prices_on_request ? 'Цены по запросу' : 'Цены опубликованы'}
                      </span>
                      <span className="admin-coach-app-pill">
                        Опыт: {app.coach_experience_years ? `${app.coach_experience_years} лет` : 'не указан'}
                      </span>
                    </div>

                    <div className="admin-coach-app-grid">
                      <div className="admin-coach-app-section admin-coach-app-section--full">
                        <div className="admin-coach-app-section-label">Специализации</div>
                        {specs.length > 0 ? (
                          <div className="admin-coach-app-specs">
                            {specs.map((s) => (
                              <span key={s} className="coach-spec-tag">{formatCoachSpecialization(s)}</span>
                            ))}
                          </div>
                        ) : (
                          <div className="admin-coach-app-section-value">Не указаны</div>
                        )}
                      </div>

                      {renderApplicationField('О себе', app.coach_bio, 'admin-coach-app-section--full')}
                      {renderApplicationField('Тренерская философия', app.coach_philosophy)}
                      {renderApplicationField('Свои достижения как бегун', app.coach_runner_achievements)}
                      {renderApplicationField('Достижения учеников', app.coach_athlete_achievements)}
                      {renderApplicationField('Сертификации', app.coach_certifications)}
                      {renderApplicationField('Дополнительные контакты', app.coach_contacts_extra)}

                      <div className="admin-coach-app-section admin-coach-app-section--full">
                        <div className="admin-coach-app-section-label">Стоимость услуг</div>
                        {app.coach_prices_on_request ? (
                          <div className="admin-coach-app-section-value">Цены по запросу</div>
                        ) : pricingItems.length > 0 ? (
                          <div className="admin-coach-app-pricing-list">
                            {pricingItems.map((item, index) => (
                              <div key={`${item.type || 'pricing'}-${index}`} className="admin-coach-app-pricing-item">
                                <div className="admin-coach-app-pricing-title">
                                  {item.label || formatPricingType(item.type)}
                                </div>
                                <div className="admin-coach-app-pricing-value">
                                  {formatPricingValue(item)}
                                </div>
                              </div>
                            ))}
                          </div>
                        ) : (
                          <div className="admin-coach-app-section-value">Не указана</div>
                        )}
                      </div>
                    </div>

                    <div className="admin-coach-app-actions">
                      <button
                        type="button"
                        className="btn btn-primary btn-sm"
                        onClick={() => handleApproveCoach(app.id)}
                      >
                        Одобрить
                      </button>
                      <button
                        type="button"
                        className="btn btn-outline btn-sm"
                        onClick={() => handleRejectCoach(app.id)}
                      >
                        Отклонить
                      </button>
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </section>
      )}
      </div>

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
