/* v3 Chat — полный редизайн чата.
   Покрывает то, чего не было в упрощённых AIChat/TrainerChat:
   - Список чатов (sidebar) на десктопе: AI / тренер / администрация
   - Tool-calling индикаторы (Обновляю тренировку…) + result-карточки
   - Стриминг-фазы (печатает / думает / вызывает инструмент)
   - Пустой AI-чат с suggested prompts
   - Композер: голос, фото, emoji
   - Контекстные quick-replies
   6 артбордов: desktop AI, desktop trainer, mobile AI, mobile empty,
   mobile tool-calling (live), mobile trainer.                          */

const { useState: useStateCH } = React;
const CT3 = V2.T;

// Tool labels (из реального chatScreen)
const TOOL_LABELS = {
  get_training_day: 'Смотрю план на сегодня…',
  update_training_day: 'Обновляю тренировку…',
  swap_training_days: 'Меняю дни местами…',
  recalculate_plan: 'Пересчитываю план…',
  get_stats_summary: 'Загружаю статистику…',
  log_workout: 'Записываю результат…',
};

const SUGGESTED = [
  { text: 'Что у меня сегодня?', icon: '📋' },
  { text: 'Покажи план на неделю', icon: '📅' },
  { text: 'Перенеси тренировку на завтра', icon: '↔' },
  { text: 'Поставь сегодня выходной', icon: '💤' },
  { text: 'Как прошла последняя?', icon: '📊' },
  { text: 'Пересчитай план', icon: '🔄' },
];

// ── Chat list data ──────────────────────────────────────────────────
const CHAT_LIST = [
  { id: 'ai', kind: 'ai', label: 'AI-тренер', desc: 'Печатает…', time: 'сейчас', unread: 0, active: true, online: true },
  { id: 'coach', kind: 'coach', label: 'Михаил Краснов', desc: 'Поставлю 5×1 км на чт', time: '14:18', unread: 0, online: true, initials: 'МК' },
  { id: 'admin', kind: 'admin', label: 'От администрации', desc: 'Обновление приложения 3.28', time: 'вчера', unread: 1 },
];

// ── Message bubbles ─────────────────────────────────────────────────
function Bubble({ role, text, time, tool, attachment }) {
  const isAi = role === 'ai' || role === 'coach';
  return (
    <div style={{ display: 'flex', justifyContent: isAi ? 'flex-start' : 'flex-end', marginBottom: 10 }}>
      <div style={{ maxWidth: '78%' }}>
        <div style={{
          padding: '11px 14px',
          borderRadius: isAi ? '16px 16px 16px 5px' : '16px 16px 5px 16px',
          background: isAi ? 'rgba(255,255,255,0.72)' : CT3.primary,
          backdropFilter: isAi ? 'blur(14px) saturate(1.14)' : 'none',
          WebkitBackdropFilter: isAi ? 'blur(14px) saturate(1.14)' : 'none',
          border: isAi ? '1px solid rgba(252,76,2,0.08)' : 'none',
          color: isAi ? CT3.ink : 'white',
          fontSize: 14, lineHeight: 1.45,
          boxShadow: isAi ? '0 4px 12px rgba(15,23,42,0.05)' : '0 6px 16px rgba(252,76,2,0.25)',
        }}>{text}</div>
        {tool && <ToolResultCard tool={tool} />}
        {time && <div style={{ fontSize: 10, color: CT3.ink3, marginTop: 4, textAlign: isAi ? 'left' : 'right', fontFamily: '"Jost", sans-serif' }}>{time}</div>}
      </div>
    </div>
  );
}

// Result card after a tool runs (e.g. workout moved)
function ToolResultCard({ tool }) {
  return (
    <div style={{
      marginTop: 6, padding: 12, borderRadius: 12,
      background: 'rgba(34,197,94,0.1)', border: '1px solid rgba(34,197,94,0.25)',
      display: 'flex', alignItems: 'center', gap: 10,
    }}>
      <div style={{ width: 32, height: 32, borderRadius: 9, background: CT3.success, color: 'white', display: 'grid', placeItems: 'center', fontWeight: 800, fontSize: 14, flexShrink: 0 }}>✓</div>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ fontSize: 12, fontWeight: 700, color: '#166534' }}>{tool.title}</div>
        <div style={{ fontSize: 11, color: CT3.ink2, marginTop: 1 }}>{tool.detail}</div>
      </div>
      <button style={{ padding: '5px 10px', background: 'white', border: `1px solid ${CT3.line}`, borderRadius: 7, fontSize: 11, fontWeight: 700, color: CT3.ink, cursor: 'pointer', fontFamily: 'inherit', flexShrink: 0 }}>Открыть</button>
    </div>
  );
}

// Live tool-calling indicator
function ToolCallingIndicator({ label }) {
  return (
    <div style={{ display: 'flex', justifyContent: 'flex-start', marginBottom: 10 }}>
      <div style={{
        display: 'flex', alignItems: 'center', gap: 10,
        padding: '11px 16px', borderRadius: '16px 16px 16px 5px',
        background: 'rgba(252,76,2,0.08)', border: '1px solid rgba(252,76,2,0.2)',
      }}>
        <span style={CH3.toolSpinner} />
        <span style={{ fontSize: 13, color: CT3.primary, fontWeight: 600 }}>{label}</span>
      </div>
    </div>
  );
}

// Typing dots
function TypingDots() {
  return (
    <div style={{ display: 'flex', justifyContent: 'flex-start', marginBottom: 10 }}>
      <div style={{
        display: 'flex', alignItems: 'center', gap: 4,
        padding: '14px 18px', borderRadius: '16px 16px 16px 5px',
        background: 'rgba(255,255,255,0.72)', border: '1px solid rgba(252,76,2,0.08)',
      }}>
        {[0, 1, 2].map(i => (
          <span key={i} style={{ ...CH3.typingDot, animationDelay: `${i * 0.16}s` }} />
        ))}
      </div>
    </div>
  );
}

// Composer
function Composer({ placeholder = 'Спроси что угодно про тренировки…', value, onChange, mode }) {
  return (
    <div style={CH3.composer}>
      <button style={CH3.composerIcon} title="Фото">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="M21 15l-5-5L5 21"/></svg>
      </button>
      <input value={value} onChange={(e) => onChange?.(e.target.value)} placeholder={placeholder} style={CH3.composerInput} />
      <button style={CH3.composerIcon} title="Эмодзи">😊</button>
      <button style={CH3.composerSend} title={value ? 'Отправить' : 'Голосовое'}>
        {value
          ? <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
          : <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2M12 19v4"/></svg>}
      </button>
    </div>
  );
}

// ── Chat avatar (AI gradient / coach photo / admin mail) ────────────
function ChatAvatar({ kind, initials, size = 40 }) {
  if (kind === 'ai') {
    return <div style={{ width: size, height: size, borderRadius: 12, background: 'linear-gradient(135deg, #FC4C02, #FF6B3D)', color: 'white', display: 'grid', placeItems: 'center', fontWeight: 800, fontSize: size * 0.33, flexShrink: 0, boxShadow: '0 4px 12px rgba(252,76,2,0.3)' }}>AI</div>;
  }
  if (kind === 'coach') {
    return <V2.Avatar a={{ initials: initials || 'МК', tone: '#FFD9C9' }} size={size} />;
  }
  return <div style={{ width: size, height: size, borderRadius: 12, background: CT3.surf3, color: CT3.ink2, display: 'grid', placeItems: 'center', flexShrink: 0 }}>
    <svg width={size*0.5} height={size*0.5} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 7l-10 5L2 7"/></svg>
  </div>;
}

// ─────────────────────────────────────────────────────────────────────
// DESKTOP — 2 column (list + conversation)
// ─────────────────────────────────────────────────────────────────────
function ChatDesktop({ activeKind = 'ai' }) {
  const active = CHAT_LIST.find(c => c.kind === activeKind) || CHAT_LIST[0];

  return (
    <div style={CH3.deskShell}>
      {/* App top */}
      <div style={CH3.deskTop}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          <span style={{ width: 30, height: 30, borderRadius: 9, background: 'linear-gradient(135deg, #FC4C02, #FF6B3D)', color: 'white', display: 'grid', placeItems: 'center', fontWeight: 800, fontSize: 15 }}>P</span>
          <span style={{ fontWeight: 800, fontSize: 17, letterSpacing: '-0.02em', color: CT3.ink }}>planrun</span>
        </div>
        <nav style={{ display: 'flex', gap: 2, marginLeft: 24 }}>
          {[['Дэшборд', false], ['Календарь', false], ['Чат', true], ['Прогресс', false], ['Настройки', false]].map(([l, on]) => (
            <a key={l} style={{ padding: '8px 14px', borderRadius: 8, fontSize: 13, fontWeight: on ? 700 : 500, color: on ? CT3.ink : CT3.ink2, background: on ? CT3.surf3 : 'transparent' }}>{l}</a>
          ))}
        </nav>
        <div style={{ flex: 1 }} />
        <div style={{ width: 36, height: 36, borderRadius: '50%', background: '#FFD9C9', display: 'grid', placeItems: 'center', fontWeight: 700, fontSize: 13 }}>АП</div>
      </div>

      <div style={CH3.deskBody}>
        {/* Sidebar: chat list */}
        <aside style={CH3.sidebar}>
          <div style={{ padding: '16px 16px 10px' }}>
            <div style={{ fontSize: 20, fontWeight: 800, color: CT3.ink, letterSpacing: '-0.02em' }}>Чаты</div>
          </div>
          <div style={{ padding: '0 12px', display: 'flex', flexDirection: 'column', gap: 4 }}>
            {CHAT_LIST.map(c => (
              <button key={c.id} style={{
                ...CH3.chatListItem,
                background: c.kind === activeKind ? CT3.primaryWash : 'transparent',
                border: c.kind === activeKind ? `1px solid rgba(252,76,2,0.15)` : '1px solid transparent',
              }}>
                <div style={{ position: 'relative' }}>
                  <ChatAvatar kind={c.kind} initials={c.initials} size={44} />
                  {c.online && <span style={CH3.onlineDot} />}
                </div>
                <div style={{ flex: 1, minWidth: 0, textAlign: 'left' }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                    <span style={{ fontSize: 14, fontWeight: 700, color: CT3.ink, flex: 1, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{c.label}</span>
                    <span style={{ fontSize: 11, color: CT3.ink3, flexShrink: 0 }}>{c.time}</span>
                  </div>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginTop: 2 }}>
                    <span style={{ fontSize: 12, color: c.kind === 'ai' ? CT3.primary : CT3.ink3, flex: 1, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis', fontWeight: c.kind === 'ai' ? 600 : 400 }}>{c.desc}</span>
                    {c.unread > 0 && <span style={CH3.unreadBadge}>{c.unread}</span>}
                  </div>
                </div>
              </button>
            ))}
          </div>
        </aside>

        {/* Conversation */}
        <main style={CH3.conversation}>
          <ConversationHeader active={active} desktop />
          <div style={CH3.messagesArea}>
            <div style={CH3.dateLabel}>СЕГОДНЯ</div>
            {activeKind === 'ai' ? <AIConversation /> : <CoachConversation />}
          </div>
          <QuickReplies items={activeKind === 'ai' ? ['Что на завтра?', 'Покажи неделю', 'Перенеси на завтра'] : ['👍 Понял', 'Когда подводка?', 'Болит колено']} />
          <Composer mode={activeKind} value="" />
        </main>
      </div>
    </div>
  );
}

function ConversationHeader({ active, desktop, onBack }) {
  return (
    <div style={CH3.convHeader}>
      {!desktop && <button onClick={onBack} style={CH3.backBtn}>←</button>}
      <div style={{ position: 'relative' }}>
        <ChatAvatar kind={active.kind} initials={active.initials} size={desktop ? 44 : 38} />
        {active.online && <span style={CH3.onlineDot} />}
      </div>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ fontSize: 15, fontWeight: 700, color: CT3.ink }}>{active.label}</div>
        <div style={{ fontSize: 11, color: active.online ? CT3.success : CT3.ink3, fontWeight: 600 }}>
          {active.kind === 'ai' ? '● Всегда онлайн · отвечает мгновенно' : active.online ? '● онлайн · отвечает ~14 мин' : 'был(а) недавно'}
        </div>
      </div>
      {active.kind === 'coach' && <button style={CH3.headerIcon}>📞</button>}
      <button style={CH3.headerIcon}>⋯</button>
    </div>
  );
}

function AIConversation() {
  return (
    <>
      {/* Capabilities banner */}
      <div style={CH3.capBanner}>
        <span style={{ fontSize: 11, color: CT3.primary, fontWeight: 700, letterSpacing: '0.06em' }}>★ AI МОЖЕТ ИЗМЕНЯТЬ ТВОЙ ПЛАН</span>
        <div style={{ display: 'flex', gap: 6, marginTop: 8, flexWrap: 'wrap' }}>
          {['✎ править', '↔ переносить', '✓ отмечать', '🔄 пересчитать'].map(c => (
            <span key={c} style={CH3.capChip}>{c}</span>
          ))}
        </div>
      </div>
      <Bubble role="ai" text="Доброе утро, Алексей! Сегодня темповая 4×1 км. Как самочувствие после вчерашнего лёгкого?" time="7:42" />
      <Bubble role="user" text="Норм, поспал 7 часов. Можно утром вместо вечера?" time="7:48" />
      <Bubble role="ai" text="Конечно. Перенёс на 8:00. Если бежишь натощак — сбрось темп на 5-10 сек/км." time="7:48"
        tool={{ title: 'Тренировка перенесена', detail: 'Вторник · с 19:00 на 8:00' }} />
      <Bubble role="user" text="А если будет тяжело на третьем повторе?" time="7:49" />
      <Bubble role="ai" text="Снижай до 4:40, главное — закончить серию ровно. Если совсем плохо — сделай 3×1 км и заминку." time="7:49" />
    </>
  );
}

function CoachConversation() {
  return (
    <>
      <Bubble role="coach" text="Алексей, темповая вчера прошла отлично! Видел трек — последний км 4:25." time="вчера 18:42" />
      <Bubble role="user" text="Спасибо! Чувствую что могу больше." time="вчера 18:50" />
      <Bubble role="coach" text="Молодец! Поставлю 5×1 км на четверг. Темп держи 4:30 — не гони раньше времени." time="вчера 19:01"
        tool={{ title: 'План обновлён', detail: 'Четверг · 5×1 км в темпе' }} />
      <Bubble role="user" text="Окей! А что насчёт подводки к Москве?" time="12:42" />
      <Bubble role="coach" text="Через 2 недели начнём тейпер. Снизим объём, интенсивность оставим. Расскажу подробнее ближе к делу." time="14:18" />
    </>
  );
}

function QuickReplies({ items }) {
  return (
    <div style={CH3.quickReplies}>
      {items.map(q => (
        <button key={q} style={CH3.quickReplyBtn}>{q}</button>
      ))}
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────
// MOBILE — AI conversation
// ─────────────────────────────────────────────────────────────────────
function ChatMobileAI({ state = 'normal' }) {
  // state: 'normal' | 'empty' | 'tool-calling'
  const active = CHAT_LIST[0];
  return (
    <div style={CH3.mobShell}>
      <div style={CH3.statusBar}>
        <span style={{ fontFamily: '"Jost", sans-serif' }}>9:41</span>
        <span style={{ display: 'flex', gap: 5 }}>●●● ● <span style={{ marginLeft: 6 }}>5G 89%</span></span>
      </div>

      <ConversationHeader active={active} onBack={() => {}} />

      <div style={CH3.mobMessages}>
        {state === 'empty' && <AIEmptyState />}
        {state === 'normal' && (
          <>
            <div style={CH3.dateLabel}>СЕГОДНЯ</div>
            <Bubble role="ai" text="Доброе утро! Сегодня темповая 4×1 км. Как самочувствие?" time="7:42" />
            <Bubble role="user" text="Норм, можно утром?" time="7:48" />
            <Bubble role="ai" text="Конечно, перенёс на 8:00. Натощак — сбрось темп на 5-10 сек/км." time="7:48"
              tool={{ title: 'Тренировка перенесена', detail: 'на 8:00' }} />
          </>
        )}
        {state === 'tool-calling' && (
          <>
            <div style={CH3.dateLabel}>СЕГОДНЯ</div>
            <Bubble role="user" text="Перенеси завтрашнюю на пятницу" time="14:20" />
            <ToolCallingIndicator label={TOOL_LABELS.swap_training_days} />
          </>
        )}
      </div>

      {state !== 'empty' && (
        <QuickReplies items={['Что на завтра?', 'Покажи неделю']} />
      )}
      <Composer value="" mode="ai" />
    </div>
  );
}

function AIEmptyState() {
  return (
    <div style={{ padding: '24px 4px', textAlign: 'center' }}>
      <div style={{ width: 72, height: 72, margin: '0 auto', borderRadius: 22, background: 'linear-gradient(135deg, #FC4C02, #FF6B3D)', color: 'white', display: 'grid', placeItems: 'center', fontWeight: 800, fontSize: 26, boxShadow: '0 16px 36px rgba(252,76,2,0.35)' }}>AI</div>
      <h2 style={{ fontSize: 22, fontWeight: 800, color: CT3.ink, letterSpacing: '-0.02em', marginTop: 16 }}>AI-тренер на связи</h2>
      <p style={{ fontSize: 13.5, color: CT3.ink2, lineHeight: 1.5, marginTop: 8, maxWidth: 280, marginInline: 'auto' }}>
        Спрашивай про тренировки, проси перенести или пересчитать план — я отвечу мгновенно.
      </p>
      <div style={{ marginTop: 24, display: 'flex', flexDirection: 'column', gap: 8 }}>
        {SUGGESTED.map(s => (
          <button key={s.text} style={CH3.suggestedCard}>
            <span style={{ fontSize: 18 }}>{s.icon}</span>
            <span style={{ flex: 1, textAlign: 'left', fontSize: 13.5, fontWeight: 600, color: CT3.ink }}>{s.text}</span>
            <span style={{ color: CT3.ink3 }}>→</span>
          </button>
        ))}
      </div>
    </div>
  );
}

function ChatMobileTrainer() {
  const active = CHAT_LIST[1];
  return (
    <div style={CH3.mobShell}>
      <div style={CH3.statusBar}>
        <span style={{ fontFamily: '"Jost", sans-serif' }}>9:41</span>
        <span style={{ display: 'flex', gap: 5 }}>●●● ● <span style={{ marginLeft: 6 }}>5G 89%</span></span>
      </div>

      <ConversationHeader active={active} onBack={() => {}} />

      {/* Context strip: today's workout */}
      <div style={CH3.ctxStrip}>
        <span style={{ width: 4, alignSelf: 'stretch', background: CT3.warning, borderRadius: 4 }} />
        <div style={{ flex: 1 }}>
          <div style={{ fontSize: 10, color: CT3.ink3, fontWeight: 700, letterSpacing: '0.06em' }}>СЕГОДНЯ ПО ПЛАНУ</div>
          <div style={{ fontSize: 13, fontWeight: 600, color: CT3.ink }}>4×1 км в темпе · 8 км</div>
        </div>
        <button style={CH3.ctxBtn}>Открыть →</button>
      </div>

      <div style={CH3.mobMessages}>
        <div style={CH3.dateLabel}>ВЧЕРА</div>
        <Bubble role="coach" text="Темповая прошла отлично! Последний км 4:25." time="18:42" />
        <Bubble role="user" text="Спасибо! Могу больше?" time="18:50" />
        <Bubble role="coach" text="Поставлю 5×1 км на четверг. Темп держи 4:30." time="19:01"
          tool={{ title: 'План обновлён', detail: 'Чт · 5×1 км' }} />
        <div style={CH3.dateLabel}>СЕГОДНЯ</div>
        <Bubble role="user" text="А подводка к Москве?" time="12:42" />
        <Bubble role="coach" text="Через 2 недели начнём тейпер. Расскажу ближе к делу." time="14:18" />
      </div>

      <QuickReplies items={['👍 Понял', 'Когда подводка?', 'Болит колено']} />
      <Composer value="" placeholder="Сообщение Михаилу…" mode="coach" />
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────
// STYLES + keyframes
// ─────────────────────────────────────────────────────────────────────
const CH3 = {
  deskShell: { width: '100%', height: '100%', display: 'flex', flexDirection: 'column', overflow: 'hidden', fontFamily: 'Montserrat, sans-serif', color: CT3.ink, background: 'radial-gradient(60% 50% at 0% 0%, rgba(252,76,2,0.05) 0%, transparent 50%), radial-gradient(50% 60% at 100% 100%, rgba(252,76,2,0.04) 0%, transparent 55%), linear-gradient(180deg, #FAF7F3 0%, #F4F7FB 100%)' },
  deskTop: { height: 56, padding: '0 32px', display: 'flex', alignItems: 'center', gap: 12, background: 'rgba(255,255,255,0.7)', backdropFilter: 'blur(14px)', WebkitBackdropFilter: 'blur(14px)', borderBottom: `1px solid ${CT3.line}`, flexShrink: 0 },
  deskBody: { flex: 1, display: 'grid', gridTemplateColumns: '340px 1fr', overflow: 'hidden' },
  sidebar: { borderRight: `1px solid ${CT3.line}`, overflow: 'auto', background: 'rgba(255,255,255,0.4)' },
  chatListItem: { display: 'flex', alignItems: 'center', gap: 12, padding: 12, borderRadius: 14, cursor: 'pointer', fontFamily: 'inherit', width: '100%' },
  onlineDot: { position: 'absolute', bottom: -1, right: -1, width: 12, height: 12, borderRadius: '50%', background: '#22C55E', border: '2.5px solid white' },
  unreadBadge: { background: CT3.primary, color: 'white', borderRadius: 999, fontSize: 10, padding: '1px 6px', fontWeight: 700, fontFamily: '"Jost", sans-serif', flexShrink: 0 },

  conversation: { display: 'flex', flexDirection: 'column', overflow: 'hidden' },
  convHeader: { display: 'flex', alignItems: 'center', gap: 12, padding: '12px 20px', borderBottom: `1px solid ${CT3.line}`, background: 'rgba(255,255,255,0.55)', backdropFilter: 'blur(14px)', WebkitBackdropFilter: 'blur(14px)', flexShrink: 0 },
  backBtn: { width: 36, height: 36, borderRadius: 10, background: 'transparent', border: 'none', cursor: 'pointer', fontSize: 18, color: CT3.ink, fontFamily: 'inherit' },
  headerIcon: { width: 36, height: 36, borderRadius: 10, background: CT3.surf3, border: 'none', cursor: 'pointer', fontSize: 15, fontFamily: 'inherit' },

  messagesArea: { flex: 1, overflow: 'auto', padding: '16px 20px' },
  dateLabel: { textAlign: 'center', fontSize: 10, color: CT3.ink3, fontWeight: 700, letterSpacing: '0.1em', margin: '6px 0 14px' },

  capBanner: { padding: '12px 14px', marginBottom: 16, background: 'linear-gradient(135deg, rgba(252,76,2,0.08), rgba(252,76,2,0.02))', border: '1px solid rgba(252,76,2,0.18)', borderRadius: 14 },
  capChip: { padding: '4px 10px', background: 'rgba(255,255,255,0.7)', borderRadius: 7, fontSize: 11, fontWeight: 600, color: CT3.ink2, border: '1px solid rgba(252,76,2,0.15)' },

  quickReplies: { display: 'flex', gap: 8, padding: '8px 20px', overflowX: 'auto', flexShrink: 0 },
  quickReplyBtn: { padding: '8px 14px', background: 'rgba(255,255,255,0.72)', backdropFilter: 'blur(14px)', WebkitBackdropFilter: 'blur(14px)', border: '1px solid rgba(252,76,2,0.12)', borderRadius: 999, fontSize: 12.5, fontWeight: 600, color: CT3.ink, cursor: 'pointer', fontFamily: 'inherit', whiteSpace: 'nowrap', flexShrink: 0 },

  composer: { display: 'flex', alignItems: 'center', gap: 8, padding: '12px 20px 16px', borderTop: `1px solid ${CT3.line}`, flexShrink: 0, background: 'rgba(255,255,255,0.55)', backdropFilter: 'blur(14px)', WebkitBackdropFilter: 'blur(14px)' },
  composerIcon: { width: 38, height: 38, borderRadius: '50%', background: 'transparent', border: 'none', cursor: 'pointer', fontSize: 17, color: CT3.ink3, fontFamily: 'inherit', display: 'grid', placeItems: 'center', flexShrink: 0 },
  composerInput: { flex: 1, padding: '11px 16px', borderRadius: 999, border: `1px solid ${CT3.line}`, fontSize: 14, fontFamily: 'inherit', outline: 'none', background: 'rgba(255,255,255,0.7)' },
  composerSend: { width: 42, height: 42, borderRadius: '50%', background: CT3.primary, color: 'white', border: 'none', cursor: 'pointer', fontFamily: 'inherit', boxShadow: '0 6px 16px rgba(252,76,2,0.3)', display: 'grid', placeItems: 'center', flexShrink: 0 },

  // Mobile
  mobShell: { width: '100%', height: '100%', position: 'relative', display: 'flex', flexDirection: 'column', overflow: 'hidden', fontFamily: 'Montserrat, sans-serif', color: CT3.ink, background: 'radial-gradient(120% 80% at 0% 0%, rgba(252,76,2,0.07) 0%, transparent 50%), radial-gradient(100% 70% at 100% 100%, rgba(252,76,2,0.05) 0%, transparent 55%), linear-gradient(180deg, #FAF7F3 0%, #F4F7FB 100%)' },
  statusBar: { height: 36, padding: '0 24px', display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontSize: 13, fontWeight: 700, flexShrink: 0 },
  mobMessages: { flex: 1, overflow: 'auto', padding: '12px 16px', paddingBottom: 8 },

  ctxStrip: { margin: '0 16px', marginTop: 8, padding: 12, background: 'rgba(234,179,8,0.12)', border: '1px solid rgba(234,179,8,0.3)', borderRadius: 12, display: 'flex', alignItems: 'center', gap: 10, flexShrink: 0 },
  ctxBtn: { padding: '6px 10px', background: 'rgba(255,255,255,0.7)', border: 'none', borderRadius: 6, fontSize: 11, fontWeight: 700, color: CT3.ink, cursor: 'pointer', fontFamily: 'inherit' },

  suggestedCard: { display: 'flex', alignItems: 'center', gap: 12, padding: '13px 14px', background: 'rgba(255,255,255,0.72)', backdropFilter: 'blur(18px) saturate(1.16)', WebkitBackdropFilter: 'blur(18px) saturate(1.16)', border: '1px solid rgba(252,76,2,0.1)', borderRadius: 14, cursor: 'pointer', fontFamily: 'inherit', boxShadow: 'inset 0 1px 0 rgba(255,255,255,0.7), 0 8px 20px rgba(15,23,42,0.05)' },

  toolSpinner: { width: 14, height: 14, borderRadius: '50%', border: `2px solid ${CT3.primary}40`, borderTopColor: CT3.primary, display: 'inline-block', animation: 'prspin 0.7s linear infinite' },
  typingDot: { width: 7, height: 7, borderRadius: '50%', background: CT3.ink3, display: 'inline-block', animation: 'prbounce 1.2s ease-in-out infinite' },
};

// Inject keyframes once
if (typeof document !== 'undefined' && !document.getElementById('pr-chat-keyframes')) {
  const s = document.createElement('style');
  s.id = 'pr-chat-keyframes';
  s.textContent = `
    @keyframes prspin { to { transform: rotate(360deg); } }
    @keyframes prbounce { 0%, 60%, 100% { transform: translateY(0); opacity: 0.4; } 30% { transform: translateY(-5px); opacity: 1; } }
  `;
  document.head.appendChild(s);
}

window.ChatDesktop = ChatDesktop;
window.ChatMobileAI = ChatMobileAI;
window.ChatMobileTrainer = ChatMobileTrainer;
