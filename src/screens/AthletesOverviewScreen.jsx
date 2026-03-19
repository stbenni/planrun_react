/**
 * AthletesOverviewScreen — главный экран тренера
 * Обзор атлетов: карточки с прогрессом, compliance, быстрые действия
 * Секция «Требуют внимания» сверху
 */

import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import useAuthStore from '../stores/useAuthStore';
import { getAvatarSrc } from '../utils/avatarUrl';
import { UsersIcon, MailIcon, TrashIcon, CloseIcon } from '../components/common/Icons';
import './AthletesOverviewScreen.css';

const SORT_OPTIONS = [
  { value: 'activity', label: 'По активности' },
  { value: 'name', label: 'По имени' },
  { value: 'compliance', label: 'По выполняемости' },
];

function daysAgo(dateStr) {
  if (!dateStr) return Infinity;
  const d = new Date(dateStr);
  return Math.floor((Date.now() - d.getTime()) / 86400000);
}

const GOAL_LABELS = {
  race: 'Забег',
  health: 'Здоровье',
  time_improvement: 'Улучшение времени',
  weight_loss: 'Снижение веса',
};

const DISTANCE_LABELS = {
  '5k': '5 км',
  '10k': '10 км',
  'half_marathon': 'Полумарафон',
  'marathon': 'Марафон',
  'ultra': 'Ультра',
};

function formatGoalInfo(athlete) {
  const parts = [];
  if (athlete.goal_type) {
    parts.push(GOAL_LABELS[athlete.goal_type] || athlete.goal_type);
  }
  if (athlete.race_distance) {
    parts.push(DISTANCE_LABELS[athlete.race_distance] || athlete.race_distance);
  }
  if (athlete.race_target_time) {
    parts.push(`цель ${athlete.race_target_time}`);
  }
  return parts.length > 0 ? parts.join(' · ') : null;
}

function formatRaceDate(dateStr) {
  if (!dateStr) return null;
  const d = new Date(dateStr);
  const now = new Date();
  const diffDays = Math.ceil((d - now) / 86400000);
  const formatted = d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
  if (diffDays < 0) return `${formatted} (прошёл)`;
  if (diffDays === 0) return `${formatted} (сегодня!)`;
  if (diffDays <= 7) return `${formatted} (через ${diffDays} дн.)`;
  if (diffDays <= 30) return `${formatted} (через ${Math.ceil(diffDays / 7)} нед.)`;
  return formatted;
}

function formatLastActivity(dateStr) {
  if (!dateStr) return 'Нет данных';
  const days = daysAgo(dateStr);
  if (days === 0) return 'Сегодня';
  if (days === 1) return 'Вчера';
  if (days < 7) return `${days} дн. назад`;
  return new Date(dateStr).toLocaleDateString('ru');
}

function needsAttention(athlete) {
  const days = daysAgo(athlete.last_activity);
  if (days >= 7) return true;
  if (athlete.week_completed !== undefined && athlete.week_total > 0) {
    const compliance = athlete.week_completed / athlete.week_total;
    if (compliance < 0.5) return true;
  }
  return false;
}

function getComplianceColor(completed, total) {
  if (!total || total === 0) return 'var(--text-tertiary)';
  const pct = completed / total;
  if (pct >= 0.8) return 'var(--success-500, #22c55e)';
  if (pct >= 0.5) return 'var(--warning-500, #f59e0b)';
  return 'var(--error-500, #ef4444)';
}

export default function AthletesOverviewScreen() {
  const navigate = useNavigate();
  const { api, user } = useAuthStore();
  const [athletes, setAthletes] = useState([]);
  const [requestsCount, setRequestsCount] = useState(0);
  const [loading, setLoading] = useState(true);
  const [sortBy, setSortBy] = useState('activity');
  const [groups, setGroups] = useState([]);
  const [filterGroupId, setFilterGroupId] = useState(null);
  const [showGroupModal, setShowGroupModal] = useState(false);

  const loadData = useCallback(async () => {
    if (!api) return;
    setLoading(true);
    try {
      const [athRes, reqRes, grpRes] = await Promise.all([
        api.getCoachAthletes(),
        api.getCoachRequests({ status: 'pending' }),
        api.getCoachGroups(),
      ]);
      setAthletes(athRes?.data?.athletes || athRes?.athletes || []);
      const reqs = reqRes?.data?.requests || reqRes?.requests || [];
      setRequestsCount(reqs.length);
      setGroups(grpRes?.data?.groups || grpRes?.groups || []);
    } catch (e) {
      console.error('AthletesOverview load error:', e);
    }
    setLoading(false);
  }, [api]);

  useEffect(() => { loadData(); }, [loadData]);

  const filteredAthletes = useMemo(() => {
    if (!filterGroupId) return athletes;
    return athletes.filter(a =>
      Array.isArray(a.groups) && a.groups.some(g => g.id === filterGroupId)
    );
  }, [athletes, filterGroupId]);

  const { attentionAthletes, normalAthletes } = useMemo(() => {
    const attention = [];
    const normal = [];
    filteredAthletes.forEach(a => {
      if (needsAttention(a)) attention.push(a);
      else normal.push(a);
    });
    return { attentionAthletes: attention, normalAthletes: normal };
  }, [filteredAthletes]);

  const sortedNormal = useMemo(() => {
    const sorted = [...normalAthletes];
    switch (sortBy) {
      case 'name':
        sorted.sort((a, b) => (a.username || '').localeCompare(b.username || '', 'ru'));
        break;
      case 'compliance':
        sorted.sort((a, b) => {
          const ca = a.week_total ? a.week_completed / a.week_total : -1;
          const cb = b.week_total ? b.week_completed / b.week_total : -1;
          return ca - cb;
        });
        break;
      case 'activity':
      default:
        sorted.sort((a, b) => daysAgo(a.last_activity) - daysAgo(b.last_activity));
        break;
    }
    return sorted;
  }, [normalAthletes, sortBy]);

  const sortedAttention = useMemo(() => {
    return [...attentionAthletes].sort((a, b) => daysAgo(b.last_activity) - daysAgo(a.last_activity));
  }, [attentionAthletes]);

  if (loading) {
    return (
      <div className="athletes-overview">
        <h1 className="athletes-overview-title">Мои ученики</h1>
        <div className="athletes-overview-loading">Загрузка...</div>
      </div>
    );
  }

  if (athletes.length === 0) {
    return (
      <div className="athletes-overview">
        <h1 className="athletes-overview-title">Мои ученики</h1>
        <div className="athletes-overview-empty">
          <UsersIcon size={64} strokeWidth={1.5} className="athletes-overview-empty-icon" />
          <h2>У вас пока нет учеников</h2>
          <p>Когда атлеты отправят вам запрос, они появятся здесь.</p>
          {requestsCount > 0 && (
            <button className="btn btn-primary" onClick={() => navigate('/trainers')}>
              <MailIcon size={18} /> Запросы ({requestsCount})
            </button>
          )}
        </div>
      </div>
    );
  }

  return (
    <div className="athletes-overview">
      <div className="athletes-overview-header">
        <h1 className="athletes-overview-title">Мои ученики</h1>
        {requestsCount > 0 && (
          <button className="btn btn-secondary btn--sm athletes-overview-requests-btn" onClick={() => navigate('/trainers')}>
            <MailIcon size={16} /> Запросы <span className="athletes-overview-badge">{requestsCount}</span>
          </button>
        )}
      </div>

      {/* Фильтр по группам */}
      {groups.length > 0 && (
        <div className="ao-groups-bar">
          <button
            className={`ao-group-chip ${!filterGroupId ? 'ao-group-chip--active' : ''}`}
            onClick={() => setFilterGroupId(null)}
          >
            Все
          </button>
          {groups.map(g => (
            <button
              key={g.id}
              className={`ao-group-chip ${filterGroupId === g.id ? 'ao-group-chip--active' : ''}`}
              style={filterGroupId === g.id ? { background: g.color, borderColor: g.color, color: '#fff' } : { borderColor: g.color, color: g.color }}
              onClick={() => setFilterGroupId(filterGroupId === g.id ? null : g.id)}
            >
              {g.name} <span className="ao-group-chip-count">{g.member_count}</span>
            </button>
          ))}
          <button className="ao-group-chip ao-group-chip--manage" onClick={() => setShowGroupModal(true)}>
            Группы...
          </button>
        </div>
      )}

      {groups.length === 0 && athletes.length > 0 && (
        <div className="ao-groups-bar">
          <button className="ao-group-chip ao-group-chip--manage" onClick={() => setShowGroupModal(true)}>
            + Создать группу
          </button>
        </div>
      )}

      {showGroupModal && (
        <GroupsModal
          api={api}
          groups={groups}
          athletes={athletes}
          onClose={() => setShowGroupModal(false)}
          onSave={loadData}
        />
      )}

      {/* Требуют внимания */}
      {sortedAttention.length > 0 && (
        <section className="athletes-attention">
          <h2 className="athletes-section-title athletes-section-title--attention">
            Требуют внимания
            <span className="athletes-attention-count">{sortedAttention.length}</span>
          </h2>
          <div className="athletes-list">
            {sortedAttention.map(a => (
              <AthleteCard key={a.id} athlete={a} navigate={navigate} api={api} attention />
            ))}
          </div>
        </section>
      )}

      {/* Все ученики */}
      <section className="athletes-all">
        <div className="athletes-section-header">
          <h2 className="athletes-section-title">
            {sortedAttention.length > 0 ? 'Остальные' : 'Все ученики'}
            <span className="athletes-count">{sortedNormal.length}</span>
          </h2>
          <select
            className="athletes-sort-select"
            value={sortBy}
            onChange={e => setSortBy(e.target.value)}
          >
            {SORT_OPTIONS.map(o => (
              <option key={o.value} value={o.value}>{o.label}</option>
            ))}
          </select>
        </div>
        <div className="athletes-list">
          {sortedNormal.map(a => (
            <AthleteCard key={a.id} athlete={a} navigate={navigate} api={api} />
          ))}
          {sortedNormal.length === 0 && sortedAttention.length > 0 && (
            <div className="athletes-overview-all-ok">Все ученики в секции «Требуют внимания»</div>
          )}
        </div>
      </section>
    </div>
  );
}

function AthleteCard({ athlete, navigate, api, attention }) {
  const a = athlete;
  const hasCompliance = a.week_total !== undefined && a.week_total > 0;
  const compliancePct = hasCompliance ? Math.round((a.week_completed / a.week_total) * 100) : null;
  const activityDays = daysAgo(a.last_activity);

  const goalInfo = formatGoalInfo(a);
  const raceDate = formatRaceDate(a.race_date);

  return (
    <div className={`ao-card card card--interactive ${attention ? 'ao-card--attention' : ''}`}>
      <Link to={`/${a.username_slug}`} className="ao-card-avatar">
        {a.avatar_path ? (
          <img src={getAvatarSrc(a.avatar_path, api?.baseUrl || '/api', 'sm')} alt="" />
        ) : (
          <div className="ao-card-avatar-placeholder">{(a.username || '?')[0]}</div>
        )}
      </Link>
      <div className="ao-card-body">
        <div className="ao-card-top">
          <Link to={`/${a.username_slug}`} className="ao-card-name">
            {a.username}
            {a.has_new_activity && <span className="ao-card-new-badge">Новое</span>}
          </Link>
          <span className={`ao-card-activity ${activityDays >= 7 ? 'ao-card-activity--stale' : ''}`}>
            {formatLastActivity(a.last_activity)}
          </span>
        </div>
        {(goalInfo || raceDate) && (
          <div className="ao-card-goal">
            {goalInfo && <span className="ao-card-goal-type">{goalInfo}</span>}
            {raceDate && <span className="ao-card-goal-date">{raceDate}</span>}
          </div>
        )}
        {hasCompliance && (
          <div className="ao-card-compliance">
            <div className="ao-card-compliance-bar">
              <div
                className="ao-card-compliance-fill"
                style={{
                  width: `${compliancePct}%`,
                  background: getComplianceColor(a.week_completed, a.week_total),
                }}
              />
            </div>
            <span className="ao-card-compliance-text" style={{ color: getComplianceColor(a.week_completed, a.week_total) }}>
              {a.week_completed}/{a.week_total} ({compliancePct}%)
            </span>
          </div>
        )}
        {Array.isArray(a.groups) && a.groups.length > 0 && (
          <div className="ao-card-groups">
            {a.groups.map(g => (
              <span key={g.id} className="ao-card-group-tag" style={{ background: g.color + '22', color: g.color, borderColor: g.color }}>
                {g.name}
              </span>
            ))}
          </div>
        )}
        <div className="ao-card-actions">
          <button className="btn btn-primary btn--sm" onClick={() => navigate(`/calendar?athlete=${a.username_slug}`)}>
            Календарь
          </button>
          <button className="btn btn-secondary btn--sm" onClick={() => navigate(`/${a.username_slug}`)}>
            Профиль
          </button>
          <button className="btn btn-ghost btn--sm" onClick={() => navigate(`/chat?contact=${a.username_slug}`)}>
            Написать
          </button>
        </div>
      </div>
    </div>
  );
}

const GROUP_COLORS = ['#6366f1', '#ec4899', '#f59e0b', '#22c55e', '#3b82f6', '#8b5cf6', '#ef4444', '#14b8a6'];

function GroupsModal({ api, groups, athletes, onClose, onSave }) {
  const [localGroups, setLocalGroups] = useState(groups);
  const [editingGroup, setEditingGroup] = useState(null); // {id, name, color, memberIds}
  const [newGroupName, setNewGroupName] = useState('');
  const [newGroupColor, setNewGroupColor] = useState(GROUP_COLORS[0]);
  const [saving, setSaving] = useState(false);
  const modalRef = useRef(null);

  const handleCreateGroup = async () => {
    if (!newGroupName.trim() || saving) return;
    setSaving(true);
    try {
      await api.saveCoachGroup({ name: newGroupName.trim(), color: newGroupColor });
      setNewGroupName('');
      onSave();
      const res = await api.getCoachGroups();
      setLocalGroups(res?.data?.groups || res?.groups || []);
    } catch (e) {
      console.error('Create group error:', e);
    }
    setSaving(false);
  };

  const handleDeleteGroup = async (groupId) => {
    if (saving) return;
    setSaving(true);
    try {
      await api.deleteCoachGroup(groupId);
      setLocalGroups(prev => prev.filter(g => g.id !== groupId));
      onSave();
    } catch (e) {
      console.error('Delete group error:', e);
    }
    setSaving(false);
  };

  const handleEditMembers = async (group) => {
    try {
      const res = await api.getGroupMembers(group.id);
      const members = res?.data?.members || res?.members || [];
      setEditingGroup({ ...group, memberIds: members.map(m => m.id) });
    } catch (e) {
      console.error('Load members error:', e);
    }
  };

  const toggleMember = (userId) => {
    if (!editingGroup) return;
    setEditingGroup(prev => {
      const ids = prev.memberIds.includes(userId)
        ? prev.memberIds.filter(id => id !== userId)
        : [...prev.memberIds, userId];
      return { ...prev, memberIds: ids };
    });
  };

  const handleSaveMembers = async () => {
    if (!editingGroup || saving) return;
    setSaving(true);
    try {
      await api.updateGroupMembers(editingGroup.id, editingGroup.memberIds);
      setEditingGroup(null);
      onSave();
      const res = await api.getCoachGroups();
      setLocalGroups(res?.data?.groups || res?.groups || []);
    } catch (e) {
      console.error('Save members error:', e);
    }
    setSaving(false);
  };

  return (
    <div className="ao-modal-overlay" onClick={e => { if (e.target === e.currentTarget) onClose(); }}>
      <div className="ao-modal" ref={modalRef}>
        <div className="ao-modal-header">
          <h2>Группы</h2>
          <button className="ao-modal-close" onClick={onClose} aria-label="Закрыть">
            <CloseIcon className="modal-close-icon" />
          </button>
        </div>

        {editingGroup ? (
          <div className="ao-modal-body">
            <h3 className="ao-modal-subtitle">
              Участники: {editingGroup.name}
            </h3>
            <div className="ao-member-list">
              {athletes.map(a => (
                <label key={a.id} className="ao-member-item">
                  <input
                    type="checkbox"
                    checked={editingGroup.memberIds.includes(a.id)}
                    onChange={() => toggleMember(a.id)}
                  />
                  <span>{a.username}</span>
                </label>
              ))}
            </div>
            <div className="ao-modal-actions">
              <button className="btn btn-secondary btn--sm" onClick={() => setEditingGroup(null)}>Назад</button>
              <button className="btn btn-primary btn--sm" onClick={handleSaveMembers} disabled={saving}>
                {saving ? 'Сохранение...' : 'Сохранить'}
              </button>
            </div>
          </div>
        ) : (
          <div className="ao-modal-body">
            {/* Список групп */}
            {localGroups.length > 0 && (
              <div className="ao-group-list">
                {localGroups.map(g => (
                  <div key={g.id} className="ao-group-item">
                    <span className="ao-group-item-dot" style={{ background: g.color }} />
                    <span className="ao-group-item-name">{g.name}</span>
                    <span className="ao-group-item-count">{g.member_count}</span>
                    <button className="btn btn-ghost btn--sm" onClick={() => handleEditMembers(g)}>
                      Участники
                    </button>
                    <button className="btn btn-ghost btn--sm ao-group-item-delete" onClick={() => handleDeleteGroup(g.id)}>
                      <TrashIcon size={14} />
                    </button>
                  </div>
                ))}
              </div>
            )}

            {/* Создание новой группы */}
            <div className="ao-group-create">
              <input
                type="text"
                className="ao-group-input"
                placeholder="Название группы"
                value={newGroupName}
                onChange={e => setNewGroupName(e.target.value)}
                maxLength={100}
                onKeyDown={e => e.key === 'Enter' && handleCreateGroup()}
              />
              <div className="ao-group-colors">
                {GROUP_COLORS.map(c => (
                  <button
                    key={c}
                    className={`ao-color-btn ${newGroupColor === c ? 'ao-color-btn--active' : ''}`}
                    style={{ background: c }}
                    onClick={() => setNewGroupColor(c)}
                  />
                ))}
              </div>
              <button className="btn btn-primary btn--sm" onClick={handleCreateGroup} disabled={!newGroupName.trim() || saving}>
                Создать
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
