/* v3 Notifications — panel that opens from the bell.
   Mobile = full-screen sheet.
   Desktop = dropdown anchored to bell.
   Types: ai-briefing, workout-upload, coach-message, pr, plan-ready,
          missed, goal-milestone, group-invite, etc.                 */

const TN3 = V2.T;

// Notification taxonomy
const NOTIF_DATA = [
  {
    id: 1, kind: 'ai-briefing', tone: 'primary', unread: true, when: 'сейчас',
    icon: 'AI', title: 'AI · утренний брифинг',
    body: 'Сегодня темповая — ключевая. Снился нормально?',
    cta: 'Открыть чат', emoji: null,
  },
  {
    id: 2, kind: 'pr', tone: 'success', unread: true, when: '14 мин',
    icon: '★', title: 'Личный рекорд на 5 км',
    body: 'Новое время: 20:14. Прошлое — 20:48.',
    cta: 'Посмотреть', emoji: null,
  },
  {
    id: 3, kind: 'workout-upload', tone: 'info', unread: true, when: '2 ч',
    icon: '↑', title: 'Загружена тренировка',
    body: 'Strava · лёгкий бег 8 км · 5:48 /км',
    cta: 'Открыть', emoji: null,
  },
  {
    id: 4, kind: 'coach-message', tone: 'warn', unread: false, when: 'вчера',
    icon: 'МК', title: 'Михаил написал',
    body: 'Молодец на темповой! Поставлю 5×1 км на четверг.',
    cta: 'Ответить', emoji: null,
  },
  {
    id: 5, kind: 'goal-milestone', tone: 'primary', unread: false, when: 'вчера',
    icon: '🎯', title: '4 недели до Москвы',
    body: 'Сегодня начинается peak-фаза. Объём растёт.',
    cta: 'План недели', emoji: null,
  },
  {
    id: 6, kind: 'missed', tone: 'danger', unread: false, when: '2 дня',
    icon: '!', title: 'Пропущена тренировка',
    body: 'Интервалы 6×400 м · перенесём?',
    cta: 'Перенести', emoji: null,
  },
  {
    id: 7, kind: 'plan-ready', tone: 'success', unread: false, when: '3 дня',
    icon: '✓', title: 'Новый план готов',
    body: '16 недель · цель Москва-полумарафон 1:35:00',
    cta: 'Посмотреть', emoji: null,
  },
];

function NotifIcon({ kind, tone, icon }) {
  const t = V2.toneStyles(tone);
  // AI gets gradient
  if (kind === 'ai-briefing') {
    return (
      <div style={{
        width: 40, height: 40, borderRadius: 12,
        background: 'linear-gradient(135deg, #FC4C02, #FF6B3D)',
        color: 'white', display: 'grid', placeItems: 'center',
        fontWeight: 800, fontSize: 13, flexShrink: 0,
        boxShadow: '0 4px 12px rgba(252,76,2,0.3)',
      }}>AI</div>
    );
  }
  if (kind === 'coach-message') {
    return (
      <V2.Avatar a={{ initials: icon, tone: '#FFD9C9' }} size={40} />
    );
  }
  return (
    <div style={{
      width: 40, height: 40, borderRadius: 12,
      background: t.bg, color: t.color,
      display: 'grid', placeItems: 'center',
      fontWeight: 800, fontSize: 16, flexShrink: 0,
    }}>{icon}</div>
  );
}

function NotifRow({ n, large }) {
  const t = V2.toneStyles(n.tone);
  return (
    <button style={{
      display: 'flex', alignItems: 'flex-start', gap: 12,
      padding: large ? '14px 16px' : '12px 14px',
      background: n.unread ? 'rgba(252,76,2,0.04)' : 'transparent',
      border: n.unread ? '1px solid rgba(252,76,2,0.12)' : `1px solid ${TN3.line}`,
      borderRadius: 14, cursor: 'pointer', fontFamily: 'inherit', width: '100%', textAlign: 'left',
      position: 'relative',
    }}>
      <NotifIcon kind={n.kind} tone={n.tone} icon={n.icon} />
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <span style={{ fontWeight: 700, fontSize: 13.5, color: TN3.ink, flex: 1, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{n.title}</span>
          {n.unread && <span style={{ width: 8, height: 8, borderRadius: 999, background: TN3.primary, flexShrink: 0, boxShadow: `0 0 6px ${TN3.primary}` }} />}
        </div>
        <div style={{ fontSize: 12.5, color: TN3.ink2, lineHeight: 1.4, marginTop: 2 }}>{n.body}</div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginTop: 8 }}>
          <span style={{ fontSize: 11, color: TN3.ink3, fontWeight: 600 }}>{n.when}</span>
          {n.cta && (
            <span style={{
              fontSize: 11, fontWeight: 700, color: t.solid,
              padding: '3px 9px', borderRadius: 6, background: t.bg,
            }}>{n.cta} →</span>
          )}
        </div>
      </div>
    </button>
  );
}

// ── Mobile full-screen notification sheet ─────────────────────────────
function NotificationsMobile() {
  const today = NOTIF_DATA.filter(n => ['сейчас','14 мин','2 ч'].includes(n.when));
  const yesterday = NOTIF_DATA.filter(n => n.when === 'вчера');
  const earlier = NOTIF_DATA.filter(n => !['сейчас','14 мин','2 ч','вчера'].includes(n.when));
  const unreadCount = NOTIF_DATA.filter(n => n.unread).length;

  return (
    <div style={NV3.mobShell}>
      <div style={NV3.statusBar}>
        <span style={{ fontFamily: '"Jost", sans-serif' }}>9:41</span>
        <span style={{ display: 'flex', gap: 5 }}>●●● ● <span style={{ marginLeft: 6 }}>5G 89%</span></span>
      </div>

      {/* Header */}
      <div style={NV3.mobHeader}>
        <button style={NV3.backBtn}>←</button>
        <div style={{ flex: 1 }}>
          <div style={{ fontSize: 10, color: TN3.ink3, fontWeight: 700, letterSpacing: '0.1em' }}>УВЕДОМЛЕНИЯ</div>
          <div style={{ fontSize: 20, fontWeight: 800, color: TN3.ink, letterSpacing: '-0.02em', marginTop: 2 }}>
            {unreadCount > 0 ? `${unreadCount} новых` : 'Всё прочитано'}
          </div>
        </div>
        <button style={NV3.markAllBtn}>Прочитать все</button>
      </div>

      {/* Filter chips */}
      <div style={NV3.filters}>
        {[
          ['all', 'Все', NOTIF_DATA.length],
          ['ai', 'AI', 1],
          ['coach', 'Тренер', 1],
          ['workouts', 'Тренировки', 2],
          ['plan', 'План', 2],
        ].map(([k, l, c], i) => (
          <button key={k} style={{ ...NV3.filterChip, ...(i === 0 ? NV3.filterChipActive : {}) }}>
            {l} <span style={{ color: i === 0 ? 'white' : TN3.ink3, fontFamily: '"Jost", sans-serif', fontWeight: 700 }}>· {c}</span>
          </button>
        ))}
      </div>

      <div style={NV3.scroll}>
        {today.length > 0 && (
          <div style={NV3.section}>
            <div style={NV3.sectionLabel}>СЕГОДНЯ</div>
            <div style={NV3.list}>
              {today.map(n => <NotifRow key={n.id} n={n} />)}
            </div>
          </div>
        )}

        {yesterday.length > 0 && (
          <div style={NV3.section}>
            <div style={NV3.sectionLabel}>ВЧЕРА</div>
            <div style={NV3.list}>
              {yesterday.map(n => <NotifRow key={n.id} n={n} />)}
            </div>
          </div>
        )}

        {earlier.length > 0 && (
          <div style={NV3.section}>
            <div style={NV3.sectionLabel}>РАНЕЕ</div>
            <div style={NV3.list}>
              {earlier.map(n => <NotifRow key={n.id} n={n} />)}
            </div>
          </div>
        )}

        {/* Settings link */}
        <div style={NV3.section}>
          <button style={NV3.settingsRow}>
            <span style={{ fontSize: 18 }}>⚙</span>
            <span style={{ flex: 1, textAlign: 'left', fontSize: 13, fontWeight: 600, color: TN3.ink }}>Настройки уведомлений</span>
            <span style={{ color: TN3.ink3 }}>→</span>
          </button>
        </div>

        <div style={{ height: 100 }} />
      </div>

      <MobileNav activeIndex={0} />
    </div>
  );
}

// ── Desktop dropdown anchored to bell ─────────────────────────────────
function NotificationsDesktopDropdown() {
  const unreadCount = NOTIF_DATA.filter(n => n.unread).length;

  return (
    <div style={NV3.deskShell}>
      {/* Faux top bar with bell highlighted */}
      <div style={NV3.deskTop}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          <span style={{ width: 30, height: 30, borderRadius: 9, background: 'linear-gradient(135deg, #FC4C02, #FF6B3D)', color: 'white', display: 'grid', placeItems: 'center', fontWeight: 800, fontSize: 15 }}>P</span>
          <span style={{ fontWeight: 800, fontSize: 17, letterSpacing: '-0.02em', color: TN3.ink }}>planrun</span>
        </div>
        <nav style={{ display: 'flex', gap: 2, marginLeft: 24 }}>
          {[['Дэшборд', true], ['Календарь', false], ['Чат', false], ['Прогресс', false], ['Настройки', false]].map(([l, on]) => (
            <a key={l} style={{ padding: '8px 14px', borderRadius: 8, fontSize: 13, fontWeight: on ? 700 : 500, color: on ? TN3.ink : TN3.ink2, background: on ? TN3.surf3 : 'transparent' }}>{l}</a>
          ))}
        </nav>
        <div style={{ flex: 1 }} />
        <button style={NV3.bellBtnActive}>
          🔔
          <span style={NV3.bellBadge}>{unreadCount}</span>
        </button>
        <div style={{ width: 36, height: 36, borderRadius: '50%', background: '#FFD9C9', display: 'grid', placeItems: 'center', fontWeight: 700, fontSize: 13, marginLeft: 8 }}>АП</div>
      </div>

      {/* Background (dimmed) showing dashboard hint */}
      <div style={{ flex: 1, position: 'relative', overflow: 'hidden' }}>
        <div style={{ position: 'absolute', inset: 0, opacity: 0.35, padding: 32, pointerEvents: 'none' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 16, paddingBottom: 16 }}>
            <div style={{ width: 48, height: 48, borderRadius: '50%', background: '#FFD9C9' }} />
            <div style={{ width: 240, height: 38, borderRadius: 6, background: TN3.surf3 }} />
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: '1.6fr 1fr', gap: 18 }}>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
              <div style={{ height: 380, background: 'rgba(255,255,255,0.7)', borderRadius: 18 }} />
              <div style={{ height: 200, background: 'rgba(255,255,255,0.7)', borderRadius: 16 }} />
            </div>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
              <div style={{ height: 240, background: 'rgba(255,255,255,0.7)', borderRadius: 16 }} />
              <div style={{ height: 180, background: 'rgba(255,255,255,0.7)', borderRadius: 16 }} />
            </div>
          </div>
        </div>

        {/* Scrim */}
        <div style={{ position: 'absolute', inset: 0, background: 'rgba(15,23,42,0.18)' }} />

        {/* Dropdown panel */}
        <div style={NV3.dropdown}>
          {/* Pointer */}
          <div style={NV3.dropdownPointer} />

          {/* Header */}
          <div style={NV3.dropdownHead}>
            <div>
              <div style={{ fontSize: 11, color: TN3.ink3, fontWeight: 700, letterSpacing: '0.08em' }}>УВЕДОМЛЕНИЯ</div>
              <div style={{ fontSize: 16, fontWeight: 800, color: TN3.ink, marginTop: 2 }}>{unreadCount} новых · {NOTIF_DATA.length - unreadCount} прочитано</div>
            </div>
            <div style={{ flex: 1 }} />
            <button style={NV3.smallBtn}>Прочитать все</button>
          </div>

          {/* Tabs */}
          <div style={NV3.dropdownTabs}>
            {[['all','Все',NOTIF_DATA.length],['unread','Новые',unreadCount],['ai','AI',1],['coach','Тренер',1],['workouts','Тренировки',2]].map(([k,l,c], i) => (
              <button key={k} style={{ ...NV3.dropdownTab, ...(i === 0 ? NV3.dropdownTabActive : {}) }}>
                {l} <span style={{ fontFamily: '"Jost", sans-serif', color: i === 0 ? TN3.ink : TN3.ink3, fontWeight: 700 }}>· {c}</span>
              </button>
            ))}
          </div>

          {/* List (scrollable) */}
          <div style={NV3.dropdownList}>
            <div style={NV3.sectionLabel}>СЕГОДНЯ</div>
            <div style={NV3.list}>
              {NOTIF_DATA.filter(n => ['сейчас','14 мин','2 ч'].includes(n.when)).map(n => <NotifRow key={n.id} n={n} large />)}
            </div>
            <div style={{ ...NV3.sectionLabel, marginTop: 16 }}>ВЧЕРА</div>
            <div style={NV3.list}>
              {NOTIF_DATA.filter(n => n.when === 'вчера').map(n => <NotifRow key={n.id} n={n} large />)}
            </div>
            <div style={{ ...NV3.sectionLabel, marginTop: 16 }}>РАНЕЕ</div>
            <div style={NV3.list}>
              {NOTIF_DATA.filter(n => !['сейчас','14 мин','2 ч','вчера'].includes(n.when)).map(n => <NotifRow key={n.id} n={n} large />)}
            </div>
          </div>

          {/* Footer */}
          <div style={NV3.dropdownFoot}>
            <button style={NV3.footBtn}>⚙ Настройки уведомлений</button>
            <span style={{ flex: 1 }} />
            <button style={NV3.footBtnPrimary}>Открыть все →</button>
          </div>
        </div>
      </div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────
// STYLES
// ─────────────────────────────────────────────────────────────────────
const NV3 = {
  mobShell: { width: '100%', height: '100%', position: 'relative', background: 'radial-gradient(120% 80% at 0% 0%, rgba(252,76,2,0.07) 0%, transparent 50%), radial-gradient(100% 70% at 100% 100%, rgba(252,76,2,0.05) 0%, transparent 55%), linear-gradient(180deg, #FAF7F3 0%, #F4F7FB 100%)', fontFamily: 'Montserrat, sans-serif', color: TN3.ink, display: 'flex', flexDirection: 'column', overflow: 'hidden' },
  statusBar: { height: 36, padding: '0 24px', display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontSize: 13, fontWeight: 700, flexShrink: 0 },
  mobHeader: { padding: '8px 16px 14px', display: 'flex', alignItems: 'center', gap: 12 },
  backBtn: { width: 40, height: 40, borderRadius: 12, background: 'rgba(255,255,255,0.72)', backdropFilter: 'blur(20px) saturate(1.16)', WebkitBackdropFilter: 'blur(20px) saturate(1.16)', border: '1px solid rgba(252,76,2,0.08)', cursor: 'pointer', fontSize: 16, color: TN3.ink, fontFamily: 'inherit' },
  markAllBtn: { padding: '8px 12px', background: 'transparent', border: 'none', color: TN3.primary, fontSize: 12, fontWeight: 700, cursor: 'pointer', fontFamily: 'inherit' },

  filters: { display: 'flex', gap: 6, padding: '0 16px 12px', overflowX: 'auto' },
  filterChip: { padding: '6px 12px', background: 'rgba(255,255,255,0.72)', backdropFilter: 'blur(20px) saturate(1.16)', WebkitBackdropFilter: 'blur(20px) saturate(1.16)', border: '1px solid rgba(252,76,2,0.08)', borderRadius: 999, fontSize: 12, fontWeight: 600, color: TN3.ink2, cursor: 'pointer', fontFamily: 'inherit', whiteSpace: 'nowrap', flexShrink: 0 },
  filterChipActive: { background: TN3.ink, color: 'white', borderColor: TN3.ink },

  scroll: { flex: 1, overflow: 'auto', paddingBottom: 100 },
  section: { padding: '0 16px', marginBottom: 16 },
  sectionLabel: { fontSize: 10, color: TN3.ink3, fontWeight: 700, letterSpacing: '0.12em', padding: '8px 4px' },
  list: { display: 'flex', flexDirection: 'column', gap: 8 },

  settingsRow: { display: 'flex', alignItems: 'center', gap: 12, padding: 14, background: 'rgba(255,255,255,0.72)', backdropFilter: 'blur(20px) saturate(1.16)', WebkitBackdropFilter: 'blur(20px) saturate(1.16)', border: '1px solid rgba(252,76,2,0.08)', borderRadius: 14, cursor: 'pointer', fontFamily: 'inherit', width: '100%' },

  // Desktop
  deskShell: { width: '100%', height: '100%', position: 'relative', background: 'radial-gradient(60% 50% at 0% 0%, rgba(252,76,2,0.05) 0%, transparent 50%), radial-gradient(50% 60% at 100% 100%, rgba(252,76,2,0.04) 0%, transparent 55%), linear-gradient(180deg, #FAF7F3 0%, #F4F7FB 100%)', display: 'flex', flexDirection: 'column', overflow: 'hidden', fontFamily: 'Montserrat, sans-serif', color: TN3.ink },
  deskTop: { height: 56, padding: '0 32px', display: 'flex', alignItems: 'center', gap: 12, background: 'rgba(255,255,255,0.7)', backdropFilter: 'blur(14px)', WebkitBackdropFilter: 'blur(14px)', borderBottom: `1px solid ${TN3.line}`, flexShrink: 0 },
  bellBtnActive: { position: 'relative', width: 40, height: 40, borderRadius: 12, border: 'none', background: 'rgba(252,76,2,0.18)', cursor: 'pointer', fontSize: 16, color: TN3.primary, fontFamily: 'inherit', boxShadow: '0 0 0 2px rgba(252,76,2,0.28)' },
  bellBadge: { position: 'absolute', top: 4, right: 4, background: TN3.primary, color: 'white', fontSize: 9, fontWeight: 700, padding: '1px 5px', borderRadius: 999, minWidth: 16, textAlign: 'center', lineHeight: '14px', fontFamily: '"Jost", sans-serif', border: '1.5px solid white' },

  dropdown: { position: 'absolute', top: 16, right: 70, width: 420, maxHeight: 'calc(100% - 32px)', background: 'rgba(255,255,255,0.78)', backdropFilter: 'blur(28px) saturate(1.24)', WebkitBackdropFilter: 'blur(28px) saturate(1.24)', border: '1px solid rgba(252,76,2,0.12)', borderRadius: 18, boxShadow: 'inset 0 1px 0 rgba(255,255,255,0.85), 0 30px 60px rgba(15,23,42,0.18), 0 12px 28px rgba(252,76,2,0.08)', display: 'flex', flexDirection: 'column', overflow: 'hidden', zIndex: 10 },
  dropdownPointer: { position: 'absolute', top: -7, right: 32, width: 14, height: 14, background: 'rgba(255,255,255,0.78)', borderLeft: '1px solid rgba(252,76,2,0.12)', borderTop: '1px solid rgba(252,76,2,0.12)', transform: 'rotate(45deg)' },

  dropdownHead: { padding: '16px 18px 12px', display: 'flex', alignItems: 'center', gap: 10, borderBottom: `1px solid ${TN3.line}` },
  smallBtn: { padding: '6px 12px', background: 'transparent', border: 'none', color: TN3.primary, fontSize: 12, fontWeight: 700, cursor: 'pointer', fontFamily: 'inherit' },

  dropdownTabs: { display: 'flex', gap: 4, padding: '8px 14px', overflowX: 'auto' },
  dropdownTab: { padding: '6px 10px', background: 'transparent', border: 'none', borderRadius: 999, fontSize: 11.5, fontWeight: 600, color: TN3.ink3, cursor: 'pointer', fontFamily: 'inherit', whiteSpace: 'nowrap' },
  dropdownTabActive: { background: TN3.surf3, color: TN3.ink, fontWeight: 700 },

  dropdownList: { flex: 1, overflow: 'auto', padding: '8px 14px 14px' },
  dropdownFoot: { display: 'flex', alignItems: 'center', gap: 10, padding: '12px 16px', borderTop: `1px solid ${TN3.line}`, background: 'rgba(255,255,255,0.5)' },
  footBtn: { padding: '6px 10px', background: 'transparent', border: 'none', color: TN3.ink2, fontSize: 12, fontWeight: 600, cursor: 'pointer', fontFamily: 'inherit' },
  footBtnPrimary: { padding: '7px 14px', background: TN3.primary, color: 'white', border: 'none', borderRadius: 8, fontSize: 12, fontWeight: 700, cursor: 'pointer', fontFamily: 'inherit' },
};

window.NotificationsMobile = NotificationsMobile;
window.NotificationsDesktopDropdown = NotificationsDesktopDropdown;
