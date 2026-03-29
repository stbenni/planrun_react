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
const TAB_NOTIFICATION_TEMPLATES = 'notification_templates';
const TAB_COACH_APPS = 'coach_apps';
const ADMIN_TABS = [TAB_USERS, TAB_SETTINGS, TAB_NOTIFICATION_TEMPLATES, TAB_COACH_APPS];
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

function normalizeNotificationTemplateGroups(groups) {
  if (!Array.isArray(groups)) {
    return [];
  }

  return groups
    .filter((group) => group && typeof group === 'object')
    .map((group) => ({
      key: String(group.key || ''),
      label: String(group.label || ''),
      description: String(group.description || ''),
      events: Array.isArray(group.events)
        ? group.events
          .filter((event) => event && typeof event === 'object' && event.event_key)
          .map((event) => ({
            event_key: String(event.event_key || ''),
            label: String(event.label || event.event_key || ''),
            description: String(event.description || ''),
            placeholders: Array.isArray(event.placeholders) ? event.placeholders.map((token) => String(token || '')).filter(Boolean) : [],
            defaults: {
              title_template: String(event.defaults?.title_template || ''),
              body_template: String(event.defaults?.body_template || ''),
              link_template: String(event.defaults?.link_template || ''),
              email_action_label_template: String(event.defaults?.email_action_label_template || ''),
            },
            overrides: {
              title_template: String(event.overrides?.title_template || ''),
              body_template: String(event.overrides?.body_template || ''),
              link_template: String(event.overrides?.link_template || ''),
              email_action_label_template: String(event.overrides?.email_action_label_template || ''),
            },
            has_override: Boolean(event.has_override),
            updated_at: String(event.updated_at || ''),
            updated_by: event.updated_by ?? null,
          }))
        : [],
    }))
    .filter((group) => group.events.length > 0);
}

function buildNotificationTemplateDrafts(groups) {
  return groups.reduce((acc, group) => {
    (group.events || []).forEach((event) => {
      acc[event.event_key] = {
        title_template: event.overrides?.title_template || '',
        body_template: event.overrides?.body_template || '',
        link_template: event.overrides?.link_template || '',
        email_action_label_template: event.overrides?.email_action_label_template || '',
      };
    });
    return acc;
  }, {});
}

function formatNotificationTemplateUpdatedAt(value) {
  if (!value) {
    return '';
  }

  const date = new Date(String(value).replace(' ', 'T'));
  if (Number.isNaN(date.getTime())) {
    return '';
  }

  return date.toLocaleString('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  });
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
  const [notice, setNotice] = useState('');

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

  // Шаблоны уведомлений
  const [notificationTemplateGroups, setNotificationTemplateGroups] = useState([]);
  const [notificationTemplateDrafts, setNotificationTemplateDrafts] = useState({});
  const [notificationTemplatesLoading, setNotificationTemplatesLoading] = useState(false);
  const [savingTemplateKey, setSavingTemplateKey] = useState('');
  const [resettingTemplateKey, setResettingTemplateKey] = useState('');

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

  const loadNotificationTemplates = useCallback(async () => {
    if (!api) return;
    setNotificationTemplatesLoading(true);
    try {
      const res = await api.getAdminNotificationTemplates();
      const data = res?.data ?? res;
      const groups = normalizeNotificationTemplateGroups(data?.groups ?? []);
      setNotificationTemplateGroups(groups);
      setNotificationTemplateDrafts(buildNotificationTemplateDrafts(groups));
    } catch (e) {
      setError(e.message || 'Ошибка загрузки шаблонов уведомлений');
      setNotificationTemplateGroups([]);
      setNotificationTemplateDrafts({});
    } finally {
      setNotificationTemplatesLoading(false);
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
    if (tab === TAB_NOTIFICATION_TEMPLATES) loadNotificationTemplates();
    if (tab === TAB_COACH_APPS) loadCoachApps();
  }, [isAdmin, api, tab, loadUsers, loadSettings, loadNotificationTemplates, loadCoachApps]);

  useEffect(() => {
    if (!isAdmin || !api || !isAdminRouteActive || tab !== TAB_COACH_APPS) {
      return;
    }

    loadCoachApps();

    const handleVisibilityChange = () => {
      if (document.visibilityState === 'visible') {
        loadCoachApps();
      }
    };

    const intervalId = window.setInterval(loadCoachApps, 60000);
    document.addEventListener('visibilitychange', handleVisibilityChange);

    return () => {
      window.clearInterval(intervalId);
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
    setNotice('');
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
      setNotice('Настройки сайта сохранены');
    } catch (e) {
      setError(e.message || 'Ошибка сохранения настроек');
    } finally {
      setSavingSettings(false);
    }
  };

  const handleTemplateDraftChange = useCallback((eventKey, field, value) => {
    setNotificationTemplateDrafts((prev) => ({
      ...prev,
      [eventKey]: {
        ...(prev[eventKey] || {}),
        [field]: value,
      },
    }));
  }, []);

  const handleSaveNotificationTemplate = useCallback(async (eventKey) => {
    if (!api || !csrfToken || !eventKey) {
      return;
    }

    setSavingTemplateKey(eventKey);
    setError('');
    setNotice('');

    try {
      const draft = notificationTemplateDrafts[eventKey] || {};
      await api.updateAdminNotificationTemplate({
        csrf_token: csrfToken,
        event_key: eventKey,
        title_template: draft.title_template || '',
        body_template: draft.body_template || '',
        link_template: draft.link_template || '',
        email_action_label_template: draft.email_action_label_template || '',
      });
      await loadNotificationTemplates();
      setNotice('Шаблон уведомления сохранён');
    } catch (e) {
      setError(e.message || 'Ошибка сохранения шаблона уведомления');
    } finally {
      setSavingTemplateKey('');
    }
  }, [api, csrfToken, loadNotificationTemplates, notificationTemplateDrafts]);

  const handleResetNotificationTemplate = useCallback(async (eventKey) => {
    if (!api || !csrfToken || !eventKey) {
      return;
    }

    setResettingTemplateKey(eventKey);
    setError('');
    setNotice('');

    try {
      await api.resetAdminNotificationTemplate({
        csrf_token: csrfToken,
        event_key: eventKey,
      });
      await loadNotificationTemplates();
      setNotice('Шаблон уведомления сброшен к значениям по умолчанию');
    } catch (e) {
      setError(e.message || 'Ошибка сброса шаблона уведомления');
    } finally {
      setResettingTemplateKey('');
    }
  }, [api, csrfToken, loadNotificationTemplates]);

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
          aria-selected={tab === TAB_NOTIFICATION_TEMPLATES}
          className={`admin-tab ${tab === TAB_NOTIFICATION_TEMPLATES ? 'active' : ''}`}
          onClick={() => setTab(TAB_NOTIFICATION_TEMPLATES)}
        >
          Шаблоны уведомлений
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

      {notice && (
        <div className="admin-success" role="status">
          {notice}
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

      {tab === TAB_NOTIFICATION_TEMPLATES && (
        <section className="admin-section" aria-label="Шаблоны уведомлений">
          <div className="admin-template-head">
            <div>
              <h2 className="admin-template-title">Шаблоны уведомлений</h2>
              <p className="admin-template-desc">
                Пустое поле оставляет дефолт из кода. Для динамических частей используйте переменные вида <code>{'{{body}}'}</code>.
              </p>
            </div>
          </div>

          {notificationTemplatesLoading ? (
            <div className="admin-loading"><LogoLoading size="sm" /></div>
          ) : notificationTemplateGroups.length === 0 ? (
            <p className="admin-empty">Пока нет доступных шаблонов уведомлений</p>
          ) : (
            <div className="admin-template-groups">
              {notificationTemplateGroups.map((group) => (
                <div key={group.key} className="admin-template-group">
                  <div className="admin-template-group-head">
                    <h3>{group.label}</h3>
                    {group.description && <p>{group.description}</p>}
                  </div>

                  <div className="admin-template-list">
                    {group.events.map((event) => {
                      const draft = notificationTemplateDrafts[event.event_key] || {
                        title_template: '',
                        body_template: '',
                        link_template: '',
                        email_action_label_template: '',
                      };
                      const updatedAt = formatNotificationTemplateUpdatedAt(event.updated_at);
                      const isSaving = savingTemplateKey === event.event_key;
                      const isResetting = resettingTemplateKey === event.event_key;

                      return (
                        <article key={event.event_key} className="admin-template-card">
                          <div className="admin-template-card-head">
                            <div>
                              <h4>{event.label}</h4>
                              {event.description && <p>{event.description}</p>}
                            </div>
                            <div className="admin-template-card-meta">
                              <span className={`admin-template-status ${event.has_override ? 'is-custom' : ''}`}>
                                {event.has_override ? 'Переопределён' : 'По умолчанию'}
                              </span>
                              {updatedAt && <span className="admin-template-updated">Обновлён: {updatedAt}</span>}
                            </div>
                          </div>

                          {event.placeholders.length > 0 && (
                            <div className="admin-template-placeholders">
                              {event.placeholders.map((token) => (
                                <span key={`${event.event_key}-${token}`} className="admin-template-token">
                                  {`{{${token}}}`}
                                </span>
                              ))}
                            </div>
                          )}

                          <div className="admin-template-defaults">
                            <div><strong>По умолчанию, title:</strong> {event.defaults.title_template || '—'}</div>
                            <div><strong>По умолчанию, body:</strong> {event.defaults.body_template || '—'}</div>
                            <div><strong>По умолчанию, link:</strong> {event.defaults.link_template || '—'}</div>
                            <div><strong>По умолчанию, CTA:</strong> {event.defaults.email_action_label_template || '—'}</div>
                          </div>

                          <div className="admin-template-grid">
                            <div className="admin-form-group">
                              <label>Title override</label>
                              <input
                                type="text"
                                value={draft.title_template}
                                onChange={(e) => handleTemplateDraftChange(event.event_key, 'title_template', e.target.value)}
                                placeholder={event.defaults.title_template || 'Оставьте пустым для дефолта'}
                              />
                            </div>
                            <div className="admin-form-group">
                              <label>Link override</label>
                              <input
                                type="text"
                                value={draft.link_template}
                                onChange={(e) => handleTemplateDraftChange(event.event_key, 'link_template', e.target.value)}
                                placeholder={event.defaults.link_template || 'Оставьте пустым для дефолта'}
                              />
                            </div>
                            <div className="admin-form-group admin-form-group--full">
                              <label>Body override</label>
                              <textarea
                                value={draft.body_template}
                                onChange={(e) => handleTemplateDraftChange(event.event_key, 'body_template', e.target.value)}
                                placeholder={event.defaults.body_template || 'Оставьте пустым для дефолта'}
                                rows={4}
                              />
                            </div>
                            <div className="admin-form-group">
                              <label>CTA override</label>
                              <input
                                type="text"
                                value={draft.email_action_label_template}
                                onChange={(e) => handleTemplateDraftChange(event.event_key, 'email_action_label_template', e.target.value)}
                                placeholder={event.defaults.email_action_label_template || 'Оставьте пустым для дефолта'}
                              />
                            </div>
                          </div>

                          <div className="admin-template-actions">
                            <button
                              type="button"
                              className="btn btn-primary admin-btn-save"
                              onClick={() => handleSaveNotificationTemplate(event.event_key)}
                              disabled={isSaving || isResetting}
                            >
                              {isSaving ? 'Сохраняем...' : 'Сохранить'}
                            </button>
                            <button
                              type="button"
                              className="admin-btn-reset-template"
                              onClick={() => handleResetNotificationTemplate(event.event_key)}
                              disabled={isSaving || isResetting}
                            >
                              {isResetting ? 'Сбрасываем...' : 'Сбросить'}
                            </button>
                          </div>
                        </article>
                      );
                    })}
                  </div>
                </div>
              ))}
            </div>
          )}
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
