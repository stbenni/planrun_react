/**
 * CoachWorkspace — главный экран тренера (Фаза 1: каркас).
 *
 * Структура (под существующим TopHeader проекта):
 * - Hero: приветствие + 4 KPI-карточки
 * - Tabs row: «Таблица / Сетка / Поток» (placeholder для grid/stream) + filter chips + CTA
 * - Main: AthleteTable (read-only в Ф1)
 * - Drill-in AthleteOverlay при клике на атлета
 *
 * Bulk-actions, мастер «Назначить тренировку», полноценные Сетка/Поток — Фазы 2–3.
 */

import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import useAuthStore from '../stores/useAuthStore';
import useCoachStore, { selectFilteredAthletes, selectKpi } from '../stores/useCoachStore';
import useWorkoutRefreshStore from '../stores/useWorkoutRefreshStore';
import AthleteTable from '../components/Coach/AthleteTable';
import AthleteGrid from '../components/Coach/AthleteGrid';
import AthleteOverlay from '../components/Coach/AthleteOverlay';
import BulkActionBar from '../components/Coach/BulkActionBar';
import BulkAssignModal from '../components/Coach/BulkAssignModal';
import ConfirmConflictDialog from '../components/Coach/ConfirmConflictDialog';
import EventStream from '../components/Coach/EventStream';
import CompareAthletesPanel from '../components/Coach/CompareAthletesPanel';
import EventQuickReplySheet from '../components/Coach/EventQuickReplySheet';
import GroupMessageDialog from '../components/Coach/GroupMessageDialog';
import { TONE } from '../components/Coach/CoachPrimitives';
import { AlertTriangleIcon, UploadIcon, HelpCircleIcon, TargetIcon, ClipboardListIcon, PlusIcon } from '../components/common/Icons';
import './CoachWorkspace.css';

const VIEW_TABS = [
  { id: 'table', label: 'Таблица', hint: 'все метрики' },
  { id: 'grid', label: 'Сетка', hint: 'тепловая карта' },
  { id: 'stream', label: 'Поток', hint: 'события' },
];

function firstName(user) {
  if (!user) return '';
  if (user.name) return String(user.name).trim().split(/\s+/)[0];
  return user.username || '';
}

function formatTodayHeader(now = new Date()) {
  const weekday = now.toLocaleDateString('ru-RU', { weekday: 'long' });
  const day = now.getDate();
  const month = now.toLocaleDateString('ru-RU', { month: 'long' });
  return `${weekday} · ${day} ${month}`;
}

export default function CoachWorkspace() {
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const { api, user } = useAuthStore();
  const athletes = useCoachStore((s) => s.athletes);
  const groups = useCoachStore((s) => s.groups);
  const templates = useCoachStore((s) => s.templates);
  const view = useCoachStore((s) => s.view);
  const filterGroup = useCoachStore((s) => s.filterGroup);
  const activeAthleteId = useCoachStore((s) => s.activeAthleteId);
  const loading = useCoachStore((s) => s.loading);
  const loadError = useCoachStore((s) => s.loadError);
  const loadAll = useCoachStore((s) => s.loadAll);
  const setView = useCoachStore((s) => s.setView);
  const setFilterGroup = useCoachStore((s) => s.setFilterGroup);
  const setActiveAthleteId = useCoachStore((s) => s.setActiveAthleteId);
  const selected = useCoachStore((s) => s.selected);
  const toggleSelected = useCoachStore((s) => s.toggleSelected);
  const selectMany = useCoachStore((s) => s.selectMany);
  const clearSelected = useCoachStore((s) => s.clearSelected);
  const bulkAssignOpen = useCoachStore((s) => s.bulkAssignOpen);
  const openBulkAssign = useCoachStore((s) => s.openBulkAssign);
  const closeBulkAssign = useCoachStore((s) => s.closeBulkAssign);

  // Локальное состояние процесса bulk-assign (preflight + confirm)
  const [assignBusy, setAssignBusy] = useState(false);
  const [pendingPayload, setPendingPayload] = useState(null); // { template_id, athlete_ids, date }
  const [conflicts, setConflicts] = useState([]);
  const [compareOpen, setCompareOpen] = useState(false);
  const [quickReplyEvent, setQuickReplyEvent] = useState(null);
  const [groupMsgOpen, setGroupMsgOpen] = useState(false);
  const isMobileView = typeof window !== 'undefined' && window.matchMedia
    ? window.matchMedia('(max-width: 768px)').matches
    : false;

  // Реальные данные из API
  useEffect(() => {
    if (api) loadAll(api);
  }, [api, loadAll]);

  // View ↔ URL sync: ?view=table|grid|stream — позволяет «Поток» в TopHeader
  // открывать поток событий напрямую (а не сначала Дэшборд → tab Поток).
  useEffect(() => {
    const urlView = searchParams.get('view');
    if (urlView && ['table', 'grid', 'stream'].includes(urlView) && urlView !== view) {
      setView(urlView);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchParams]);

  const handleSetView = (next) => {
    setView(next);
    setSearchParams((prev) => {
      const p = new URLSearchParams(prev);
      if (next === 'table') p.delete('view');
      else p.set('view', next);
      return p;
    }, { replace: true });
  };

  // Live updates: переподгружаем при изменении глобальной workout-refresh версии
  // (Strava webhook, чужой save_result и т.д.). Также — мягкий polling каждые 60 сек
  // для подхвата новых событий/сообщений/PR.
  const refreshVersion = useWorkoutRefreshStore((s) => s.version);
  useEffect(() => {
    if (!api || refreshVersion === 0) return;
    loadAll(api);
  }, [api, refreshVersion, loadAll]);

  useEffect(() => {
    if (!api) return undefined;
    const id = setInterval(() => {
      // Тихо обновляем только события (не весь loadAll, чтобы не мигать таблицей)
      useCoachStore.getState().reloadEvents(api);
    }, 60000);
    return () => clearInterval(id);
  }, [api]);

  // Селекторы возвращают новые массивы/объекты — мемоизируем здесь, иначе
  // useSyncExternalStore зациклится (React error #185).
  const events = useCoachStore((s) => s.events);

  const filtered = useMemo(
    () => selectFilteredAthletes({ athletes, filterGroup }),
    [athletes, filterGroup]
  );
  const kpi = useMemo(() => selectKpi({ athletes, events }), [athletes, events]);

  const activeAthlete = useMemo(
    () => athletes.find((a) => String(a.id) === String(activeAthleteId)) || null,
    [athletes, activeAthleteId]
  );

  // URL ↔ activeAthleteId sync. ?athlete=slug&panel=open открывает drill-in,
  // browser back закрывает (через очистку searchParam).
  // 1. URL → state: при изменении slug в URL находим атлета и открываем overlay.
  useEffect(() => {
    const slug = searchParams.get('athlete');
    const panel = searchParams.get('panel');
    if (!slug || panel !== 'open') {
      // Если query очищен — синхронно закрываем overlay
      if (activeAthleteId && !slug) setActiveAthleteId(null);
      return;
    }
    if (athletes.length === 0) return;
    const found = athletes.find((a) => (a.username_slug || a.username) === slug);
    if (found && String(found.id) !== String(activeAthleteId)) {
      setActiveAthleteId(found.id);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchParams, athletes]);

  // 2. state → URL: когда юзер открывает overlay из таблицы — обновить URL.
  const openAthlete = (id) => {
    setActiveAthleteId(id);
    const a = athletes.find((x) => String(x.id) === String(id));
    const slug = a?.username_slug || a?.username;
    if (slug) {
      setSearchParams((prev) => {
        const next = new URLSearchParams(prev);
        next.set('athlete', slug);
        next.set('panel', 'open');
        return next;
      }, { replace: false });
    }
  };

  const closeAthlete = () => {
    setActiveAthleteId(null);
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev);
      next.delete('athlete');
      next.delete('panel');
      return next;
    }, { replace: false });
  };

  const pendingCount = kpi.risk + kpi.questions;
  const greeting = (() => {
    const h = new Date().getHours();
    if (h < 11) return 'доброе утро';
    if (h < 17) return 'добрый день';
    if (h < 23) return 'добрый вечер';
    return 'доброй ночи';
  })();

  return (
    <div className="coach-workspace">
      <section className="coach-workspace__hero">
        <div className="coach-workspace__hero-left">
          <div className="coach-workspace__eyebrow">
            {formatTodayHeader()} · {greeting}{firstName(user) ? `, ${firstName(user)}` : ''}
          </div>
          <h1 className="coach-workspace__h1">
            Сегодня <span className="coach-workspace__h1-accent">{pendingCount}</span>{' '}
            {pluralAtletov(pendingCount)} ждут вас
          </h1>
        </div>
        <div className="coach-workspace__kpi-row">
          <KpiCard label="Требуют внимания" num={kpi.risk} tone="danger" Icon={AlertTriangleIcon} />
          <KpiCard label="Новые загрузки" num={kpi.fresh} tone="success" Icon={UploadIcon} />
          <KpiCard label="Без ответа" num={kpi.questions} tone="info" Icon={HelpCircleIcon} />
          <KpiCard label="Средн. выполнение" num={`${kpi.avgCompliance}%`} tone="primary" Icon={TargetIcon} />
        </div>
      </section>

      <div className="coach-workspace__tabs-row">
        <div className="coach-workspace__view-tabs">
          {VIEW_TABS.map((t) => (
            <button
              key={t.id}
              type="button"
              className={`coach-workspace__view-tab ${view === t.id ? 'coach-workspace__view-tab--active' : ''}`}
              onClick={() => handleSetView(t.id)}
            >
              <span className="coach-workspace__view-tab-label">{t.label}</span>
              <span className="coach-workspace__view-tab-hint">{t.hint}</span>
            </button>
          ))}
        </div>

        <div className="coach-workspace__chips" role="tablist" aria-label="Фильтр групп">
          <FilterChip active={filterGroup === 'all'} onClick={() => setFilterGroup('all')}>
            Все · {athletes.length}
          </FilterChip>
          {groups.map((g) => (
            <FilterChip
              key={g.id}
              active={String(filterGroup) === String(g.id)}
              dot={g.color}
              onClick={() => setFilterGroup(g.id)}
            >
              {g.name} · {athletesInGroup(athletes, g.id)}
            </FilterChip>
          ))}
          {(kpi.risk > 0 || kpi.fresh > 0) && (
            <span className="coach-workspace__chips-divider" aria-hidden />
          )}
          {kpi.risk > 0 && (
            <FilterChip active={filterGroup === 'risk'} dot="var(--danger-500)" onClick={() => setFilterGroup('risk')}>
              ⚠ Риск · {kpi.risk}
            </FilterChip>
          )}
          {kpi.fresh > 0 && (
            <FilterChip active={filterGroup === 'fresh'} dot="var(--success-500)" onClick={() => setFilterGroup('fresh')}>
              ↑ Свежие · {kpi.fresh}
            </FilterChip>
          )}
        </div>

        <button
          type="button"
          className="coach-workspace__cta-ghost"
          onClick={() => navigate('/library')}
          title="Управлять шаблонами тренировок"
        >
          <ClipboardListIcon size={16} /> Шаблоны
        </button>
        <button type="button" className="coach-workspace__cta" onClick={openBulkAssign}>
          <PlusIcon size={16} /> Назначить тренировку
        </button>
      </div>

      <main className="coach-workspace__main">
        {loading && athletes.length === 0 && (
          <div className="coach-workspace__placeholder">Загружаю команду…</div>
        )}
        {loadError && (
          <div className="coach-workspace__placeholder coach-workspace__placeholder--error">
            {loadError}
          </div>
        )}
        {!loading && athletes.length === 0 && (
          <div className="coach-workspace__placeholder">
            У вас пока нет атлетов. Когда они отправят запрос — появятся здесь.
          </div>
        )}
        {athletes.length > 0 && view === 'table' && (
          <AthleteTable
            athletes={filtered}
            activeId={activeAthleteId}
            onOpenAthlete={openAthlete}
            selected={selected}
            onToggleSelected={toggleSelected}
            onSelectMany={selectMany}
          />
        )}
        {athletes.length > 0 && view === 'grid' && (
          <AthleteGrid
            athletes={filtered}
            activeId={activeAthleteId}
            onOpenAthlete={openAthlete}
            selected={selected}
            onToggleSelected={toggleSelected}
          />
        )}
        {athletes.length > 0 && view === 'stream' && (
          <EventStream
            events={events}
            onOpenAthlete={(id) => {
              // На мобиле: bottom-sheet с quick reply (по событию из этого id)
              // Иначе — drill-in overlay.
              if (isMobileView) {
                const ev = events.find((e) => String(e.athlete_id) === String(id));
                if (ev) { setQuickReplyEvent(ev); return; }
              }
              openAthlete(id);
            }}
            onCta={(ev) => {
              if (ev.cta_action === 'reply' || ev.cta_action === 'contact') {
                navigate('/chat');
              } else {
                openAthlete(ev.athlete_id);
              }
            }}
          />
        )}
      </main>

      {activeAthlete && (
        <AthleteOverlay athlete={activeAthlete} onClose={closeAthlete} />
      )}

      {selected.size > 0 && (
        <BulkActionBar
          athletes={athletes}
          selected={selected}
          onClear={clearSelected}
          onAssign={openBulkAssign}
          onCompare={() => setCompareOpen(true)}
          onApplyTemplate={openBulkAssign}
          onSendMessage={() => setGroupMsgOpen(true)}
        />
      )}

      <CompareAthletesPanel
        isOpen={compareOpen}
        athletes={athletes.filter((a) => selected.has(a.id))}
        onClose={() => setCompareOpen(false)}
        onOpenAthlete={(id) => {
          setCompareOpen(false);
          openAthlete(id);
        }}
      />

      <GroupMessageDialog
        isOpen={groupMsgOpen}
        athletes={athletes}
        selectedIds={selected}
        onClose={() => setGroupMsgOpen(false)}
        onSend={async (athleteId, text) => {
          if (!api) throw new Error('Нет соединения');
          return api.chatSendMessageToUser(athleteId, text);
        }}
      />

      <EventQuickReplySheet
        isOpen={!!quickReplyEvent}
        event={quickReplyEvent}
        onClose={() => setQuickReplyEvent(null)}
        onOpenAthlete={(id) => openAthlete(id)}
        onSendMessage={async (payload) => {
          if (!api) return false;
          await api.chatSendMessageToUser(payload.athlete_id, payload.text);
          return true;
        }}
      />


      <BulkAssignModal
        isOpen={bulkAssignOpen}
        onClose={() => {
          if (!assignBusy) closeBulkAssign();
        }}
        athletes={athletes}
        groups={groups}
        templates={templates}
        initialSelected={Array.from(selected)}
        busy={assignBusy}
        onConfirm={async (payload) => {
          if (!api) return;
          setAssignBusy(true);
          try {
            // Preflight: overwrite=false
            const res = await api.bulkAssignTraining({ ...payload, overwrite: false });
            const data = res?.data || res || {};
            if (data.ok) {
              const note = buildSuccessNote(data);
              if (note) console.log('[Coach] bulk-assign:', note);
              closeBulkAssign();
              clearSelected();
              loadAll(api);
            } else if (Array.isArray(data.conflicts) && data.conflicts.length > 0) {
              // Сохраняем payload для повторного запроса с overwrite=true
              setPendingPayload(payload);
              setConflicts(data.conflicts);
            } else if (Array.isArray(data.errors) && data.errors.length > 0) {
              alert(data.errors.join('\n'));
            } else {
              alert('Не удалось назначить тренировку');
            }
          } catch (e) {
            console.error('bulk-assign error:', e);
            alert(e?.message || 'Ошибка назначения');
          } finally {
            setAssignBusy(false);
          }
        }}
      />

      <ConfirmConflictDialog
        isOpen={conflicts.length > 0}
        conflicts={conflicts}
        busy={assignBusy}
        onClose={() => { setConflicts([]); setPendingPayload(null); }}
        onConfirm={async () => {
          if (!api || !pendingPayload) return;
          setAssignBusy(true);
          try {
            const res = await api.bulkAssignTraining({ ...pendingPayload, overwrite: true });
            const data = res?.data || res || {};
            if (data.ok) {
              setConflicts([]);
              setPendingPayload(null);
              closeBulkAssign();
              clearSelected();
              loadAll(api);
            } else {
              alert((data.errors || ['Ошибка перезаписи']).join('\n'));
            }
          } catch (e) {
            console.error('bulk-assign overwrite error:', e);
            alert(e?.message || 'Ошибка перезаписи');
          } finally {
            setAssignBusy(false);
          }
        }}
      />
    </div>
  );
}

function buildSuccessNote(data) {
  const parts = [];
  if (data.assigned) parts.push(`назначено ${data.assigned}`);
  if (data.overwritten) parts.push(`перезаписано ${data.overwritten}`);
  if (data.forbidden_count) parts.push(`нет прав на ${data.forbidden_count}`);
  return parts.join(', ');
}

function KpiCard({ label, num, tone, Icon }) {
  const t = TONE[tone] || TONE.primary;
  return (
    <div
      className="coach-workspace__kpi"
      style={{ borderColor: `color-mix(in srgb, ${t.solid} 25%, var(--card-border))` }}
    >
      <div className="coach-workspace__kpi-head">
        <span
          className="coach-workspace__kpi-icon"
          style={{ background: t.bg, color: t.color }}
          aria-hidden
        >
          {Icon ? <Icon size={16} /> : null}
        </span>
        <span className="coach-workspace__kpi-label">{label}</span>
      </div>
      <div className="coach-workspace__kpi-num" style={{ color: t.color }}>{num}</div>
    </div>
  );
}

function FilterChip({ active, dot, onClick, children }) {
  return (
    <button
      type="button"
      className={`coach-workspace__chip ${active ? 'coach-workspace__chip--active' : ''}`}
      onClick={onClick}
    >
      {dot && <span className="coach-workspace__chip-dot" style={{ background: dot }} aria-hidden />}
      {children}
    </button>
  );
}

function athletesInGroup(athletes, groupId) {
  return athletes.filter((a) => {
    const gs = Array.isArray(a.groups) ? a.groups : [];
    return gs.some((g) => String(g.id) === String(groupId));
  }).length;
}

function pluralAtletov(n) {
  const mod10 = n % 10;
  const mod100 = n % 100;
  if (mod10 === 1 && mod100 !== 11) return 'атлет';
  if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return 'атлета';
  return 'атлетов';
}
