/* Direction B — "Pulse"
   Inbox-стиль для тренера: лента событий (загрузки, пропуски, вопросы),
   тренер реагирует в потоке. Атлет — лента «история сегодня» с фокусом
   на одну задачу за раз.                                                   */

const { useState: useStateB, useMemo: useMemoB } = React;
const TB = PR_TOKENS;

// ─────────────────────────────────────────────────────────────────────
// Coach Desktop (1440×900)
// ─────────────────────────────────────────────────────────────────────
function PulseCoach() {
  const [filter, setFilter] = useStateB('all');
  const [activeId, setActiveId] = useStateB(1);
  const [readIds, setReadIds] = useStateB(new Set());

  const filtered = useMemoB(() => {
    if (filter === 'all') return PR_EVENTS;
    if (filter === 'risk') return PR_EVENTS.filter(e => e.tone === 'danger' || e.tone === 'warn');
    if (filter === 'uploads') return PR_EVENTS.filter(e => e.kind === 'upload' || e.kind === 'pr');
    if (filter === 'questions') return PR_EVENTS.filter(e => e.kind === 'question');
    return PR_EVENTS;
  }, [filter]);

  const counts = {
    all: PR_EVENTS.length,
    risk: PR_EVENTS.filter(e => e.tone === 'danger' || e.tone === 'warn').length,
    uploads: PR_EVENTS.filter(e => e.kind === 'upload' || e.kind === 'pr').length,
    questions: PR_EVENTS.filter(e => e.kind === 'question').length,
  };

  const active = filtered.find(e => e.id === activeId) || filtered[0];
  const activeAthlete = active ? PR_ATHLETES.find(a => a.id === active.athleteId) : null;

  const markRead = (id) => {
    const n = new Set(readIds);
    n.add(id);
    setReadIds(n);
  };

  return (
    <div style={pulseStyles.shell}>
      {/* Side rail */}
      <aside style={pulseStyles.rail}>
        <div style={pulseStyles.brand}>
          <span style={pulseStyles.brandMark}>P</span>
        </div>
        <button style={{ ...pulseStyles.railIcon, ...pulseStyles.railActive }}>📥</button>
        <button style={pulseStyles.railIcon}>👥</button>
        <button style={pulseStyles.railIcon}>📅</button>
        <button style={pulseStyles.railIcon}>📈</button>
        <button style={pulseStyles.railIcon}>💬</button>
        <div style={{ flex: 1 }} />
        <div style={pulseStyles.coachAvatar}>МК</div>
      </aside>

      {/* Column 1: filters & metrics */}
      <div style={pulseStyles.col1}>
        <div style={pulseStyles.colHead}>
          <div style={{ fontSize: 22, fontWeight: 800, letterSpacing: '-0.02em' }}>Поток</div>
          <div style={{ fontSize: 12, color: TB.ink3, marginTop: 2 }}>Реагируй на события команды</div>
        </div>
        <div style={pulseStyles.metricStack}>
          <div style={{ ...pulseStyles.metric, background: TB.primary, color: 'white' }}>
            <div style={pulseStyles.metricNum}>{counts.risk + counts.questions}</div>
            <div style={pulseStyles.metricLbl}>ТРЕБУЕТ ОТВЕТА</div>
            <div style={pulseStyles.metricSpark} />
          </div>
          <div style={pulseStyles.metricRow}>
            <div style={pulseStyles.metricSmall}>
              <div style={{ ...pulseStyles.metricNum, color: TB.danger, fontSize: 28 }}>{counts.risk}</div>
              <div style={pulseStyles.metricLbl}>риск</div>
            </div>
            <div style={pulseStyles.metricSmall}>
              <div style={{ ...pulseStyles.metricNum, color: TB.success, fontSize: 28 }}>{counts.uploads}</div>
              <div style={pulseStyles.metricLbl}>загрузки</div>
            </div>
          </div>
        </div>
        <div style={pulseStyles.filterList}>
          {[
            ['all', 'Все', counts.all, TB.ink3],
            ['risk', 'Риски и пропуски', counts.risk, TB.danger],
            ['uploads', 'Новые загрузки', counts.uploads, TB.success],
            ['questions', 'Вопросы', counts.questions, TB.info],
          ].map(([k, l, c, dot]) => (
            <button key={k} onClick={() => setFilter(k)}
              style={{ ...pulseStyles.filterItem, ...(filter === k ? pulseStyles.filterActive : {}) }}>
              <span style={{ width: 8, height: 8, borderRadius: 999, background: dot }} />
              <span style={{ flex: 1, textAlign: 'left' }}>{l}</span>
              <span style={pulseStyles.filterCount}>{c}</span>
            </button>
          ))}
        </div>

        <div style={{ marginTop: 24, padding: 16, background: TB.surf3, borderRadius: 12 }}>
          <div style={{ fontSize: 11, color: TB.ink3, fontWeight: 700, letterSpacing: '0.1em', marginBottom: 8 }}>СНАПШОТ КОМАНДЫ</div>
          <div style={{ display: 'flex', alignItems: 'baseline', gap: 6 }}>
            <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 28, fontWeight: 800, color: TB.ink }}>82%</div>
            <div style={{ fontSize: 11, color: TB.success, fontWeight: 700 }}>+4%</div>
          </div>
          <div style={{ fontSize: 11, color: TB.ink3, marginBottom: 12 }}>средний compliance · неделя</div>
          <div style={{ display: 'flex', gap: 2, height: 32, alignItems: 'flex-end' }}>
            {[0.7, 0.8, 0.65, 0.9, 0.78, 0.82, 0.85].map((v, i) => (
              <div key={i} style={{ flex: 1, height: `${v * 100}%`, background: v >= 0.8 ? TB.success : v >= 0.6 ? TB.warning : TB.danger, borderRadius: 2 }} />
            ))}
          </div>
        </div>
      </div>

      {/* Column 2: event stream */}
      <div style={pulseStyles.col2}>
        <div style={pulseStyles.streamHead}>
          <div style={{ fontSize: 13, color: TB.ink2, fontWeight: 600 }}>{filtered.length} событий · сегодня</div>
          <div style={{ flex: 1 }} />
          <button style={pulseStyles.smallBtn}>Все прочитано</button>
        </div>
        <div style={pulseStyles.stream}>
          {filtered.map(ev => {
            const a = PR_ATHLETES.find(x => x.id === ev.athleteId);
            const isRead = readIds.has(ev.id);
            const isActive = active?.id === ev.id;
            const toneColor = {
              danger: TB.danger, warn: TB.warning, success: TB.success,
              info: TB.info, primary: TB.primary,
            }[ev.tone] || TB.ink3;
            return (
              <button key={ev.id}
                onClick={() => { setActiveId(ev.id); markRead(ev.id); }}
                style={{
                  ...pulseStyles.evCard,
                  ...(isActive ? pulseStyles.evCardActive : {}),
                  opacity: isRead && !isActive ? 0.66 : 1,
                }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                  <PR_Avatar a={a} size={36} ring={isActive ? TB.primary : null} />
                  <div style={{ flex: 1, minWidth: 0, textAlign: 'left' }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                      <span style={{ fontWeight: 700, fontSize: 14, color: TB.ink }}>{a?.name}</span>
                      {!isRead && <span style={{ width: 8, height: 8, borderRadius: 999, background: TB.primary }} />}
                    </div>
                    <div style={{ fontSize: 11, color: TB.ink3 }}>{ev.time}</div>
                  </div>
                  <div style={{
                    width: 28, height: 28, borderRadius: 8,
                    background: toneColor + '20', color: toneColor,
                    display: 'grid', placeItems: 'center', fontWeight: 800, fontSize: 14, flexShrink: 0,
                  }}>{ev.icon}</div>
                </div>
                <div style={{ marginTop: 8, fontSize: 13, color: TB.ink, fontWeight: 600, textAlign: 'left' }}>{ev.headline}</div>
                <div style={{ marginTop: 2, fontSize: 12, color: TB.ink3, textAlign: 'left' }}>{ev.detail}</div>
                {ev.accent && (
                  <div style={{ marginTop: 10, display: 'flex', gap: 6, alignItems: 'center' }}>
                    <span style={{
                      fontSize: 11, fontWeight: 700, padding: '4px 8px', borderRadius: 6,
                      background: toneColor, color: 'white',
                    }}>{ev.accent}</span>
                  </div>
                )}
              </button>
            );
          })}
        </div>
      </div>

      {/* Column 3: action panel */}
      <div style={pulseStyles.col3}>
        {active && activeAthlete && (
          <ActionPanel ev={active} a={activeAthlete} />
        )}
      </div>
    </div>
  );
}

function ActionPanel({ ev, a }) {
  const [reply, setReply] = useStateB('');
  const tone = ev.tone;

  return (
    <div style={{ height: '100%', display: 'flex', flexDirection: 'column' }}>
      {/* Hero */}
      <div style={{
        padding: '28px 28px 24px',
        background: tone === 'danger' ? 'linear-gradient(135deg, #FEE2E2 0%, #FECACA 100%)' :
                    tone === 'warn' ? 'linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%)' :
                    tone === 'success' ? 'linear-gradient(135deg, #DCFCE7 0%, #BBF7D0 100%)' :
                    tone === 'info' ? 'linear-gradient(135deg, #DBEAFE 0%, #BFDBFE 100%)' :
                    'linear-gradient(135deg, #FFE5D9 0%, #FFC7B3 100%)',
      }}>
        <div style={{ display: 'flex', gap: 14, alignItems: 'center' }}>
          <PR_Avatar a={a} size={56} />
          <div style={{ flex: 1, minWidth: 0 }}>
            <div style={{ fontWeight: 800, fontSize: 18, color: TB.ink, letterSpacing: '-0.01em' }}>{a.name}</div>
            <div style={{ fontSize: 12, color: TB.ink2 }}>{a.group} · {a.goal} {a.target ? '· ' + a.target : ''}</div>
          </div>
          <button style={pulseStyles.iconBtnSm}>↗</button>
        </div>
        <div style={{ marginTop: 18, fontSize: 11, color: TB.ink2, fontWeight: 700, letterSpacing: '0.08em' }}>СОБЫТИЕ · {ev.time}</div>
        <div style={{ marginTop: 6, fontSize: 22, fontWeight: 800, color: TB.ink, letterSpacing: '-0.02em', lineHeight: 1.2 }}>{ev.headline}</div>
        <div style={{ marginTop: 4, fontSize: 13, color: TB.ink2 }}>{ev.detail}</div>
      </div>

      {/* Quick actions */}
      <div style={pulseStyles.actionSection}>
        <div style={pulseStyles.sectionLabel}>БЫСТРЫЕ ДЕЙСТВИЯ</div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
          {ev.kind === 'miss' && <>
            <ActionTile icon="↔" label="Перенести тренировку" primary />
            <ActionTile icon="↧" label="Снизить объём" />
            <ActionTile icon="✉" label="Написать в чат" />
            <ActionTile icon="📋" label="Изменить план" />
          </>}
          {ev.kind === 'upload' && <>
            <ActionTile icon="👍" label="Похвалить" primary />
            <ActionTile icon="📈" label="Открыть тренировку" />
            <ActionTile icon="✎" label="Скорректировать след." />
            <ActionTile icon="✉" label="Комментарий" />
          </>}
          {ev.kind === 'question' && <>
            <ActionTile icon="✉" label="Ответить" primary />
            <ActionTile icon="📋" label="Открыть план" />
            <ActionTile icon="🤖" label="Черновик AI" />
            <ActionTile icon="📚" label="Шаблон ответа" />
          </>}
          {ev.kind === 'pr' && <>
            <ActionTile icon="🎉" label="Поздравить" primary />
            <ActionTile icon="📤" label="Поделиться в группе" />
            <ActionTile icon="📈" label="Открыть результат" />
            <ActionTile icon="✎" label="Обновить цели" />
          </>}
        </div>
      </div>

      {/* Context */}
      <div style={pulseStyles.actionSection}>
        <div style={pulseStyles.sectionLabel}>КОНТЕКСТ · ПОСЛЕДНИЕ 7 ДНЕЙ</div>
        <div style={pulseStyles.ctx}>
          <div style={{ flex: 1 }}>
            <div style={{ fontSize: 11, color: TB.ink3 }}>Объём</div>
            <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 22, fontWeight: 700 }}>{a.spark.reduce((s, x) => s + x, 0)} км</div>
          </div>
          <div style={{ flex: 1 }}>
            <div style={{ fontSize: 11, color: TB.ink3 }}>Compliance</div>
            <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 22, fontWeight: 700,
                          color: a.compliance >= 0.8 ? TB.success : a.compliance >= 0.5 ? TB.warning : TB.danger }}>
              {Math.round(a.compliance * 100)}%
            </div>
          </div>
          <div style={{ flex: 1 }}>
            <div style={{ fontSize: 11, color: TB.ink3 }}>Тренд</div>
            <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 22, fontWeight: 700,
                          color: a.paceTrend?.startsWith('+') ? TB.success : a.paceTrend?.startsWith('−') ? TB.danger : TB.ink3 }}>
              {a.paceTrend}
            </div>
          </div>
        </div>
        <div style={{ marginTop: 12 }}>
          <PR_Sparkline data={a.spark} w={324} h={56} color={TB.primary} bg />
        </div>
      </div>

      {/* Reply composer */}
      <div style={{ marginTop: 'auto', padding: 20, borderTop: `1px solid ${TB.line}` }}>
        <div style={pulseStyles.sectionLabel}>ОТВЕТ</div>
        <div style={pulseStyles.replyBox}>
          <textarea
            value={reply}
            onChange={(e) => setReply(e.target.value)}
            placeholder="Напишите ответ или выберите шаблон..."
            style={pulseStyles.replyInput}
          />
          <div style={{ display: 'flex', gap: 6, marginTop: 8 }}>
            <button style={pulseStyles.tplBtn}>👍 Молодец</button>
            <button style={pulseStyles.tplBtn}>🤖 Черновик AI</button>
            <div style={{ flex: 1 }} />
            <button style={{ ...pulseStyles.sendBtn, opacity: reply ? 1 : 0.5 }}>Отправить →</button>
          </div>
        </div>
      </div>
    </div>
  );
}

function ActionTile({ icon, label, primary }) {
  return (
    <button style={{
      ...pulseStyles.actionTile,
      ...(primary ? pulseStyles.actionTilePrimary : {}),
    }}>
      <span style={{ fontSize: 18 }}>{icon}</span>
      <span style={{ fontSize: 12, fontWeight: 600, textAlign: 'left' }}>{label}</span>
    </button>
  );
}

// ─────────────────────────────────────────────────────────────────────
// Athlete Mobile (390×844) — "story" approach
// ─────────────────────────────────────────────────────────────────────
function PulseAthlete() {
  const [story, setStory] = useStateB(0);
  const stories = ['today', 'context', 'goal'];

  return (
    <div style={pulseMobile.shell}>
      <div style={pulseMobile.statusBar}>
        <span>9:41</span>
        <span style={{ display: 'flex', gap: 4 }}>●●● ●</span>
      </div>

      {/* Story progress */}
      <div style={pulseMobile.progressBars}>
        {stories.map((_, i) => (
          <div key={i} style={{
            flex: 1, height: 3, borderRadius: 2,
            background: i < story ? TB.ink : i === story ? TB.ink : '#e2e8f0',
            opacity: i <= story ? 1 : 0.3,
          }} />
        ))}
      </div>

      <div style={pulseMobile.header}>
        <PR_Avatar a={{ initials: 'АП', tone: '#FFD9C9' }} size={32} />
        <div style={{ flex: 1, fontSize: 13, color: TB.ink2 }}>
          <b style={{ color: TB.ink }}>Алексей</b> · 12 мая, вт
        </div>
        <button style={{ background: 'none', border: 'none', fontSize: 18, color: TB.ink2 }}>×</button>
      </div>

      <div style={pulseMobile.story}>
        {stories[story] === 'today' && <StoryToday />}
        {stories[story] === 'context' && <StoryContext />}
        {stories[story] === 'goal' && <StoryGoal />}
      </div>

      {/* Tap zones */}
      <div style={pulseMobile.tapZones}>
        <button style={{ ...pulseMobile.tapZone, left: 0 }}
          onClick={() => setStory(Math.max(0, story - 1))} />
        <button style={{ ...pulseMobile.tapZone, right: 0 }}
          onClick={() => setStory(Math.min(stories.length - 1, story + 1))} />
      </div>

      {/* Bottom shortcuts */}
      <div style={pulseMobile.bottom}>
        <button style={pulseMobile.bottomBtn}>📅 Неделя</button>
        <button style={pulseMobile.bottomBtn}>💬 Тренер</button>
        <button style={pulseMobile.bottomBtn}>📊 Графики</button>
      </div>
    </div>
  );
}

function StoryToday() {
  const t = PR_TODAY;
  return (
    <div style={pulseMobile.storyInner}>
      <div style={pulseMobile.eyebrow}>СЕГОДНЯ · КЛЮЧЕВАЯ</div>
      <div style={{
        background: `linear-gradient(135deg, ${PR_TYPE_COLOR(t.type)} 0%, ${TB.primary} 100%)`,
        borderRadius: 24, padding: 28, color: 'white', marginTop: 12,
        boxShadow: '0 20px 40px rgba(252,76,2,0.25)',
      }}>
        <div style={{ fontSize: 13, opacity: 0.9, fontWeight: 600 }}>ТЕМПОВАЯ</div>
        <div style={{ fontSize: 44, fontWeight: 800, marginTop: 6, letterSpacing: '-0.03em', lineHeight: 1 }}>
          4×1 км<br/>в темпе
        </div>
        <div style={{
          display: 'flex', gap: 20, marginTop: 24, padding: '16px 0',
          borderTop: '1px solid rgba(255,255,255,0.2)', borderBottom: '1px solid rgba(255,255,255,0.2)',
        }}>
          <div>
            <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 28, fontWeight: 800, lineHeight: 1 }}>8,0</div>
            <div style={{ fontSize: 11, opacity: 0.85 }}>километров</div>
          </div>
          <div>
            <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 28, fontWeight: 800, lineHeight: 1 }}>4:30</div>
            <div style={{ fontSize: 11, opacity: 0.85 }}>темп /км</div>
          </div>
          <div>
            <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 28, fontWeight: 800, lineHeight: 1 }}>42<span style={{ fontSize: 14, opacity: 0.85 }}>′</span></div>
            <div style={{ fontSize: 11, opacity: 0.85 }}>примерно</div>
          </div>
        </div>
        <div style={{ display: 'flex', height: 6, borderRadius: 999, overflow: 'hidden', marginTop: 18, gap: 1, background: 'rgba(255,255,255,0.2)' }}>
          {t.segments.map((s, i) => (
            <div key={i} style={{
              flex: s.km,
              background: s.type === 'tempo' ? 'white' : 'rgba(255,255,255,0.45)',
            }} />
          ))}
        </div>
        <div style={{ fontSize: 11, opacity: 0.85, marginTop: 10 }}>
          Разм. → 1км × 4 с восст. → Зам.
        </div>
      </div>

      <div style={pulseMobile.coachStrip}>
        <div style={pulseMobile.coachDot}>М</div>
        <div style={{ flex: 1, fontSize: 13, color: TB.ink2, lineHeight: 1.4 }}>
          <b style={{ color: TB.ink }}>Михаил:</b> старт спокойно, держи 4:30 ровно
        </div>
      </div>

      <button style={pulseMobile.cta}>Я готов · начать ↗</button>
    </div>
  );
}

function StoryContext() {
  return (
    <div style={pulseMobile.storyInner}>
      <div style={pulseMobile.eyebrow}>ПОЧЕМУ ЭТО ВАЖНО</div>
      <h2 style={pulseMobile.storyTitle}>Темп — основа<br/>марафонской скорости</h2>
      <div style={{ marginTop: 16, padding: 18, background: TB.surf3, borderRadius: 16 }}>
        <div style={{ fontSize: 14, color: TB.ink, lineHeight: 1.5 }}>
          На этой неделе ты бежишь темповую трижды быстрее, чем месяц назад. Это нормально — твой <b style={{ color: TB.primary }}>лактатный порог растёт</b>.
        </div>
      </div>
      <div style={{ marginTop: 16, padding: 18, background: TB.surf3, borderRadius: 16, display: 'flex', gap: 16, alignItems: 'center' }}>
        <PR_Sparkline data={[5.0, 4.9, 4.8, 4.7, 4.6, 4.5, 4.3]} w={120} h={56} color={TB.success} bg />
        <div>
          <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 28, fontWeight: 800, color: TB.success, letterSpacing: '-0.03em' }}>−42″</div>
          <div style={{ fontSize: 12, color: TB.ink2 }}>темп vs месяц назад</div>
        </div>
      </div>
      <div style={pulseMobile.coachStrip}>
        <div style={pulseMobile.coachDot}>AI</div>
        <div style={{ flex: 1, fontSize: 13, color: TB.ink2, lineHeight: 1.4 }}>
          Если сегодня тяжело — снизим темп до 4:40. Скажи в чате.
        </div>
      </div>
    </div>
  );
}

function StoryGoal() {
  const g = PR_GOAL;
  return (
    <div style={pulseMobile.storyInner}>
      <div style={pulseMobile.eyebrow}>ТВОЯ ЦЕЛЬ</div>
      <h2 style={pulseMobile.storyTitle}>{g.title}</h2>
      <div style={{ marginTop: 4, fontSize: 14, color: TB.ink2 }}>{g.date} · через {g.daysLeft} дней</div>

      <div style={{
        marginTop: 20, padding: '32px 24px', borderRadius: 24,
        background: 'linear-gradient(180deg, #0F172A 0%, #1E293B 100%)', color: 'white',
        textAlign: 'center',
      }}>
        <div style={{ fontSize: 11, opacity: 0.7, fontWeight: 700, letterSpacing: '0.1em' }}>ТЕКУЩИЙ ПРОГНОЗ</div>
        <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 64, fontWeight: 800, letterSpacing: '-0.04em', lineHeight: 1, marginTop: 6 }}>
          {g.predicted}
        </div>
        <div style={{ fontSize: 12, opacity: 0.7, marginTop: 6 }}>цель {g.target} · −{g.daysLeft} дн.</div>
        <div style={{ marginTop: 18, height: 4, background: 'rgba(255,255,255,0.15)', borderRadius: 999, overflow: 'hidden' }}>
          <div style={{ width: `${g.progress * 100}%`, height: '100%', background: TB.primary }} />
        </div>
        <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 8, fontSize: 11, opacity: 0.7 }}>
          <span>Неделя {g.weeksDone}/{g.weeksTotal}</span>
          <span>{Math.round(g.progress * 100)}%</span>
        </div>
      </div>

      <div style={pulseMobile.coachStrip}>
        <div style={pulseMobile.coachDot}>М</div>
        <div style={{ flex: 1, fontSize: 13, color: TB.ink2, lineHeight: 1.4 }}>
          Темп растёт стабильно. {g.trend} — выходим на цель.
        </div>
      </div>
    </div>
  );
}

const pulseStyles = {
  shell: { width: '100%', height: '100%', background: TB.surf2, display: 'grid', gridTemplateColumns: '64px 280px 1fr 380px', fontFamily: 'Montserrat, sans-serif', color: TB.ink, overflow: 'hidden' },
  rail: { background: TB.ink, padding: '16px 12px', display: 'flex', flexDirection: 'column', gap: 8, alignItems: 'center' },
  brand: { width: 40, height: 40, borderRadius: 12, background: TB.primary, color: 'white', display: 'grid', placeItems: 'center', marginBottom: 12 },
  brandMark: { fontWeight: 800, fontSize: 18 },
  railIcon: { width: 40, height: 40, borderRadius: 10, background: 'transparent', border: 'none', color: 'rgba(255,255,255,0.55)', cursor: 'pointer', fontSize: 18 },
  railActive: { background: 'rgba(255,255,255,0.1)', color: 'white' },
  coachAvatar: { width: 36, height: 36, borderRadius: '50%', background: '#FFD9C9', color: TB.ink, display: 'grid', placeItems: 'center', fontWeight: 700, fontSize: 12 },

  col1: { background: 'white', padding: 24, borderRight: `1px solid ${TB.line}`, overflow: 'auto' },
  colHead: { marginBottom: 20 },
  metricStack: { display: 'flex', flexDirection: 'column', gap: 8 },
  metric: { padding: 18, borderRadius: 16, position: 'relative', overflow: 'hidden' },
  metricNum: { fontFamily: '"Jost", sans-serif', fontSize: 48, fontWeight: 800, letterSpacing: '-0.03em', lineHeight: 1 },
  metricLbl: { fontSize: 11, fontWeight: 700, letterSpacing: '0.1em', marginTop: 6, textTransform: 'uppercase', opacity: 0.85 },
  metricSpark: { position: 'absolute', right: -8, bottom: -8, width: 100, height: 60, background: 'radial-gradient(circle, rgba(255,255,255,0.18) 0%, transparent 70%)' },
  metricRow: { display: 'flex', gap: 8 },
  metricSmall: { flex: 1, padding: 14, background: TB.surf3, borderRadius: 12 },
  filterList: { marginTop: 18, display: 'flex', flexDirection: 'column', gap: 4 },
  filterItem: { display: 'flex', alignItems: 'center', gap: 10, padding: '10px 12px', borderRadius: 8, background: 'transparent', border: 'none', cursor: 'pointer', fontSize: 13, color: TB.ink2, fontFamily: 'inherit' },
  filterActive: { background: TB.surf3, color: TB.ink, fontWeight: 600 },
  filterCount: { fontFamily: '"Jost", sans-serif', fontSize: 12, color: TB.ink3, fontWeight: 600 },

  col2: { display: 'flex', flexDirection: 'column', overflow: 'hidden', background: TB.surf2 },
  streamHead: { padding: '16px 20px', display: 'flex', alignItems: 'center', gap: 10, borderBottom: `1px solid ${TB.line}` },
  smallBtn: { padding: '6px 10px', borderRadius: 6, fontSize: 12, fontWeight: 600, background: 'white', border: `1px solid ${TB.line}`, cursor: 'pointer', color: TB.ink2, fontFamily: 'inherit' },
  stream: { flex: 1, overflow: 'auto', padding: 16, display: 'flex', flexDirection: 'column', gap: 8 },
  evCard: { background: 'white', borderRadius: 14, padding: 14, border: `1px solid ${TB.line}`, cursor: 'pointer', textAlign: 'left', transition: 'all 0.15s', fontFamily: 'inherit' },
  evCardActive: { border: `1.5px solid ${TB.primary}`, boxShadow: '0 4px 12px rgba(252,76,2,0.1)' },

  col3: { background: 'white', borderLeft: `1px solid ${TB.line}`, overflow: 'auto' },
  iconBtnSm: { width: 32, height: 32, borderRadius: 8, background: 'rgba(255,255,255,0.5)', border: 'none', cursor: 'pointer', fontSize: 14 },
  actionSection: { padding: '20px 24px' },
  sectionLabel: { fontSize: 10, color: TB.ink3, fontWeight: 700, letterSpacing: '0.12em', marginBottom: 10 },
  actionTile: { padding: 14, background: TB.surf3, borderRadius: 12, border: 'none', cursor: 'pointer', display: 'flex', flexDirection: 'column', alignItems: 'flex-start', gap: 6, fontFamily: 'inherit' },
  actionTilePrimary: { background: TB.primary, color: 'white' },
  ctx: { display: 'flex', gap: 16, padding: 16, background: TB.surf3, borderRadius: 12 },
  replyBox: { background: TB.surf3, borderRadius: 12, padding: 12 },
  replyInput: { width: '100%', minHeight: 60, background: 'transparent', border: 'none', outline: 'none', fontSize: 13, color: TB.ink, resize: 'none', fontFamily: 'inherit' },
  tplBtn: { padding: '6px 10px', borderRadius: 6, fontSize: 11, fontWeight: 600, background: 'white', border: `1px solid ${TB.line}`, cursor: 'pointer', color: TB.ink2, fontFamily: 'inherit' },
  sendBtn: { padding: '6px 14px', borderRadius: 6, fontSize: 12, fontWeight: 700, background: TB.primary, color: 'white', border: 'none', cursor: 'pointer', fontFamily: 'inherit' },
};

const pulseMobile = {
  shell: { width: '100%', height: '100%', background: TB.surf, display: 'flex', flexDirection: 'column', fontFamily: 'Montserrat, sans-serif', color: TB.ink, position: 'relative', overflow: 'hidden' },
  statusBar: { height: 36, padding: '0 24px', display: 'flex', alignItems: 'center', justifyContent: 'space-between', fontSize: 13, fontWeight: 600 },
  progressBars: { display: 'flex', gap: 4, padding: '4px 16px 0' },
  header: { padding: '12px 20px', display: 'flex', alignItems: 'center', gap: 10 },
  story: { flex: 1, padding: '0 20px', overflow: 'auto', paddingBottom: 80 },
  storyInner: {},
  eyebrow: { fontSize: 11, color: TB.ink3, fontWeight: 700, letterSpacing: '0.12em' },
  storyTitle: { fontSize: 32, fontWeight: 800, letterSpacing: '-0.03em', lineHeight: 1.05, color: TB.ink, marginTop: 6 },
  coachStrip: { display: 'flex', gap: 10, alignItems: 'center', padding: 12, marginTop: 16, background: 'white', border: `1px solid ${TB.line}`, borderRadius: 14 },
  coachDot: { width: 28, height: 28, borderRadius: '50%', background: TB.primary, color: 'white', fontSize: 11, fontWeight: 700, display: 'grid', placeItems: 'center', flexShrink: 0 },
  cta: { marginTop: 20, width: '100%', padding: 18, borderRadius: 999, background: TB.ink, color: 'white', border: 'none', fontWeight: 700, fontSize: 15, cursor: 'pointer', fontFamily: 'inherit' },
  tapZones: { position: 'absolute', top: 60, bottom: 80, left: 0, right: 0, display: 'flex', pointerEvents: 'none' },
  tapZone: { position: 'absolute', top: 0, bottom: 0, width: '40%', background: 'transparent', border: 'none', cursor: 'pointer', pointerEvents: 'auto' },
  bottom: { position: 'absolute', bottom: 16, left: 16, right: 16, display: 'flex', gap: 8 },
  bottomBtn: { flex: 1, padding: '12px 8px', borderRadius: 12, background: 'rgba(15,23,42,0.85)', backdropFilter: 'blur(20px)', color: 'white', border: 'none', fontWeight: 600, fontSize: 12, cursor: 'pointer', fontFamily: 'inherit' },
};

window.PulseCoach = PulseCoach;
window.PulseAthlete = PulseAthlete;
