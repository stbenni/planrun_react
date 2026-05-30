/* v3: чаты (AI и тренер) + настройки режима + переключение режима */

const { useState: useStateV3b } = React;
const T3b = V2.T;

// ──────────────────────────────────────────────────────────────────────
// 3. AI CHAT (для режима AI)
// ──────────────────────────────────────────────────────────────────────
function AIChat() {
  const [input, setInput] = useStateV3b('');
  const messages = [
    { role: 'ai', time: '7:42', text: 'Доброе утро, Алексей! Сегодня темповая — 4×1 км. Как самочувствие?' },
    { role: 'user', time: '7:48', text: 'Норм, поспал 7 часов. Можно ли пробежать утром, не вечером?' },
    { role: 'ai', time: '7:48', text: 'Конечно. Если бежишь натощак — снизь темп на 5-10 сек/км. Тренировку перенёс на 8:00.', tool: { name: 'update_training_day', desc: 'Перенёс на утро' } },
    { role: 'user', time: '7:49', text: 'Спасибо! А что если будет тяжело?' },
    { role: 'ai', time: '7:49', text: 'Если на 2-м повторе не выходит держать 4:30 — снижай до 4:40. Главное закончить серию. Если совсем плохо — сделай только 3×1 км и заминку.' },
  ];

  const suggested = [
    'Замени темповую на лёгкий',
    'Что делать если болит колено?',
    'Покажи прогноз на полумарафон',
    'Как готовиться к гонке',
  ];

  return (
    <div style={CH.shell}>
      <div style={CH.statusBar}>
        <span style={{ fontFamily: '"Jost", sans-serif' }}>9:41</span>
        <span style={{ display: 'flex', gap: 5 }}>●●● ● <span style={{ marginLeft: 6 }}>5G 89%</span></span>
      </div>

      {/* Chat switcher header */}
      <div style={CH.chatHead}>
        <div style={CH.headLeft}>
          <div style={{ position: 'relative' }}>
            <div style={CH.aiAvatar}>AI</div>
            <div style={{ ...CH.statusDot, background: T3b.success }} />
          </div>
          <div>
            <div style={{ fontSize: 15, fontWeight: 700, color: T3b.ink }}>AI-тренер</div>
            <div style={{ fontSize: 11, color: T3b.success, fontWeight: 600 }}>● Всегда онлайн</div>
          </div>
        </div>
        <button style={CH.headBtn}>⋯</button>
      </div>

      {/* AI capabilities banner */}
      <div style={CH.aiBanner}>
        <div style={{ fontSize: 11, color: T3b.primary, fontWeight: 700, letterSpacing: '0.06em' }}>★ ВСЁ ЭТО Я УМЕЮ</div>
        <div style={{ display: 'flex', gap: 6, marginTop: 6, flexWrap: 'wrap' }}>
          {['✎ править план', '↔ переносить дни', '✓ отметить вып.', '📈 объяснять метрики', '🎯 строить прогнозы'].map(c => (
            <span key={c} style={CH.capChip}>{c}</span>
          ))}
        </div>
      </div>

      {/* Messages */}
      <div style={CH.messages}>
        <div style={CH.dateLabel}>СЕГОДНЯ</div>
        {messages.map((m, i) => (
          <Bubble key={i} m={m} />
        ))}
      </div>

      {/* Suggested replies */}
      <div style={CH.suggested}>
        {suggested.map(s => (
          <button key={s} style={CH.suggBtn}>{s}</button>
        ))}
      </div>

      {/* Input */}
      <div style={CH.inputRow}>
        <button style={CH.inpAttach}>+</button>
        <input value={input} onChange={(e) => setInput(e.target.value)} placeholder="Спроси что угодно про тренировки…" style={CH.input} />
        <button style={CH.sendBtn}>{input ? '→' : '🎤'}</button>
      </div>
    </div>
  );
}

function Bubble({ m }) {
  const isAi = m.role === 'ai';
  return (
    <div style={{ display: 'flex', justifyContent: isAi ? 'flex-start' : 'flex-end', marginBottom: 12 }}>
      <div style={{ maxWidth: '80%' }}>
        <div style={{
          padding: '12px 14px', borderRadius: isAi ? '16px 16px 16px 4px' : '16px 16px 4px 16px',
          background: isAi ? T3b.surf3 : T3b.primary, color: isAi ? T3b.ink : 'white',
          fontSize: 14, lineHeight: 1.45,
        }}>{m.text}</div>
        {m.tool && (
          <div style={{ marginTop: 6, padding: '6px 10px', background: T3b.successWash, color: '#166534', borderRadius: 8, fontSize: 11, fontWeight: 600 }}>
            ✓ {m.tool.desc}
          </div>
        )}
        <div style={{ fontSize: 10, color: T3b.ink3, marginTop: 4, textAlign: isAi ? 'left' : 'right', fontFamily: '"Jost", sans-serif' }}>{m.time}</div>
      </div>
    </div>
  );
}

// ──────────────────────────────────────────────────────────────────────
// 4. TRAINER CHAT (для режима с тренером)
// ──────────────────────────────────────────────────────────────────────
function TrainerChat() {
  const messages = [
    { role: 'coach', time: 'вчера 18:42', text: 'Алексей, темповая прошла отлично! Видел трек.' },
    { role: 'user', time: 'вчера 18:50', text: 'Спасибо! Последний км получился 4:25 — могу больше?' },
    { role: 'coach', time: 'вчера 19:01', text: 'Молодец! Да, поставлю 5×1 км на четверг. Темп держи тот же — 4:30. Не нужно гнать раньше времени.', tool: { name: 'update_training_day', desc: 'Поменял план: 5×1 км в четверг' } },
    { role: 'user', time: '12:42', text: 'Окей! А что насчёт подводки к Москве?' },
    { role: 'coach', time: '14:18', text: 'Через 2 недели начнём таперинг. Снизим объём, но интенсивность оставим. Я тебе всё расскажу.' },
  ];

  return (
    <div style={CH.shell}>
      <div style={CH.statusBar}>
        <span style={{ fontFamily: '"Jost", sans-serif' }}>9:41</span>
        <span style={{ display: 'flex', gap: 5 }}>●●● ● <span style={{ marginLeft: 6 }}>5G 89%</span></span>
      </div>

      <div style={CH.chatHead}>
        <button style={CH.headBtnL}>←</button>
        <div style={CH.headCenter}>
          <div style={{ position: 'relative' }}>
            <V2.Avatar a={{ initials: 'МК', tone: '#FFD9C9' }} size={36} />
            <div style={{ ...CH.statusDot, background: T3b.success }} />
          </div>
          <div>
            <div style={{ fontSize: 15, fontWeight: 700, color: T3b.ink }}>Михаил Краснов</div>
            <div style={{ fontSize: 11, color: T3b.success, fontWeight: 600 }}>● онлайн · отвечает ~14 мин</div>
          </div>
        </div>
        <button style={CH.headBtn}>📞</button>
      </div>

      {/* Context strip — сегодняшняя тренировка */}
      <div style={CH.ctxStrip}>
        <span style={{ width: 4, alignSelf: 'stretch', background: T3b.warning, borderRadius: 4 }} />
        <div style={{ flex: 1 }}>
          <div style={{ fontSize: 10, color: T3b.ink3, fontWeight: 700, letterSpacing: '0.06em' }}>СЕГОДНЯ ПО ПЛАНУ</div>
          <div style={{ fontSize: 13, fontWeight: 600, color: T3b.ink }}>4×1 км в темпе · 8 км</div>
        </div>
        <button style={CH.ctxBtn}>Открыть план →</button>
      </div>

      <div style={CH.messages}>
        <div style={CH.dateLabel}>ВЧЕРА</div>
        {messages.slice(0, 3).map((m, i) => <Bubble key={i} m={{ ...m, role: m.role === 'coach' ? 'ai' : m.role }} />)}
        <div style={CH.dateLabel}>СЕГОДНЯ</div>
        {messages.slice(3).map((m, i) => <Bubble key={i} m={{ ...m, role: m.role === 'coach' ? 'ai' : m.role }} />)}
      </div>

      {/* Quick replies */}
      <div style={CH.suggested}>
        <button style={CH.suggBtn}>👍 Понял</button>
        <button style={CH.suggBtn}>Когда подводка?</button>
        <button style={CH.suggBtn}>Болит правое колено</button>
      </div>

      <div style={CH.inputRow}>
        <button style={CH.inpAttach}>+</button>
        <input placeholder="Сообщение Михаилу…" style={CH.input} />
        <button style={CH.sendBtn}>🎤</button>
      </div>
    </div>
  );
}

// ──────────────────────────────────────────────────────────────────────
// 5. MODE SETTINGS — текущий режим + переключение
// ──────────────────────────────────────────────────────────────────────
function ModeSettings({ mode = 'ai' }) {
  return (
    <div style={MS.shell}>
      <div style={CH.statusBar}>
        <span style={{ fontFamily: '"Jost", sans-serif' }}>9:41</span>
        <span style={{ display: 'flex', gap: 5 }}>●●● ● <span style={{ marginLeft: 6 }}>5G 89%</span></span>
      </div>

      <div style={MS.header}>
        <button style={CH.headBtnL}>←</button>
        <div style={{ flex: 1, textAlign: 'center', fontWeight: 700, fontSize: 16 }}>Режим тренировок</div>
        <div style={{ width: 36 }} />
      </div>

      <div style={MS.body}>
        {/* Current mode banner */}
        <div style={MS.currentCard}>
          <div style={{ fontSize: 11, color: T3b.ink3, fontWeight: 700, letterSpacing: '0.08em' }}>СЕЙЧАС АКТИВЕН</div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 14, marginTop: 12 }}>
            {mode === 'ai' ? (
              <div style={{ width: 56, height: 56, borderRadius: 16, background: 'linear-gradient(135deg, #FC4C02, #FF6B3D)', color: 'white', display: 'grid', placeItems: 'center', fontWeight: 800, fontSize: 22, boxShadow: '0 12px 28px rgba(252,76,2,0.3)' }}>AI</div>
            ) : (
              <V2.Avatar a={{ initials: 'МК', tone: '#FFD9C9' }} size={56} />
            )}
            <div style={{ flex: 1, minWidth: 0 }}>
              <div style={{ fontSize: 20, fontWeight: 800, color: T3b.ink, letterSpacing: '-0.02em' }}>
                {mode === 'ai' ? 'AI-тренер' : 'Михаил Краснов'}
              </div>
              <div style={{ fontSize: 12, color: T3b.ink3, marginTop: 2 }}>
                {mode === 'ai' ? 'с момента регистрации' : 'тренирует тебя 4 месяца'}
              </div>
            </div>
            <span style={{ background: T3b.successWash, color: '#166534', fontSize: 10, fontWeight: 800, padding: '4px 8px', borderRadius: 6, letterSpacing: '0.04em' }}>АКТИВЕН</span>
          </div>

          <div style={MS.stats}>
            <div style={MS.stat}>
              <div style={MS.statVal}>{mode === 'ai' ? '124' : '67'}</div>
              <div style={MS.statLbl}>сообщений</div>
            </div>
            <div style={MS.stat}>
              <div style={MS.statVal}>{mode === 'ai' ? '16' : '4'}</div>
              <div style={MS.statLbl}>{mode === 'ai' ? 'недель' : 'месяцев'}</div>
            </div>
            <div style={MS.stat}>
              <div style={MS.statVal}>{mode === 'ai' ? '94%' : '4.9★'}</div>
              <div style={MS.statLbl}>{mode === 'ai' ? 'compliance' : 'рейтинг'}</div>
            </div>
          </div>
        </div>

        {/* Switch banner */}
        <div style={MS.eyebrow}>ПЕРЕКЛЮЧИТЬСЯ НА</div>

        {mode === 'ai' ? (
          /* Switch to trainer */
          <button style={MS.switchCard}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
              <div style={{ width: 48, height: 48, borderRadius: 14, background: T3b.ink, color: 'white', display: 'grid', placeItems: 'center', fontWeight: 800, fontSize: 22 }}>👤</div>
              <div style={{ flex: 1, textAlign: 'left' }}>
                <div style={{ fontSize: 17, fontWeight: 800, color: T3b.ink, letterSpacing: '-0.01em' }}>Живой тренер</div>
                <div style={{ fontSize: 12, color: T3b.ink3, marginTop: 2 }}>Персональный план · человеческий подход</div>
              </div>
              <span style={{ color: T3b.ink3, fontSize: 18 }}>→</span>
            </div>
            <ul style={MS.featList}>
              <li>★ Подбираешь сам, можно сменить</li>
              <li>📝 Тренер пишет твой план вручную</li>
              <li>💬 Голосовые, видео, обратная связь</li>
              <li>💰 от 3 500 ₽/мес</li>
            </ul>
          </button>
        ) : (
          /* Switch to AI */
          <button style={MS.switchCard}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
              <div style={{ width: 48, height: 48, borderRadius: 14, background: 'linear-gradient(135deg, #FC4C02, #FF6B3D)', color: 'white', display: 'grid', placeItems: 'center', fontWeight: 800, fontSize: 22 }}>AI</div>
              <div style={{ flex: 1, textAlign: 'left' }}>
                <div style={{ fontSize: 17, fontWeight: 800, color: T3b.ink, letterSpacing: '-0.01em' }}>AI-тренер</div>
                <div style={{ fontSize: 12, color: T3b.ink3, marginTop: 2 }}>Бесплатно · отвечает мгновенно · 24/7</div>
              </div>
              <span style={{ color: T3b.ink3, fontSize: 18 }}>→</span>
            </div>
          </button>
        )}

        {/* Warning */}
        <div style={MS.warn}>
          <div style={{ fontSize: 16 }}>⚠</div>
          <div style={{ flex: 1, fontSize: 12, color: T3b.ink2, lineHeight: 1.5 }}>
            <b>Режим эксклюзивный.</b> При смене {mode === 'ai' ? 'на тренера AI-чат отключится' : 'на AI чат с Михаилом архивируется'}, но история сохранится.
            История твоих тренировок и прогресс — остаются.
          </div>
        </div>

        {/* Other actions */}
        <div style={{ marginTop: 24, display: 'flex', flexDirection: 'column', gap: 1, background: 'white', borderRadius: 14, overflow: 'hidden', border: `1px solid ${T3b.line}` }}>
          {[
            ['📋', 'Шаблоны тренировок', 'твоих сохранённых: 4'],
            ['🎯', 'Целевая гонка', 'Москва · 28 сен'],
            ['🔗', 'Интеграции',  'Strava, Polar'],
            ['🛎', 'Уведомления',  'включены'],
          ].map(([ic, l, d]) => (
            <button key={l} style={MS.settingRow}>
              <span style={{ fontSize: 18 }}>{ic}</span>
              <div style={{ flex: 1, textAlign: 'left' }}>
                <div style={{ fontSize: 14, fontWeight: 600, color: T3b.ink }}>{l}</div>
                <div style={{ fontSize: 11, color: T3b.ink3 }}>{d}</div>
              </div>
              <span style={{ color: T3b.ink3 }}>→</span>
            </button>
          ))}
        </div>
      </div>
    </div>
  );
}

const CH = {
  shell: { width: '100%', height: '100%', background: 'radial-gradient(120% 80% at 0% 0%, rgba(252,76,2,0.07) 0%, transparent 50%), radial-gradient(100% 70% at 100% 100%, rgba(252,76,2,0.05) 0%, transparent 55%), linear-gradient(180deg, #FAF7F3 0%, #F4F7FB 100%)', fontFamily: 'Montserrat, sans-serif', color: T3b.ink, display: 'flex', flexDirection: 'column', overflow: 'hidden', position: 'relative' },
  statusBar: { height: 36, padding: '0 24px', display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontSize: 13, fontWeight: 700, flexShrink: 0 },
  chatHead: { padding: '8px 16px 12px', display: 'flex', alignItems: 'center', gap: 10, borderBottom: `1px solid ${T3b.line}`, flexShrink: 0 },
  headLeft: { display: 'flex', alignItems: 'center', gap: 10, flex: 1 },
  headCenter: { display: 'flex', alignItems: 'center', gap: 10, flex: 1 },
  headBtn: { width: 36, height: 36, borderRadius: 10, background: 'transparent', border: 'none', cursor: 'pointer', fontSize: 16, fontFamily: 'inherit', color: T3b.ink2 },
  headBtnL: { width: 36, height: 36, borderRadius: 10, background: 'transparent', border: 'none', cursor: 'pointer', fontSize: 18, fontFamily: 'inherit', color: T3b.ink },
  aiAvatar: { width: 36, height: 36, borderRadius: '50%', background: 'linear-gradient(135deg, #FC4C02 0%, #FF6B3D 100%)', color: 'white', display: 'grid', placeItems: 'center', fontWeight: 800, fontSize: 13 },
  statusDot: { position: 'absolute', bottom: -1, right: -1, width: 11, height: 11, borderRadius: '50%', border: '2px solid white' },

  aiBanner: { margin: '12px 16px 0', padding: '10px 14px', background: 'linear-gradient(135deg, rgba(252,76,2,0.06), rgba(252,76,2,0.02))', border: `1px solid ${T3b.primary}20`, borderRadius: 12 },
  capChip: { padding: '4px 9px', background: 'white', borderRadius: 6, fontSize: 11, fontWeight: 600, color: T3b.ink2, border: `1px solid ${T3b.primary}25` },

  ctxStrip: { margin: '12px 16px 0', padding: 12, background: T3b.warningWash, border: `1px solid ${T3b.warning}30`, borderRadius: 12, display: 'flex', alignItems: 'center', gap: 10 },
  ctxBtn: { padding: '6px 10px', background: 'rgba(255,255,255,0.7)', border: 'none', borderRadius: 6, fontSize: 11, fontWeight: 700, color: T3b.ink, cursor: 'pointer', fontFamily: 'inherit' },

  messages: { flex: 1, overflow: 'auto', padding: '14px 16px' },
  dateLabel: { textAlign: 'center', fontSize: 10, color: T3b.ink3, fontWeight: 700, letterSpacing: '0.1em', margin: '10px 0 14px' },

  suggested: { display: 'flex', gap: 6, padding: '8px 16px', overflowX: 'auto', flexShrink: 0 },
  suggBtn: { padding: '8px 12px', background: 'white', border: `1px solid ${T3b.line}`, borderRadius: 999, fontSize: 12, fontWeight: 600, color: T3b.ink2, cursor: 'pointer', fontFamily: 'inherit', whiteSpace: 'nowrap' },

  inputRow: { padding: '10px 16px 16px', display: 'flex', gap: 8, alignItems: 'center', flexShrink: 0, borderTop: `1px solid ${T3b.line}` },
  inpAttach: { width: 36, height: 36, borderRadius: '50%', background: T3b.surf3, border: 'none', cursor: 'pointer', fontSize: 18, fontWeight: 300, color: T3b.ink, fontFamily: 'inherit' },
  input: { flex: 1, padding: '10px 16px', borderRadius: 999, border: `1px solid ${T3b.line}`, fontSize: 14, fontFamily: 'inherit', outline: 'none', background: T3b.surf2 },
  sendBtn: { width: 40, height: 40, borderRadius: '50%', background: T3b.primary, color: 'white', border: 'none', cursor: 'pointer', fontSize: 16, fontFamily: 'inherit', boxShadow: '0 6px 16px rgba(252,76,2,0.3)' },
};

const MS = {
  shell: { width: '100%', height: '100%', background: 'radial-gradient(120% 80% at 0% 0%, rgba(252,76,2,0.07) 0%, transparent 50%), radial-gradient(100% 70% at 100% 100%, rgba(252,76,2,0.05) 0%, transparent 55%), linear-gradient(180deg, #FAF7F3 0%, #F4F7FB 100%)', fontFamily: 'Montserrat, sans-serif', color: T3b.ink, display: 'flex', flexDirection: 'column', overflow: 'hidden' },
  header: { padding: '8px 16px 14px', display: 'flex', alignItems: 'center' },
  body: { flex: 1, overflow: 'auto', padding: '0 16px 24px' },
  currentCard: { padding: 18, background: 'rgba(255,255,255,0.78)', backdropFilter: 'blur(24px) saturate(1.2)', WebkitBackdropFilter: 'blur(24px) saturate(1.2)', border: `1.5px solid ${T3b.success}40`, borderRadius: 16, boxShadow: 'inset 0 1px 0 rgba(255,255,255,0.85), 0 20px 40px rgba(15,23,42,0.08), 0 8px 20px rgba(34,197,94,0.06)' },
  stats: { display: 'flex', gap: 12, marginTop: 16, paddingTop: 16, borderTop: `1px solid ${T3b.line}` },
  stat: { flex: 1, textAlign: 'center' },
  statVal: { fontFamily: '"Jost", sans-serif', fontSize: 22, fontWeight: 700, color: T3b.ink, letterSpacing: '-0.02em', lineHeight: 1 },
  statLbl: { fontSize: 10, color: T3b.ink3, fontWeight: 600, marginTop: 4, letterSpacing: '0.04em' },
  eyebrow: { fontSize: 11, color: T3b.ink3, fontWeight: 700, letterSpacing: '0.12em', marginTop: 24, marginBottom: 10 },
  switchCard: { width: '100%', padding: 18, background: 'rgba(255,255,255,0.72)', backdropFilter: 'blur(20px) saturate(1.16)', WebkitBackdropFilter: 'blur(20px) saturate(1.16)', border: '1px solid rgba(252,76,2,0.08)', borderRadius: 16, cursor: 'pointer', fontFamily: 'inherit', boxShadow: 'inset 0 1px 0 rgba(255,255,255,0.7), 0 12px 28px rgba(15,23,42,0.06), 0 4px 12px rgba(252,76,2,0.04)' },
  featList: { listStyle: 'none', padding: 0, margin: '12px 0 0', display: 'flex', flexDirection: 'column', gap: 4, textAlign: 'left', fontSize: 12, color: T3b.ink2 },
  warn: { marginTop: 16, padding: 14, background: T3b.warningWash, border: `1px solid ${T3b.warning}30`, borderRadius: 12, display: 'flex', gap: 10, alignItems: 'flex-start' },
  settingRow: { display: 'flex', alignItems: 'center', gap: 12, padding: '14px 16px', background: 'rgba(255,255,255,0.62)', backdropFilter: 'blur(16px) saturate(1.14)', WebkitBackdropFilter: 'blur(16px) saturate(1.14)', border: 'none', borderBottom: `1px solid ${T3b.line}`, cursor: 'pointer', fontFamily: 'inherit' },
};

window.AIChat = AIChat;
window.TrainerChat = TrainerChat;
window.ModeSettings = ModeSettings;
