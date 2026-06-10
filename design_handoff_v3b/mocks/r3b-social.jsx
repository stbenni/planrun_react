// ============================================================
// v3B · Уведомления (NotificationCenter), Поиск тренера
// (FindTrainerV3), Публичные профили (/:username · ProfileV3).
// ============================================================

const BNOTIF = [
  { kind: 'ai', t: '07:30', title: 'Утренний брифинг', text: 'Сегодня темповый 10 км. Готовность 82 — окно до 11:00.', today: true },
  { kind: 'pr', t: '06:58', title: 'Личный рекорд!', text: '21,1 км за 1:43:05 — минус 2:11 к прошлому.', today: true },
  { kind: 'sync', t: '06:55', title: 'Тренировка загружена', text: 'Garmin · Интервалы 6×800 · 9,4 км.', today: true },
  { kind: 'coach', t: 'вчера', title: 'Сергей Климов', text: '«Посмотрел твой темповый — солидно! Не гони 7-й км.»' },
  { kind: 'plan', t: 'вчера', title: 'План обновлён', text: 'Длительная перенесена на воскресенье по твоей просьбе.' },
  { kind: 'goal', t: 'пн', title: 'До марафона 117 дней', text: 'Прогноз 3:34:10 — разрыв с целью сокращается.' },
];

function BNotifIcon({ T, kind }) {
  const m = {
    ai: ['AI', B_GRAD(T), '#fff'],
    pr: ['PR', T.good, '#fff'],
    sync: ['↓', T.card2, T.ink],
    coach: ['СК', T.accent2, '#fff'],
    plan: ['⟳', T.card2, T.accent],
    goal: ['◎', T.card2, T.ink],
  };
  const [txt, bg, c] = m[kind] || m.sync;
  return (
    <div style={{ width: 38, height: 38, borderRadius: 12, flexShrink: 0, background: bg, border: bg === T.card2 ? `1px solid ${T.cardBorder}` : 'none', display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: B_DISP, fontSize: 12, fontWeight: 700, color: c }}>{txt}</div>
  );
}

function BNotifRow({ T, n, dense = false }) {
  return (
    <div className="r3b-card r3b-hover" style={bCard(T, { padding: dense ? '10px 13px' : '12px 15px', display: 'flex', gap: 12, alignItems: 'flex-start', border: n.today ? `1px solid ${T.cardBorder}` : `1px solid transparent`, background: n.today ? T.card : 'transparent' })}>
      <BNotifIcon T={T} kind={n.kind} />
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', gap: 8 }}>
          <span style={{ fontSize: 13, fontWeight: 700, color: T.ink }}>{n.title}</span>
          <span style={bLabel(T, { fontSize: 8 })}>{n.t}</span>
        </div>
        <div style={{ fontSize: 12, color: T.sub, lineHeight: 1.45, marginTop: 3 }}>{n.text}</div>
      </div>
      {n.today && <div style={{ width: 7, height: 7, borderRadius: 99, background: T.accent, marginTop: 5, flexShrink: 0 }}></div>}
    </div>
  );
}

// ---------- Уведомления · мобайл ----------
function BNotifMobile({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, display: 'flex', flexDirection: 'column', fontFamily: B_BODY, overflow: 'hidden' }}>
      <BStyle T={T} /><BDefs />
      <div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '14px 18px 10px', flexShrink: 0 }}>
        <div className="r3b-btn" onClick={() => prNav('back')} style={{ width: 32, height: 32, borderRadius: 99, border: `1px solid ${T.cardBorder}`, background: T.card, display: 'flex', alignItems: 'center', justifyContent: 'center', transform: 'rotate(180deg)' }}>
          {R3Icon.arrow(T.ink, 15)}
        </div>
        <div style={{ flex: 1 }}>
          <div style={{ fontFamily: B_DISP, fontSize: 16, fontWeight: 700, color: T.ink }}>Уведомления</div>
          <div style={bLabel(T, { fontSize: 8.5, color: T.accent })}>3 новых</div>
        </div>
        <div className="r3b-btn" style={{ ...bLabel(T, { fontSize: 8.5 }), border: `1px solid ${T.cardBorder}`, borderRadius: 99, padding: '6px 12px' }}>прочитать все</div>
      </div>
      <div style={{ padding: '6px 18px 4px', ...bLabel(T, { fontSize: 9 }) }}>Сегодня</div>
      <div style={{ padding: '0 16px', display: 'flex', flexDirection: 'column', gap: 7, flexShrink: 0 }}>
        {BNOTIF.filter((n) => n.today).map((n, i) => <BNotifRow key={i} T={T} n={n} />)}
      </div>
      <div style={{ padding: '14px 18px 4px', ...bLabel(T, { fontSize: 9 }) }}>Ранее</div>
      <div style={{ padding: '0 16px', display: 'flex', flexDirection: 'column', gap: 4, flex: 1, minHeight: 0 }}>
        {BNOTIF.filter((n) => !n.today).map((n, i) => <BNotifRow key={i} T={T} n={n} dense />)}
      </div>
      <BNav T={T} active="profile" />
    </div>
  );
}

// ---------- Уведомления · десктоп (dropdown) ----------
function BNotifDropdown({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, fontFamily: B_BODY, overflow: 'hidden', display: 'flex', alignItems: 'flex-start', justifyContent: 'flex-end', padding: '64px 28px 0' }}>
      <BStyle T={T} /><BDefs />
      {/* имитация шапки за панелью */}
      <div style={{ position: 'absolute', top: 0, left: 0, right: 0, height: 56, borderBottom: `1px solid ${T.line}`, display: 'flex', alignItems: 'center', padding: '0 28px', gap: 20 }}>
        <BLogo T={T} size={15} />
        <div style={{ flex: 1 }}></div>
        <div style={{ position: 'relative' }}>
          {R3Icon.bell(T.accent, 19)}
          <div style={{ position: 'absolute', top: -2, right: -4, minWidth: 14, height: 14, borderRadius: 99, background: B_GRAD(T), color: '#fff', fontSize: 8.5, fontWeight: 800, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: '0 3px' }}>3</div>
        </div>
        <div style={{ width: 30, height: 30, borderRadius: 99, background: B_GRAD(T), display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: B_DISP, fontSize: 10, fontWeight: 700, color: '#fff' }}>И</div>
      </div>
      <div className="r3b-card" style={bCard(T, { width: 400, maxHeight: 540, display: 'flex', flexDirection: 'column', overflow: 'hidden', background: T.card2, boxShadow: '0 24px 64px rgba(0,0,0,0.35)' })}>
        <div style={{ padding: '14px 18px 10px', borderBottom: `1px solid ${T.line}`, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <div style={{ fontFamily: B_DISP, fontSize: 14, fontWeight: 700, color: T.ink }}>Уведомления</div>
          <div className="r3b-btn" style={bLabel(T, { fontSize: 8.5, color: T.accent })}>прочитать все</div>
        </div>
        <div style={{ padding: 10, display: 'flex', flexDirection: 'column', gap: 5, overflow: 'hidden' }}>
          {BNOTIF.slice(0, 5).map((n, i) => <BNotifRow key={i} T={T} n={n} dense />)}
        </div>
        <div style={{ padding: '10px 18px', borderTop: `1px solid ${T.line}`, textAlign: 'center' }}>
          <span className="r3b-btn" style={bLabel(T, { fontSize: 9, color: T.accent })}>показать все →</span>
        </div>
      </div>
    </div>
  );
}

// ---------- Поиск тренера · мобайл ----------
const BTRAINERS = [
  { name: 'Сергей Климов', ini: 'СК', specs: ['Марафон', 'Полумарафон'], price: 'от 6 000 ₽', exp: '12 лет · КМС', online: true, accepting: true, rating: '4.9 · 31 отзыв' },
  { name: 'Анна Бегова', ini: 'АБ', specs: ['5–10 км', 'Начинающие'], price: 'от 4 500 ₽', exp: '7 лет · МС', online: false, accepting: true, rating: '4.8 · 18 отзывов' },
  { name: 'Михаил Тропин', ini: 'МТ', specs: ['Трейл', 'Ультра'], price: 'по запросу', exp: '15 лет', online: true, accepting: false, rating: '5.0 · 9 отзывов' },
];

function BTrainersMobile({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, display: 'flex', flexDirection: 'column', fontFamily: B_BODY, overflow: 'hidden' }}>
      <BStyle T={T} /><BDefs />
      <div style={{ padding: '16px 20px 10px', flexShrink: 0 }}>
        <div style={{ fontFamily: B_DISP, fontSize: 17, fontWeight: 700, color: T.ink }}>Тренеры</div>
        <div style={{ fontSize: 12.5, color: T.sub, marginTop: 4 }}>Живой тренер вместо AI — или вместе с ним.</div>
      </div>
      <div style={{ display: 'flex', gap: 7, padding: '0 16px 12px', flexShrink: 0, flexWrap: 'wrap' }}>
        {['Все', 'Марафон', 'Полумарафон', '5–10 км', 'Трейл'].map((x, i) => (
          <div key={i} className="r3b-btn" style={{ fontSize: 11.5, fontWeight: 700, padding: '7px 13px', borderRadius: 99, background: i === 0 ? B_GRAD(T) : T.card, color: i === 0 ? '#fff' : T.sub, border: i === 0 ? 'none' : `1px solid ${T.cardBorder}` }}>{x}</div>
        ))}
      </div>
      <div style={{ flex: 1, minHeight: 0, padding: '0 16px', display: 'flex', flexDirection: 'column', gap: 9 }}>
        {BTRAINERS.map((t, i) => (
          <div key={i} className="r3b-card r3b-hover" style={bCard(T, { padding: '14px 16px', flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column', justifyContent: 'center', gap: 9 })}>
            <div style={{ display: 'flex', gap: 12, alignItems: 'center' }}>
              <div style={{ position: 'relative', flexShrink: 0 }}>
                <div style={{ width: 46, height: 46, borderRadius: 99, background: i === 0 ? B_GRAD(T) : T.card2, border: i === 0 ? 'none' : `1px solid ${T.cardBorder}`, display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: B_DISP, fontSize: 13, fontWeight: 700, color: i === 0 ? '#fff' : T.ink }}>{t.ini}</div>
                {t.online && <div style={{ position: 'absolute', right: 0, bottom: 0, width: 12, height: 12, borderRadius: 99, background: T.good, border: `2.5px solid ${T.bgFlat}` }}></div>}
              </div>
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ fontSize: 14.5, fontWeight: 700, color: T.ink }}>{t.name}</div>
                <div style={{ fontSize: 11, color: T.sub, marginTop: 2 }}>{t.exp} · ★ {t.rating}</div>
              </div>
              <div style={{ textAlign: 'right', flexShrink: 0 }}>
                <div style={{ fontFamily: B_DISP, fontSize: 13, fontWeight: 700, color: T.accent }}>{t.price}</div>
                {t.price !== 'по запросу' && <div style={bLabel(T, { fontSize: 7.5 })}>в месяц</div>}
              </div>
            </div>
            <div style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
              {t.specs.map((s, j) => (
                <div key={j} style={{ fontSize: 10, fontWeight: 700, padding: '4px 10px', borderRadius: 99, background: T.card2, border: `1px solid ${T.cardBorder}`, color: T.sub }}>{s}</div>
              ))}
              <div style={{ flex: 1 }}></div>
              {t.accepting
                ? <div className="r3b-btn" onClick={() => prNav('coach-profile')} style={{ fontSize: 11.5, fontWeight: 700, padding: '8px 16px', borderRadius: 99, background: B_GRAD(T), color: '#fff' }}>Заявка →</div>
                : <div style={bLabel(T, { fontSize: 8.5 })}>набор закрыт</div>}
            </div>
          </div>
        ))}
        <div className="r3b-card" style={bCard(T, { padding: '12px 16px', display: 'flex', alignItems: 'center', gap: 12, border: `1px dashed ${T.accent}`, background: 'transparent', flexShrink: 0 })}>
          <div style={{ flex: 1 }}>
            <div style={{ fontSize: 13, fontWeight: 700, color: T.ink }}>Ты тренер?</div>
            <div style={{ fontSize: 11, color: T.sub, marginTop: 2 }}>Создай страницу и набирай учеников</div>
          </div>
          <div className="r3b-btn" onClick={() => prNav('apply')} style={{ ...bLabel(T, { fontSize: 8.5, color: T.accent }), border: `1px solid ${T.accent}`, borderRadius: 99, padding: '6px 12px' }}>подать заявку</div>
        </div>
      </div>
      <BNav T={T} active="profile" />
    </div>
  );
}

// ---------- Публичный профиль тренера · десктоп ----------
function BCoachProfileDesktop({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, fontFamily: B_BODY, overflow: 'hidden', display: 'flex', flexDirection: 'column' }}>
      <BStyle T={T} /><BDefs />
      {/* cover — тонкая градиентная лента */}
      <div style={{ height: 128, flexShrink: 0, position: 'relative', overflow: 'hidden', background: T.bgFlat }}>
        <div style={{ position: 'absolute', inset: 0, background: `radial-gradient(90% 160% at 15% 110%, ${T.accent}2E 0%, transparent 55%), radial-gradient(70% 140% at 85% -20%, ${T.accent2}1F 0%, transparent 60%)` }}></div>
        <div style={{ position: 'absolute', left: 0, right: 0, bottom: 0, height: 3, background: B_GRAD(T) }}></div>
        <div style={{ position: 'absolute', top: 18, left: 36 }}><BLogo T={T} size={15} /></div>
        <div style={{ position: 'absolute', top: 16, right: 36, ...bLabel(T, { fontSize: 9 }) }}>planrun.ru/sergey</div>
      </div>
      {/* шапка профиля — на чистом фоне, без наложения на градиент */}
      <div style={{ display: 'flex', alignItems: 'flex-end', gap: 22, padding: '0 36px', marginTop: -44, flexShrink: 0, position: 'relative' }}>
        <div style={{ width: 104, height: 104, borderRadius: 99, flexShrink: 0, background: B_GRAD(T), border: `4px solid ${T.bgFlat}`, display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: B_DISP, fontSize: 28, fontWeight: 700, color: '#fff', boxShadow: '0 10px 30px rgba(0,0,0,0.25)' }}>СК</div>
        <div style={{ flex: 1, paddingBottom: 4, minWidth: 0 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
            <span style={{ fontFamily: B_DISP, fontSize: 23, fontWeight: 700, color: T.ink }}>Сергей Климов</span>
            <div style={{ display: 'flex', alignItems: 'center', gap: 5, border: `1px solid ${T.good}`, borderRadius: 99, padding: '3px 10px' }}>
              <div style={{ width: 6, height: 6, borderRadius: 99, background: T.good }}></div>
              <span style={bLabel(T, { fontSize: 8, color: T.good })}>набор открыт</span>
            </div>
          </div>
          <div style={{ fontSize: 13, color: T.sub, marginTop: 4 }}>Тренер по бегу · КМС · 12 лет опыта · ★ 4.9 (31 отзыв)</div>
        </div>
        <div style={{ display: 'flex', gap: 10, paddingBottom: 8, flexShrink: 0 }}>
          <div className="r3b-btn" onClick={() => prNav('chat')} style={{ border: `1px solid ${T.cardBorder}`, background: T.card, color: T.ink, borderRadius: 12, padding: '11px 18px', fontSize: 13, fontWeight: 600 }}>Написать</div>
          <div className="r3b-btn" style={{ background: B_GRAD(T), color: '#fff', borderRadius: 12, padding: '11px 22px', fontSize: 13, fontWeight: 700, boxShadow: T.glow }}>Запросить тренера →</div>
        </div>
      </div>

      <div style={{ flex: 1, minHeight: 0, display: 'grid', gridTemplateColumns: '1.5fr 1fr', gap: 16, padding: '20px 36px 24px' }}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 14, minHeight: 0 }}>
          <div className="r3b-card" style={bCard(T, { padding: '18px 22px' })}>
            <div style={bLabel(T, { fontSize: 9, marginBottom: 8 })}>О себе</div>
            <div style={{ fontSize: 13.5, color: T.ink, lineHeight: 1.6 }}>
              Готовлю к марафонам и полумарафонам — от первого финиша до 2:50. Философия: бег должен встраиваться в жизнь, а не ломать её. Работаю через PlanRun: план в календаре, разборы в чате, корректировки еженедельно.
            </div>
            <div style={{ display: 'flex', gap: 7, marginTop: 12 }}>
              {['Марафон', 'Полумарафон', 'Подводка к старту', 'Работа с темпом'].map((s, i) => (
                <div key={i} style={{ fontSize: 10.5, fontWeight: 700, padding: '5px 12px', borderRadius: 99, background: T.card2, border: `1px solid ${T.cardBorder}`, color: T.sub }}>{s}</div>
              ))}
            </div>
          </div>
          <div className="r3b-card" style={bCard(T, { padding: '18px 22px', flex: 1, minHeight: 0 })}>
            <div style={bLabel(T, { fontSize: 9, marginBottom: 12 })}>Отзывы учеников</div>
            {[
              ['Екатерина Ю.', 'Полумарафон из 1:45 → 1:38 за сезон. Сергей видит по данным больше, чем я чувствую ногами.'],
              ['Павел Г.', 'Первый марафон без стены — план подводки был ювелирный.'],
            ].map(([who, txt], i) => (
              <div key={i} style={{ padding: '11px 0', borderTop: i ? `1px solid ${T.line}` : 'none' }}>
                <div style={{ fontSize: 12.5, fontWeight: 700, color: T.ink, marginBottom: 3 }}>{who} <span style={{ color: T.accent }}>★★★★★</span></div>
                <div style={{ fontSize: 12.5, color: T.sub, lineHeight: 1.5 }}>{txt}</div>
              </div>
            ))}
          </div>
        </div>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 14, minHeight: 0 }}>
          <div className="r3b-card" style={bCard(T, { padding: '18px 22px' })}>
            <div style={bLabel(T, { fontSize: 9, marginBottom: 10 })}>Тарифы</div>
            {[
              ['План + еженедельный разбор', '6 000 ₽/мес', true],
              ['Полное ведение + чат', '12 000 ₽/мес', false],
              ['Разовая консультация', '2 500 ₽', false],
            ].map(([name, price, hot], i) => (
              <div key={i} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '10px 12px', borderRadius: 12, marginTop: i ? 6 : 0, background: hot ? T.card2 : 'transparent', border: hot ? `1.5px solid ${T.accent}` : `1px solid ${T.line}` }}>
                <span style={{ fontSize: 12.5, fontWeight: 600, color: T.ink }}>{name}</span>
                <span style={{ fontFamily: B_DISP, fontSize: 13, fontWeight: 700, color: hot ? T.accent : T.ink, whiteSpace: 'nowrap' }}>{price}</span>
              </div>
            ))}
          </div>
          <div className="r3b-card" style={bCard(T, { padding: '18px 22px', flex: 1 })}>
            <div style={bLabel(T, { fontSize: 9, marginBottom: 10 })}>Сейчас тренирует</div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
              <R3Ring pct={0.8} size={66} stroke={7} color="url(#r3b-grad)" track={T.track}>
                <span style={{ fontFamily: B_DISP, fontSize: 16, fontWeight: 700, color: T.ink }}>8</span>
              </R3Ring>
              <div style={{ fontSize: 12.5, color: T.sub, lineHeight: 1.5 }}>8 из 10 мест занято.<br />Средний прогресс учеников: <span style={{ color: T.good, fontWeight: 700 }}>+2.1 VDOT</span> за сезон.</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

// ---------- Страница пользователя · мобайл (5-я вкладка таб-бара) ----------
function BAthleteProfileMobile({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, display: 'flex', flexDirection: 'column', fontFamily: B_BODY, overflow: 'hidden' }}>
      <BStyle T={T} /><BDefs />
      {/* шапка: аватар + имя + шестерёнка настроек */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 13, padding: '16px 20px 12px', flexShrink: 0 }}>
        <div style={{ width: 54, height: 54, borderRadius: 99, background: B_GRAD(T), display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: B_DISP, fontSize: 16, fontWeight: 700, color: '#fff', flexShrink: 0 }}>ИП</div>
        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{ fontFamily: B_DISP, fontSize: 17, fontWeight: 700, color: T.ink }}>Иван Петров</div>
          <div style={{ fontSize: 11.5, color: T.sub, marginTop: 2 }}>@ivanrun · бегает 3 года · Москва</div>
        </div>
        <div className="r3b-btn" onClick={() => prNav('settings')} title="Настройки" style={{ width: 40, height: 40, borderRadius: 99, border: `1px solid ${T.cardBorder}`, background: T.card, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
          {R3Icon.gear(T.ink, 19)}
        </div>
      </div>
      {/* режим тренировок */}
      <div style={{ display: 'flex', gap: 8, padding: '0 20px 10px', flexShrink: 0, alignItems: 'center' }}>
        <BModeChip T={T} />
        <div style={{ display: 'flex', alignItems: 'center', gap: 5 }}>
          {R3Icon.flame(T.accent, 14)}
          <span style={{ fontFamily: B_DISP, fontSize: 13, fontWeight: 700, color: T.ink }}>{R3.user.streak} дней</span>
        </div>
      </div>
      <div className="r3b-card" style={bCard(T, { margin: '2px 16px 0', padding: '13px 16px', display: 'flex', alignItems: 'center', gap: 14, border: `1px solid ${T.accent}`, flexShrink: 0 })}>
        <R3Ring pct={0.64} size={52} stroke={6} color="url(#r3b-grad)" track={T.track}>
          <span style={{ fontSize: 10, fontWeight: 800, color: T.ink }}>64%</span>
        </R3Ring>
        <div style={{ flex: 1 }}>
          <div style={{ fontSize: 13, fontWeight: 700, color: T.ink }}>Московский марафон</div>
          <div style={{ fontSize: 11, color: T.sub, marginTop: 2 }}>04.10.2026 · цель 3:29:59 · осталось 117 дней</div>
        </div>
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 8, padding: '12px 16px 0', flexShrink: 0 }}>
        {R3.prs.map((p, i) => (
          <div key={i} className="r3b-card" style={bCard(T, { padding: '11px 12px', textAlign: 'center' })}>
            <div style={{ fontFamily: B_DISP, fontSize: 15, fontWeight: 700, color: T.ink }}>{p.time}</div>
            <div style={bLabel(T, { fontSize: 8, marginTop: 3 })}>{p.dist}</div>
          </div>
        ))}
      </div>
      <div style={{ padding: '14px 20px 6px', ...bLabel(T, { fontSize: 9 }), flexShrink: 0 }}>Последние тренировки</div>
      <div style={{ flex: 1, minHeight: 0, padding: '0 16px', display: 'flex', flexDirection: 'column', gap: 7 }}>
        {[
          ['Темповый 10 км', 'сегодня · 4:34 ср. · ЧСС 158', T.accent],
          ['Интервалы 6×800', 'вт · 3:45 ср. на отрезках', T.good],
          ['Лёгкий 8 км', 'ср · 5:48 ср. · восстановление', T.good],
          ['Длительная 22 км', 'вс · 5:52 ср. · Z2', T.good],
        ].map(([name, meta, c], i) => (
          <div key={i} className="r3b-card r3b-hover" onClick={() => prNav('workout')} style={bCard(T, { padding: '11px 14px', display: 'flex', alignItems: 'center', gap: 12, flex: 1, minHeight: 0 })}>
            <div style={{ width: 3, alignSelf: 'stretch', borderRadius: 3, background: c, flexShrink: 0 }}></div>
            <div style={{ flex: 1 }}>
              <div style={{ fontSize: 13, fontWeight: 700, color: T.ink }}>{name}</div>
              <div style={{ fontSize: 11, color: T.sub, marginTop: 2 }}>{meta}</div>
            </div>
            {R3Icon.arrow(T.sub, 13)}
          </div>
        ))}
      </div>
      <div style={{ display: 'flex', gap: 8, padding: '10px 16px 12px', flexShrink: 0 }}>
        <div className="r3b-btn" onClick={() => prNav('trainers')} style={{ flex: 1, border: `1px solid ${T.cardBorder}`, background: T.card, color: T.ink, borderRadius: 14, padding: '12px 16px', fontSize: 13, fontWeight: 700, textAlign: 'center' }}>Мои тренеры</div>
        <div className="r3b-btn" style={{ flex: 1, border: `1px solid ${T.cardBorder}`, background: T.card, color: T.ink, borderRadius: 14, padding: '12px 16px', fontSize: 13, fontWeight: 700, textAlign: 'center' }}>Поделиться</div>
      </div>
      <BNav T={T} active="profile" />
    </div>
  );
}

Object.assign(window, { BNotifMobile, BNotifDropdown, BTrainersMobile, BCoachProfileDesktop, BAthleteProfileMobile });
