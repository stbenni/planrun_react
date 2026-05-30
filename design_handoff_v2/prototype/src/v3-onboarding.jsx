/* v3: Дополнения для бегуна (athlete-side) к v2.
   Покрывает 6 экранов:
   1. Онбординг бегуна (3 шага)
   2. Экран поиска тренера
   3. Профиль тренера + заявка
   4. AI-чат (для режима «без тренера»)
   5. Чат с тренером (online/offline)
   6. Настройки режима + переключение */

const { useState: useStateV3 } = React;
const T3 = V2.T;

// ── Trainers data ────────────────────────────────────────────────────
V2.TRAINERS = [
  { id: 1, name: 'Михаил Краснов', initials: 'МК', tone: '#FFD9C9',
    title: 'Тренер по бегу · сертификат IAAF',
    bio: '12 лет тренирую от 10К до ультра. Подвёл 40+ человек на марафон под 3:00.',
    online: true, lastSeen: 'сейчас', avgResponse: '14 мин',
    rating: 4.9, reviews: 87, athletes: 12, freeSlots: 2,
    specs: ['Марафон', '10 км', 'Полумарафон'],
    pr: 'Марафон 2:41', price: '6 000 ₽/мес', verified: true,
  },
  { id: 2, name: 'Анна Морозова', initials: 'АМ', tone: '#FFE0F0',
    title: 'Тренер по бегу · МСМК',
    bio: 'Специализируюсь на женских планах и подготовке к гонкам с нуля. Тёплый подход.',
    online: true, lastSeen: '4 мин назад', avgResponse: '22 мин',
    rating: 4.8, reviews: 64, athletes: 8, freeSlots: 4,
    specs: ['Новичкам', 'Полумарафон', 'Здоровье'],
    pr: 'Марафон 2:58', price: '4 500 ₽/мес', verified: true,
  },
  { id: 3, name: 'Дмитрий Соколов', initials: 'ДС', tone: '#D9E8FF',
    title: 'Кандидат в мастера спорта',
    bio: 'Жёсткие интервальные методики. Подходит тем, кто готов терпеть.',
    online: false, lastSeen: 'час назад', avgResponse: '1 ч',
    rating: 4.7, reviews: 41, athletes: 15, freeSlots: 0,
    specs: ['Трейл', '10 км', 'Скорость'],
    pr: '10К 31:42', price: '5 000 ₽/мес', verified: true,
  },
  { id: 4, name: 'Екатерина Лебедева', initials: 'ЕЛ', tone: '#E8D9FF',
    title: 'Тренер · физиолог · нутрициолог',
    bio: 'Холистический подход: бег + восстановление + питание. Работаю с метриками.',
    online: false, lastSeen: '2 ч назад', avgResponse: '45 мин',
    rating: 4.9, reviews: 53, athletes: 9, freeSlots: 1,
    specs: ['Восстановление', 'Полумарафон', 'Триатлон'],
    pr: 'Марафон 3:14', price: '8 000 ₽/мес', verified: true,
  },
  { id: 5, name: 'Сергей Орлов', initials: 'СО', tone: '#FFE9B3',
    title: 'Тренер по бегу',
    bio: 'Простой и понятный план. Никакой воды, только то что работает.',
    online: true, lastSeen: 'сейчас', avgResponse: '8 мин',
    rating: 4.6, reviews: 29, athletes: 18, freeSlots: 6,
    specs: ['10 км', 'Полумарафон', 'Марафон'],
    pr: 'Полумарафон 1:18', price: '3 500 ₽/мес', verified: false,
  },
  { id: 6, name: 'Ольга Никитина', initials: 'ОН', tone: '#FFE3D9',
    title: 'Тренер · 6 лет в коучинге',
    bio: 'Работаю с теми, кто бегает 1-3 года и хочет первый марафон.',
    online: true, lastSeen: '12 мин', avgResponse: '32 мин',
    rating: 4.8, reviews: 38, athletes: 11, freeSlots: 3,
    specs: ['Марафон', 'Новичкам'],
    pr: 'Марафон 3:08', price: '5 500 ₽/мес', verified: true,
  },
];

// ──────────────────────────────────────────────────────────────────────
// 1. ONBOARDING — 3 шага: welcome / goal / mode
// ──────────────────────────────────────────────────────────────────────
function OnboardingFlow({ initialStep = 1 }) {
  const [step, setStep] = useStateV3(initialStep);
  const [goal, setGoal] = useStateV3('marathon');
  const [level, setLevel] = useStateV3('intermediate');
  const [mode, setMode] = useStateV3(null);

  return (
    <div style={OB.shell}>
      <div style={OB.statusBar}>
        <span style={{ fontFamily: '"Jost", sans-serif' }}>9:41</span>
        <span style={{ display: 'flex', gap: 5 }}><span>●●●</span><span style={{ opacity: 0.5 }}>●</span><span style={{ marginLeft: 6 }}>5G</span><span style={{ marginLeft: 6 }}>89%</span></span>
      </div>

      {/* Progress bar */}
      <div style={OB.progress}>
        {[1, 2, 3].map(n => (
          <div key={n} style={{
            flex: 1, height: 3, borderRadius: 2,
            background: n <= step ? T3.primary : T3.line,
          }} />
        ))}
      </div>

      {/* Back button */}
      {step > 1 && (
        <button onClick={() => setStep(step - 1)} style={OB.backBtn}>← Назад</button>
      )}

      <div style={OB.body}>
        {step === 1 && <WelcomeStep onNext={() => setStep(2)} goal={goal} setGoal={setGoal} level={level} setLevel={setLevel} />}
        {step === 2 && <ModeStep onNext={(m) => { setMode(m); setStep(3); }} />}
        {step === 3 && <FinalStep mode={mode} goal={goal} />}
      </div>
    </div>
  );
}

function WelcomeStep({ onNext, goal, setGoal, level, setLevel }) {
  const goals = [
    { id: 'health', label: 'Здоровье', emoji: '💚', desc: 'Бегать в удовольствие, 3 раза в неделю' },
    { id: '10k',    label: '10 км',    emoji: '⚡', desc: 'Подготовиться к первой десятке' },
    { id: 'half',   label: 'Полумарафон', emoji: '🎯', desc: '21.1 км — первый или быстрее' },
    { id: 'marathon', label: 'Марафон', emoji: '🏆', desc: '42.2 км — большая мечта' },
  ];
  const levels = [
    { id: 'beginner',     label: 'Новичок',  desc: 'Бегаю меньше года' },
    { id: 'intermediate', label: 'Средний',  desc: '1-3 года в беге' },
    { id: 'advanced',     label: 'Опытный',  desc: '3+ года, есть гонки' },
  ];

  return (
    <div style={OB.stepInner}>
      <div style={OB.eyebrow}>ШАГ 1 ИЗ 3</div>
      <h1 style={OB.h1}>Какая у тебя<br/>цель?</h1>
      <p style={OB.sub}>Это поможет нам собрать правильный план</p>

      <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginTop: 20 }}>
        {goals.map(g => (
          <button key={g.id} onClick={() => setGoal(g.id)}
            style={{ ...OB.choice, ...(goal === g.id ? OB.choiceActive : {}) }}>
            <span style={{ fontSize: 28 }}>{g.emoji}</span>
            <div style={{ flex: 1, textAlign: 'left' }}>
              <div style={{ fontWeight: 700, fontSize: 16 }}>{g.label}</div>
              <div style={{ fontSize: 12, color: T3.ink3, marginTop: 2 }}>{g.desc}</div>
            </div>
            <div style={{
              width: 22, height: 22, borderRadius: '50%',
              border: `2px solid ${goal === g.id ? T3.primary : T3.line2}`,
              background: goal === g.id ? T3.primary : 'transparent',
              display: 'grid', placeItems: 'center', color: 'white', fontSize: 12, fontWeight: 700,
            }}>{goal === g.id ? '✓' : ''}</div>
          </button>
        ))}
      </div>

      <div style={{ marginTop: 28 }}>
        <div style={{ fontSize: 11, color: T3.ink3, fontWeight: 700, letterSpacing: '0.08em', marginBottom: 8 }}>УРОВЕНЬ</div>
        <div style={{ display: 'flex', gap: 6 }}>
          {levels.map(l => (
            <button key={l.id} onClick={() => setLevel(l.id)}
              style={{ ...OB.levelChip, ...(level === l.id ? OB.levelChipActive : {}) }}>
              <div style={{ fontWeight: 700, fontSize: 13 }}>{l.label}</div>
              <div style={{ fontSize: 10, marginTop: 2, opacity: level === l.id ? 0.85 : 0.6 }}>{l.desc}</div>
            </button>
          ))}
        </div>
      </div>

      <button onClick={onNext} style={OB.cta}>Дальше →</button>
    </div>
  );
}

function ModeStep({ onNext }) {
  return (
    <div style={OB.stepInner}>
      <div style={OB.eyebrow}>ШАГ 2 ИЗ 3</div>
      <h1 style={OB.h1}>Как хочешь<br/>тренироваться?</h1>
      <p style={OB.sub}>Можно поменять в любой момент</p>

      <div style={{ display: 'flex', flexDirection: 'column', gap: 14, marginTop: 24 }}>
        {/* AI mode */}
        <button onClick={() => onNext('ai')} style={OB.modeCard}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 14, marginBottom: 14 }}>
            <div style={{ width: 48, height: 48, borderRadius: 14, background: 'linear-gradient(135deg, #FC4C02 0%, #FF6B3D 100%)', display: 'grid', placeItems: 'center', color: 'white', fontWeight: 800, fontSize: 22, boxShadow: '0 8px 20px rgba(252,76,2,0.3)' }}>AI</div>
            <div style={{ flex: 1, textAlign: 'left' }}>
              <div style={{ fontSize: 18, fontWeight: 800, color: T3.ink, letterSpacing: '-0.01em' }}>AI-тренер</div>
              <div style={{ fontSize: 12, color: T3.ink3, marginTop: 2 }}>Бесплатно · работает 24/7</div>
            </div>
            <span style={{ background: T3.successWash, color: '#166534', fontSize: 10, fontWeight: 800, padding: '4px 8px', borderRadius: 6, letterSpacing: '0.04em' }}>РЕКОМЕНДУЕМ</span>
          </div>
          <ul style={OB.featList}>
            <li>✓ Соберёт план под твою цель</li>
            <li>✓ Отвечает мгновенно — спрашивай что угодно</li>
            <li>✓ Подстроится под пропуски и плохие дни</li>
            <li>✓ Анализирует тренировки из Strava/Polar</li>
          </ul>
        </button>

        {/* Live trainer mode */}
        <button onClick={() => onNext('trainer')} style={{ ...OB.modeCard, borderColor: T3.line }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 14, marginBottom: 14 }}>
            <div style={{ width: 48, height: 48, borderRadius: 14, background: T3.ink, color: 'white', display: 'grid', placeItems: 'center', fontWeight: 800, fontSize: 22 }}>👤</div>
            <div style={{ flex: 1, textAlign: 'left' }}>
              <div style={{ fontSize: 18, fontWeight: 800, color: T3.ink, letterSpacing: '-0.01em' }}>Живой тренер</div>
              <div style={{ fontSize: 12, color: T3.ink3, marginTop: 2 }}>от 3 500 ₽/мес · ответ в течение часа</div>
            </div>
          </div>
          <ul style={OB.featList}>
            <li>✓ Персональный план под тебя</li>
            <li>✓ Разбор каждой тренировки</li>
            <li>✓ Подсказки и мотивация от человека</li>
            <li>✓ Можно созвон/видео</li>
          </ul>
        </button>
      </div>

      <div style={{ marginTop: 20, padding: 14, background: T3.surf3, borderRadius: 12 }}>
        <div style={{ fontSize: 12, color: T3.ink2, lineHeight: 1.5 }}>
          <b>💡 Совет:</b> начни с AI — это бесплатно. Тренера можно подобрать позже, когда поймёшь, нужен ли он.
        </div>
      </div>
    </div>
  );
}

function FinalStep({ mode, goal }) {
  return (
    <div style={OB.stepInner}>
      <div style={OB.eyebrow}>ШАГ 3 ИЗ 3</div>
      <div style={{
        margin: '24px auto', width: 96, height: 96, borderRadius: 32,
        background: mode === 'ai' ? 'linear-gradient(135deg, #FC4C02, #FF6B3D)' : T3.ink,
        color: 'white', display: 'grid', placeItems: 'center',
        fontWeight: 800, fontSize: 32,
        boxShadow: mode === 'ai' ? '0 16px 40px rgba(252,76,2,0.35)' : '0 16px 40px rgba(15,23,42,0.2)',
      }}>{mode === 'ai' ? 'AI' : '✓'}</div>

      <h1 style={{ ...OB.h1, textAlign: 'center', fontSize: 28 }}>
        {mode === 'ai' ? 'AI-тренер готов!' : 'Выберем тренера'}
      </h1>
      <p style={{ ...OB.sub, textAlign: 'center' }}>
        {mode === 'ai'
          ? 'Соберу план на 16 недель за пару минут. Можно сразу спрашивать в чате.'
          : 'Покажем тренеров под твою цель. Выберешь любого, отправишь заявку, начнём тренироваться.'}
      </p>

      <button style={{ ...OB.cta, marginTop: 28 }}>
        {mode === 'ai' ? 'Создать план →' : 'Выбрать тренера →'}
      </button>
      <button style={OB.ctaGhost}>
        {mode === 'ai' ? 'Найти тренера всё-таки' : 'Начать с AI, а потом'}
      </button>
    </div>
  );
}

const OB = {
  shell: { width: '100%', height: '100%', background: 'radial-gradient(120% 80% at 0% 0%, rgba(252,76,2,0.07) 0%, transparent 50%), radial-gradient(100% 70% at 100% 100%, rgba(252,76,2,0.05) 0%, transparent 55%), linear-gradient(180deg, #FAF7F3 0%, #F4F7FB 100%)', fontFamily: 'Montserrat, sans-serif', color: T3.ink, display: 'flex', flexDirection: 'column', overflow: 'hidden', position: 'relative' },
  statusBar: { height: 36, padding: '0 24px', display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontSize: 13, fontWeight: 700 },
  progress: { display: 'flex', gap: 4, padding: '4px 20px' },
  backBtn: { background: 'transparent', border: 'none', padding: '12px 20px', textAlign: 'left', fontSize: 13, color: T3.ink2, fontWeight: 600, cursor: 'pointer', fontFamily: 'inherit' },
  body: { flex: 1, overflow: 'auto', padding: '12px 20px 24px' },
  stepInner: {},
  eyebrow: { fontSize: 11, color: T3.ink3, fontWeight: 700, letterSpacing: '0.12em' },
  h1: { fontSize: 36, fontWeight: 800, letterSpacing: '-0.03em', lineHeight: 1.05, marginTop: 8 },
  sub: { fontSize: 14, color: T3.ink2, marginTop: 8 },
  choice: { display: 'flex', alignItems: 'center', gap: 14, padding: 16, background: 'rgba(255,255,255,0.72)', backdropFilter: 'blur(20px) saturate(1.16)', WebkitBackdropFilter: 'blur(20px) saturate(1.16)', border: '1px solid rgba(252,76,2,0.08)', borderRadius: 14, cursor: 'pointer', fontFamily: 'inherit', boxShadow: 'inset 0 1px 0 rgba(255,255,255,0.7), 0 12px 28px rgba(15,23,42,0.06), 0 4px 12px rgba(252,76,2,0.04)' },
  choiceActive: { borderColor: T3.primary, background: T3.primaryWash },
  levelChip: { flex: 1, padding: '10px 8px', background: 'rgba(255,255,255,0.72)', backdropFilter: 'blur(20px) saturate(1.16)', WebkitBackdropFilter: 'blur(20px) saturate(1.16)', border: '1px solid rgba(252,76,2,0.08)', borderRadius: 12, color: T3.ink, cursor: 'pointer', fontFamily: 'inherit', textAlign: 'center', boxShadow: 'inset 0 1px 0 rgba(255,255,255,0.7)' },
  levelChipActive: { background: T3.ink, color: 'white', borderColor: T3.ink },
  modeCard: { padding: 20, background: 'rgba(255,255,255,0.78)', backdropFilter: 'blur(24px) saturate(1.2)', WebkitBackdropFilter: 'blur(24px) saturate(1.2)', border: '1px solid rgba(252,76,2,0.12)', borderRadius: 18, cursor: 'pointer', fontFamily: 'inherit', boxShadow: 'inset 0 1px 0 rgba(255,255,255,0.85), 0 20px 40px rgba(15,23,42,0.08), 0 8px 20px rgba(252,76,2,0.07)' },
  featList: { listStyle: 'none', padding: 0, margin: 0, display: 'flex', flexDirection: 'column', gap: 6, textAlign: 'left' },
  cta: { width: '100%', padding: 18, borderRadius: 16, background: T3.primary, color: 'white', border: 'none', fontWeight: 700, fontSize: 15, cursor: 'pointer', marginTop: 28, boxShadow: '0 12px 28px rgba(252,76,2,0.3)', fontFamily: 'inherit' },
  ctaGhost: { width: '100%', padding: 14, marginTop: 8, background: 'transparent', color: T3.ink3, border: 'none', fontWeight: 600, fontSize: 13, cursor: 'pointer', fontFamily: 'inherit' },
};

// ──────────────────────────────────────────────────────────────────────
// 2. FIND TRAINER — список с фильтрами
// ──────────────────────────────────────────────────────────────────────
function FindTrainer({ onPick }) {
  const [spec, setSpec] = useStateV3('Марафон');
  const [onlineOnly, setOnlineOnly] = useStateV3(false);

  const filtered = V2.TRAINERS.filter(t => {
    if (onlineOnly && !t.online) return false;
    if (spec !== 'Все' && !t.specs.includes(spec)) return false;
    return true;
  });

  return (
    <div style={FT.shell}>
      <div style={FT.statusBar}>
        <span style={{ fontFamily: '"Jost", sans-serif' }}>9:41</span>
        <span style={{ display: 'flex', gap: 5 }}>●●● ● <span style={{ marginLeft: 6 }}>5G 89%</span></span>
      </div>

      <div style={FT.header}>
        <button style={FT.backBtn}>← </button>
        <div style={{ flex: 1, textAlign: 'center', fontWeight: 700, fontSize: 16 }}>Выбрать тренера</div>
        <button style={FT.backBtn}>⌕</button>
      </div>

      {/* Filter pills */}
      <div style={FT.filters}>
        {['Все', 'Марафон', 'Полумарафон', '10 км', 'Новичкам', 'Здоровье'].map(s => (
          <button key={s} onClick={() => setSpec(s)}
            style={{ ...FT.specChip, ...(spec === s ? FT.specChipActive : {}) }}>
            {s}
          </button>
        ))}
      </div>

      <div style={FT.controlRow}>
        <button onClick={() => setOnlineOnly(!onlineOnly)} style={FT.toggle}>
          <div style={{ ...FT.toggleSwitch, background: onlineOnly ? T3.success : T3.line2 }}>
            <div style={{ ...FT.toggleKnob, transform: `translateX(${onlineOnly ? 14 : 0}px)` }} />
          </div>
          <span style={{ fontSize: 12, fontWeight: 600 }}>Только онлайн</span>
        </button>
        <span style={{ fontSize: 12, color: T3.ink3, fontWeight: 600 }}>{filtered.length} тренеров</span>
      </div>

      <div style={FT.list}>
        {filtered.map(t => (
          <div key={t.id} role="button" tabIndex={0}
            onClick={() => onPick?.(t.id)}
            style={FT.card}>
            <div style={{ display: 'flex', gap: 12, alignItems: 'flex-start' }}>
              <div style={{ position: 'relative' }}>
                <V2.Avatar a={t} size={52} />
                {t.online && <div style={FT.onlineDot} />}
              </div>
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                  <span style={{ fontSize: 15, fontWeight: 700, color: T3.ink }}>{t.name}</span>
                  {t.verified && <span style={{ background: T3.info, color: 'white', borderRadius: '50%', width: 14, height: 14, display: 'grid', placeItems: 'center', fontSize: 9, fontWeight: 800 }}>✓</span>}
                </div>
                <div style={{ fontSize: 11, color: T3.ink3, marginTop: 2 }}>{t.title}</div>
                <div style={{ display: 'flex', gap: 12, marginTop: 8 }}>
                  <Stat3 label="★" value={t.rating} sub={`${t.reviews}`} />
                  <Stat3 label="ATH" value={t.athletes} sub="атлетов" />
                  <Stat3 label="ОТВЕТ" value={t.avgResponse} />
                </div>
              </div>
            </div>

            <div style={{ marginTop: 12, fontSize: 12, color: T3.ink2, lineHeight: 1.45 }}>
              {t.bio}
            </div>

            <div style={{ marginTop: 12, display: 'flex', alignItems: 'center', gap: 10 }}>
              <div style={{ display: 'flex', gap: 4, flexWrap: 'wrap' }}>
                {t.specs.slice(0, 3).map(s => (
                  <span key={s} style={FT.specTag}>{s}</span>
                ))}
              </div>
              <div style={{ flex: 1 }} />
              <div style={{ textAlign: 'right' }}>
                <div style={{ fontFamily: '"Jost", sans-serif', fontSize: 16, fontWeight: 700, color: T3.ink, lineHeight: 1 }}>{t.price.split('/')[0]}</div>
                <div style={{ fontSize: 10, color: T3.ink3 }}>/мес</div>
              </div>
            </div>

            {t.freeSlots === 0 && (
              <div style={{ marginTop: 10, padding: '6px 10px', background: T3.dangerWash, color: T3.danger, fontSize: 11, fontWeight: 600, borderRadius: 6 }}>
                Нет свободных мест — в листе ожидания
              </div>
            )}
            {t.freeSlots > 0 && t.freeSlots <= 2 && (
              <div style={{ marginTop: 10, padding: '6px 10px', background: T3.warningWash, color: '#92400E', fontSize: 11, fontWeight: 600, borderRadius: 6 }}>
                Осталось {t.freeSlots} места
              </div>
            )}
          </div>
        ))}
      </div>
    </div>
  );
}

function Stat3({ label, value, sub }) {
  return (
    <div>
      <div style={{ fontSize: 9, color: T3.ink3, fontWeight: 700, letterSpacing: '0.06em' }}>{label}</div>
      <div style={{ display: 'flex', alignItems: 'baseline', gap: 3 }}>
        <span style={{ fontFamily: '"Jost", sans-serif', fontSize: 14, fontWeight: 700, color: T3.ink, lineHeight: 1 }}>{value}</span>
        {sub && <span style={{ fontSize: 10, color: T3.ink3 }}>{sub}</span>}
      </div>
    </div>
  );
}

const FT = {
  shell: { width: '100%', height: '100%', background: 'radial-gradient(120% 80% at 0% 0%, rgba(252,76,2,0.07) 0%, transparent 50%), radial-gradient(100% 70% at 100% 100%, rgba(252,76,2,0.05) 0%, transparent 55%), linear-gradient(180deg, #FAF7F3 0%, #F4F7FB 100%)', fontFamily: 'Montserrat, sans-serif', color: T3.ink, display: 'flex', flexDirection: 'column', overflow: 'hidden' },
  statusBar: { height: 36, padding: '0 24px', display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontSize: 13, fontWeight: 700 },
  header: { padding: '8px 16px 14px', display: 'flex', alignItems: 'center' },
  backBtn: { width: 36, height: 36, borderRadius: 10, background: T3.surf3, border: 'none', cursor: 'pointer', fontSize: 16, fontFamily: 'inherit' },
  filters: { display: 'flex', gap: 6, padding: '4px 16px 12px', overflowX: 'auto' },
  specChip: { padding: '8px 14px', background: T3.surf3, border: 'none', borderRadius: 999, fontSize: 12, fontWeight: 600, color: T3.ink2, cursor: 'pointer', fontFamily: 'inherit', whiteSpace: 'nowrap' },
  specChipActive: { background: T3.ink, color: 'white' },
  controlRow: { padding: '0 20px 12px', display: 'flex', justifyContent: 'space-between', alignItems: 'center' },
  toggle: { display: 'flex', alignItems: 'center', gap: 8, background: 'transparent', border: 'none', cursor: 'pointer', fontFamily: 'inherit', color: T3.ink2 },
  toggleSwitch: { width: 32, height: 18, borderRadius: 999, padding: 2, transition: 'background 0.2s' },
  toggleKnob: { width: 14, height: 14, borderRadius: '50%', background: 'white', boxShadow: '0 1px 2px rgba(0,0,0,0.2)', transition: 'transform 0.2s' },
  list: { flex: 1, overflow: 'auto', padding: '0 16px 20px', display: 'flex', flexDirection: 'column', gap: 12 },
  card: { padding: 16, background: 'rgba(255,255,255,0.72)', backdropFilter: 'blur(20px) saturate(1.16)', WebkitBackdropFilter: 'blur(20px) saturate(1.16)', border: '1px solid rgba(252,76,2,0.08)', borderRadius: 16, cursor: 'pointer', boxShadow: 'inset 0 1px 0 rgba(255,255,255,0.7), 0 12px 28px rgba(15,23,42,0.06), 0 4px 12px rgba(252,76,2,0.04)' },
  onlineDot: { position: 'absolute', bottom: 0, right: 0, width: 14, height: 14, borderRadius: '50%', background: T3.success, border: '2.5px solid white' },
  specTag: { padding: '3px 8px', background: T3.surf3, borderRadius: 6, fontSize: 11, fontWeight: 600, color: T3.ink2 },
};

window.OnboardingFlow = OnboardingFlow;
window.FindTrainer = FindTrainer;
