import { useEffect, useMemo, useState } from 'react';
import useAuthStore from '../../stores/useAuthStore';
import useCoachStore from '../../stores/useCoachStore';
import { CoachAvatar } from '../../components/Coach/CoachPrimitives';
import { getDisplayName } from '../../utils/displayName';
import BulkAssignModal from '../../components/Coach/BulkAssignModal';
import './CoachGroups.css';

const COLORS = ['#FC4C02', '#EC4899', '#3B82F6', '#22C55E', '#A855F7', '#F59E0B', '#14B8A6', '#6366F1'];

function compliancePct(member, athletesById) {
  const a = athletesById[member.id];
  if (!a) return null;
  const total = Number(a.week_total || 0);
  if (!total) return null;
  return Math.round((Number(a.week_completed || 0) / total) * 100);
}

export default function CoachGroupsView() {
  const { api } = useAuthStore();
  const groups = useCoachStore((s) => s.groups);
  const athletes = useCoachStore((s) => s.athletes);
  const templates = useCoachStore((s) => s.templates);
  const loadAll = useCoachStore((s) => s.loadAll);

  const [activeId, setActiveId] = useState(null);
  const [members, setMembers] = useState([]);
  const [membersLoading, setMembersLoading] = useState(false);
  const [editor, setEditor] = useState(null);
  const [pickerOpen, setPickerOpen] = useState(false);
  const [bulkOpen, setBulkOpen] = useState(false);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (api && groups.length === 0 && athletes.length === 0) loadAll(api);
  }, [api, groups.length, athletes.length, loadAll]);

  useEffect(() => {
    if (activeId == null && groups.length > 0) setActiveId(groups[0].id);
  }, [groups, activeId]);

  const athletesById = useMemo(() => {
    const m = {};
    athletes.forEach((a) => { m[a.id] = a; });
    return m;
  }, [athletes]);

  const active = useMemo(() => groups.find((g) => g.id === activeId) || null, [groups, activeId]);

  useEffect(() => {
    if (!api || activeId == null) { setMembers([]); return undefined; }
    let cancelled = false;
    setMembersLoading(true);
    api.getGroupMembers(activeId)
      .then((res) => { if (!cancelled) setMembers(res?.data?.members || res?.members || []); })
      .catch(() => { if (!cancelled) setMembers([]); })
      .finally(() => { if (!cancelled) setMembersLoading(false); });
    return () => { cancelled = true; };
  }, [api, activeId]);

  const refreshMembers = async () => {
    if (!api || activeId == null) return;
    const res = await api.getGroupMembers(activeId);
    setMembers(res?.data?.members || res?.members || []);
  };

  const saveGroup = async ({ id, name, color }) => {
    if (!api || !name.trim()) return;
    setBusy(true);
    try {
      const res = await api.saveCoachGroup({ group_id: id || undefined, name: name.trim(), color });
      await loadAll(api);
      const d = res?.data ?? res ?? {};
      const newId = d.group_id || d.id || id;
      if (newId) setActiveId(newId);
      setEditor(null);
    } finally { setBusy(false); }
  };

  const deleteGroup = async (id) => {
    if (!api || !window.confirm('Удалить группу? Атлеты останутся, удалится только команда.')) return;
    setBusy(true);
    try {
      await api.deleteCoachGroup(id);
      setActiveId(null);
      await loadAll(api);
      setEditor(null);
    } finally { setBusy(false); }
  };

  const setGroupMembers = async (ids) => {
    if (!api || activeId == null) return;
    setBusy(true);
    try {
      await api.updateGroupMembers(activeId, ids);
      await refreshMembers();
      await loadAll(api);
    } finally { setBusy(false); }
  };

  const removeMember = (id) => setGroupMembers(members.filter((m) => m.id !== id).map((m) => m.id));

  const memberIds = useMemo(() => members.map((m) => m.id), [members]);

  const avgCompliance = useMemo(() => {
    const vals = members.map((m) => compliancePct(m, athletesById)).filter((v) => v != null);
    if (!vals.length) return null;
    return Math.round(vals.reduce((s, v) => s + v, 0) / vals.length);
  }, [members, athletesById]);

  const List = (
    <div className="cg-list">
      {groups.length === 0 && <div className="cg-empty">Групп пока нет</div>}
      {groups.map((g) => (
        <button key={g.id} type="button"
          className={`cg-grow ${activeId === g.id ? 'is-active' : ''}`}
          onClick={() => setActiveId(g.id)}>
          <span className="cg-dot" style={{ background: g.color || COLORS[0] }} />
          <span className="cg-grow-main">
            <span className="cg-grow-name">{g.name}</span>
            <span className="cg-grow-sub">{g.member_count} {athletesWord(g.member_count)}</span>
          </span>
        </button>
      ))}
      <button type="button" className="cg-add-group" onClick={() => setEditor({ name: '', color: COLORS[0] })}>
        + Создать группу
      </button>
    </div>
  );

  const Detail = active ? (
    <div className="cg-detail">
      <div className="cg-card">
        <div className="cg-card-head">
          <span className="cg-dot cg-dot--lg" style={{ background: active.color || COLORS[0] }} />
          <div className="cg-card-titles">
            <div className="cg-card-title">{active.name}</div>
            <div className="cg-card-sub">{active.member_count} {athletesWord(active.member_count)}</div>
          </div>
          <button type="button" className="cg-icon-btn" onClick={() => setEditor({ id: active.id, name: active.name, color: active.color || COLORS[0] })} aria-label="Изменить">✎</button>
        </div>
        <div className="cg-stats">
          <div className="cg-stat"><div className="cg-stat-n">{avgCompliance != null ? `${avgCompliance}%` : '—'}</div><div className="cg-stat-l">ср. выполнение</div></div>
          <div className="cg-stat-div" />
          <div className="cg-stat"><div className="cg-stat-n">{active.member_count}</div><div className="cg-stat-l">атлетов</div></div>
        </div>
      </div>

      <div className="cg-card">
        <div className="cg-card-row">
          <div className="cg-lbl">УЧАСТНИКИ</div>
          <div className="cg-spacer" />
          <button type="button" className="cg-icon-btn" onClick={() => setPickerOpen(true)}>+ Добавить</button>
        </div>
        {membersLoading ? (
          <div className="cg-members-empty">Загрузка…</div>
        ) : members.length === 0 ? (
          <div className="cg-members-empty">В группе пока нет атлетов</div>
        ) : (
          <div className="cg-members">
            {members.map((m) => {
              const pct = compliancePct(m, athletesById);
              return (
                <div key={m.id} className="cg-member">
                  <CoachAvatar athlete={m} size={36} apiBaseUrl={api?.baseUrl || '/api'} />
                  <div className="cg-member-main">
                    <div className="cg-member-name">{getDisplayName(m)}</div>
                    {pct != null && <div className="cg-member-sub">{pct}% за неделю</div>}
                  </div>
                  <button type="button" className="cg-member-del" onClick={() => removeMember(m.id)} aria-label="Убрать">✕</button>
                </div>
              );
            })}
          </div>
        )}
        {members.length > 0 && (
          <button type="button" className="cg-assign-btn" onClick={() => setBulkOpen(true)} disabled={busy}>
            + Назначить тренировку всей группе
          </button>
        )}
      </div>
    </div>
  ) : (
    <div className="cg-detail cg-detail--empty">Выберите группу или создайте новую</div>
  );

  return (
    <div className="cg">
      <div className="cg-layout">
        <aside className="cg-rail">{List}</aside>
        <main className="cg-main">{Detail}</main>
      </div>

      {editor && (
        <GroupEditor
          initial={editor}
          busy={busy}
          onCancel={() => setEditor(null)}
          onSave={saveGroup}
          onDelete={editor.id ? () => deleteGroup(editor.id) : null}
        />
      )}

      {pickerOpen && (
        <MemberPicker
          athletes={athletes}
          memberIds={memberIds}
          apiBaseUrl={api?.baseUrl || '/api'}
          busy={busy}
          onCancel={() => setPickerOpen(false)}
          onConfirm={async (ids) => { await setGroupMembers(ids); setPickerOpen(false); }}
        />
      )}

      <BulkAssignModal
        isOpen={bulkOpen}
        onClose={() => setBulkOpen(false)}
        athletes={athletes}
        groups={groups}
        templates={templates}
        initialSelected={memberIds}
        busy={busy}
        onConfirm={async (payload) => {
          setBusy(true);
          try {
            await api.bulkAssignTraining({ ...payload, overwrite: false });
            setBulkOpen(false);
          } finally { setBusy(false); }
        }}
      />
    </div>
  );
}

function GroupEditor({ initial, busy, onCancel, onSave, onDelete }) {
  const [name, setName] = useState(initial.name || '');
  const [color, setColor] = useState(initial.color || COLORS[0]);
  return (
    <div className="cg-modal-scrim" onClick={onCancel}>
      <div className="cg-modal" onClick={(e) => e.stopPropagation()}>
        <div className="cg-modal-title">{initial.id ? 'Изменить группу' : 'Новая группа'}</div>
        <input className="cg-modal-input" placeholder="Название группы" value={name} autoFocus
          onChange={(e) => setName(e.target.value)} />
        <div className="cg-swatches">
          {COLORS.map((c) => (
            <button key={c} type="button" className={`cg-swatch ${color === c ? 'is-on' : ''}`}
              style={{ background: c }} onClick={() => setColor(c)} aria-label={c} />
          ))}
        </div>
        <div className="cg-modal-actions">
          {onDelete && <button type="button" className="cg-modal-del" onClick={onDelete} disabled={busy}>Удалить</button>}
          <div className="cg-spacer" />
          <button type="button" className="cg-modal-ghost" onClick={onCancel}>Отмена</button>
          <button type="button" className="cg-modal-save" onClick={() => onSave({ id: initial.id, name, color })} disabled={busy || !name.trim()}>
            {busy ? '…' : 'Сохранить'}
          </button>
        </div>
      </div>
    </div>
  );
}

function MemberPicker({ athletes, memberIds, apiBaseUrl, busy, onCancel, onConfirm }) {
  const [sel, setSel] = useState(() => new Set(memberIds));
  const toggle = (id) => setSel((prev) => {
    const next = new Set(prev);
    if (next.has(id)) next.delete(id); else next.add(id);
    return next;
  });
  return (
    <div className="cg-modal-scrim" onClick={onCancel}>
      <div className="cg-modal cg-modal--tall" onClick={(e) => e.stopPropagation()}>
        <div className="cg-modal-title">Участники группы</div>
        <div className="cg-picker">
          {athletes.length === 0 && <div className="cg-members-empty">Нет атлетов</div>}
          {athletes.map((a) => (
            <button key={a.id} type="button" className={`cg-pick ${sel.has(a.id) ? 'is-on' : ''}`} onClick={() => toggle(a.id)}>
              <CoachAvatar athlete={a} size={34} apiBaseUrl={apiBaseUrl} />
              <span className="cg-pick-name">{getDisplayName(a)}</span>
              <span className={`cg-check ${sel.has(a.id) ? 'is-on' : ''}`}>{sel.has(a.id) ? '✓' : ''}</span>
            </button>
          ))}
        </div>
        <div className="cg-modal-actions">
          <div className="cg-spacer" />
          <button type="button" className="cg-modal-ghost" onClick={onCancel}>Отмена</button>
          <button type="button" className="cg-modal-save" onClick={() => onConfirm([...sel])} disabled={busy}>
            {busy ? '…' : 'Готово'}
          </button>
        </div>
      </div>
    </div>
  );
}

function athletesWord(n) {
  const a = Math.abs(n) % 100; const b = a % 10;
  if (a > 10 && a < 20) return 'атлетов';
  if (b > 1 && b < 5) return 'атлета';
  if (b === 1) return 'атлет';
  return 'атлетов';
}
