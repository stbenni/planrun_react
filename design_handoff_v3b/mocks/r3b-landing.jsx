// ============================================================
// v3B · Лендинг (мобайл + десктоп) и Вход / Регистрация.
// Соответствует LandingScreen, LoginModal, RegisterScreen (minimalOnly).
// ============================================================

function BField({ T, label, value, placeholder, right }) {
  return (
    <div>
      <div style={bLabel(T, { fontSize: 9, marginBottom: 6 })}>{label}</div>
      <div className="r3b-card" style={bCard(T, { padding: '12px 15px', display: 'flex', alignItems: 'center', gap: 10, background: T.card2 })}>
        <span style={{ flex: 1, fontSize: 14, fontWeight: 600, color: value ? T.ink : T.sub }}>{value || placeholder}</span>
        {right}
      </div>
    </div>
  );
}

// ---------- Вход · мобайл ----------
function BLoginMobile({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, display: 'flex', flexDirection: 'column', fontFamily: B_BODY, overflow: 'hidden' }}>
      <BStyle T={T} /><BDefs />
      <div style={{ padding: '20px 24px 0', flexShrink: 0 }}><BLogo T={T} size={18} /></div>
      <div style={{ flex: 1, display: 'flex', flexDirection: 'column', justifyContent: 'center', padding: '0 24px', gap: 14 }}>
        <div>
          <div style={{ fontFamily: B_DISP, fontSize: 26, fontWeight: 700, color: T.ink, lineHeight: 1.2 }}>С возвращением.</div>
          <div style={{ fontSize: 13.5, color: T.sub, marginTop: 6 }}>Твой план ждёт — сегодня темповый.</div>
        </div>
        <BField T={T} label="Email" value="ivan@example.com" />
        <BField T={T} label="Пароль" value="••••••••" right={<span style={bLabel(T, { fontSize: 8.5, color: T.accent })}>показать</span>} />
        <div className="r3b-btn" onClick={() => prNav('home')} style={{ background: B_GRAD(T), color: '#fff', borderRadius: 14, padding: '14px 18px', fontSize: 14.5, fontWeight: 700, textAlign: 'center', boxShadow: T.glow }}>
          Войти →
        </div>
        <div style={{ textAlign: 'center' }}>
          <span className="r3b-btn" onClick={() => prNav('forgot')} style={{ fontSize: 12, fontWeight: 600, color: T.sub }}>Забыл пароль?</span>
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12, margin: '4px 0' }}>
          <div style={{ flex: 1, height: 1, background: T.line }}></div>
          <span style={bLabel(T, { fontSize: 8.5 })}>или</span>
          <div style={{ flex: 1, height: 1, background: T.line }}></div>
        </div>
        <div className="r3b-btn r3b-card" style={bCard(T, { padding: '13px 18px', display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 10 })}>
          <div style={{ width: 22, height: 22, borderRadius: 99, background: '#29A9EB', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#fff', fontSize: 11, fontWeight: 800 }}>T</div>
          <span style={{ fontSize: 13.5, fontWeight: 700, color: T.ink }}>Войти через Telegram</span>
        </div>
      </div>
      <div style={{ padding: '0 24px 26px', textAlign: 'center', flexShrink: 0 }}>
        <span style={{ fontSize: 12.5, color: T.sub }}>Нет аккаунта? </span>
        <span className="r3b-btn" onClick={() => prNav('register')} style={{ fontSize: 12.5, fontWeight: 700, color: T.accent }}>Создать бесплатно</span>
      </div>
    </div>
  );
}

// ---------- Регистрация · мобайл (minimalOnly) ----------
function BRegisterMobile({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, display: 'flex', flexDirection: 'column', fontFamily: B_BODY, overflow: 'hidden' }}>
      <BStyle T={T} /><BDefs />
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '20px 24px 0', flexShrink: 0 }}>
        <BLogo T={T} size={18} />
        <div style={bLabel(T, { fontSize: 9 })}>шаг 1 из 3</div>
      </div>
      <div style={{ flex: 1, display: 'flex', flexDirection: 'column', justifyContent: 'center', padding: '0 24px', gap: 14 }}>
        <div>
          <div style={{ fontFamily: B_DISP, fontSize: 26, fontWeight: 700, color: T.ink, lineHeight: 1.2 }}>Создаём аккаунт.</div>
          <div style={{ fontSize: 13.5, color: T.sub, marginTop: 6, lineHeight: 1.5 }}>Дальше — цель, и через 3 минуты у тебя будет план.</div>
        </div>
        <BField T={T} label="Имя" placeholder="Как к тебе обращаться" />
        <BField T={T} label="Email" placeholder="you@example.com" />
        <BField T={T} label="Пароль" placeholder="Минимум 8 символов" />
        <div style={{ display: 'flex', alignItems: 'flex-start', gap: 10 }}>
          <div style={{ width: 18, height: 18, borderRadius: 6, background: B_GRAD(T), display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0, marginTop: 1 }}>{R3Icon.check('#fff', 10)}</div>
          <span style={{ fontSize: 11.5, color: T.sub, lineHeight: 1.45 }}>Согласен с <span style={{ color: T.accent, fontWeight: 600 }}>политикой конфиденциальности</span> и обработкой данных о тренировках</span>
        </div>
        <div className="r3b-btn" onClick={() => prNav('ob-goal')} style={{ background: B_GRAD(T), color: '#fff', borderRadius: 14, padding: '14px 18px', fontSize: 14.5, fontWeight: 700, textAlign: 'center', boxShadow: T.glow }}>
          Продолжить →
        </div>
        <div className="r3b-btn r3b-card" style={bCard(T, { padding: '13px 18px', display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 10 })}>
          <div style={{ width: 22, height: 22, borderRadius: 99, background: '#29A9EB', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#fff', fontSize: 11, fontWeight: 800 }}>T</div>
          <span style={{ fontSize: 13.5, fontWeight: 700, color: T.ink }}>Быстрее через Telegram</span>
        </div>
      </div>
      <div style={{ padding: '0 24px 26px', textAlign: 'center', flexShrink: 0 }}>
        <span style={{ fontSize: 12.5, color: T.sub }}>Уже бегаешь с нами? </span>
        <span className="r3b-btn" onClick={() => prNav('login')} style={{ fontSize: 12.5, fontWeight: 700, color: T.accent }}>Войти</span>
      </div>
    </div>
  );
}

// ---------- Лендинг · общие блоки ----------
function BLandFeature({ T, title, sub, ic }) {
  return (
    <div className="r3b-card r3b-hover" style={bCard(T, { padding: '16px 18px', display: 'flex', flexDirection: 'column', gap: 8 })}>
      <div style={{ width: 36, height: 36, borderRadius: 12, background: T.card2, border: `1px solid ${T.cardBorder}`, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
        {R3Icon[ic](T.accent, 18)}
      </div>
      <div style={{ fontSize: 14.5, fontWeight: 700, color: T.ink }}>{title}</div>
      <div style={{ fontSize: 12, color: T.sub, lineHeight: 1.5 }}>{sub}</div>
    </div>
  );
}

const BLAND_FEATURES = [
  { ic: 'run', title: 'План под цель', sub: 'От первых 5 км до марафона — план строится под твою цель, уровень и расписание.' },
  { ic: 'chat', title: 'AI или живой тренер', sub: 'AI ведёт 24/7 и правит план в чате. Или выбери тренера из каталога — режим переключается.' },
  { ic: 'stats', title: 'Темп, пульс, прогресс', sub: 'VDOT, зоны, готовность и прогноз результата — телеметрия твоей формы.' },
  { ic: 'cal', title: 'Импорт тренировок', sub: 'Garmin, Strava, Polar, Suunto, COROS, Huawei — тренировки подтягиваются сами.' },
];

function BLandRingVisual({ T, size = 240 }) {
  return (
    <div style={{ position: 'relative', width: size, height: size }}>
      <div style={{ position: 'absolute', inset: -30, background: `radial-gradient(50% 50% at 50% 50%, ${T.accent}2E 0%, transparent 70%)` }}></div>
      <R3Ring pct={0.82} size={size} stroke={16} color="url(#r3b-grad)" track={T.track}>
        <div style={{ fontFamily: B_DISP, fontSize: size * 0.19, fontWeight: 700, color: T.ink, lineHeight: 1 }}>82</div>
        <div style={bLabel(T, { fontSize: size * 0.045 })}>готовность</div>
      </R3Ring>
      <div className="r3b-card" style={bCard(T, { position: 'absolute', top: -6, right: -34, padding: '8px 12px', background: T.card2 })}>
        <div style={bLabel(T, { fontSize: 7.5, color: T.good })}>план обновлён</div>
        <div style={{ fontSize: 11, fontWeight: 700, color: T.ink, marginTop: 2 }}>Сб → Вс · 22 км</div>
      </div>
      <div className="r3b-card" style={bCard(T, { position: 'absolute', bottom: 0, left: -38, padding: '8px 12px', background: T.card2 })}>
        <div style={bLabel(T, { fontSize: 7.5, color: T.accent })}>прогноз</div>
        <div style={{ fontFamily: B_DISP, fontSize: 13, fontWeight: 700, color: T.ink, marginTop: 2 }}>3:34:10</div>
      </div>
    </div>
  );
}

// ---------- Лендинг · мобайл ----------
function BLandingMobile({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, fontFamily: B_BODY, overflow: 'hidden', display: 'flex', flexDirection: 'column' }}>
      <BStyle T={T} /><BDefs />
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '18px 22px', flexShrink: 0 }}>
        <BLogo T={T} size={17} />
        <div style={{ display: 'flex', gap: 16, alignItems: 'center' }}>
          <span onClick={() => prNav('login')} style={{ fontSize: 12.5, fontWeight: 700, color: T.sub, cursor: 'pointer' }}>Войти</span>
          <span className="r3b-btn" onClick={() => prNav('register')} style={{ fontSize: 12, fontWeight: 700, color: '#fff', background: B_GRAD(T), borderRadius: 99, padding: '7px 14px' }}>Начать</span>
        </div>
      </div>
      {/* hero */}
      <div style={{ padding: '18px 24px 0', flexShrink: 0 }}>
        <BLive T={T} label="бег по данным" />
        <div style={{ fontFamily: B_DISP, fontSize: 30, fontWeight: 700, color: T.ink, lineHeight: 1.18, margin: '12px 0' }}>
          Тренируйся с AI<br />или живым тренером.
        </div>
        <div style={{ fontSize: 14, color: T.sub, lineHeight: 1.55 }}>
          От первых 5 км до марафона. План адаптируется под каждую твою тренировку.
        </div>
        <div className="r3b-btn" onClick={() => prNav('register')} style={{ background: B_GRAD(T), color: '#fff', borderRadius: 14, padding: '14px 18px', fontSize: 14.5, fontWeight: 700, textAlign: 'center', boxShadow: T.glow, margin: '18px 0 6px' }}>
          Начать бесплатно →
        </div>
        <div style={{ textAlign: 'center', ...bLabel(T, { fontSize: 8.5 }) }}>без карты · план за 3 минуты</div>
      </div>
      <div style={{ display: 'flex', justifyContent: 'center', padding: '28px 0 10px', flexShrink: 0 }}>
        <BLandRingVisual T={T} size={210} />
      </div>
      {/* фичи */}
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10, padding: '18px 20px', flexShrink: 0 }}>
        {BLAND_FEATURES.map((f, i) => <BLandFeature key={i} T={T} {...f} />)}
      </div>
      {/* AI vs тренер */}
      <div style={{ padding: '6px 20px 16px', flexShrink: 0 }}>
        <div className="r3b-card" style={bCard(T, { padding: '18px 20px', background: T.card2 })}>
          <div style={bLabel(T, { fontSize: 9, marginBottom: 12 })}>Два режима — одна цель</div>
          {[
            ['AI-тренер', 'Бесплатно · правки плана в чате 24/7', true],
            ['Живой тренер', 'От 4 500 ₽/мес · каталог проверенных тренеров', false],
          ].map(([t, s, hot], i) => (
            <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '11px 13px', borderRadius: 13, marginTop: i ? 8 : 0, background: hot ? (dark ? 'rgba(255,90,31,0.1)' : 'rgba(244,72,10,0.07)') : 'transparent', border: hot ? `1.5px solid ${T.accent}` : `1px solid ${T.line}` }}>
              <div style={{ width: 34, height: 34, borderRadius: 99, flexShrink: 0, background: hot ? B_GRAD(T) : T.card, border: hot ? 'none' : `1px solid ${T.cardBorder}`, display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: B_DISP, fontSize: 10, fontWeight: 700, color: hot ? '#fff' : T.ink }}>{hot ? 'AI' : 'СК'}</div>
              <div style={{ flex: 1 }}>
                <div style={{ fontSize: 13.5, fontWeight: 700, color: T.ink }}>{t}</div>
                <div style={{ fontSize: 11, color: T.sub, marginTop: 2 }}>{s}</div>
              </div>
            </div>
          ))}
          <div style={{ fontSize: 11, color: T.sub, marginTop: 10, lineHeight: 1.5 }}>Режим можно переключить в любой момент — история и прогресс сохраняются.</div>
        </div>
      </div>
      {/* финальный CTA */}
      <div style={{ flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column', justifyContent: 'center', padding: '0 24px 26px' }}>
        <div style={{ fontFamily: B_DISP, fontSize: 21, fontWeight: 700, color: T.ink, textAlign: 'center', lineHeight: 1.3 }}>
          Первая тренировка —<br />уже завтра утром.
        </div>
        <div className="r3b-btn" onClick={() => prNav('register')} style={{ background: B_GRAD(T), color: '#fff', borderRadius: 14, padding: '14px 18px', fontSize: 14.5, fontWeight: 700, textAlign: 'center', boxShadow: T.glow, marginTop: 16 }}>
          Создать план →
        </div>
      </div>
    </div>
  );
}

// ---------- Лендинг · десктоп ----------
function BLandingDesktop({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, fontFamily: B_BODY, overflow: 'hidden', display: 'flex', flexDirection: 'column' }}>
      <BStyle T={T} /><BDefs />
      <div style={{ display: 'flex', alignItems: 'center', gap: 30, padding: '0 64px', height: 72, flexShrink: 0 }}>
        <BLogo T={T} size={19} />
        <div style={{ flex: 1 }}></div>
        {['Возможности', 'Тренерам', 'Цены'].map((x, i) => (
          <span key={i} className="r3b-btn" style={{ fontSize: 13, fontWeight: 600, color: T.sub }}>{x}</span>
        ))}
        <span className="r3b-btn" onClick={() => prNav('login')} style={{ fontSize: 13, fontWeight: 700, color: T.ink }}>Войти</span>
        <span className="r3b-btn" onClick={() => prNav('register')} style={{ fontSize: 13, fontWeight: 700, color: '#fff', background: B_GRAD(T), borderRadius: 99, padding: '10px 20px', boxShadow: T.glow }}>Начать бесплатно</span>
      </div>
      {/* hero */}
      <div style={{ display: 'grid', gridTemplateColumns: '1.1fr 1fr', gap: 40, padding: '36px 64px 30px', flexShrink: 0, alignItems: 'center' }}>
        <div>
          <BLive T={T} label="бег по данным · 12 000+ бегунов" />
          <div style={{ fontFamily: B_DISP, fontSize: 50, fontWeight: 700, color: T.ink, lineHeight: 1.12, margin: '18px 0' }}>
            Тренируйся с AI<br />или живым тренером.
          </div>
          <div style={{ fontSize: 16, color: T.sub, lineHeight: 1.6, maxWidth: 460 }}>
            От первых 5 км до марафона. План строится за 3 минуты и адаптируется под каждую тренировку — а ты просто бежишь.
          </div>
          <div style={{ display: 'flex', gap: 12, marginTop: 26, alignItems: 'center' }}>
            <div className="r3b-btn" onClick={() => prNav('register')} style={{ background: B_GRAD(T), color: '#fff', borderRadius: 14, padding: '15px 30px', fontSize: 15, fontWeight: 700, boxShadow: T.glow }}>Начать бесплатно →</div>
            <div className="r3b-btn r3b-card" onClick={() => prNav('trainers')} style={bCard(T, { padding: '15px 24px', fontSize: 14, fontWeight: 600, color: T.ink })}>Смотреть тренеров</div>
          </div>
          <div style={{ ...bLabel(T, { fontSize: 9 }), marginTop: 14 }}>без карты · Garmin / Strava / Polar / Suunto / COROS</div>
        </div>
        <div style={{ display: 'flex', justifyContent: 'center' }}>
          <BLandRingVisual T={T} size={280} />
        </div>
      </div>
      {/* фичи */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 14, padding: '10px 64px', flexShrink: 0 }}>
        {BLAND_FEATURES.map((f, i) => <BLandFeature key={i} T={T} {...f} />)}
      </div>
      {/* нижняя полоса: тренерам + CTA */}
      <div style={{ flex: 1, minHeight: 0, display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14, padding: '14px 64px 28px' }}>
        <div className="r3b-card" style={bCard(T, { padding: '24px 28px', display: 'flex', flexDirection: 'column', justifyContent: 'center', background: T.card2 })}>
          <div style={bLabel(T, { fontSize: 9, color: T.accent })}>Тренерам</div>
          <div style={{ fontFamily: B_DISP, fontSize: 21, fontWeight: 700, color: T.ink, margin: '8px 0' }}>Веди команду на одном экране.</div>
          <div style={{ fontSize: 13, color: T.sub, lineHeight: 1.55 }}>Атлеты, тепловая карта недели и живой поток событий. Назначай тренировки группе в два клика.</div>
          <div className="r3b-btn" onClick={() => prNav('apply')} style={{ alignSelf: 'flex-start', marginTop: 14, ...bLabel(T, { fontSize: 9, color: T.accent }), border: `1px solid ${T.accent}`, borderRadius: 99, padding: '8px 16px' }}>стать тренером →</div>
        </div>
        <div className="r3b-card" style={bCard(T, { padding: '24px 28px', display: 'flex', flexDirection: 'column', justifyContent: 'center', alignItems: 'center', textAlign: 'center', border: `1.5px solid ${T.accent}`, boxShadow: T.glow })}>
          <div style={{ fontFamily: B_DISP, fontSize: 21, fontWeight: 700, color: T.ink, lineHeight: 1.3 }}>Первая тренировка —<br />уже завтра утром.</div>
          <div className="r3b-btn" onClick={() => prNav('register')} style={{ background: B_GRAD(T), color: '#fff', borderRadius: 14, padding: '13px 28px', fontSize: 14, fontWeight: 700, marginTop: 16, boxShadow: T.glow }}>Создать план →</div>
        </div>
      </div>
    </div>
  );
}

Object.assign(window, { BLoginMobile, BRegisterMobile, BLandingMobile, BLandingDesktop });
