/* v2: Coach Mobile — тренер на ходу.
   Главный экран = поток событий + быстрые ответы.
   Тренер реагирует прямо из ленты, не уходя на детали.   */

const { useState: useStateCM } = React;
const CT = V2.T;

function CoachMobile() {
  const [tab, setTab] = useStateCM('stream');
  const [drawerEvent, setDrawerEvent] = useStateCM(null);

  return (
    <div style={CM.shell}>
      <div style={CM.statusBar}>
        <span style={{ fontFamily: '"Jost", sans-serif' }}>9:41</span>
        <span style={{ display: 'flex', gap: 5 }}>
          <span>●●●</span><span style={{ opacity: 0.5 }}>●</span>
          <span style={{ marginLeft: 6 }}>5G</span>
          <span style={{ marginLeft: 6 }}>89%</span>
        </span>
      </div>

      {/* Header */}
      <div style={CM.header}>
        <div>
          <div style={{ fontSize: 11, color: CT.ink3, fontWeight: 700, letterSpacing: '0.1em', textTransform: 'uppercase' }}>Вторник · 12 мая</div>
          <div style={{ fontSize: 24, fontWeight: 800, color: CT.ink, letterSpacing: '-0.02em', marginTop: 2 }}>На связи, Михаил</div>
        </div>
        <div style={{ width: 40, height: 40, borderRadius: '50%', background: '#FFD9C9', display: 'grid', placeItems: 'center', fontWeight: 700, fontSize: 13 }}>МК</div>
      </div>

      {/* KPI strip */}
      <div style={CM.kpiStrip}>
        <KPI num={V2.ATHLETES.filter(a => a.atRisk).length}  label="Риск"     color={CT.danger} />
        <KPI num={V2.ATHLETES.filter(a => a.freshUpload).length} label="Загрузки" color={CT.success} />
        <KPI num={V2.EVENTS.filter(e => e.kind === 'question').length} label="Вопросы"  color={CT.info} />
        <KPI num={V2.ATHLETES.length}                         label="Атлетов"  color={CT.ink2} />
      </div>

      {/* Tabs */}
      <div style={CM.tabs}>
        {[['stream', 'Поток ' + (V2.EVENTS.length)], ['team', 'Команда'], ['cal', 'Календарь']].map(([k, l]) => (
          <button key={k} onClick={() => setTab(k)}
            style={{ ...CM.tab, ...(tab === k ? CM.tabActive : {}) }}>
            {l}
          </button>
        ))}
      </div>

      <div style={CM.scroll}>
        {tab === 'stream' && <StreamTab onOpen={setDrawerEvent} />}
        {tab === 'team'   && <TeamTab />}
        {tab === 'cal'    && <CalTab />}
      </div>

      {/* Bottom nav (Variant C) */}
      <MobileNav activeIndex={1} role="coach" />

      {/* Quick reply bottom sheet */}
      {drawerEvent && <QuickReplySheet ev={drawerEvent} onClose={() => setDrawerEvent(null)} />}
    </div>
  );
}

function KPI({ num, label, color }) {
  return (
    <div style={CM.kpi}>
      <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 22, fontWeight: 800, color, lineHeight: 1, letterSpacing: '-0.02em' }}>{num}</div>
      <div style={{ fontSize: 10, color: CT.ink3, fontWeight: 600, letterSpacing: '0.04em', marginTop: 2 }}>{label}</div>
    </div>
  );
}

function StreamTab({ onOpen }) {
  return (
    <div style={{ padding: '14px 16px 100px' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '4px 0 10px' }}>
        <span style={{ fontSize: 12, color: CT.ink2, fontWeight: 600 }}>{V2.EVENTS.length} событий · сегодня</span>
        <div style={{ flex: 1 }} />
        <button style={CM.smallBtn}>Фильтр</button>
      </div>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
        {V2.EVENTS.map(ev => {
          const a = V2.athleteById(ev.athleteId);
          const t = V2.toneStyles(ev.tone);
          const kindIcon = ev.kind === 'upload' ? '↑' : ev.kind === 'risk' ? '!' : ev.kind === 'question' ? '?' : ev.kind === 'pr' ? '★' : '•';
          return (
            <div key={ev.id} onClick={() => onOpen(ev)} role="button" tabIndex={0} style={CM.eventCard}>
              <V2.Avatar a={a} size={40} ring={t.solid} />
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                  <span style={{ fontWeight: 700, fontSize: 14, color: CT.ink, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{a?.name.split(' ')[0]}</span>
                  <span style={{ width: 18, height: 18, borderRadius: 5, background: t.bg, color: t.color, display: 'grid', placeItems: 'center', fontWeight: 800, fontSize: 10, flexShrink: 0 }}>{kindIcon}</span>
                  <span style={{ flex: 1 }} />
                  <span style={{ fontSize: 11, color: CT.ink3 }}>{ev.time}</span>
                </div>
                <div style={{ fontSize: 13, color: CT.ink, fontWeight: 600, marginTop: 4 }}>{ev.title}</div>
                <div style={{ fontSize: 12, color: CT.ink3, marginTop: 1 }}>{ev.detail}</div>
                {ev.cta && (
                  <div style={{ marginTop: 8 }}>
                    <button onClick={(e) => { e.stopPropagation(); onOpen(ev); }} style={{
                      background: t.solid, color: 'white', border: 'none',
                      padding: '6px 12px', borderRadius: 8, fontSize: 12, fontWeight: 700,
                      cursor: 'pointer', fontFamily: 'inherit',
                    }}>{ev.cta} →</button>
                  </div>
                )}
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}

function TeamTab() {
  return (
    <div style={{ padding: '14px 16px 100px' }}>
      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 12 }}>
        {V2.GROUPS.map(g => (
          <span key={g.id} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '6px 12px', borderRadius: 999, background: g.color + '15', color: g.color, fontSize: 12, fontWeight: 600 }}>
            <span style={{ width: 6, height: 6, borderRadius: 999, background: g.color }} />
            {g.name} · {V2.athletesInGroup(g.id).length}
          </span>
        ))}
      </div>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
        {V2.ATHLETES.map(a => (
          <div key={a.id} style={CM.athleteRow}>
            <V2.Avatar a={a} size={40} ring={a.atRisk ? CT.danger : a.freshUpload ? CT.success : null} />
            <div style={{ flex: 1, minWidth: 0 }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                <span style={{ fontSize: 14, fontWeight: 700, color: CT.ink }}>{a.name}</span>
                {a.unread > 0 && <span style={{ background: CT.primary, color: 'white', borderRadius: 999, fontSize: 10, padding: '1px 6px', fontWeight: 700, fontFamily: '"Jost", sans-serif' }}>{a.unread}</span>}
              </div>
              <div style={{ fontSize: 11, color: CT.ink3 }}>{a.goal} {a.target && '· ' + a.target}</div>
            </div>
            <div style={{ textAlign: 'right', minWidth: 60 }}>
              <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 16, fontWeight: 700, color: a.compliance >= 0.8 ? CT.success : a.compliance >= 0.5 ? CT.warning : CT.danger, lineHeight: 1 }}>{a.weekDone}/{a.weekTotal}</div>
              <V2.Compliance done={a.weekDone} total={a.weekTotal} w={50} />
              <div style={{ fontSize: 10, color: CT.ink3, marginTop: 2 }}>{a.lastActivity}</div>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

function CalTab() {
  return (
    <div style={{ padding: '14px 16px 100px' }}>
      <div style={{ fontSize: 11, color: CT.ink3, fontWeight: 700, letterSpacing: '0.08em', marginBottom: 10 }}>НЕДЕЛЯ 12 · 11–17 МАЯ · ВСЯ КОМАНДА</div>
      <div style={{ background: 'white', border: `1px solid ${CT.line}`, borderRadius: 14, padding: 12 }}>
        {V2.WEEK.map((d, i) => {
          const dayAthletes = V2.ATHLETES.filter(() => Math.random() > 0.3).slice(0, 4); // stub
          return (
            <div key={i} style={{ display: 'flex', gap: 10, padding: '10px 0', borderBottom: i < V2.WEEK.length - 1 ? `1px solid ${CT.line}` : 'none' }}>
              <div style={{ width: 38, textAlign: 'center', paddingTop: 2 }}>
                <div style={{ fontSize: 10, color: CT.ink3, fontWeight: 700 }}>{d.day}</div>
                <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 18, fontWeight: 800, color: d.status === 'today' ? CT.primary : CT.ink, lineHeight: 1 }}>{d.date}</div>
              </div>
              <div style={{ flex: 1 }}>
                <div style={{ display: 'flex', gap: 4, flexWrap: 'wrap' }}>
                  {dayAthletes.map(a => (
                    <span key={a.id} style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '4px 8px', background: V2.typeColor(a.todayPlan?.type) + '15', borderRadius: 6, fontSize: 11, fontWeight: 600 }}>
                      <span style={{ width: 5, height: 5, borderRadius: 999, background: V2.typeColor(a.todayPlan?.type) }} />
                      {a.initials} · {a.todayPlan?.label || 'отдых'}
                    </span>
                  ))}
                </div>
              </div>
            </div>
          );
        })}
      </div>

      <button style={{ marginTop: 14, width: '100%', padding: 14, borderRadius: 12, background: CT.primary, color: 'white', border: 'none', fontWeight: 700, fontSize: 14, cursor: 'pointer', fontFamily: 'inherit' }}>+ Назначить группе</button>
    </div>
  );
}

// ── Quick reply sheet (bottom sheet) ──────────────────────────────────
function QuickReplySheet({ ev, onClose }) {
  const a = V2.athleteById(ev.athleteId);
  const t = V2.toneStyles(ev.tone);
  const [reply, setReply] = useStateCM('');

  const tplsByKind = {
    upload:   ['👍 Молодец!', 'Сильно!', 'Отлично прошла'],
    risk:     ['Что случилось?', 'Скорректирую план', 'Давай созвонимся'],
    question: ['Сейчас расскажу', 'Открой план', 'Хороший вопрос!'],
    pr:       ['🎉 Поздравляю!', 'Так держать!', 'Личный рекорд!'],
  };

  return (
    <>
      <div onClick={onClose} style={CM.sheetScrim} />
      <div style={CM.sheet}>
        <div style={CM.sheetGrip} />
        <div style={{ display: 'flex', gap: 12, alignItems: 'center', marginTop: 8 }}>
          <V2.Avatar a={a} size={44} ring={t.solid} />
          <div style={{ flex: 1, minWidth: 0 }}>
            <div style={{ fontSize: 15, fontWeight: 700, color: CT.ink }}>{a?.name}</div>
            <div style={{ fontSize: 11, color: CT.ink3 }}>{ev.time} назад · {ev.title}</div>
          </div>
          <button onClick={onClose} style={CM.iconBtnSm}>✕</button>
        </div>
        <div style={{ padding: 12, background: t.bg, borderRadius: 10, marginTop: 14, fontSize: 13, color: t.color, fontWeight: 500 }}>
          {ev.detail}
        </div>

        <div style={{ marginTop: 18 }}>
          <div style={{ fontSize: 10, color: CT.ink3, fontWeight: 700, letterSpacing: '0.08em', marginBottom: 8 }}>БЫСТРЫЙ ОТВЕТ</div>
          <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginBottom: 10 }}>
            {(tplsByKind[ev.kind] || ['Спасибо']).map(t2 => (
              <button key={t2} onClick={() => setReply(t2)} style={CM.tplChip}>{t2}</button>
            ))}
          </div>
          <div style={{ display: 'flex', gap: 8 }}>
            <input value={reply} onChange={(e) => setReply(e.target.value)} placeholder="Написать сообщение…" style={{ flex: 1, padding: '12px 14px', borderRadius: 12, border: `1px solid ${CT.line}`, fontSize: 13, fontFamily: 'inherit', outline: 'none' }} />
            <button style={{ background: CT.primary, color: 'white', border: 'none', padding: '0 18px', borderRadius: 12, fontWeight: 700, cursor: 'pointer', fontFamily: 'inherit', fontSize: 14 }}>→</button>
          </div>
        </div>

        <div style={{ marginTop: 16, paddingTop: 14, borderTop: `1px solid ${CT.line}` }}>
          <div style={{ fontSize: 10, color: CT.ink3, fontWeight: 700, letterSpacing: '0.08em', marginBottom: 8 }}>ИЛИ ДЕЙСТВИЕ</div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
            <button style={CM.actionBtn}>📋 Открыть план</button>
            <button style={CM.actionBtn}>↔ Перенести</button>
            <button style={CM.actionBtn}>📈 Графики</button>
            <button style={CM.actionBtn}>🤖 Черновик AI</button>
          </div>
        </div>
      </div>
    </>
  );
}

const CM = {
  shell: { width: '100%', height: '100%', background: CT.surf, display: 'flex', flexDirection: 'column', overflow: 'hidden', position: 'relative', fontFamily: 'Montserrat, sans-serif', color: CT.ink },
  statusBar: { height: 36, padding: '0 24px', display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontSize: 13, fontWeight: 700 },
  header: { padding: '8px 20px 12px', display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end' },
  kpiStrip: { display: 'flex', gap: 8, padding: '0 20px 14px' },
  kpi: { flex: 1, padding: 12, background: CT.surf2, border: `1px solid ${CT.line}`, borderRadius: 12 },
  tabs: { display: 'flex', padding: '0 20px', gap: 18, borderBottom: `1px solid ${CT.line}` },
  tab: { padding: '12px 0', background: 'transparent', border: 'none', borderBottom: '2px solid transparent', color: CT.ink3, fontWeight: 600, fontSize: 14, cursor: 'pointer', fontFamily: 'inherit' },
  tabActive: { color: CT.ink, borderBottomColor: CT.primary, fontWeight: 700 },
  scroll: { flex: 1, overflow: 'auto' },
  smallBtn: { padding: '6px 12px', borderRadius: 6, fontSize: 11, fontWeight: 600, background: CT.surf3, border: 'none', cursor: 'pointer', color: CT.ink2, fontFamily: 'inherit' },

  eventCard: { display: 'flex', gap: 12, padding: 14, background: 'white', border: `1px solid ${CT.line}`, borderRadius: 14, cursor: 'pointer' },
  athleteRow: { display: 'flex', gap: 12, padding: 14, background: 'white', border: `1px solid ${CT.line}`, borderRadius: 14, alignItems: 'center' },

  nav: { position: 'absolute', bottom: 12, left: 12, right: 12, height: 64, background: 'rgba(255,255,255,0.92)', backdropFilter: 'blur(20px)', borderRadius: 20, border: `1px solid ${CT.line}`, display: 'flex', justifyContent: 'space-around', alignItems: 'center', boxShadow: '0 12px 32px rgba(0,0,0,0.06)' },
  navItem: { display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 2 },
  navBadge: { position: 'absolute', top: -2, right: -8, background: CT.primary, color: 'white', fontSize: 9, fontWeight: 700, padding: '0 4px', borderRadius: 999, minWidth: 14, textAlign: 'center', lineHeight: '14px' },

  sheetScrim: { position: 'absolute', inset: 0, background: 'rgba(15,23,42,0.4)', zIndex: 10 },
  sheet: { position: 'absolute', bottom: 0, left: 0, right: 0, background: 'white', borderRadius: '24px 24px 0 0', padding: 20, paddingBottom: 28, zIndex: 11, boxShadow: '0 -20px 40px rgba(0,0,0,0.15)' },
  sheetGrip: { width: 40, height: 4, borderRadius: 999, background: CT.line2, margin: '0 auto' },
  iconBtnSm: { width: 32, height: 32, borderRadius: 8, border: 'none', background: CT.surf3, cursor: 'pointer', fontSize: 14 },
  tplChip: { padding: '8px 14px', background: CT.surf3, border: 'none', borderRadius: 999, fontSize: 12, fontWeight: 600, color: CT.ink, cursor: 'pointer', fontFamily: 'inherit' },
  actionBtn: { padding: '12px 14px', background: CT.surf3, border: 'none', borderRadius: 10, fontSize: 12, fontWeight: 600, color: CT.ink, cursor: 'pointer', fontFamily: 'inherit', textAlign: 'left' },
};

window.CoachMobile = CoachMobile;
