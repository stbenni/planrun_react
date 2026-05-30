/* v2: Coach Desktop — главный экран тренера.
   Тройной режим: Таблица / Сетка / Поток.
   Overlay-панель атлета (drill-in без потери контекста).
   Bulk-actions bar + модалка «Назначить тренировку группе».      */

const { useState: useStateC, useMemo: useMemoC } = React;
const VT = V2.T;

// ─────────────────────────────────────────────────────────────────────
// SHELL: nav + hero + view tabs + content + ticker + overlays
// ─────────────────────────────────────────────────────────────────────
function CoachShell({ initialView = 'table', initialOverlayId = null, initialBulkOpen = false }) {
  const [view, setView] = useStateC(initialView);
  const [activeAthleteId, setActiveAthleteId] = useStateC(initialOverlayId);
  const [selected, setSelected] = useStateC(new Set());
  const [bulkOpen, setBulkOpen] = useStateC(initialBulkOpen);
  const [filterGroup, setFilterGroup] = useStateC('all');

  const filtered = useMemoC(() => {
    if (filterGroup === 'all') return V2.ATHLETES;
    if (filterGroup === 'risk') return V2.ATHLETES.filter(a => a.atRisk);
    if (filterGroup === 'fresh') return V2.ATHLETES.filter(a => a.freshUpload);
    return V2.ATHLETES.filter(a => a.group === filterGroup);
  }, [filterGroup]);

  const counts = {
    risk: V2.ATHLETES.filter(a => a.atRisk).length,
    fresh: V2.ATHLETES.filter(a => a.freshUpload).length,
    questions: V2.EVENTS.filter(e => e.kind === 'question').length,
    avgCompliance: Math.round(V2.ATHLETES.reduce((s, a) => s + a.compliance, 0) / V2.ATHLETES.length * 100),
  };

  const onSelectAll = (ids, on) => {
    const n = new Set(selected);
    if (on) ids.forEach(i => n.add(i)); else ids.forEach(i => n.delete(i));
    setSelected(n);
  };
  const onToggle = (id) => {
    const n = new Set(selected);
    if (n.has(id)) n.delete(id); else n.add(id);
    setSelected(n);
  };

  const activeAthlete = activeAthleteId ? V2.athleteById(activeAthleteId) : null;

  return (
    <div style={CS.shell}>
      {/* Top nav */}
      <header style={CS.topbar}>
        <div style={CS.brand}>
          <span style={CS.brandMark}>P</span>
          <span style={CS.brandText}>planrun</span>
        </div>
        <nav style={CS.nav}>
          {[
            { id: 'team',     label: 'Команда',   active: true },
            { id: 'stream',   label: 'Поток',     badge: counts.risk + counts.questions },
            { id: 'cal',      label: 'Календарь' },
            { id: 'chat',     label: 'Чат',       badge: 7 },
            { id: 'analytics',label: 'Аналитика' },
            { id: 'lib',      label: 'Шаблоны' },
          ].map(t => (
            <a key={t.id} style={{ ...CS.navItem, ...(t.active ? CS.navItemActive : {}) }}>
              {t.label}
              {t.badge != null && <span style={CS.navBadge}>{t.badge}</span>}
            </a>
          ))}
        </nav>
        <div style={CS.topRight}>
          <div style={CS.search}>
            <span style={{ color: VT.ink4 }}>⌕</span>
            <input placeholder="Поиск атлета, тренировки, шаблона…" style={CS.searchInput} />
            <span style={CS.kbd}>⌘K</span>
          </div>
          <button style={CS.iconBtn}>🔔</button>
          <div style={{ width: 36, height: 36, borderRadius: '50%', background: '#FFD9C9', display: 'grid', placeItems: 'center', fontWeight: 700, fontSize: 12, color: VT.ink }}>МК</div>
        </div>
      </header>

      {/* Hero — приоритет дня */}
      <section style={CS.hero}>
        <div style={CS.heroLeft}>
          <div style={{ fontSize: 11, fontWeight: 700, letterSpacing: '0.12em', color: VT.ink3, textTransform: 'uppercase' }}>
            Вторник · 12 мая · доброе утро, Михаил
          </div>
          <h1 style={CS.heroH1}>
            Сегодня <span style={{ color: VT.primary }}>{counts.risk + counts.questions}</span> атлетов ждут вас
          </h1>
        </div>
        <div style={CS.heroCards}>
          <HeroCard label="Требуют внимания"  num={counts.risk}        tone="danger"  icon="!" />
          <HeroCard label="Новые загрузки"    num={counts.fresh}       tone="success" icon="↑" />
          <HeroCard label="Без ответа"        num={counts.questions}   tone="info"    icon="?" />
          <HeroCard label="Средн. compliance" num={counts.avgCompliance + '%'} tone="primary" icon="◐" />
        </div>
      </section>

      {/* Tabs row + primary action */}
      <div style={CS.tabsRow}>
        <div style={CS.tabs}>
          {[
            { id: 'table',  label: 'Таблица',  hint: 'все метрики' },
            { id: 'grid',   label: 'Сетка',    hint: 'тепловая карта' },
            { id: 'stream', label: 'Поток',    hint: 'события' },
          ].map(t => (
            <button key={t.id} onClick={() => setView(t.id)}
              style={{ ...CS.tab, ...(view === t.id ? CS.tabActive : {}) }}>
              <span>{t.label}</span>
              <span style={CS.tabHint}>{t.hint}</span>
            </button>
          ))}
        </div>
        <div style={CS.filterRow}>
          <FilterChip active={filterGroup === 'all'} onClick={() => setFilterGroup('all')}>Все · {V2.ATHLETES.length}</FilterChip>
          {V2.GROUPS.map(g => (
            <FilterChip key={g.id} active={filterGroup === g.id} dot={g.color} onClick={() => setFilterGroup(g.id)}>
              {g.name} · {V2.athletesInGroup(g.id).length}
            </FilterChip>
          ))}
          <span style={{ width: 1, height: 18, background: VT.line, margin: '0 4px' }} />
          <FilterChip active={filterGroup === 'risk'} dot={VT.danger} onClick={() => setFilterGroup('risk')}>⚠ Риск · {counts.risk}</FilterChip>
          <FilterChip active={filterGroup === 'fresh'} dot={VT.success} onClick={() => setFilterGroup('fresh')}>↑ Свежие · {counts.fresh}</FilterChip>
        </div>
        <button onClick={() => setBulkOpen(true)} style={CS.primaryBtn}>+ Назначить тренировку</button>
      </div>

      {/* Content */}
      <main style={CS.main}>
        {view === 'table'  && <TableView   athletes={filtered} selected={selected} onToggle={onToggle} onSelectAll={onSelectAll} onOpen={setActiveAthleteId} active={activeAthleteId} />}
        {view === 'grid'   && <GridView    athletes={filtered} onOpen={setActiveAthleteId} active={activeAthleteId} />}
        {view === 'stream' && <StreamView  events={V2.EVENTS} onOpen={setActiveAthleteId} active={activeAthleteId} />}
      </main>

      {/* Bulk action bar */}
      {selected.size > 0 && (
        <div style={CS.bulkBar}>
          <span style={{ fontWeight: 700 }}>Выбрано · {selected.size}</span>
          <span style={{ color: 'rgba(255,255,255,0.6)' }}>
            {Array.from(selected).slice(0, 3).map(id => V2.athleteById(id)?.name.split(' ')[0]).join(', ')}
            {selected.size > 3 ? ' …' : ''}
          </span>
          <div style={{ flex: 1 }} />
          <button style={CS.bulkBtn} onClick={() => setBulkOpen(true)}>✎ Назначить тренировку</button>
          <button style={CS.bulkBtn}>📋 Применить шаблон…</button>
          <button style={CS.bulkBtn}>✉ Сообщение группе</button>
          <button onClick={() => setSelected(new Set())} style={{ ...CS.bulkBtn, background: 'transparent' }}>✕</button>
        </div>
      )}

      {/* Athlete overlay */}
      {activeAthlete && <AthleteOverlay a={activeAthlete} onClose={() => setActiveAthleteId(null)} />}

      {/* Bulk-assign modal */}
      {bulkOpen && <BulkAssignModal onClose={() => setBulkOpen(false)} selected={selected} setSelected={setSelected} />}
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────
// Hero cards
// ─────────────────────────────────────────────────────────────────────
function HeroCard({ label, num, tone, icon }) {
  const t = V2.toneStyles(tone);
  return (
    <div style={{ ...CS.heroCard, borderColor: t.solid + '40' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
        <span style={{ width: 28, height: 28, borderRadius: 8, background: t.bg, color: t.color, display: 'grid', placeItems: 'center', fontWeight: 800 }}>{icon}</span>
        <span style={{ fontSize: 11, color: VT.ink3, fontWeight: 600, letterSpacing: '0.06em' }}>{label}</span>
      </div>
      <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 44, fontWeight: 800, color: t.color, letterSpacing: '-0.04em', lineHeight: 1, marginTop: 6 }}>{num}</div>
    </div>
  );
}

function FilterChip({ active, dot, onClick, children }) {
  return (
    <button onClick={onClick} style={{
      ...CS.chip,
      ...(active ? CS.chipActive : {}),
    }}>
      {dot && <span style={{ width: 6, height: 6, borderRadius: 999, background: dot }} />}
      {children}
    </button>
  );
}

// ─────────────────────────────────────────────────────────────────────
// VIEW 1: TABLE
// ─────────────────────────────────────────────────────────────────────
function TableView({ athletes, selected, onToggle, onSelectAll, onOpen, active }) {
  const allIds = athletes.map(a => a.id);
  const allSelected = allIds.length > 0 && allIds.every(i => selected.has(i));
  return (
    <div style={CS.tableWrap}>
      <div style={CS.tableHead}>
        <div style={{ width: 28, display: 'grid', placeItems: 'center' }}>
          <input type="checkbox" checked={allSelected} onChange={(e) => onSelectAll(allIds, e.target.checked)} style={CS.cb} />
        </div>
        <div style={{ flex: '2 1 220px' }}>АТЛЕТ</div>
        <div style={{ width: 130 }}>ЦЕЛЬ</div>
        <div style={{ width: 88 }}>ДО ГОНКИ</div>
        <div style={{ width: 110 }}>НЕДЕЛЯ</div>
        <div style={{ width: 110 }}>7 ДНЕЙ · ОБЪЁМ</div>
        <div style={{ width: 130 }}>СЕГОДНЯ ПО ПЛАНУ</div>
        <div style={{ width: 105 }}>АКТИВНОСТЬ</div>
        <div style={{ width: 75, textAlign: 'right' }}>VDOT</div>
      </div>
      <div style={CS.tableBody}>
        {athletes.map(a => {
          const isActive = active === a.id;
          const isSel = selected.has(a.id);
          return (
            <div key={a.id} onClick={() => onOpen(a.id)}
              style={{ ...CS.row, background: isActive ? VT.primaryWash : isSel ? '#FFF8F4' : 'white', borderLeft: isActive ? `3px solid ${VT.primary}` : isSel ? `3px solid ${VT.primary400}` : '3px solid transparent' }}>
              <div style={{ width: 28, display: 'grid', placeItems: 'center' }}>
                <input type="checkbox" checked={isSel} onChange={() => onToggle(a.id)} onClick={(e) => e.stopPropagation()} style={CS.cb} />
              </div>
              <div style={{ flex: '2 1 220px', display: 'flex', alignItems: 'center', gap: 10, minWidth: 0 }}>
                <V2.Avatar a={a} size={36} ring={a.atRisk ? VT.danger : a.freshUpload ? VT.success : null} />
                <div style={{ minWidth: 0 }}>
                  <div style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
                    <span style={{ fontWeight: 600, fontSize: 14, color: VT.ink, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{a.name}</span>
                    {a.unread > 0 && <span style={CS.unread}>{a.unread}</span>}
                  </div>
                  <div style={{ marginTop: 3 }}><V2.GroupTag id={a.group} /></div>
                </div>
              </div>
              <div style={{ width: 130, fontSize: 13 }}>
                <div style={{ color: VT.ink, fontWeight: 500 }}>{a.goal}</div>
                {a.target && <div style={{ color: VT.ink3, fontFamily: '"Jost", sans-serif', fontSize: 12 }}>цель {a.target}</div>}
              </div>
              <div style={{ width: 88, fontFamily: '"Jost", sans-serif' }}>
                {a.daysToRace != null ? (
                  <>
                    <div style={{ fontWeight: 700, fontSize: 15, color: a.daysToRace <= 30 ? VT.primary : VT.ink, lineHeight: 1.1 }}>{a.daysToRace}<span style={{ fontSize: 11, color: VT.ink3 }}> дн.</span></div>
                    <div style={{ fontSize: 11, color: VT.ink3 }}>{a.raceDate}</div>
                  </>
                ) : <span style={{ color: VT.ink4, fontSize: 12 }}>—</span>}
              </div>
              <div style={{ width: 110, display: 'flex', alignItems: 'center', gap: 8 }}>
                <V2.Compliance done={a.weekDone} total={a.weekTotal} w={48} />
                <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 13, fontWeight: 700, color: VT.ink }}>{a.weekDone}/{a.weekTotal}</span>
              </div>
              <div style={{ width: 110, display: 'flex', alignItems: 'center', gap: 8 }}>
                <V2.Sparkline data={a.spark} w={70} h={22} color={a.atRisk ? VT.danger : VT.primary} bg />
                <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 12, color: VT.ink2, fontWeight: 600 }}>{a.spark.reduce((s, x) => s + x, 0)}к</span>
              </div>
              <div style={{ width: 130, fontSize: 13 }}>
                <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                  <span style={{ width: 6, height: 6, borderRadius: 999, background: V2.typeColor(a.todayPlan?.type) }} />
                  <span style={{ color: VT.ink }}>{a.todayPlan?.label || '—'}</span>
                </span>
                {a.todayPlan?.distance > 0 && <div style={{ fontSize: 11, color: VT.ink3, fontFamily: '"Jost", sans-serif' }}>{a.todayPlan.distance} км · {a.todayPlan.pace}</div>}
              </div>
              <div style={{ width: 105, fontSize: 12 }}>
                <div style={{ color: a.atRisk ? VT.danger : VT.ink, fontWeight: a.atRisk ? 600 : 400 }}>{a.lastActivity}</div>
                {a.freshUpload && <div style={CS.freshTag}>↑ новая</div>}
              </div>
              <div style={{ width: 75, textAlign: 'right', fontFamily: '"Jost", sans-serif' }}>
                <div style={{ fontWeight: 700, fontSize: 15, color: VT.ink, lineHeight: 1 }}>{a.vdot}</div>
                <div style={{ fontSize: 10, color: a.paceTrend?.startsWith('+') ? VT.success : a.paceTrend?.startsWith('−') ? VT.danger : VT.ink3, fontWeight: 600, marginTop: 2 }}>{a.paceTrend}</div>
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────
// VIEW 2: GRID — heatmap tiles
// ─────────────────────────────────────────────────────────────────────
function GridView({ athletes, onOpen, active }) {
  return (
    <div style={CS.gridWrap}>
      {athletes.map(a => {
        const pct = a.compliance;
        const color = pct >= 0.8 ? VT.success : pct >= 0.5 ? VT.warning : pct > 0 ? VT.danger : VT.line2;
        const isActive = active === a.id;
        return (
          <div key={a.id} role="button" tabIndex={0}
            onClick={() => onOpen(a.id)}
            style={{ ...CS.tile, borderColor: isActive ? VT.primary : VT.line, borderWidth: isActive ? 1.5 : 1 }}>
            <div style={{ position: 'absolute', top: 0, left: 0, right: 0, height: 3, background: VT.surf3 }}>
              <div style={{ width: `${pct * 100}%`, height: '100%', background: color }} />
            </div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginTop: 6 }}>
              <V2.Avatar a={a} size={36} ring={a.atRisk ? VT.danger : a.freshUpload ? VT.success : null} />
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ fontSize: 14, fontWeight: 700, color: VT.ink, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{a.name.split(' ')[0]} {a.name.split(' ')[1]?.[0]}.</div>
                <div style={{ fontSize: 11, color: VT.ink3 }}>{a.goal} {a.target && '· ' + a.target}</div>
              </div>
            </div>
            <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', marginTop: 14 }}>
              <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 32, fontWeight: 800, color: a.atRisk ? VT.danger : VT.ink, letterSpacing: '-0.03em', lineHeight: 1 }}>
                {a.weekDone}<span style={{ color: VT.ink4, fontWeight: 500, fontSize: 18 }}>/{a.weekTotal}</span>
              </div>
              <div style={{ textAlign: 'right' }}>
                <div style={{ fontSize: 10, color: VT.ink3, fontWeight: 700, letterSpacing: '0.06em' }}>VDOT</div>
                <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 20, fontWeight: 700, color: VT.ink, lineHeight: 1 }}>{a.vdot}</div>
              </div>
            </div>
            <div style={{ marginTop: 10 }}>
              <V2.Sparkline data={a.spark} w={200} h={32} color={a.atRisk ? VT.danger : VT.primary} bg thick />
            </div>
            <div style={{ marginTop: 10, display: 'flex', alignItems: 'center', gap: 6, fontSize: 11 }}>
              {a.atRisk && <span style={{ background: VT.dangerWash, color: VT.danger, padding: '2px 8px', borderRadius: 4, fontWeight: 700 }}>РИСК</span>}
              {a.freshUpload && <span style={{ background: VT.successWash, color: '#166534', padding: '2px 8px', borderRadius: 4, fontWeight: 700 }}>↑ {a.lastActivity}</span>}
              {!a.atRisk && !a.freshUpload && <span style={{ color: VT.ink3 }}>{a.lastActivity}</span>}
              <div style={{ flex: 1 }} />
              {a.daysToRace != null && a.daysToRace <= 60 && (
                <span style={{ fontFamily: '"Jost", sans-serif', fontWeight: 700, color: VT.primary, fontSize: 13 }}>{a.daysToRace}д</span>
              )}
            </div>
          </div>
        );
      })}
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────
// VIEW 3: STREAM — event feed
// ─────────────────────────────────────────────────────────────────────
function StreamView({ events, onOpen, active }) {
  return (
    <div style={CS.streamWrap}>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
        {events.map(ev => {
          const a = V2.athleteById(ev.athleteId);
          const t = V2.toneStyles(ev.tone);
          const isActive = active === a?.id;
          return (
            <div key={ev.id} onClick={() => onOpen(a.id)} role="button" tabIndex={0}
              style={{
                background: 'white', border: `1px solid ${isActive ? VT.primary : VT.line}`,
                borderRadius: 14, padding: '14px 18px', cursor: 'pointer',
                display: 'flex', alignItems: 'center', gap: 14,
              }}>
              <V2.Avatar a={a} size={44} ring={t.solid} />
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                  <span style={{ fontWeight: 700, fontSize: 15, color: VT.ink }}>{a?.name}</span>
                  <V2.GroupTag id={a?.group} />
                  <span style={{ flex: 1 }} />
                  <span style={{ fontSize: 11, color: VT.ink3, fontWeight: 600 }}>{ev.time} назад</span>
                </div>
                <div style={{ marginTop: 4, fontSize: 14, color: VT.ink, fontWeight: 600 }}>{ev.title}</div>
                <div style={{ fontSize: 13, color: VT.ink2, marginTop: 1 }}>{ev.detail}</div>
              </div>
              {ev.cta && (
                <button onClick={(e) => e.stopPropagation()} style={{
                  background: t.solid, color: 'white', border: 'none',
                  padding: '10px 16px', borderRadius: 10, fontSize: 13, fontWeight: 700,
                  cursor: 'pointer', flexShrink: 0, fontFamily: 'inherit',
                }}>{ev.cta} →</button>
              )}
            </div>
          );
        })}
      </div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────
// Athlete drill-in overlay (slide from right)
// ─────────────────────────────────────────────────────────────────────
function AthleteOverlay({ a, onClose }) {
  const [tab, setTab] = useStateC('overview');
  const athleteEvents = V2.EVENTS.filter(e => e.athleteId === a.id);

  return (
    <>
      <div style={CS.overlayScrim} onClick={onClose} />
      <aside style={CS.overlay}>
        {/* Header */}
        <div style={CS.overlayHead}>
          <V2.Avatar a={a} size={56} />
          <div style={{ flex: 1, minWidth: 0 }}>
            <div style={{ fontWeight: 800, fontSize: 22, color: VT.ink, letterSpacing: '-0.02em' }}>{a.name}</div>
            <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginTop: 4 }}>
              <V2.GroupTag id={a.group} />
              <span style={{ fontSize: 12, color: VT.ink3 }}>· {a.goal} {a.target && '· цель ' + a.target}</span>
            </div>
          </div>
          <button onClick={onClose} style={CS.iconBtnSm}>✕</button>
        </div>

        {a.note && (
          <div style={{
            margin: '14px 24px 0', padding: '12px 14px', borderRadius: 10,
            background: a.atRisk ? VT.dangerWash : a.freshUpload ? VT.successWash : VT.surf3,
            color: a.atRisk ? '#991B1B' : a.freshUpload ? '#166534' : VT.ink, fontSize: 13, fontWeight: 500,
          }}>{a.note}</div>
        )}

        {/* Quick actions */}
        <div style={CS.overlayActions}>
          <OvAction icon="✉" label="Чат" primary />
          <OvAction icon="✎" label="Править план" />
          <OvAction icon="↔" label="Перенести" />
          <OvAction icon="📋" label="Шаблон" />
        </div>

        {/* Tabs */}
        <div style={CS.overlayTabs}>
          {[
            ['overview', 'Обзор'],
            ['plan',     'План недели'],
            ['stats',    'Графики'],
            ['chat',     'Чат · ' + (a.unread || 0)],
          ].map(([k, l]) => (
            <button key={k} onClick={() => setTab(k)} style={{ ...CS.overlayTab, ...(tab === k ? CS.overlayTabActive : {}) }}>{l}</button>
          ))}
        </div>

        <div style={CS.overlayBody}>
          {tab === 'overview' && <OverlayOverview a={a} events={athleteEvents} />}
          {tab === 'plan'     && <OverlayPlan a={a} />}
          {tab === 'stats'    && <OverlayStats a={a} />}
          {tab === 'chat'     && <OverlayChat a={a} />}
        </div>
      </aside>
    </>
  );
}

function OvAction({ icon, label, primary }) {
  return (
    <button style={{
      flex: 1, padding: '12px 8px', borderRadius: 10, border: 'none',
      background: primary ? VT.primary : VT.surf3,
      color: primary ? 'white' : VT.ink,
      fontWeight: 600, fontSize: 12, cursor: 'pointer', fontFamily: 'inherit',
      display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 4,
    }}>
      <span style={{ fontSize: 16 }}>{icon}</span>
      {label}
    </button>
  );
}

function OverlayOverview({ a, events }) {
  return (
    <>
      {/* Metrics grid */}
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
        <Metric label="COMPLIANCE" value={`${Math.round(a.compliance * 100)}%`} color={a.compliance >= 0.8 ? VT.success : a.compliance >= 0.5 ? VT.warning : VT.danger} />
        <Metric label="ОБЪЁМ · 7 ДН" value={a.spark.reduce((s, x) => s + x, 0)} suffix="км" />
        <Metric label="VDOT" value={a.vdot} delta={a.paceTrend} />
        <Metric label={a.daysToRace ? 'ДО ГОНКИ' : 'ЦЕЛЬ'} value={a.daysToRace || '∞'} suffix={a.daysToRace ? 'дн.' : ''} />
      </div>

      {/* Today */}
      <div style={CS.section}>
        <div style={CS.sectionLabel}>СЕГОДНЯ ПО ПЛАНУ</div>
        <div style={{ display: 'flex', gap: 12, padding: 14, background: VT.surf3, borderRadius: 12, alignItems: 'center' }}>
          <span style={{ width: 4, alignSelf: 'stretch', background: V2.typeColor(a.todayPlan?.type), borderRadius: 4 }} />
          <div style={{ flex: 1 }}>
            <div style={{ fontWeight: 700, fontSize: 15 }}>{a.todayPlan?.label}</div>
            <div style={{ fontSize: 12, color: VT.ink3, fontFamily: '"Jost", sans-serif' }}>{a.todayPlan?.distance} км {a.todayPlan?.pace && '· ' + a.todayPlan.pace + ' /км'}</div>
          </div>
          <button style={CS.tinyBtn}>Открыть</button>
        </div>
      </div>

      {/* Mini chart */}
      <div style={CS.section}>
        <div style={CS.sectionLabel}>ОБЪЁМ · ПОСЛЕДНИЕ 7 ДНЕЙ</div>
        <div style={{ display: 'flex', alignItems: 'baseline', gap: 10 }}>
          <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 36, fontWeight: 800, color: VT.ink, letterSpacing: '-0.03em' }}>
            {a.spark.reduce((s, x) => s + x, 0)}
          </span>
          <span style={{ color: VT.ink3, fontSize: 13 }}>км</span>
          <span style={{ flex: 1 }} />
          <V2.Sparkline data={a.spark} w={180} h={48} color={VT.primary} bg thick />
        </div>
      </div>

      {/* Events */}
      {events.length > 0 && (
        <div style={CS.section}>
          <div style={CS.sectionLabel}>СОБЫТИЯ</div>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
            {events.map(ev => {
              const t = V2.toneStyles(ev.tone);
              return (
                <div key={ev.id} style={{ display: 'flex', gap: 10, padding: 12, background: VT.surf3, borderRadius: 10 }}>
                  <span style={{ width: 24, height: 24, borderRadius: 6, background: t.bg, color: t.color, display: 'grid', placeItems: 'center', fontWeight: 800, fontSize: 12, flexShrink: 0 }}>{ev.kind === 'upload' ? '↑' : ev.kind === 'risk' ? '!' : ev.kind === 'question' ? '?' : '★'}</span>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ fontSize: 13, color: VT.ink, fontWeight: 600 }}>{ev.title}</div>
                    <div style={{ fontSize: 11, color: VT.ink3 }}>{ev.detail} · {ev.time}</div>
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      )}
    </>
  );
}

function OverlayPlan({ a }) {
  return (
    <>
      <div style={CS.sectionLabel}>ПЛАН НА ЭТУ НЕДЕЛЮ</div>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 6, marginTop: 8 }}>
        {V2.WEEK.map((d, i) => (
          <div key={i} style={{
            display: 'flex', gap: 10, padding: 10, borderRadius: 10,
            background: d.status === 'today' ? VT.primaryWash : VT.surf3,
            border: d.status === 'today' ? `1px solid ${VT.primary}` : `1px solid transparent`,
            alignItems: 'center',
          }}>
            <div style={{ width: 32, textAlign: 'center' }}>
              <div style={{ fontSize: 10, color: VT.ink3, fontWeight: 700 }}>{d.day}</div>
              <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 16, fontWeight: 700, color: d.status === 'today' ? VT.primary : VT.ink }}>{d.date}</div>
            </div>
            <span style={{ width: 3, alignSelf: 'stretch', background: V2.typeColor(d.type), borderRadius: 4 }} />
            <div style={{ flex: 1 }}>
              <div style={{ fontWeight: 600, fontSize: 13 }}>{d.label}</div>
              {d.km > 0 && <div style={{ fontSize: 11, color: VT.ink3, fontFamily: '"Jost", sans-serif' }}>{d.km} км</div>}
            </div>
            {d.status === 'done' && <span style={{ color: VT.success, fontWeight: 700 }}>✓</span>}
            {d.key && <span style={{ background: VT.primary, color: 'white', fontSize: 9, padding: '2px 6px', borderRadius: 4, fontWeight: 700 }}>КЛЮЧ</span>}
            <button style={CS.tinyBtnGhost}>✎</button>
          </div>
        ))}
      </div>
      <button style={{ ...CS.tinyBtn, marginTop: 12, width: '100%', padding: '10px' }}>+ Добавить тренировку</button>
    </>
  );
}

function OverlayStats({ a }) {
  return (
    <>
      <div style={CS.sectionLabel}>VDOT · ДИНАМИКА</div>
      <div style={{ background: VT.surf3, padding: 18, borderRadius: 12, marginTop: 8 }}>
        <div style={{ display: 'flex', alignItems: 'baseline', gap: 10 }}>
          <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 48, fontWeight: 800, color: VT.ink, letterSpacing: '-0.03em' }}>{a.vdot}</span>
          <span style={{ fontSize: 14, color: VT.success, fontWeight: 700 }}>{a.paceTrend}</span>
        </div>
        <V2.Sparkline data={[a.vdot - 4, a.vdot - 3, a.vdot - 3, a.vdot - 2, a.vdot - 1, a.vdot - 1, a.vdot]} w={300} h={60} color={VT.primary} bg thick />
      </div>
      <div style={{ ...CS.section, marginTop: 16 }}>
        <div style={CS.sectionLabel}>ПРОГНОЗЫ ВРЕМЕНИ</div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
          {[['5 км', '20:42'], ['10 км', '43:18'], ['Полумарафон', '1:35:42'], ['Марафон', '3:18:24']].map(([d, t]) => (
            <div key={d} style={{ background: VT.surf3, padding: 12, borderRadius: 10 }}>
              <div style={{ fontSize: 11, color: VT.ink3, fontWeight: 600 }}>{d}</div>
              <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 22, fontWeight: 700, color: VT.ink, letterSpacing: '-0.02em' }}>{t}</div>
            </div>
          ))}
        </div>
      </div>
    </>
  );
}

function OverlayChat({ a }) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
      <div style={{ alignSelf: 'flex-start', maxWidth: '80%', background: VT.surf3, padding: '10px 14px', borderRadius: '14px 14px 14px 4px' }}>
        <div style={{ fontSize: 11, color: VT.ink3, fontWeight: 600 }}>{a.name.split(' ')[0]} · вчера 18:42</div>
        <div style={{ fontSize: 13, color: VT.ink, marginTop: 2 }}>Темповая прошла отлично, последний км получился даже 4:25. Может усложнить на следующей неделе?</div>
      </div>
      <div style={{ alignSelf: 'flex-end', maxWidth: '80%', background: VT.primary, color: 'white', padding: '10px 14px', borderRadius: '14px 14px 4px 14px' }}>
        <div style={{ fontSize: 11, opacity: 0.85, fontWeight: 600 }}>Михаил · вчера 19:01</div>
        <div style={{ fontSize: 13, marginTop: 2 }}>Молодец! Да, поставлю 5×1 км на четверг. Темп держи тот же — 4:30.</div>
      </div>
      <div style={{ alignSelf: 'flex-start', maxWidth: '80%', background: VT.surf3, padding: '10px 14px', borderRadius: '14px 14px 14px 4px' }}>
        <div style={{ fontSize: 11, color: VT.ink3, fontWeight: 600 }}>{a.name.split(' ')[0]} · 12:42</div>
        <div style={{ fontSize: 13, color: VT.ink, marginTop: 2 }}>Окей! Спросить про подводку к Москве?</div>
      </div>
      <div style={{ marginTop: 8, display: 'flex', gap: 8 }}>
        <input placeholder="Ответить…" style={{ flex: 1, padding: '12px 14px', borderRadius: 10, border: `1px solid ${VT.line}`, fontSize: 13, fontFamily: 'inherit', outline: 'none' }} />
        <button style={{ background: VT.primary, color: 'white', border: 'none', padding: '0 16px', borderRadius: 10, fontWeight: 700, cursor: 'pointer', fontFamily: 'inherit' }}>→</button>
      </div>
    </div>
  );
}

function Metric({ label, value, suffix, color, delta }) {
  return (
    <div style={{ background: VT.surf3, padding: 14, borderRadius: 12 }}>
      <div style={{ fontSize: 10, color: VT.ink3, fontWeight: 700, letterSpacing: '0.08em' }}>{label}</div>
      <div style={{ display: 'flex', alignItems: 'baseline', gap: 6, marginTop: 4 }}>
        <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 28, fontWeight: 700, color: color || VT.ink, letterSpacing: '-0.02em', lineHeight: 1 }}>{value}</span>
        {suffix && <span style={{ fontSize: 11, color: VT.ink3 }}>{suffix}</span>}
        {delta && <span style={{ fontSize: 11, color: delta.startsWith('+') ? VT.success : delta.startsWith('−') ? VT.danger : VT.ink3, fontWeight: 700 }}>{delta}</span>}
      </div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────
// Bulk-assign modal
// ─────────────────────────────────────────────────────────────────────
function BulkAssignModal({ onClose, selected, setSelected }) {
  const [step, setStep] = useStateC(selected.size > 0 ? 2 : 1);
  const [templateId, setTemplateId] = useStateC('t1');
  const [date, setDate] = useStateC('завтра');

  const tpl = V2.TEMPLATES.find(t => t.id === templateId);
  const selectedAthletes = Array.from(selected).map(id => V2.athleteById(id)).filter(Boolean);

  return (
    <>
      <div style={CS.modalScrim} onClick={onClose} />
      <div style={CS.modal}>
        <div style={CS.modalHead}>
          <div>
            <div style={{ fontSize: 11, color: VT.ink3, fontWeight: 700, letterSpacing: '0.1em' }}>ШАГ {step} ИЗ 3</div>
            <div style={{ fontSize: 22, fontWeight: 800, color: VT.ink, letterSpacing: '-0.02em' }}>
              {step === 1 && 'Выберите шаблон тренировки'}
              {step === 2 && 'Кому назначаем'}
              {step === 3 && 'Когда и подтверждение'}
            </div>
          </div>
          <button onClick={onClose} style={CS.iconBtnSm}>✕</button>
        </div>

        <div style={CS.stepBar}>
          {[1, 2, 3].map(n => (
            <div key={n} style={{ flex: 1, height: 3, borderRadius: 2, background: n <= step ? VT.primary : VT.line }} />
          ))}
        </div>

        <div style={CS.modalBody}>
          {step === 1 && (
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
              {V2.TEMPLATES.map(t => (
                <button key={t.id} onClick={() => setTemplateId(t.id)} style={{
                  ...CS.tplCard,
                  borderColor: templateId === t.id ? VT.primary : VT.line,
                  background: templateId === t.id ? VT.primaryWash : 'white',
                }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                    <span style={{ fontSize: 28 }}>{t.emoji}</span>
                    <div style={{ flex: 1, textAlign: 'left' }}>
                      <div style={{ fontWeight: 700, fontSize: 14 }}>{t.name}</div>
                      <div style={{ fontSize: 11, color: VT.ink3 }}>{t.distance > 0 ? `${t.distance} км · ` : ''}использован {t.uses} раз</div>
                    </div>
                  </div>
                  <div style={{ marginTop: 8, fontSize: 12, color: VT.ink2, textAlign: 'left' }}>{t.desc}</div>
                </button>
              ))}
            </div>
          )}

          {step === 2 && (
            <>
              <div style={{ display: 'flex', gap: 8, marginBottom: 14, flexWrap: 'wrap' }}>
                {V2.GROUPS.map(g => (
                  <button key={g.id} onClick={() => {
                    const ids = V2.athletesInGroup(g.id).map(a => a.id);
                    setSelected(new Set([...selected, ...ids]));
                  }} style={{ ...CS.chip, ...CS.chipGhost }}>
                    <span style={{ width: 6, height: 6, borderRadius: 999, background: g.color }} />
                    + вся группа «{g.name}»
                  </button>
                ))}
                <button onClick={() => setSelected(new Set(V2.ATHLETES.map(a => a.id)))} style={{ ...CS.chip, ...CS.chipGhost }}>+ Все атлеты</button>
                <button onClick={() => setSelected(new Set())} style={{ ...CS.chip, ...CS.chipGhost, color: VT.danger }}>Очистить</button>
              </div>
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
                {V2.ATHLETES.map(a => {
                  const sel = selected.has(a.id);
                  return (
                    <button key={a.id} onClick={() => {
                      const n = new Set(selected);
                      if (sel) n.delete(a.id); else n.add(a.id);
                      setSelected(n);
                    }} style={{
                      ...CS.athletePick,
                      borderColor: sel ? VT.primary : VT.line,
                      background: sel ? VT.primaryWash : 'white',
                    }}>
                      <input type="checkbox" checked={sel} readOnly style={{ accentColor: VT.primary }} />
                      <V2.Avatar a={a} size={28} />
                      <div style={{ flex: 1, textAlign: 'left', minWidth: 0 }}>
                        <div style={{ fontWeight: 600, fontSize: 13 }}>{a.name}</div>
                        <div style={{ fontSize: 11, color: VT.ink3 }}>{a.goal}</div>
                      </div>
                    </button>
                  );
                })}
              </div>
            </>
          )}

          {step === 3 && (
            <>
              <div style={{ background: VT.surf3, padding: 16, borderRadius: 12 }}>
                <div style={{ fontSize: 11, color: VT.ink3, fontWeight: 700, letterSpacing: '0.08em' }}>СВОДКА</div>
                <div style={{ fontSize: 18, fontWeight: 700, marginTop: 4 }}>
                  {tpl?.emoji} <span style={{ color: V2.typeColor(tpl?.type) }}>{tpl?.name}</span>
                </div>
                <div style={{ fontSize: 13, color: VT.ink2, marginTop: 4 }}>{tpl?.desc}</div>
              </div>
              <div style={{ marginTop: 16 }}>
                <div style={{ fontSize: 11, color: VT.ink3, fontWeight: 700, letterSpacing: '0.08em', marginBottom: 8 }}>КОГДА</div>
                <div style={{ display: 'flex', gap: 6 }}>
                  {['сегодня', 'завтра', 'послезавтра', 'выбрать дату…'].map(d => (
                    <button key={d} onClick={() => setDate(d)} style={{
                      ...CS.chip,
                      ...(date === d ? CS.chipActive : {}),
                    }}>{d}</button>
                  ))}
                </div>
              </div>
              <div style={{ marginTop: 16 }}>
                <div style={{ fontSize: 11, color: VT.ink3, fontWeight: 700, letterSpacing: '0.08em', marginBottom: 8 }}>
                  АТЛЕТОВ · {selectedAthletes.length}
                </div>
                <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                  {selectedAthletes.map(a => (
                    <div key={a.id} style={{ display: 'flex', gap: 6, alignItems: 'center', padding: '4px 10px 4px 4px', background: VT.surf3, borderRadius: 999 }}>
                      <V2.Avatar a={a} size={22} />
                      <span style={{ fontSize: 12, fontWeight: 600 }}>{a.name.split(' ')[0]}</span>
                    </div>
                  ))}
                </div>
              </div>
            </>
          )}
        </div>

        <div style={CS.modalFoot}>
          {step > 1 && <button onClick={() => setStep(step - 1)} style={CS.ghostBtn}>← Назад</button>}
          <span style={{ flex: 1 }} />
          {step < 3 && <button onClick={() => setStep(step + 1)} disabled={step === 2 && selected.size === 0} style={CS.primaryBtn}>Дальше →</button>}
          {step === 3 && <button style={{ ...CS.primaryBtn, background: VT.success }}>✓ Назначить · {selectedAthletes.length} атлетам</button>}
        </div>
      </div>
    </>
  );
}

// ─────────────────────────────────────────────────────────────────────
// Styles
// ─────────────────────────────────────────────────────────────────────
const CS = {
  shell: { width: '100%', height: '100%', background: VT.surf4, display: 'flex', flexDirection: 'column', overflow: 'hidden', fontFamily: 'Montserrat, sans-serif', color: VT.ink, position: 'relative' },

  topbar: { height: 60, padding: '0 24px', display: 'flex', alignItems: 'center', gap: 24, background: 'white', borderBottom: `1px solid ${VT.line}` },
  brand: { display: 'flex', alignItems: 'center', gap: 9 },
  brandMark: { width: 30, height: 30, borderRadius: 9, background: VT.primary, color: 'white', display: 'grid', placeItems: 'center', fontWeight: 800, fontSize: 15 },
  brandText: { fontWeight: 800, fontSize: 17, letterSpacing: '-0.02em' },
  nav: { display: 'flex', gap: 2, flex: 1, marginLeft: 16 },
  navItem: { padding: '8px 14px', borderRadius: 8, fontSize: 13, fontWeight: 500, color: VT.ink2, cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: 6 },
  navItemActive: { background: VT.surf3, color: VT.ink, fontWeight: 700 },
  navBadge: { background: VT.primary, color: 'white', borderRadius: 999, fontSize: 10, padding: '1px 6px', fontWeight: 700, fontFamily: '"Jost", sans-serif' },
  topRight: { display: 'flex', gap: 8, alignItems: 'center' },
  search: { display: 'flex', alignItems: 'center', gap: 6, background: VT.surf3, borderRadius: 9, padding: '7px 12px', width: 320 },
  searchInput: { border: 'none', outline: 'none', fontSize: 13, flex: 1, background: 'transparent', fontFamily: 'inherit' },
  kbd: { fontSize: 11, color: VT.ink3, background: 'white', padding: '2px 6px', borderRadius: 4, border: `1px solid ${VT.line}` },
  iconBtn: { width: 36, height: 36, borderRadius: 9, border: 'none', background: VT.surf3, cursor: 'pointer', fontSize: 14 },
  iconBtnSm: { width: 32, height: 32, borderRadius: 8, border: 'none', background: VT.surf3, cursor: 'pointer', fontSize: 14 },

  hero: { padding: '20px 28px', display: 'flex', alignItems: 'center', gap: 24, background: 'white', borderBottom: `1px solid ${VT.line}` },
  heroLeft: { flex: '1 1 auto' },
  heroH1: { fontSize: 32, fontWeight: 800, letterSpacing: '-0.03em', lineHeight: 1.1, marginTop: 6, color: VT.ink },
  heroCards: { display: 'flex', gap: 10 },
  heroCard: { background: 'white', border: `1.5px solid ${VT.line}`, borderRadius: 14, padding: 14, width: 158 },

  tabsRow: { padding: '14px 24px', display: 'flex', alignItems: 'center', gap: 16, background: 'white', borderBottom: `1px solid ${VT.line}` },
  tabs: { display: 'flex', background: VT.surf3, borderRadius: 10, padding: 3 },
  tab: { padding: '8px 14px', background: 'transparent', border: 'none', borderRadius: 7, cursor: 'pointer', fontFamily: 'inherit', fontWeight: 600, color: VT.ink3, display: 'flex', flexDirection: 'column', alignItems: 'flex-start', gap: 1 },
  tabActive: { background: 'white', color: VT.ink, boxShadow: '0 2px 6px rgba(0,0,0,0.05)' },
  tabHint: { fontSize: 10, fontWeight: 500, color: VT.ink4, letterSpacing: '0.04em' },
  filterRow: { display: 'flex', gap: 4, alignItems: 'center', flex: 1, overflow: 'hidden' },
  chip: { padding: '6px 10px', background: 'white', border: `1px solid ${VT.line}`, borderRadius: 999, fontSize: 12, fontWeight: 600, color: VT.ink2, cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: 6, fontFamily: 'inherit', whiteSpace: 'nowrap' },
  chipActive: { background: VT.ink, color: 'white', borderColor: VT.ink },
  chipGhost: { background: 'transparent', borderStyle: 'dashed' },
  primaryBtn: { padding: '10px 18px', background: VT.primary, color: 'white', border: 'none', borderRadius: 10, fontWeight: 700, fontSize: 13, cursor: 'pointer', fontFamily: 'inherit', whiteSpace: 'nowrap', boxShadow: '0 4px 12px rgba(252,76,2,0.25)' },
  ghostBtn: { padding: '10px 18px', background: 'transparent', color: VT.ink2, border: `1px solid ${VT.line}`, borderRadius: 10, fontWeight: 600, fontSize: 13, cursor: 'pointer', fontFamily: 'inherit' },

  main: { flex: 1, overflow: 'auto', padding: '16px 24px' },

  tableWrap: { background: 'white', borderRadius: 14, border: `1px solid ${VT.line}`, overflow: 'hidden' },
  tableHead: { display: 'flex', padding: '12px 14px', gap: 12, fontSize: 10, color: VT.ink3, fontWeight: 700, letterSpacing: '0.08em', borderBottom: `1px solid ${VT.line}`, background: VT.surf2 },
  tableBody: { },
  row: { display: 'flex', padding: '12px 11px 12px 11px', gap: 12, alignItems: 'center', borderBottom: `1px solid ${VT.line}`, cursor: 'pointer', transition: 'background 0.12s' },
  cb: { width: 15, height: 15, accentColor: VT.primary, cursor: 'pointer' },
  unread: { background: VT.primary, color: 'white', borderRadius: 999, fontSize: 10, padding: '1px 6px', fontWeight: 700, fontFamily: '"Jost", sans-serif' },
  freshTag: { display: 'inline-block', background: VT.successWash, color: '#166534', fontSize: 10, fontWeight: 700, padding: '1px 6px', borderRadius: 4, marginTop: 2 },

  gridWrap: { display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 12 },
  tile: { position: 'relative', background: 'white', border: `1px solid ${VT.line}`, borderRadius: 14, padding: 14, paddingTop: 16, cursor: 'pointer', overflow: 'hidden' },

  streamWrap: { maxWidth: 920, margin: '0 auto' },

  bulkBar: { position: 'absolute', left: 24, right: 24, bottom: 20, background: VT.ink, color: 'white', borderRadius: 12, padding: '12px 16px', display: 'flex', alignItems: 'center', gap: 12, boxShadow: '0 20px 40px rgba(0,0,0,0.2)' },
  bulkBtn: { background: 'rgba(255,255,255,0.12)', color: 'white', border: 'none', padding: '8px 14px', borderRadius: 8, fontSize: 12, fontWeight: 600, cursor: 'pointer', fontFamily: 'inherit' },

  // Overlay
  overlayScrim: { position: 'absolute', inset: 0, background: 'rgba(15,23,42,0.4)', zIndex: 10 },
  overlay: { position: 'absolute', top: 0, right: 0, bottom: 0, width: 480, background: 'white', boxShadow: '-20px 0 40px rgba(0,0,0,0.1)', display: 'flex', flexDirection: 'column', zIndex: 11 },
  overlayHead: { padding: 24, paddingBottom: 0, display: 'flex', gap: 14, alignItems: 'center' },
  overlayActions: { display: 'flex', gap: 6, padding: '16px 24px' },
  overlayTabs: { display: 'flex', padding: '0 24px', borderBottom: `1px solid ${VT.line}` },
  overlayTab: { padding: '12px 0', background: 'transparent', border: 'none', borderBottom: '2px solid transparent', color: VT.ink3, fontWeight: 600, fontSize: 13, cursor: 'pointer', marginRight: 18, fontFamily: 'inherit' },
  overlayTabActive: { color: VT.ink, borderBottomColor: VT.primary, fontWeight: 700 },
  overlayBody: { flex: 1, overflow: 'auto', padding: 24 },
  section: { marginTop: 20 },
  sectionLabel: { fontSize: 10, color: VT.ink3, fontWeight: 700, letterSpacing: '0.1em', marginBottom: 10 },
  tinyBtn: { padding: '6px 12px', background: VT.ink, color: 'white', border: 'none', borderRadius: 8, fontSize: 12, fontWeight: 600, cursor: 'pointer', fontFamily: 'inherit' },
  tinyBtnGhost: { padding: '6px 10px', background: 'transparent', color: VT.ink3, border: `1px solid ${VT.line}`, borderRadius: 8, fontSize: 13, cursor: 'pointer', fontFamily: 'inherit' },

  // Modal
  modalScrim: { position: 'absolute', inset: 0, background: 'rgba(15,23,42,0.5)', zIndex: 20 },
  modal: { position: 'absolute', top: '5%', left: '50%', transform: 'translateX(-50%)', width: 720, maxHeight: '90%', background: 'white', borderRadius: 18, boxShadow: '0 30px 60px rgba(0,0,0,0.25)', display: 'flex', flexDirection: 'column', zIndex: 21, overflow: 'hidden' },
  modalHead: { padding: '20px 24px 14px', display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' },
  stepBar: { display: 'flex', gap: 6, padding: '0 24px' },
  modalBody: { padding: '20px 24px', overflow: 'auto', flex: 1 },
  modalFoot: { padding: '14px 24px', display: 'flex', gap: 10, alignItems: 'center', borderTop: `1px solid ${VT.line}`, background: VT.surf2 },
  tplCard: { padding: 14, background: 'white', border: `1.5px solid ${VT.line}`, borderRadius: 12, cursor: 'pointer', fontFamily: 'inherit', textAlign: 'left' },
  athletePick: { display: 'flex', gap: 10, alignItems: 'center', padding: 10, background: 'white', border: `1.5px solid ${VT.line}`, borderRadius: 10, cursor: 'pointer', fontFamily: 'inherit' },
};

window.CoachShell = CoachShell;
