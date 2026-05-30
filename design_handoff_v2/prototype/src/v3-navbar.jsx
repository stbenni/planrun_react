/* v3 Navbar variants — 3 alternative bottom-nav designs */

const TN = V2.T;

// Reusable phone shell wrapper
function PhoneFrame({ children, content }) {
  return (
    <div style={NV.shell}>
      <div style={NV.statusBar}>
        <span style={{ fontFamily: '"Jost", sans-serif' }}>9:41</span>
        <span style={{ display: 'flex', gap: 5 }}>●●● ● <span style={{ marginLeft: 6 }}>5G 89%</span></span>
      </div>
      <div style={NV.fakeContent}>
        {content || (
          <>
            <div style={NV.fakeHeader}>
              <div>
                <div style={{ fontSize: 11, color: TN.ink3, fontWeight: 700, letterSpacing: '0.1em' }}>ВТ · 12 МАЯ</div>
                <div style={{ fontSize: 22, fontWeight: 800, color: TN.ink, letterSpacing: '-0.02em', marginTop: 2 }}>Привет, Алексей</div>
              </div>
              <V2.Avatar a={{ initials: 'АП', tone: '#FFD9C9' }} size={40} />
            </div>
            <FakeWorkout />
            <FakeWeek />
            <div style={{ height: 100 }} />
          </>
        )}
      </div>
      {children}
    </div>
  );
}

function FakeWorkout() {
  return (
    <div style={{ background: 'white', border: `1px solid ${TN.line}`, borderRadius: 16, padding: 18, margin: '0 16px 12px' }}>
      <div style={{ fontSize: 11, color: TN.ink3, fontWeight: 700, letterSpacing: '0.12em' }}>
        <span style={{ display: 'inline-block', width: 8, height: 8, borderRadius: 999, background: TN.warning, marginRight: 6, verticalAlign: 'middle' }} />
        ТЕМПОВАЯ · КЛЮЧЕВАЯ
      </div>
      <div style={{ fontSize: 30, fontWeight: 800, color: TN.ink, letterSpacing: '-0.03em', lineHeight: 1.05, marginTop: 8 }}>
        4×1 км<br/><span style={{ color: TN.warning }}>в темпе</span>
      </div>
      <div style={{ display: 'flex', gap: 16, marginTop: 16, paddingTop: 14, borderTop: `1px solid ${TN.line}` }}>
        <div><div style={{ fontFamily: '"Jost", sans-serif', fontSize: 26, fontWeight: 800, color: TN.ink }}>8,0</div><div style={{ fontSize: 10, color: TN.ink3 }}>км</div></div>
        <div><div style={{ fontFamily: '"Jost", sans-serif', fontSize: 26, fontWeight: 800, color: TN.primary }}>4:30</div><div style={{ fontSize: 10, color: TN.ink3 }}>темп /км</div></div>
        <div><div style={{ fontFamily: '"Jost", sans-serif', fontSize: 26, fontWeight: 800, color: TN.ink }}>42′</div><div style={{ fontSize: 10, color: TN.ink3 }}>~ время</div></div>
      </div>
    </div>
  );
}

function FakeWeek() {
  return (
    <div style={{ background: 'white', border: `1px solid ${TN.line}`, borderRadius: 16, padding: 16, margin: '0 16px' }}>
      <div style={{ fontSize: 10, color: TN.ink3, fontWeight: 700, letterSpacing: '0.1em' }}>НЕДЕЛЯ 12</div>
      <div style={{ display: 'flex', alignItems: 'baseline', gap: 8, marginTop: 6 }}>
        <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 26, fontWeight: 800, color: TN.ink, letterSpacing: '-0.03em' }}>60</span>
        <span style={{ fontSize: 12, color: TN.ink3 }}>км · 5 ключевых</span>
        <span style={{ flex: 1 }} />
        <span style={{ fontSize: 12, color: TN.success, fontWeight: 700 }}>✓ 1/5</span>
      </div>
    </div>
  );
}

// Icons
const ICONS = {
  home: <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></svg>,
  cal: <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>,
  chat: <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M6 4h12a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2h-4l-2 4v-4H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/></svg>,
  stats: <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M18 20V10M12 20V4M6 20v-6"/></svg>,
  settings: <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>,
  bolt: <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M13 2L3 14h7l-1 8 10-12h-7l1-8z"/></svg>,
  plus: <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round"><path d="M12 5v14M5 12h14"/></svg>,
  play: <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>,
};

function PillNavBase({ items, activeIndex, variant }) {
  return (
    <nav style={NV.bar}>
      {items.map((it, i) => {
        const isActive = i === activeIndex;
        return (
          <button key={i} style={{ ...NV.item, ...(isActive ? NV.itemActive : {}) }}>
            <span style={{ position: 'relative', display: 'flex', alignItems: 'center', justifyContent: 'center', color: isActive ? TN.primary : TN.ink3 }}>
              {it.icon}
              {it.badge && <span style={NV.badge}>{it.badge}</span>}
              {it.dot && <span style={NV.dot} />}
            </span>
            {(variant !== 'minimal' || isActive) && (
              <span style={{ fontSize: 10, color: isActive ? TN.primary : TN.ink3, fontWeight: isActive ? 700 : 500, marginTop: 3 }}>{it.label}</span>
            )}
          </button>
        );
      })}
    </nav>
  );
}

// ────────────────────────────────────────────────────────────────────
// Shared MobileNav — переиспользуется по всему дизайну (вариант C)
// ────────────────────────────────────────────────────────────────────
function MobileNav({ items, activeIndex = 0, onChange, role = 'user' }) {
  const [active, setActive] = React.useState(activeIndex);
  React.useEffect(() => { setActive(activeIndex); }, [activeIndex]);

  const userItems = [
    { icon: ICONS.home, label: 'Главная' },
    { icon: ICONS.cal, label: 'План' },
    { icon: ICONS.chat, label: 'Чат', badge: 2 },
    { icon: ICONS.stats, label: 'Прогресс', dot: true },
    { icon: ICONS.settings, label: 'Меню' },
  ];
  const coachItems = [
    { icon: ICONS.home, label: 'Команда' },
    { icon: ICONS.bolt, label: 'Поток', dot: true },
    { icon: ICONS.cal, label: 'Календарь' },
    { icon: ICONS.chat, label: 'Чат', badge: 7 },
    { icon: ICONS.settings, label: 'Меню' },
  ];
  const list = items || (role === 'coach' ? coachItems : userItems);

  const click = (i) => { setActive(i); onChange?.(i); };

  return (
    <div style={NV.sharedBarWrap}>
      <nav style={{ ...NV.bar, ...NV.barMinimal }}>
        {list.map((it, i) => {
          const isActive = i === active;
          return (
            <button key={i} onClick={() => click(i)} style={{
              ...NV.itemMinimalBase,
              flex: isActive ? '1 1 0' : '0 0 auto',
              padding: isActive ? '10px 14px' : '10px 10px',
              background: isActive ? TN.primary : 'transparent',
              boxShadow: isActive ? '0 6px 16px rgba(252,76,2,0.35)' : 'none',
            }}>
              <span style={{ position: 'relative', display: 'flex', alignItems: 'center', gap: 8, color: isActive ? 'white' : TN.ink3, transition: 'color 0.28s cubic-bezier(0.33, 1, 0.68, 1)' }}>
                {it.icon}
                {it.badge && (
                  <span style={{
                    ...NV.badge, top: -4, right: -8,
                    background: isActive ? 'white' : TN.primary,
                    color: isActive ? TN.primary : 'white',
                    borderColor: isActive ? TN.primary : 'white',
                  }}>{it.badge}</span>
                )}
                {it.dot && <span style={{
                  ...NV.dot,
                  background: isActive ? 'white' : TN.primary,
                  borderColor: isActive ? TN.primary : 'white',
                }} />}
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
    </div>
  );
}

window.MobileNav = MobileNav;

// ── Variant A: Bomb in center ───────────────────────────────────────
function NavVariantA() {
  return (
    <PhoneFrame>
      <div style={NV.barWrap}>
        <nav style={NV.bar}>
          <button style={{ ...NV.item, ...NV.itemActive }}>
            <span style={{ color: TN.primary }}>{ICONS.home}</span>
            <span style={NV.lblActive}>Главная</span>
          </button>
          <button style={NV.item}>
            <span style={{ color: TN.ink3 }}>{ICONS.cal}</span>
            <span style={NV.lbl}>План</span>
          </button>
          {/* Central FAB-style button */}
          <button style={NV.centerFab}>
            <span style={{ color: 'white' }}>{ICONS.play}</span>
            <span style={NV.fabLbl}>Старт</span>
          </button>
          <button style={NV.item}>
            <span style={{ position: 'relative', color: TN.ink3 }}>
              {ICONS.chat}
              <span style={NV.badge}>2</span>
            </span>
            <span style={NV.lbl}>Чат</span>
          </button>
          <button style={NV.item}>
            <div style={{ position: 'relative' }}>
              <V2.Avatar a={{ initials: 'АП', tone: '#FFD9C9' }} size={24} />
            </div>
            <span style={NV.lbl}>Я</span>
          </button>
        </nav>
      </div>
    </PhoneFrame>
  );
}

// ── Variant B: Profile replaces settings + badges ──────────────────
function NavVariantB() {
  return (
    <PhoneFrame>
      <div style={NV.barWrap}>
        <nav style={NV.bar}>
          <button style={{ ...NV.item, ...NV.itemActive }}>
            <span style={{ color: TN.primary }}>{ICONS.home}</span>
            <span style={NV.lblActive}>Главная</span>
          </button>
          <button style={NV.item}>
            <span style={{ color: TN.ink3 }}>{ICONS.cal}</span>
            <span style={NV.lbl}>План</span>
          </button>
          <button style={NV.item}>
            <span style={{ position: 'relative', color: TN.ink3 }}>
              {ICONS.chat}
              <span style={NV.badge}>2</span>
            </span>
            <span style={NV.lbl}>Чат</span>
          </button>
          <button style={NV.item}>
            <span style={{ position: 'relative', color: TN.ink3 }}>
              {ICONS.stats}
              <span style={NV.dot} />
            </span>
            <span style={NV.lbl}>Прогресс</span>
          </button>
          <button style={NV.item}>
            <div style={{ position: 'relative' }}>
              <V2.Avatar a={{ initials: 'АП', tone: '#FFD9C9' }} size={26} />
            </div>
            <span style={NV.lbl}>Профиль</span>
          </button>
        </nav>
      </div>
    </PhoneFrame>
  );
}

// ── Variant C: Minimal (icons only, label only on active) ──────────
function NavVariantC() {
  const [active, setActive] = React.useState(0);
  const items = [
    { icon: ICONS.home, label: 'Главная' },
    { icon: ICONS.cal, label: 'План' },
    { icon: ICONS.chat, label: 'Чат', badge: 2 },
    { icon: ICONS.stats, label: 'Прогресс', dot: true },
    { icon: ICONS.settings, label: 'Меню' },
  ];
  return (
    <PhoneFrame>
      <div style={NV.barWrap}>
        <nav style={{ ...NV.bar, ...NV.barMinimal }}>
          {items.map((it, i) => {
            const isActive = i === active;
            return (
              <button
                key={i}
                onClick={() => setActive(i)}
                style={{
                  ...NV.itemMinimalBase,
                  flex: isActive ? '1 1 0' : '0 0 auto',
                  padding: isActive ? '10px 16px' : '10px 12px',
                  background: isActive ? TN.primary : 'transparent',
                  boxShadow: isActive ? '0 6px 16px rgba(252,76,2,0.35)' : 'none',
                }}>
                <span style={{ position: 'relative', display: 'flex', alignItems: 'center', gap: 8, color: isActive ? 'white' : TN.ink3, transition: 'color 0.28s cubic-bezier(0.33, 1, 0.68, 1)' }}>
                  {it.icon}
                  {it.badge && (
                    <span style={{
                      ...NV.badge, top: -4, right: -8,
                      background: isActive ? 'white' : TN.primary,
                      color: isActive ? TN.primary : 'white',
                      borderColor: isActive ? TN.primary : 'white',
                    }}>{it.badge}</span>
                  )}
                  {it.dot && <span style={{
                    ...NV.dot,
                    background: isActive ? 'white' : TN.primary,
                    borderColor: isActive ? TN.primary : 'white',
                  }} />}
                  <span style={{
                    fontSize: 12, fontWeight: 700,
                    color: 'white',
                    maxWidth: isActive ? 120 : 0,
                    opacity: isActive ? 1 : 0,
                    overflow: 'hidden',
                    whiteSpace: 'nowrap',
                    transition: 'max-width 0.34s cubic-bezier(0.33, 1, 0.68, 1), opacity 0.22s ease',
                    transitionDelay: isActive ? '0.06s' : '0s',
                  }}>{it.label}</span>
                </span>
              </button>
            );
          })}
        </nav>
        <div style={NV.hintRow}>
          <span style={{ width: 6, height: 6, borderRadius: 999, background: TN.primary }} />
          <span style={{ fontSize: 11, color: TN.ink3 }}>Тапни любую вкладку — pill плавно перетекает</span>
        </div>
      </div>
    </PhoneFrame>
  );
}

// ────────────────────────────────────────────────────────────────────
// Styles
// ────────────────────────────────────────────────────────────────────
const NV = {
  shell: { width: '100%', height: '100%', background: TN.surf4, display: 'flex', flexDirection: 'column', overflow: 'hidden', fontFamily: 'Montserrat, sans-serif', color: TN.ink, position: 'relative' },
  statusBar: { height: 36, padding: '0 24px', display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontSize: 13, fontWeight: 700 },
  fakeContent: { flex: 1, overflow: 'auto' },
  fakeHeader: { padding: '8px 16px 16px', display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end' },

  barWrap: { position: 'absolute', bottom: 12, left: 12, right: 12 },
  sharedBarWrap: { position: 'absolute', bottom: 12, left: 12, right: 12, zIndex: 100 },
  bar: { display: 'flex', justifyContent: 'space-around', alignItems: 'center', height: 72, padding: '6px 4px', background: 'linear-gradient(180deg, rgba(255,250,247,0.78) 0%, rgba(255,255,255,0.72) 100%)', backdropFilter: 'blur(20px) saturate(1.12)', WebkitBackdropFilter: 'blur(20px) saturate(1.12)', borderRadius: 22, border: '1px solid rgba(252,76,2,0.08)', boxShadow: 'inset 0 1px 0 rgba(255,255,255,0.8), 0 16px 30px rgba(15,23,42,0.10), 0 6px 18px rgba(252,76,2,0.06)', overflow: 'visible', position: 'relative' },
  barMinimal: { height: 60, padding: '6px 6px', justifyContent: 'space-between', gap: 4 },

  item: { flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 3, padding: '6px 4px', background: 'transparent', border: 'none', cursor: 'pointer', borderRadius: 16, fontFamily: 'inherit', minWidth: 0 },
  itemActive: { background: 'linear-gradient(180deg, #FFF1EA, #FFF8F3)', boxShadow: 'inset 0 1px 0 rgba(255,255,255,0.7), inset 0 -1px 0 rgba(15,23,42,0.04), 0 0 0 1px rgba(252,76,2,0.1)' },
  itemMinimal: { flex: '0 0 auto', padding: '10px 12px', borderRadius: 14, background: 'transparent' },
  itemMinimalBase: { display: 'flex', alignItems: 'center', justifyContent: 'center', border: 'none', borderRadius: 14, cursor: 'pointer', fontFamily: 'inherit', minWidth: 0, transition: 'flex 0.34s cubic-bezier(0.33, 1, 0.68, 1), padding 0.34s cubic-bezier(0.33, 1, 0.68, 1), background 0.28s ease, box-shadow 0.28s ease' },
  hintRow: { position: 'absolute', bottom: -22, left: 0, right: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 6 },
  itemActiveMinimal: { flex: '1 1 auto', padding: '10px 16px', borderRadius: 14, background: TN.primary, boxShadow: '0 6px 16px rgba(252,76,2,0.35)' },

  lbl: { fontSize: 10, color: TN.ink3, fontWeight: 500, marginTop: 3, letterSpacing: '0.02em', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis', maxWidth: '100%' },
  lblActive: { fontSize: 10, color: TN.primary, fontWeight: 700, marginTop: 3, letterSpacing: '0.02em' },

  centerFab: { width: 56, height: 56, marginTop: -16, borderRadius: '50%', background: 'linear-gradient(180deg, #FF6B3D, #FC4C02)', border: '3px solid white', cursor: 'pointer', display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', boxShadow: '0 12px 24px rgba(252,76,2,0.4), 0 0 0 1px rgba(0,0,0,0.06)', fontFamily: 'inherit', flexShrink: 0, position: 'relative' },
  fabLbl: { position: 'absolute', bottom: -16, fontSize: 9, color: TN.primary, fontWeight: 700, letterSpacing: '0.04em', whiteSpace: 'nowrap' },

  badge: { position: 'absolute', top: -6, right: -8, background: TN.primary, color: 'white', fontSize: 9, fontWeight: 700, padding: '1px 5px', borderRadius: 999, minWidth: 16, textAlign: 'center', lineHeight: '14px', border: '1.5px solid white', fontFamily: '"Jost", sans-serif' },
  dot: { position: 'absolute', top: -2, right: -2, width: 8, height: 8, background: TN.primary, borderRadius: 999, border: '2px solid white' },
};

window.NavVariantA = NavVariantA;
window.NavVariantB = NavVariantB;
window.NavVariantC = NavVariantC;
