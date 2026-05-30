/* v3 Dark Theme Mobile Dashboard — standalone dark variant.
   Использует V2_DARK токены. Не зависит от light styles.        */

const D = V2_DARK;

function MobileDashDark({ mode = 'ai' }) {
  const [active, setActive] = React.useState('today');
  const scrollRef = React.useRef(null);
  const todayRef = React.useRef(null);
  const weekRef = React.useRef(null);
  const goalRef = React.useRef(null);
  const formRef = React.useRef(null);
  const prRef = React.useRef(null);

  const tabs = [
    { id: 'today', label: 'Сегодня' },
    { id: 'week',  label: 'Неделя' },
    { id: 'goal',  label: 'Цель' },
    { id: 'form',  label: 'Форма' },
    { id: 'pr',    label: 'PR' },
  ];
  const refs = { today: todayRef, week: weekRef, goal: goalRef, form: formRef, pr: prRef };

  const scrollTo = (id) => {
    setActive(id);
    const el = refs[id]?.current;
    if (el && scrollRef.current) scrollRef.current.scrollTo({ top: el.offsetTop - 8, behavior: 'smooth' });
  };
  const onScroll = () => {
    const root = scrollRef.current;
    if (!root) return;
    const y = root.scrollTop + 60;
    let cur = 'today';
    for (const t of tabs) {
      const el = refs[t.id]?.current;
      if (el && el.offsetTop <= y) cur = t.id;
    }
    if (cur !== active) setActive(cur);
  };

  return (
    <div style={DK.shell}>
      <div style={DK.statusBar}>
        <span style={{ fontFamily: '"Jost", sans-serif' }}>9:41</span>
        <span style={{ display: 'flex', gap: 5 }}>●●● ● <span style={{ marginLeft: 6 }}>5G 89%</span></span>
      </div>

      {/* Header */}
      <div style={{ padding: '0 16px 14px', display: 'flex', alignItems: 'center', gap: 10 }}>
        <V2.Avatar a={{ initials: 'АП', tone: '#FFD9C9' }} size={40} />
        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{ fontSize: 10, color: D.ink3, fontWeight: 700, letterSpacing: '0.1em' }}>ВТ · 12 МАЯ</div>
          <div style={{ fontSize: 20, fontWeight: 800, color: D.ink, letterSpacing: '-0.02em', lineHeight: 1.1, marginTop: 2 }}>
            Привет, Алексей
          </div>
        </div>
        <button style={DK.modeBadge} title={mode === 'ai' ? 'AI-тренер' : 'Михаил К.'}>
          {mode === 'ai' ? (
            <div style={DK.aiAvatar}>AI</div>
          ) : (
            <V2.Avatar a={{ initials: 'МК', tone: '#FFD9C9' }} size={32} />
          )}
          <span style={{ position: 'absolute', bottom: 4, right: 4, width: 7, height: 7, borderRadius: 999, background: D.success, border: `2px solid ${D.surf2}` }} />
        </button>
      </div>

      {/* Sticky tabs */}
      <div style={DK.stickyTabsWrap}>
        <div style={DK.stickyTabs}>
          {tabs.map(s => (
            <button key={s.id} onClick={() => scrollTo(s.id)}
              style={{ ...DK.tabBtn, ...(active === s.id ? DK.tabBtnActive : {}) }}>
              {s.label}
            </button>
          ))}
        </div>
      </div>

      <div ref={scrollRef} onScroll={onScroll} style={DK.scroll}>
        {/* Today hero */}
        <div ref={todayRef} style={DK.mobSection}>
          <DarkTodayHero mode={mode} />
        </div>

        {/* Week */}
        <div ref={weekRef} style={DK.mobSection}>
          <DarkWeek />
        </div>

        {/* Goal */}
        <div ref={goalRef} style={DK.mobSection}>
          <DarkGoal />
        </div>

        {/* Form */}
        <div ref={formRef} style={DK.mobSection}>
          <DarkForm />
        </div>

        {/* PR */}
        <div ref={prRef} style={DK.mobSection}>
          <DarkPR />
        </div>

        <div style={{ height: 110 }} />
      </div>

      {/* FAB */}
      <button style={DK.fab} title={mode === 'ai' ? 'AI-чат' : 'Михаил'}>
        {mode === 'ai' ? <span style={{ fontWeight: 800, fontSize: 13, color: 'white' }}>AI</span> : <span style={{ fontWeight: 800, fontSize: 13, color: 'white' }}>МК</span>}
      </button>

      {/* Nav */}
      <DarkNav activeIndex={0} />
    </div>
  );
}

// ── Components ───────────────────────────────────────────────────────
function DarkTodayHero({ mode }) {
  const t = V2.TODAY;
  const tc = V2.typeColor(t.type);
  const isAI = mode === 'ai';

  return (
    <div style={DK.todayCard}>
      <div style={{
        position: 'absolute', top: 0, right: 0, width: 200, height: 200,
        background: `radial-gradient(circle at top right, ${tc}30 0%, transparent 65%)`,
        pointerEvents: 'none',
      }} />

      <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
        <span style={{ width: 8, height: 8, borderRadius: 999, background: tc, boxShadow: `0 0 12px ${tc}` }} />
        <span style={{ fontSize: 11, color: D.ink3, fontWeight: 700, letterSpacing: '0.12em' }}>
          ТЕМПОВАЯ · КЛЮЧЕВАЯ
        </span>
      </div>

      <h1 style={{ fontSize: 34, fontWeight: 800, color: D.ink, letterSpacing: '-0.03em', lineHeight: 1.02, marginTop: 10 }}>
        4×1 км<br/>
        <span style={{ color: tc }}>в темпе</span>
      </h1>

      <div style={{ display: 'flex', gap: 16, marginTop: 18, paddingBottom: 16, borderBottom: `1px solid ${D.line2}` }}>
        <DarkMetric n="8,0" l="км" />
        <DarkMetric n="4:30" l="темп /км" accent />
        <DarkMetric n="42′" l="время ~" />
      </div>

      {/* Interval bar */}
      <div style={{ display: 'flex', height: 8, borderRadius: 999, overflow: 'hidden', marginTop: 18, gap: 1, background: D.surf4 }}>
        {t.segments.map((s, i) => (
          <div key={i} style={{ flex: s.km, background: V2.typeColor(s.type) }} />
        ))}
      </div>
      <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 6, fontSize: 10, color: D.ink3, fontWeight: 600 }}>
        <span>Разм</span><span>1км × 4 + восст.</span><span>Зам</span>
      </div>

      {/* AI quote */}
      <div style={DK.aiQuote}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 8 }}>
          {isAI ? (
            <div style={DK.aiInlineAvatar}>AI</div>
          ) : (
            <V2.Avatar a={{ initials: 'МК', tone: '#FFD9C9' }} size={32} />
          )}
          <div style={{ fontSize: 11, color: D.ink3, fontWeight: 700, letterSpacing: '0.06em' }}>
            {isAI ? 'AI-ТРЕНЕР' : 'МИХАИЛ'} · 7:42
          </div>
          <div style={{ flex: 1 }} />
          <button style={DK.aiBtnSmall}>Спросить →</button>
        </div>
        <div style={{ fontSize: 13.5, color: D.ink, lineHeight: 1.5 }}>{t.coachNote}</div>
      </div>

      <div style={{ display: 'flex', gap: 8, marginTop: 18 }}>
        <button style={DK.cta}>Начать тренировку →</button>
        <button style={DK.ctaIcon}>↔</button>
        <button style={DK.ctaIcon}>✓</button>
      </div>
    </div>
  );
}

function DarkMetric({ n, l, accent }) {
  return (
    <div style={{ flex: 1 }}>
      <div style={{
        fontFamily: '"Jost", sans-serif', fontWeight: 800, fontSize: 36,
        color: accent ? D.primary : D.ink, letterSpacing: '-0.03em', lineHeight: 1,
      }}>{n}</div>
      <div style={{ fontSize: 11, color: D.ink3, marginTop: 4, fontWeight: 600, letterSpacing: '0.04em' }}>{l}</div>
    </div>
  );
}

function DarkWeek() {
  const totalKm = V2.WEEK.reduce((s, d) => s + d.km, 0);
  const doneCnt = V2.WEEK.filter(d => d.status === 'done').length;
  return (
    <div style={DK.card}>
      <div style={{ fontSize: 10, color: D.ink3, fontWeight: 700, letterSpacing: '0.1em' }}>НЕДЕЛЯ 12 · 11–17 МАЯ</div>
      <div style={{ marginTop: 6, display: 'flex', alignItems: 'baseline', gap: 12 }}>
        <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 28, fontWeight: 800, color: D.ink, letterSpacing: '-0.03em', lineHeight: 1 }}>{totalKm}</span>
        <span style={{ fontSize: 13, color: D.ink3 }}>км запланировано</span>
        <span style={{ flex: 1 }} />
        <span style={{ fontSize: 13, fontWeight: 700, color: D.success }}>✓ {doneCnt}/{V2.WEEK.filter(d => d.km > 0).length}</span>
      </div>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 6, marginTop: 14 }}>
        {V2.WEEK.map((d, i) => {
          const isToday = d.status === 'today';
          const isDone = d.status === 'done';
          return (
            <div key={i} style={{
              display: 'flex', gap: 10, padding: '10px 12px', borderRadius: 10, alignItems: 'center',
              background: isToday ? 'rgba(252,76,2,0.12)' : isDone ? D.surf3 : 'transparent',
              border: isToday ? `1.5px solid ${D.primary}` : `1px solid ${isDone ? 'transparent' : D.line2}`,
            }}>
              <div style={{ width: 32, textAlign: 'center', flexShrink: 0 }}>
                <div style={{ fontSize: 10, color: D.ink3, fontWeight: 700, letterSpacing: '0.06em' }}>{d.day}</div>
                <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 17, fontWeight: 800, color: isToday ? D.primary : isDone ? D.ink2 : D.ink, letterSpacing: '-0.02em', lineHeight: 1, marginTop: 2 }}>{d.date}</div>
              </div>
              <div style={{ width: 3, alignSelf: 'stretch', background: V2.typeColor(d.type), borderRadius: 4, opacity: isDone ? 0.5 : 1 }} />
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                  <span style={{ fontWeight: 600, fontSize: 13.5, color: isDone ? D.ink2 : D.ink, textDecoration: isDone ? 'line-through' : 'none' }}>{d.label}</span>
                  {d.key && <span style={{ background: D.primary, color: 'white', fontSize: 9, fontWeight: 800, padding: '2px 5px', borderRadius: 3, letterSpacing: '0.04em' }}>КЛЮЧ</span>}
                </div>
                {d.km > 0 && <div style={{ fontSize: 11, color: D.ink3, fontFamily: '"Jost", sans-serif', marginTop: 2 }}>{d.km} км</div>}
              </div>
              {isDone && <div style={{ width: 22, height: 22, borderRadius: '50%', background: D.success, color: D.surf, display: 'grid', placeItems: 'center', fontWeight: 800, fontSize: 11 }}>✓</div>}
              {isToday && <span style={{ background: D.primary, color: 'white', fontSize: 9, fontWeight: 800, padding: '3px 8px', borderRadius: 4, letterSpacing: '0.06em' }}>СЕГОДНЯ</span>}
            </div>
          );
        })}
      </div>
    </div>
  );
}

function DarkGoal() {
  const g = V2.GOAL;
  return (
    <div style={DK.card}>
      <div style={{ fontSize: 10, color: D.ink3, fontWeight: 700, letterSpacing: '0.1em' }}>ГЛАВНАЯ ЦЕЛЬ</div>
      <h2 style={{ fontSize: 18, fontWeight: 800, color: D.ink, letterSpacing: '-0.01em', marginTop: 6 }}>{g.title}</h2>
      <div style={{ fontSize: 12, color: D.ink3 }}>{g.date}</div>

      <div style={{ marginTop: 16, padding: 18, background: 'linear-gradient(180deg, rgba(252,76,2,0.18), rgba(252,76,2,0.05))', border: `1px solid rgba(252,76,2,0.2)`, borderRadius: 14 }}>
        <div style={{ display: 'flex', alignItems: 'baseline', gap: 10 }}>
          <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 56, fontWeight: 800, color: D.ink, letterSpacing: '-0.04em', lineHeight: 1 }}>{g.daysLeft}</span>
          <span style={{ fontSize: 13, color: D.ink2 }}>дней до старта</span>
        </div>
        <div style={{ marginTop: 12, height: 4, background: 'rgba(255,255,255,0.08)', borderRadius: 999, overflow: 'hidden' }}>
          <div style={{ width: `${g.progress * 100}%`, height: '100%', background: D.primary, boxShadow: `0 0 12px ${D.primary}` }} />
        </div>
        <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 6, fontSize: 10, color: D.ink3 }}>
          <span>Неделя {g.weeksDone}/{g.weeksTotal}</span>
          <span>Фаза: развивающая</span>
        </div>
      </div>

      <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginTop: 14, padding: 14, background: D.surf3, borderRadius: 12 }}>
        <div>
          <div style={{ fontSize: 10, color: D.ink3, fontWeight: 700, letterSpacing: '0.08em' }}>ЦЕЛЬ</div>
          <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 22, fontWeight: 700, color: D.ink, letterSpacing: '-0.02em' }}>{g.target}</div>
        </div>
        <span style={{ fontSize: 18, color: D.ink4 }}>→</span>
        <div>
          <div style={{ fontSize: 10, color: D.ink3, fontWeight: 700, letterSpacing: '0.08em' }}>ПРОГНОЗ</div>
          <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 22, fontWeight: 700, color: D.success, letterSpacing: '-0.02em' }}>{g.predicted}</div>
          <div style={{ fontSize: 10, color: D.success, fontWeight: 700 }}>↓ {g.trend}</div>
        </div>
      </div>
    </div>
  );
}

function DarkForm() {
  return (
    <div style={DK.card}>
      <div style={{ fontSize: 10, color: D.ink3, fontWeight: 700, letterSpacing: '0.1em' }}>ФОРМА И НАГРУЗКА</div>
      <div style={{ display: 'flex', alignItems: 'flex-end', gap: 10, marginTop: 8 }}>
        <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 48, fontWeight: 800, color: D.success, letterSpacing: '-0.04em', lineHeight: 1 }}>+18</span>
        <div style={{ paddingBottom: 6 }}>
          <div style={{ fontSize: 14, fontWeight: 700, color: D.success, display: 'flex', alignItems: 'center', gap: 6 }}>
            Свежий <span style={{ width: 7, height: 7, borderRadius: 999, background: D.success, boxShadow: `0 0 8px ${D.success}` }} />
          </div>
          <div style={{ fontSize: 10, color: D.ink3, fontWeight: 600 }}>TSB · готов к нагрузке</div>
        </div>
      </div>

      <div style={{ marginTop: 12 }}>
        <V2.Sparkline data={[8,12,5,14,18,16,18]} w={300} h={40} color={D.success} bg thick />
      </div>

      <div style={{ display: 'flex', gap: 8, marginTop: 14 }}>
        <DarkMiniStat label="ATL" v="32" color={D.warning} />
        <DarkMiniStat label="CTL" v="50" color={D.info} />
        <DarkMiniStat label="ACWR" v="1.1" color={D.success} sub="опт." />
      </div>

      <div style={{ marginTop: 12, padding: '10px 12px', background: 'rgba(46,213,115,0.12)', border: `1px solid rgba(46,213,115,0.25)`, color: D.success, borderRadius: 10, fontSize: 12.5, lineHeight: 1.4 }}>
        💡 Можно увеличить нагрузку. Целевой TRIMP: 40–65.
      </div>
    </div>
  );
}

function DarkMiniStat({ label, v, sub, color }) {
  return (
    <div style={{ flex: 1, padding: '8px 10px', background: D.surf3, borderRadius: 8 }}>
      <div style={{ fontSize: 9, color: D.ink3, fontWeight: 700, letterSpacing: '0.06em' }}>{label}</div>
      <div style={{ display: 'flex', alignItems: 'baseline', gap: 3 }}>
        <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 18, fontWeight: 700, color: color || D.ink, lineHeight: 1 }}>{v}</span>
        {sub && <span style={{ fontSize: 9, color, fontWeight: 700 }}>{sub}</span>}
      </div>
    </div>
  );
}

function DarkPR() {
  const prs = [
    { label: '5K',  time: '20:14', date: '5 мая', vdot: 52, fresh: true },
    { label: '10K', time: '43:18', date: '21 апр', vdot: 51 },
    { label: 'ПОЛУ', time: '1:36:42', date: '14 мар', vdot: 50 },
    { label: 'МАРАФОН', time: '—' },
  ];
  return (
    <div style={DK.card}>
      <div style={{ fontSize: 10, color: D.ink3, fontWeight: 700, letterSpacing: '0.1em' }}>ЛИЧНЫЕ РЕКОРДЫ</div>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8, marginTop: 10 }}>
        {prs.map(pr => (
          <div key={pr.label} style={{
            padding: 12, background: pr.time === '—' ? D.surf3 : D.surf4,
            border: `1px solid ${pr.fresh ? D.primary + '60' : D.line2}`,
            borderRadius: 10, position: 'relative',
          }}>
            {pr.fresh && <span style={{ position: 'absolute', top: -7, right: 8, background: D.primary, color: 'white', fontSize: 8.5, fontWeight: 800, padding: '2px 6px', borderRadius: 4, letterSpacing: '0.04em' }}>★ НОВЫЙ</span>}
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
              <div style={{ fontSize: 10, color: D.ink3, fontWeight: 700, letterSpacing: '0.06em' }}>{pr.label}</div>
              {pr.vdot && <div style={{ fontSize: 10, color: D.primary, fontWeight: 700, fontFamily: '"Jost", sans-serif' }}>VDOT {pr.vdot}</div>}
            </div>
            <div style={{ fontFamily: '"Jost", sans-serif', fontSize: pr.time === '—' ? 22 : 18, fontWeight: 700, color: pr.time === '—' ? D.ink4 : D.ink, letterSpacing: '-0.02em', marginTop: 6 }}>
              {pr.time}
            </div>
            {pr.date && <div style={{ fontSize: 10, color: D.ink3, marginTop: 2 }}>{pr.date}</div>}
          </div>
        ))}
      </div>
    </div>
  );
}

function DarkNav({ activeIndex = 0 }) {
  const [active, setActive] = React.useState(activeIndex);
  const items = [
    { ic: '◆', label: 'Главная' },
    { ic: '▦', label: 'План' },
    { ic: '◐', label: 'Чат', badge: 2 },
    { ic: '◍', label: 'Прогресс', dot: true },
    { ic: '☰', label: 'Меню' },
  ];
  return (
    <nav style={DK.nav}>
      {items.map((it, i) => {
        const isActive = i === active;
        return (
          <button key={i} onClick={() => setActive(i)} style={{
            display: 'flex', alignItems: 'center', justifyContent: 'center',
            border: 'none', borderRadius: 14, cursor: 'pointer', fontFamily: 'inherit',
            flex: isActive ? '1 1 0' : '0 0 auto',
            padding: isActive ? '10px 14px' : '10px 10px',
            background: isActive ? D.primary : 'transparent',
            boxShadow: isActive ? `0 6px 16px rgba(252,76,2,0.45)` : 'none',
            transition: 'flex 0.34s cubic-bezier(0.33, 1, 0.68, 1), padding 0.34s cubic-bezier(0.33, 1, 0.68, 1), background 0.28s ease, box-shadow 0.28s ease',
          }}>
            <span style={{ position: 'relative', display: 'flex', alignItems: 'center', gap: 8, color: isActive ? 'white' : D.ink3, fontSize: 18, transition: 'color 0.28s ease' }}>
              {it.ic}
              {it.badge && <span style={{
                position: 'absolute', top: -6, right: -8,
                background: isActive ? 'white' : D.primary, color: isActive ? D.primary : 'white',
                fontSize: 9, fontWeight: 700, padding: '1px 5px', borderRadius: 999,
                minWidth: 16, textAlign: 'center', lineHeight: '14px',
                border: `1.5px solid ${isActive ? D.primary : D.surf2}`,
                fontFamily: '"Jost", sans-serif',
              }}>{it.badge}</span>}
              {it.dot && <span style={{ position: 'absolute', top: -2, right: -2, width: 8, height: 8, borderRadius: 999, background: isActive ? 'white' : D.primary, border: `2px solid ${isActive ? D.primary : D.surf2}` }} />}
              <span style={{
                fontSize: 12, fontWeight: 700, color: 'white',
                maxWidth: isActive ? 140 : 0, opacity: isActive ? 1 : 0,
                overflow: 'hidden', whiteSpace: 'nowrap',
                transition: 'max-width 0.34s cubic-bezier(0.33, 1, 0.68, 1), opacity 0.22s ease',
                transitionDelay: isActive ? '0.06s' : '0s',
              }}>{it.label}</span>
            </span>
          </button>
        );
      })}
    </nav>
  );
}

// ── Styles ───────────────────────────────────────────────────────────
const DK = {
  shell: {
    width: '100%', height: '100%', position: 'relative',
    background: D.appBgGradient,
    fontFamily: 'Montserrat, sans-serif', color: D.ink,
    display: 'flex', flexDirection: 'column', overflow: 'hidden',
  },
  statusBar: { height: 36, padding: '0 24px', display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontSize: 13, fontWeight: 700, color: D.ink },

  modeBadge: { position: 'relative', width: 44, height: 44, padding: 0, borderRadius: 14, background: D.surf3, border: `1px solid ${D.line2}`, cursor: 'pointer', flexShrink: 0, display: 'grid', placeItems: 'center', fontFamily: 'inherit' },
  aiAvatar: { width: 32, height: 32, borderRadius: '50%', background: 'linear-gradient(135deg, #FC4C02, #FF6B3D)', color: 'white', display: 'grid', placeItems: 'center', fontWeight: 800, fontSize: 11, boxShadow: '0 0 12px rgba(252,76,2,0.4)' },
  aiInlineAvatar: { width: 32, height: 32, borderRadius: '50%', background: 'linear-gradient(135deg, #FC4C02, #FF6B3D)', color: 'white', display: 'grid', placeItems: 'center', fontWeight: 800, fontSize: 11, flexShrink: 0, boxShadow: '0 0 12px rgba(252,76,2,0.3)' },

  stickyTabsWrap: { position: 'sticky', top: 0, zIndex: 5, background: 'rgba(15,21,29,0.88)', backdropFilter: 'blur(12px)', WebkitBackdropFilter: 'blur(12px)', borderBottom: `1px solid ${D.line}`, flexShrink: 0 },
  stickyTabs: { display: 'flex', gap: 4, padding: '8px 16px', overflowX: 'auto', scrollbarWidth: 'none' },
  tabBtn: { padding: '7px 13px', background: 'transparent', border: 'none', borderRadius: 999, color: D.ink3, fontWeight: 600, fontSize: 13, cursor: 'pointer', fontFamily: 'inherit', whiteSpace: 'nowrap', flexShrink: 0 },
  tabBtnActive: { background: D.primary, color: 'white', fontWeight: 700, boxShadow: `0 4px 14px rgba(252,76,2,0.4)` },

  scroll: { flex: 1, overflow: 'auto', paddingTop: 12, paddingBottom: 100, scrollBehavior: 'smooth' },
  mobSection: { padding: '0 16px', marginBottom: 12 },

  // Cards — dark liquid glass
  card: {
    background: D.cardBg,
    backdropFilter: 'blur(20px) saturate(1.2)',
    WebkitBackdropFilter: 'blur(20px) saturate(1.2)',
    border: `1px solid ${D.cardBorder}`,
    borderRadius: 16, padding: 18,
    boxShadow: `inset 0 1px 0 ${D.cardInsetTop}, 0 12px 28px rgba(0,0,0,0.35), 0 4px 12px rgba(252,76,2,0.08)`,
  },
  todayCard: {
    position: 'relative',
    background: D.cardBgStrong,
    backdropFilter: 'blur(24px) saturate(1.24)',
    WebkitBackdropFilter: 'blur(24px) saturate(1.24)',
    border: `1px solid ${D.cardBorder}`,
    borderRadius: 18, padding: 20, overflow: 'hidden',
    boxShadow: `inset 0 1px 0 rgba(255,255,255,0.06), 0 20px 40px rgba(0,0,0,0.45), 0 8px 20px rgba(252,76,2,0.12)`,
  },

  aiQuote: {
    padding: 14, marginTop: 18,
    background: 'linear-gradient(135deg, rgba(252,76,2,0.15) 0%, rgba(252,76,2,0.04) 100%)',
    border: `1px solid rgba(252,76,2,0.22)`,
    borderRadius: 12,
  },
  aiBtnSmall: { padding: '5px 10px', background: D.primary, color: 'white', border: 'none', borderRadius: 7, fontWeight: 700, fontSize: 11, cursor: 'pointer', fontFamily: 'inherit', flexShrink: 0, boxShadow: '0 4px 10px rgba(252,76,2,0.4)' },

  cta: { flex: 1, padding: '15px 20px', borderRadius: 14, background: D.primary, color: 'white', border: 'none', fontWeight: 700, fontSize: 14, cursor: 'pointer', boxShadow: '0 8px 20px rgba(252,76,2,0.4)', fontFamily: 'inherit' },
  ctaIcon: { width: 48, height: 48, borderRadius: 14, background: D.surf4, color: D.ink, border: 'none', fontWeight: 600, fontSize: 18, cursor: 'pointer', fontFamily: 'inherit', display: 'grid', placeItems: 'center', flexShrink: 0 },

  fab: { position: 'absolute', bottom: 92, right: 16, width: 56, height: 56, borderRadius: '50%', border: 'none', background: 'linear-gradient(135deg, #FC4C02, #FF6B3D)', cursor: 'pointer', fontFamily: 'inherit', boxShadow: '0 12px 28px rgba(252,76,2,0.55), 0 0 0 1px rgba(255,255,255,0.05)', display: 'grid', placeItems: 'center', overflow: 'hidden' },

  nav: {
    position: 'absolute', bottom: 12, left: 12, right: 12,
    display: 'flex', justifyContent: 'space-around', alignItems: 'center', height: 60, padding: '6px 6px',
    background: D.navBg,
    backdropFilter: 'blur(20px) saturate(1.12)',
    WebkitBackdropFilter: 'blur(20px) saturate(1.12)',
    borderRadius: 22,
    border: `1px solid ${D.navBorder}`,
    boxShadow: `inset 0 1px 0 rgba(255,255,255,0.05), 0 16px 30px rgba(0,0,0,0.45), 0 6px 18px rgba(252,76,2,0.1)`,
  },
};

window.MobileDashDark = MobileDashDark;

// ────────────────────────────────────────────────────────────────────
// DESKTOP DASHBOARD DARK (1440×900)
// ────────────────────────────────────────────────────────────────────
function DesktopDashDark({ mode = 'ai' }) {
  return (
    <div style={DKD.shell}>
      {/* App top */}
      <div style={DKD.top}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          <span style={{ width: 30, height: 30, borderRadius: 9, background: 'linear-gradient(135deg, #FC4C02, #FF6B3D)', color: 'white', display: 'grid', placeItems: 'center', fontWeight: 800, fontSize: 15, boxShadow: '0 4px 12px rgba(252,76,2,0.35)' }}>P</span>
          <span style={{ fontWeight: 800, fontSize: 17, letterSpacing: '-0.02em', color: D.ink }}>planrun</span>
        </div>
        <nav style={{ display: 'flex', gap: 2, marginLeft: 24 }}>
          {[['Дэшборд', true], ['Календарь', false], ['Чат', false], ['Прогресс', false], ['Настройки', false]].map(([l, on]) => (
            <a key={l} style={{
              padding: '8px 14px', borderRadius: 8, fontSize: 13,
              fontWeight: on ? 700 : 500,
              color: on ? D.ink : D.ink3,
              background: on ? D.surf3 : 'transparent',
              cursor: 'pointer',
            }}>{l}</a>
          ))}
        </nav>
        <div style={{ flex: 1 }} />
        <button style={DKD.ghostBtn}>⚙ Настроить виджеты</button>
        <button style={DKD.primaryBtn}>+ Тренировка</button>
      </div>

      {/* Greeting */}
      <div style={{ padding: '24px 32px 0' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 16, paddingBottom: 16 }}>
          <V2.Avatar a={{ initials: 'АП', tone: '#FFD9C9' }} size={48} />
          <div style={{ flex: 1, minWidth: 0 }}>
            <div style={{ fontSize: 11, color: D.ink3, fontWeight: 700, letterSpacing: '0.1em' }}>ВТОРНИК · 12 МАЯ</div>
            <div style={{ fontSize: 24, fontWeight: 800, color: D.ink, letterSpacing: '-0.02em', lineHeight: 1.1, marginTop: 2 }}>Привет, Алексей 👋</div>
            <div style={{ fontSize: 12, color: D.ink2, marginTop: 4 }}>
              На этой неделе: <b style={{ color: D.ink }}>5/5 ключевых · 60 км · форма растёт</b>
            </div>
          </div>
          <button style={DKD.modeBadgeWide}>
            {mode === 'ai' ? (
              <div style={DK.aiAvatar}>AI</div>
            ) : (
              <div style={{ position: 'relative' }}>
                <V2.Avatar a={{ initials: 'МК', tone: '#FFD9C9' }} size={36} />
                <div style={{ position: 'absolute', bottom: -1, right: -1, width: 11, height: 11, borderRadius: '50%', background: D.success, border: `2px solid ${D.surf2}` }} />
              </div>
            )}
            <div>
              <div style={{ fontSize: 11, color: D.ink3, fontWeight: 600, letterSpacing: '0.04em' }}>
                {mode === 'ai' ? 'РЕЖИМ' : 'ТРЕНЕР · ОНЛАЙН'}
              </div>
              <div style={{ fontSize: 13, fontWeight: 700, color: D.ink, display: 'flex', alignItems: 'center', gap: 5 }}>
                {mode === 'ai' ? 'AI-тренер' : 'Михаил К.'}
                {mode === 'ai' && <span style={{ width: 7, height: 7, borderRadius: 999, background: D.success, boxShadow: `0 0 8px ${D.success}` }} />}
              </div>
            </div>
          </button>
          <button style={DKD.bellBtn}>
            🔔<span style={{ position: 'absolute', top: 8, right: 9, width: 7, height: 7, borderRadius: 999, background: D.primary, border: `1.5px solid ${D.surf2}` }} />
          </button>
        </div>
      </div>

      {/* 2-column grid */}
      <div style={DKD.grid}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
          <DarkDesktopTodayHero mode={mode} />
          <DarkNext />
          <DarkWeek />
          <DarkForm />
          <DarkStatsDesk />
        </div>
        <aside style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
          <DarkGoal />
          <DarkPR />
          <DarkTrendsSmall />
          <DarkRacePred />
          <DarkPaceZones />
        </aside>
      </div>
    </div>
  );
}

// Large hero variant for desktop
function DarkDesktopTodayHero({ mode }) {
  const t = V2.TODAY;
  const tc = V2.typeColor(t.type);
  const isAI = mode === 'ai';

  return (
    <div style={{ ...DK.todayCard, padding: 28 }}>
      <div style={{ position: 'absolute', top: 0, right: 0, width: 320, height: 320, background: `radial-gradient(circle at top right, ${tc}28 0%, transparent 65%)`, pointerEvents: 'none' }} />
      <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
        <span style={{ width: 8, height: 8, borderRadius: 999, background: tc, boxShadow: `0 0 12px ${tc}` }} />
        <span style={{ fontSize: 11, color: D.ink3, fontWeight: 700, letterSpacing: '0.12em' }}>ТЕМПОВАЯ · КЛЮЧЕВАЯ</span>
      </div>
      <h1 style={{ fontSize: 56, fontWeight: 800, color: D.ink, letterSpacing: '-0.03em', lineHeight: 1.02, marginTop: 12 }}>
        4×1 км <span style={{ color: tc }}>в темпе</span>
      </h1>

      <div style={{ display: 'flex', gap: 24, marginTop: 22, paddingBottom: 18, borderBottom: `1px solid ${D.line2}` }}>
        <DarkMetric n="8,0" l="км" />
        <DarkMetric n="4:30" l="темп /км" accent />
        <DarkMetric n="42′" l="время ~" />
        <DarkMetric n="165" l="ЧСС средн." />
      </div>

      <div style={{ display: 'flex', height: 10, borderRadius: 999, overflow: 'hidden', marginTop: 18, gap: 1, background: D.surf4 }}>
        {t.segments.map((s, i) => (
          <div key={i} style={{ flex: s.km, background: V2.typeColor(s.type) }} />
        ))}
      </div>

      <div style={DK.aiQuote}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 8 }}>
          {isAI ? <div style={DK.aiInlineAvatar}>AI</div> : <V2.Avatar a={{ initials: 'МК', tone: '#FFD9C9' }} size={32} />}
          <div style={{ fontSize: 11, color: D.ink3, fontWeight: 700, letterSpacing: '0.06em' }}>
            {isAI ? 'AI-ТРЕНЕР' : 'МИХАИЛ'} · 7:42
          </div>
          <div style={{ flex: 1 }} />
          <button style={DK.aiBtnSmall}>Спросить →</button>
        </div>
        <div style={{ fontSize: 14, color: D.ink, lineHeight: 1.5 }}>{t.coachNote}</div>
      </div>

      <div style={{ display: 'flex', gap: 10, marginTop: 22 }}>
        <button style={{ ...DK.cta, flex: '0 1 auto', padding: '15px 28px' }}>Начать тренировку →</button>
        <button style={DK.ctaIcon}>↔ Перенести</button>
        <button style={DK.ctaIcon}>✓ Отметить</button>
      </div>
    </div>
  );
}

function DarkNext() {
  return (
    <div style={DK.card}>
      <div style={{ fontSize: 10, color: D.ink3, fontWeight: 700, letterSpacing: '0.1em' }}>СЛЕДУЮЩАЯ · ЧТ · 14 МАЯ</div>
      <div style={{ display: 'flex', gap: 14, alignItems: 'center', marginTop: 12 }}>
        <span style={{ width: 4, alignSelf: 'stretch', background: V2.typeColor('easy'), borderRadius: 4, minHeight: 60 }} />
        <div style={{ flex: 1 }}>
          <div style={{ fontSize: 17, fontWeight: 700, color: D.ink, letterSpacing: '-0.01em' }}>Лёгкий 10 км</div>
          <div style={{ fontSize: 12, color: D.ink3, marginTop: 2 }}>5:45 /км · ЧСС зона 2 · ≈ 58 мин</div>
        </div>
        <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 24, fontWeight: 700, color: D.ink, letterSpacing: '-0.02em' }}>
          10<span style={{ fontSize: 12, color: D.ink3 }}> км</span>
        </div>
      </div>
    </div>
  );
}

function DarkRacePred() {
  const preds = [
    { d: '5 км',  t: '20:42', delta: '−18″' },
    { d: '10 км', t: '43:18', delta: '−42″' },
    { d: '21.1 км', t: '1:35:42', delta: '−1:28', target: true },
    { d: '42.2 км', t: '3:18:24', delta: '−4:12' },
  ];
  return (
    <div style={DK.card}>
      <div style={{ fontSize: 10, color: D.ink3, fontWeight: 700, letterSpacing: '0.1em' }}>VDOT-ПРОГНОЗЫ · 52</div>
      <div style={{ marginTop: 12, display: 'flex', flexDirection: 'column', gap: 6 }}>
        {preds.map(p => (
          <div key={p.d} style={{
            display: 'flex', alignItems: 'center', justifyContent: 'space-between',
            padding: 10, borderRadius: 8,
            background: p.target ? 'rgba(252,76,2,0.12)' : D.surf3,
            border: p.target ? `1px solid rgba(252,76,2,0.3)` : '1px solid transparent',
          }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <span style={{ fontSize: 12, fontWeight: 600, color: D.ink }}>{p.d}</span>
              {p.target && <span style={{ background: D.primary, color: 'white', fontSize: 9, fontWeight: 800, padding: '2px 5px', borderRadius: 3, letterSpacing: '0.04em' }}>ЦЕЛЬ</span>}
            </div>
            <div style={{ display: 'flex', alignItems: 'baseline', gap: 8 }}>
              <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 16, fontWeight: 700, color: D.ink }}>{p.t}</span>
              <span style={{ fontSize: 10, color: D.success, fontWeight: 700 }}>{p.delta}</span>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

function DarkPaceZones() {
  const zones = [
    { name: 'Восстановительный', pace: '6:00–6:30', color: V2.typeColor('rest') },
    { name: 'Лёгкий (E)', pace: '5:30–6:00', color: V2.typeColor('easy') },
    { name: 'Марафонский (M)', pace: '4:50–5:10', color: V2.typeColor('long') },
    { name: 'Пороговый (T)', pace: '4:20–4:40', color: V2.typeColor('tempo') },
    { name: 'Интервальный (I)', pace: '3:45–4:05', color: V2.typeColor('interval') },
  ];
  return (
    <div style={DK.card}>
      <div style={{ fontSize: 10, color: D.ink3, fontWeight: 700, letterSpacing: '0.1em' }}>ТРЕНИРОВОЧНЫЕ ЗОНЫ</div>
      <div style={{ marginTop: 12, display: 'flex', flexDirection: 'column', gap: 5 }}>
        {zones.map(z => (
          <div key={z.name} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '8px 10px', background: D.surf3, borderRadius: 8 }}>
            <span style={{ width: 3, alignSelf: 'stretch', minHeight: 26, background: z.color, borderRadius: 4 }} />
            <div style={{ flex: 1, minWidth: 0 }}>
              <div style={{ fontSize: 12, fontWeight: 600, color: D.ink }}>{z.name}</div>
            </div>
            <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 13, fontWeight: 700, color: D.ink, letterSpacing: '-0.01em' }}>{z.pace}</span>
          </div>
        ))}
      </div>
    </div>
  );
}

function DarkTrendsSmall() {
  return (
    <div style={DK.card}>
      <div style={{ fontSize: 10, color: D.ink3, fontWeight: 700, letterSpacing: '0.1em' }}>ОБЪЁМ · vs прошлый месяц</div>
      <div style={{ display: 'flex', alignItems: 'baseline', gap: 10, marginTop: 8 }}>
        <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 32, fontWeight: 800, color: D.ink, letterSpacing: '-0.03em', lineHeight: 1 }}>248</span>
        <span style={{ fontSize: 12, color: D.ink3 }}>км</span>
        <span style={{ flex: 1 }} />
        <span style={{ fontSize: 13, color: D.success, fontWeight: 700 }}>+18%</span>
      </div>
      <div style={{ marginTop: 8 }}>
        <V2.Sparkline data={[180,195,210,220,230,238,248]} w={300} h={32} color={D.success} bg thick />
      </div>
    </div>
  );
}

function DarkStatsDesk() {
  const [period, setPeriod] = React.useState('month');
  const stats = {
    month: { dist: '248', work: '18', time: '21:14', pace: '5:12' },
    quarter: { dist: '690', work: '52', time: '62:48', pace: '5:18' },
    year: { dist: '2840', work: '218', time: '256:32', pace: '5:22' },
  };
  const s = stats[period];
  return (
    <div style={DK.card}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <div style={{ fontSize: 10, color: D.ink3, fontWeight: 700, letterSpacing: '0.1em' }}>СТАТИСТИКА</div>
        <div style={{ display: 'flex', gap: 2, background: D.surf3, borderRadius: 6, padding: 2 }}>
          {[['month','Мес'],['quarter','Квартал'],['year','Год']].map(([k,l]) => (
            <button key={k} onClick={() => setPeriod(k)} style={{
              padding: '4px 10px', background: period === k ? D.surf4 : 'transparent', border: 'none',
              borderRadius: 4, fontSize: 11, fontWeight: 600, color: period === k ? D.ink : D.ink3,
              cursor: 'pointer', fontFamily: 'inherit',
            }}>{l}</button>
          ))}
        </div>
      </div>
      <div style={{ marginTop: 12, display: 'grid', gridTemplateColumns: '1fr 1fr 1fr 1fr', gap: 8 }}>
        {[['ДИСТАНЦИЯ', s.dist, 'км'], ['ТРЕНИРОВОК', s.work, ''], ['ВРЕМЯ', s.time, 'ч'], ['СРЕДН. ТЕМП', s.pace, '/км']].map(([lbl, v, u]) => (
          <div key={lbl} style={{ padding: 12, background: D.surf3, borderRadius: 10 }}>
            <div style={{ fontSize: 10, color: D.ink3, fontWeight: 700, letterSpacing: '0.06em' }}>{lbl}</div>
            <div style={{ display: 'flex', alignItems: 'baseline', gap: 4, marginTop: 4 }}>
              <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 22, fontWeight: 800, color: D.ink, letterSpacing: '-0.03em', lineHeight: 1 }}>{v}</span>
              {u && <span style={{ fontSize: 11, color: D.ink3 }}>{u}</span>}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

const DKD = {
  shell: { width: '100%', height: '100%', background: D.appBgGradientDesk, display: 'flex', flexDirection: 'column', overflow: 'hidden', fontFamily: 'Montserrat, sans-serif', color: D.ink },
  top: { height: 56, padding: '0 32px', display: 'flex', alignItems: 'center', gap: 14, background: 'rgba(15,21,29,0.7)', backdropFilter: 'blur(14px)', WebkitBackdropFilter: 'blur(14px)', borderBottom: `1px solid ${D.line2}`, flexShrink: 0 },
  ghostBtn: { padding: '8px 14px', background: 'transparent', color: D.ink2, border: `1px solid ${D.line2}`, borderRadius: 10, fontWeight: 600, fontSize: 13, cursor: 'pointer', fontFamily: 'inherit', marginRight: 8 },
  primaryBtn: { padding: '8px 16px', background: D.primary, color: 'white', border: 'none', borderRadius: 10, fontWeight: 700, fontSize: 13, cursor: 'pointer', fontFamily: 'inherit', boxShadow: '0 4px 14px rgba(252,76,2,0.35)' },
  modeBadgeWide: { display: 'flex', alignItems: 'center', gap: 10, padding: '8px 14px 8px 10px', background: D.surf3, border: `1px solid ${D.line2}`, borderRadius: 12, cursor: 'pointer', fontFamily: 'inherit' },
  bellBtn: { position: 'relative', width: 40, height: 40, borderRadius: 12, border: `1px solid ${D.line2}`, background: D.surf3, cursor: 'pointer', fontSize: 16, color: D.ink, fontFamily: 'inherit' },
  grid: { flex: 1, overflow: 'auto', padding: '0 32px 32px', display: 'grid', gridTemplateColumns: '1.6fr 1fr', gap: 18, alignContent: 'start' },
};

window.DesktopDashDark = DesktopDashDark;
