/* v2: Athlete Mobile — фокус на «что у меня сегодня».
   Tabs: Сегодня | Неделя | Цель | Прогресс.
   Главный экран — крупная карточка тренировки + интервалы + AI-совет тренера.   */

const { useState: useStateA } = React;
const AT = V2.T;

function AthleteMobile() {
  const [tab, setTab] = useStateA('today');
  const [expanded, setExpanded] = useStateA(false);

  return (
    <div style={AM.shell}>
      {/* Status bar */}
      <div style={AM.statusBar}>
        <span style={{ fontFamily: '"Jost", sans-serif' }}>9:41</span>
        <span style={{ display: 'flex', gap: 5 }}>
          <span>●●●</span><span style={{ opacity: 0.5 }}>●</span>
          <span style={{ marginLeft: 6 }}>5G</span>
          <span style={{ marginLeft: 6 }}>89%</span>
        </span>
      </div>

      {/* Top header */}
      <div style={AM.header}>
        <div>
          <div style={{ fontSize: 11, color: AT.ink3, fontWeight: 700, letterSpacing: '0.1em', textTransform: 'uppercase' }}>Вторник · 12 мая</div>
          <div style={{ fontSize: 26, fontWeight: 800, color: AT.ink, letterSpacing: '-0.02em', marginTop: 2 }}>Привет, Алексей!</div>
        </div>
        <div style={{ display: 'flex', gap: 6 }}>
          <button style={AM.headerBtn}>🔔<span style={AM.headerBtnDot} /></button>
          <V2.Avatar a={{ initials: 'АП', tone: '#FFD9C9' }} size={40} />
        </div>
      </div>

      {/* Tabs */}
      <div style={AM.tabs}>
        {[['today', 'Сегодня'], ['week', 'Неделя'], ['goal', 'Цель'], ['progress', 'Прогресс']].map(([k, l]) => (
          <button key={k} onClick={() => setTab(k)}
            style={{ ...AM.tab, ...(tab === k ? AM.tabActive : {}) }}>
            {l}
          </button>
        ))}
      </div>

      <div style={AM.scroll}>
        {tab === 'today'    && <Today expanded={expanded} setExpanded={setExpanded} />}
        {tab === 'week'     && <WeekTab />}
        {tab === 'goal'     && <GoalTab />}
        {tab === 'progress' && <ProgressTab />}
      </div>

      {/* Bottom nav */}
      <div style={AM.nav}>
        {[
          ['Дэшборд', '◆', true, null],
          ['План',    '▦', false, null],
          ['Чат',     '◐', false, 1],
          ['Прогресс','◍', false, null],
          ['Профиль', '◉', false, null],
        ].map(([l, ic, on, badge]) => (
          <div key={l} style={AM.navItem}>
            <span style={{ position: 'relative', fontSize: 18, color: on ? AT.primary : AT.ink3 }}>
              {ic}
              {badge && <span style={AM.navBadge}>{badge}</span>}
            </span>
            <span style={{ fontSize: 9.5, color: on ? AT.ink : AT.ink3, fontWeight: on ? 700 : 500, marginTop: 2, letterSpacing: '0.02em' }}>{l}</span>
          </div>
        ))}
      </div>
    </div>
  );
}

// ── Today tab ─────────────────────────────────────────────────────────
function Today({ expanded, setExpanded }) {
  const t = V2.TODAY;
  return (
    <div style={{ padding: '20px 20px 100px' }}>
      {/* AI Briefing pill */}
      <div style={AM.aiPill}>
        <div style={AM.aiAvatar}>М</div>
        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{ fontSize: 11, color: AT.ink3, fontWeight: 700, letterSpacing: '0.06em' }}>МИХАИЛ · ТРЕНЕР</div>
          <div style={{ fontSize: 13, color: AT.ink, lineHeight: 1.4, marginTop: 2 }}>
            Сегодня темповая — ключевая. Спишь нормально?
          </div>
        </div>
        <span style={{ color: AT.ink3, fontSize: 18 }}>→</span>
      </div>

      {/* Hero workout card */}
      <div style={AM.eyebrow}>
        <span style={{ width: 8, height: 8, borderRadius: 999, background: V2.typeColor(t.type) }} />
        ТЕМПОВАЯ · КЛЮЧЕВАЯ
      </div>
      <h1 style={AM.heroTitle}>
        4×1 км<br />
        <span style={{ color: V2.typeColor(t.type) }}>в темпе</span>
      </h1>

      <div style={AM.heroStats}>
        <Stat n="8,0" l="км" />
        <Stat n="4:30" l="темп /км" accent />
        <Stat n="42′" l="время ~" />
      </div>

      {/* Interval bar */}
      <div style={AM.bar}>
        {t.segments.map((s, i) => (
          <div key={i} style={{ flex: s.km, background: V2.typeColor(s.type) }} />
        ))}
      </div>
      <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 6, fontSize: 10, color: AT.ink3, fontFamily: '"Jost", sans-serif', fontWeight: 600 }}>
        <span>0 км</span><span>{t.distance} км</span>
      </div>

      {/* Segment list */}
      <button onClick={() => setExpanded(!expanded)} style={AM.expandToggle}>
        {expanded ? '▲ Свернуть' : '▼ Показать все 9 отрезков'}
      </button>
      {expanded && (
        <div style={AM.segList}>
          {t.segments.map((s, i) => (
            <div key={i} style={AM.seg}>
              <span style={{ width: 6, height: 6, borderRadius: 999, background: V2.typeColor(s.type), flexShrink: 0 }} />
              <span style={{ flex: 1, fontSize: 13 }}>{s.label}</span>
              <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 13, color: AT.ink2, fontWeight: 600, width: 56, textAlign: 'right' }}>{s.km} км</span>
              <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 13, color: AT.ink3, width: 50, textAlign: 'right' }}>{s.pace}</span>
            </div>
          ))}
        </div>
      )}

      {/* Coach note */}
      <div style={AM.coachCard}>
        <div style={AM.coachLabel}>
          <span style={AM.coachBadge}>СОВЕТ</span>
          <span style={{ fontSize: 11, color: AT.ink3 }}>от Михаила · 7:42</span>
        </div>
        <div style={{ fontSize: 13.5, color: AT.ink, lineHeight: 1.5, marginTop: 8 }}>
          {t.coachNote}
        </div>
      </div>

      {/* Primary actions */}
      <button style={AM.cta}>
        <span>Начать тренировку</span>
        <span style={{ fontSize: 16 }}>→</span>
      </button>
      <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
        <button style={AM.secondaryBtn}>✓ Отметить выполненной</button>
        <button style={{ ...AM.secondaryBtn, flex: '0 0 auto', width: 48 }}>↔</button>
        <button style={{ ...AM.secondaryBtn, flex: '0 0 auto', width: 48 }}>?</button>
      </div>
    </div>
  );
}

function Stat({ n, l, accent }) {
  return (
    <div style={{ flex: 1 }}>
      <div style={{
        fontFamily: '"Jost", sans-serif', fontWeight: 800, fontSize: 36,
        color: accent ? AT.primary : AT.ink, letterSpacing: '-0.03em', lineHeight: 1,
      }}>{n}</div>
      <div style={{ fontSize: 11, color: AT.ink3, marginTop: 4, fontWeight: 600, letterSpacing: '0.04em' }}>{l}</div>
    </div>
  );
}

// ── Week tab ──────────────────────────────────────────────────────────
function WeekTab() {
  const totalKm = V2.WEEK.reduce((s, d) => s + d.km, 0);
  const keyCount = V2.WEEK.filter(d => d.key).length;
  return (
    <div style={{ padding: '20px 20px 100px' }}>
      <div style={AM.eyebrow}>НЕДЕЛЯ 12 · 11–17 МАЯ</div>
      <h1 style={{ ...AM.heroTitle, fontSize: 36 }}>
        {totalKm}<span style={{ color: AT.ink3, fontWeight: 500, fontSize: 18 }}> км</span>
      </h1>
      <div style={{ display: 'flex', gap: 16, marginTop: 6 }}>
        <div style={{ fontSize: 13, color: AT.ink2 }}>{keyCount} ключевых · {V2.WEEK.filter(d => d.status === 'done').length}/{V2.WEEK.filter(d => d.km > 0).length} выполнено</div>
      </div>

      <div style={{ marginTop: 18, padding: 14, background: AT.primaryWash, borderRadius: 14, border: `1px solid ${AT.primary}30` }}>
        <div style={{ fontSize: 11, color: AT.primary, fontWeight: 700, letterSpacing: '0.06em' }}>ФАЗА · РАЗВИВАЮЩАЯ</div>
        <div style={{ fontSize: 13, color: AT.ink, marginTop: 4, lineHeight: 1.4 }}>
          Объём растёт, ключевые тренировки усложняются. Через 4 недели — подводка.
        </div>
      </div>

      <div style={{ marginTop: 18, display: 'flex', flexDirection: 'column', gap: 8 }}>
        {V2.WEEK.map((d, i) => {
          const isToday = d.status === 'today';
          const isDone = d.status === 'done';
          return (
            <div key={i} style={{
              display: 'flex', gap: 12, padding: 14, borderRadius: 14, alignItems: 'center',
              background: isToday ? AT.primaryWash : 'white',
              border: isToday ? `1.5px solid ${AT.primary}` : `1px solid ${AT.line}`,
            }}>
              <div style={{ width: 40, textAlign: 'center' }}>
                <div style={{ fontSize: 10, color: AT.ink3, fontWeight: 700, letterSpacing: '0.08em' }}>{d.day}</div>
                <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 22, fontWeight: 800, color: isToday ? AT.primary : AT.ink, letterSpacing: '-0.02em', lineHeight: 1, marginTop: 2 }}>{d.date}</div>
              </div>
              <div style={{ width: 3, alignSelf: 'stretch', background: V2.typeColor(d.type), borderRadius: 4 }} />
              <div style={{ flex: 1 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                  <span style={{ fontWeight: 700, fontSize: 14, color: AT.ink }}>{d.label}</span>
                  {d.key && <span style={{ background: AT.primary, color: 'white', fontSize: 9, padding: '2px 5px', borderRadius: 3, fontWeight: 700, letterSpacing: '0.04em' }}>КЛЮЧ</span>}
                </div>
                {d.km > 0 && <div style={{ fontSize: 12, color: AT.ink3, fontFamily: '"Jost", sans-serif', marginTop: 2 }}>{d.km} км · {V2.TYPE_LABEL[d.type]}</div>}
              </div>
              {isDone && <div style={{ width: 28, height: 28, borderRadius: '50%', background: AT.success, color: 'white', display: 'grid', placeItems: 'center', fontWeight: 800, fontSize: 14 }}>✓</div>}
              {isToday && <span style={AM.todayPill}>СЕГОДНЯ</span>}
            </div>
          );
        })}
      </div>
    </div>
  );
}

// ── Goal tab ──────────────────────────────────────────────────────────
function GoalTab() {
  const g = V2.GOAL;
  return (
    <div style={{ padding: '20px 20px 100px' }}>
      <div style={AM.eyebrow}>ГЛАВНАЯ ЦЕЛЬ · {V2.PHASES[g.phase].toUpperCase()}</div>
      <h1 style={{ ...AM.heroTitle, fontSize: 28 }}>{g.title}</h1>
      <div style={{ fontSize: 14, color: AT.ink2, marginTop: 4 }}>{g.date}</div>

      {/* Countdown */}
      <div style={AM.countdown}>
        <div style={{ display: 'flex', alignItems: 'baseline', gap: 8 }}>
          <span style={AM.countdownNum}>{g.daysLeft}</span>
          <span style={{ fontSize: 16, color: 'rgba(255,255,255,0.7)', fontWeight: 500 }}>дней до старта</span>
        </div>
        <div style={{ marginTop: 16, height: 6, background: 'rgba(255,255,255,0.15)', borderRadius: 999, overflow: 'hidden' }}>
          <div style={{ width: `${g.progress * 100}%`, height: '100%', background: AT.primary }} />
        </div>
        <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 8, fontSize: 11, color: 'rgba(255,255,255,0.65)' }}>
          <span>Неделя {g.weeksDone} из {g.weeksTotal}</span>
          <span>{Math.round(g.progress * 100)}% плана</span>
        </div>
      </div>

      <div style={AM.predGrid}>
        <div style={AM.predBlock}>
          <div style={AM.predLbl}>ЦЕЛЬ</div>
          <div style={AM.predBig}>{g.target}</div>
        </div>
        <div style={{ ...AM.predBlock, background: AT.successWash, border: `1px solid ${AT.success}30` }}>
          <div style={AM.predLbl}>ПРОГНОЗ</div>
          <div style={{ ...AM.predBig, color: AT.success }}>{g.predicted}</div>
          <div style={{ fontSize: 11, color: AT.success, fontWeight: 700, marginTop: 2 }}>↓ {g.trend}</div>
        </div>
      </div>

      <div style={{ marginTop: 20, padding: 16, background: 'white', border: `1px solid ${AT.line}`, borderRadius: 14 }}>
        <div style={{ fontSize: 11, color: AT.ink3, fontWeight: 700, letterSpacing: '0.08em', marginBottom: 10 }}>ЗА ПОСЛЕДНИЕ 12 НЕДЕЛЬ</div>
        <V2.Sparkline data={[98,97,96,96,95,94,94,93,93,93,92,92]} w={310} h={50} color={AT.success} bg thick />
        <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 6, fontSize: 11, color: AT.ink3 }}>
          <span>1:42 (старт)</span>
          <span style={{ color: AT.success, fontWeight: 700 }}>1:36:42 (сейчас)</span>
        </div>
      </div>
    </div>
  );
}

// ── Progress tab ──────────────────────────────────────────────────────
function ProgressTab() {
  const heatmap = Array.from({ length: 84 }, (_, i) => {
    const v = (i * 13 + 7) % 100;
    if (v > 80) return 0;
    if (v > 60) return 3;
    if (v > 40) return 2;
    if (v > 20) return 1;
    return 0;
  });
  return (
    <div style={{ padding: '20px 20px 100px' }}>
      <div style={AM.eyebrow}>ПРОГРЕСС · 12 НЕДЕЛЬ</div>
      <h1 style={{ ...AM.heroTitle, fontSize: 32 }}>
        +12<span style={{ color: AT.ink3, fontWeight: 500, fontSize: 18 }}>% VDOT</span>
      </h1>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8, marginTop: 18 }}>
        {[
          { label: 'VDOT', value: '52', delta: '+5', color: AT.success },
          { label: 'Темп 5к', value: '20:42', delta: '−42″', color: AT.success },
          { label: 'Объём/нед', value: '60', delta: '+12 км', color: AT.success, suffix: 'км' },
          { label: 'ЧСС покоя', value: '48', delta: '−4', color: AT.success, suffix: 'bpm' },
        ].map(m => (
          <div key={m.label} style={{ background: 'white', border: `1px solid ${AT.line}`, borderRadius: 14, padding: 14 }}>
            <div style={{ fontSize: 10, color: AT.ink3, fontWeight: 700, letterSpacing: '0.08em' }}>{m.label.toUpperCase()}</div>
            <div style={{ display: 'flex', alignItems: 'baseline', gap: 4, marginTop: 4 }}>
              <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 26, fontWeight: 800, color: AT.ink, letterSpacing: '-0.02em', lineHeight: 1 }}>{m.value}</span>
              {m.suffix && <span style={{ fontSize: 11, color: AT.ink3 }}>{m.suffix}</span>}
            </div>
            <div style={{ fontSize: 11, color: m.color, fontWeight: 700, marginTop: 4 }}>{m.delta}</div>
          </div>
        ))}
      </div>

      <div style={{ marginTop: 20 }}>
        <div style={AM.eyebrow}>АКТИВНОСТЬ · 84 ДНЯ</div>
        <div style={{ background: 'white', border: `1px solid ${AT.line}`, borderRadius: 14, padding: 14, marginTop: 8 }}>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(12, 1fr)', gap: 3 }}>
            {heatmap.map((v, i) => (
              <div key={i} style={{
                aspectRatio: '1', borderRadius: 3,
                background: v === 0 ? AT.surf3 : v === 1 ? '#A7F3D0' : v === 2 ? '#34D399' : AT.success,
              }} />
            ))}
          </div>
          <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 10, fontSize: 10, color: AT.ink3 }}>
            <span>3 мес. назад</span>
            <span>Сегодня</span>
          </div>
        </div>
      </div>

      <div style={{ marginTop: 16, padding: 16, background: AT.primaryWash, borderRadius: 14, border: `1px solid ${AT.primary}30` }}>
        <div style={{ fontSize: 11, color: AT.primary, fontWeight: 700, letterSpacing: '0.06em' }}>★ ЛИЧНЫЙ РЕКОРД</div>
        <div style={{ fontSize: 18, fontWeight: 700, color: AT.ink, marginTop: 4 }}>5 км · 20:14</div>
        <div style={{ fontSize: 12, color: AT.ink2, marginTop: 2 }}>5 мая · −34 сек к прошлому</div>
      </div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────
// Styles
// ─────────────────────────────────────────────────────────────────────
const AM = {
  shell: { width: '100%', height: '100%', background: AT.surf, display: 'flex', flexDirection: 'column', overflow: 'hidden', position: 'relative', fontFamily: 'Montserrat, sans-serif', color: AT.ink },
  statusBar: { height: 36, padding: '0 24px', display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontSize: 13, fontWeight: 700 },
  header: { padding: '8px 20px 14px', display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', gap: 12 },
  headerBtn: { position: 'relative', width: 40, height: 40, borderRadius: 12, border: `1px solid ${AT.line}`, background: 'white', cursor: 'pointer', fontSize: 16 },
  headerBtnDot: { position: 'absolute', top: 8, right: 9, width: 7, height: 7, borderRadius: 999, background: AT.primary },
  tabs: { display: 'flex', padding: '0 20px', gap: 18, borderBottom: `1px solid ${AT.line}` },
  tab: { padding: '12px 0', background: 'transparent', border: 'none', borderBottom: '2px solid transparent', color: AT.ink3, fontWeight: 600, fontSize: 14, cursor: 'pointer', fontFamily: 'inherit' },
  tabActive: { color: AT.ink, borderBottomColor: AT.primary, fontWeight: 700 },
  scroll: { flex: 1, overflow: 'auto' },

  aiPill: { display: 'flex', gap: 12, alignItems: 'center', padding: '12px 14px', background: 'linear-gradient(135deg, rgba(252,76,2,0.06), rgba(252,76,2,0.02))', border: `1px solid ${AT.primary}20`, borderRadius: 14, marginBottom: 22 },
  aiAvatar: { width: 32, height: 32, borderRadius: '50%', background: AT.primary, color: 'white', display: 'grid', placeItems: 'center', fontWeight: 700, fontSize: 13, flexShrink: 0 },

  eyebrow: { fontSize: 11, color: AT.ink3, fontWeight: 700, letterSpacing: '0.12em', textTransform: 'uppercase', display: 'inline-flex', alignItems: 'center', gap: 6 },
  heroTitle: { fontSize: 44, fontWeight: 800, letterSpacing: '-0.03em', lineHeight: 1.02, color: AT.ink, marginTop: 6 },

  heroStats: { display: 'flex', gap: 16, marginTop: 22, paddingBottom: 18, borderBottom: `1px solid ${AT.line}` },
  bar: { display: 'flex', height: 10, borderRadius: 999, overflow: 'hidden', marginTop: 20, gap: 1, background: AT.surf3 },
  expandToggle: { display: 'block', width: '100%', marginTop: 14, padding: '8px', background: 'transparent', border: `1px dashed ${AT.line}`, borderRadius: 8, color: AT.ink3, fontSize: 12, fontWeight: 600, cursor: 'pointer', fontFamily: 'inherit' },
  segList: { display: 'flex', flexDirection: 'column', gap: 6, marginTop: 10, padding: 12, background: AT.surf2, borderRadius: 12 },
  seg: { display: 'flex', alignItems: 'center', gap: 10 },

  coachCard: { marginTop: 22, padding: 16, background: AT.surf2, border: `1px solid ${AT.line}`, borderRadius: 14 },
  coachLabel: { display: 'flex', alignItems: 'center', gap: 8 },
  coachBadge: { fontSize: 9, fontWeight: 800, padding: '3px 7px', borderRadius: 4, background: AT.primary, color: 'white', letterSpacing: '0.06em' },

  cta: { marginTop: 22, width: '100%', padding: 18, borderRadius: 16, background: AT.primary, color: 'white', border: 'none', fontWeight: 700, fontSize: 15, cursor: 'pointer', fontFamily: 'inherit', boxShadow: '0 12px 28px rgba(252,76,2,0.3)', display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 10 },
  secondaryBtn: { flex: 1, padding: '12px 14px', borderRadius: 14, background: AT.surf3, color: AT.ink2, border: 'none', fontWeight: 600, fontSize: 13, cursor: 'pointer', fontFamily: 'inherit' },
  todayPill: { background: AT.primary, color: 'white', fontSize: 10, fontWeight: 800, padding: '4px 9px', borderRadius: 6, letterSpacing: '0.06em' },

  countdown: { marginTop: 20, padding: 28, borderRadius: 18, background: 'linear-gradient(180deg, #0F172A 0%, #1E293B 100%)', color: 'white' },
  countdownNum: { fontFamily: '"Jost", sans-serif', fontSize: 80, fontWeight: 800, color: 'white', letterSpacing: '-0.04em', lineHeight: 1 },
  predGrid: { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10, marginTop: 14 },
  predBlock: { padding: 16, background: AT.surf3, borderRadius: 14 },
  predLbl: { fontSize: 10, color: AT.ink3, fontWeight: 700, letterSpacing: '0.08em' },
  predBig: { fontFamily: '"Jost", sans-serif', fontSize: 26, fontWeight: 800, color: AT.ink, letterSpacing: '-0.02em', marginTop: 4 },

  nav: { position: 'absolute', bottom: 12, left: 12, right: 12, height: 64, background: 'rgba(255,255,255,0.92)', backdropFilter: 'blur(20px)', borderRadius: 20, border: `1px solid ${AT.line}`, display: 'flex', justifyContent: 'space-around', alignItems: 'center', boxShadow: '0 12px 32px rgba(0,0,0,0.06)' },
  navItem: { display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 2 },
  navBadge: { position: 'absolute', top: -2, right: -8, background: AT.primary, color: 'white', fontSize: 9, fontWeight: 700, padding: '0 4px', borderRadius: 999, minWidth: 14, textAlign: 'center', lineHeight: '14px' },
};

window.AthleteMobile = AthleteMobile;
