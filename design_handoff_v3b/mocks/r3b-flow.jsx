// ============================================================
// v3B · Навигационная логика: UserDrawer (меню «Ещё») и
// карта переходов по экранам (user flow map).
// ============================================================

// ---------- Drawer-меню (UserDrawer) · мобайл ----------
function BUserDrawer({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  const items = [
    { ic: 'profile', t: 'Профиль', s: 'Имя, аватар, физданные', to: 'settings' },
    { ic: 'run', t: 'Настройки тренировок', s: 'Цель, режим, дни, темпы', to: 'settings' },
    { ic: 'bell', t: 'Уведомления', s: 'Каналы и расписание', to: 'notif' },
    { ic: 'coach', t: 'Тренеры', s: 'Каталог · мои тренеры · заявки', to: 'trainers', hot: true },
    { ic: 'integ', t: 'Интеграции', s: 'Strava, Garmin, Telegram — 3 активны', to: 'integrations' },
    { ic: 'lock', t: 'Безопасность', s: 'Email, пароль, PIN', to: 'settings' },
  ];
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, fontFamily: B_BODY, overflow: 'hidden', position: 'relative', display: 'flex', flexDirection: 'column' }}>
      <BStyle T={T} /><BDefs />
      {/* фон: приглушённый дашборд */}
      <div style={{ padding: '16px 20px', opacity: 0.35, filter: 'blur(1px)' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          <div style={{ width: 38, height: 38, borderRadius: 99, background: B_GRAD(T) }}></div>
          <div>
            <div style={{ fontSize: 14.5, fontWeight: 600, color: T.ink }}>Доброе утро, Иван</div>
            <div style={bLabel(T, { fontSize: 9 })}>Четверг, 11 июня</div>
          </div>
        </div>
      </div>
      <div style={{ position: 'absolute', inset: 0, background: dark ? 'rgba(4,6,10,0.6)' : 'rgba(14,20,32,0.35)', backdropFilter: 'blur(4px)' }}></div>
      {/* drawer справа */}
      <div className="r3b-card" style={bCard(T, {
        position: 'absolute', top: 0, right: 0, bottom: 0, width: 320,
        background: dark ? '#10161F' : '#FAFBFE', borderRadius: '24px 0 0 24px',
        display: 'flex', flexDirection: 'column', boxShadow: '-24px 0 64px rgba(0,0,0,0.45)',
      })}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '18px 20px 14px', flexShrink: 0 }}>
          <BLogo T={T} size={15} />
          <div className="r3b-btn" onClick={() => prNav('back')} style={{ width: 30, height: 30, borderRadius: 99, border: `1px solid ${T.cardBorder}`, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 13, color: T.sub }}>✕</div>
        </div>
        {/* карточка юзера + режим */}
        <div className="r3b-card" style={bCard(T, { margin: '0 16px 12px', padding: '13px 15px', background: T.card2, flexShrink: 0 })}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 11 }}>
            <div style={{ width: 42, height: 42, borderRadius: 99, background: B_GRAD(T), display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: B_DISP, fontSize: 13, fontWeight: 700, color: '#fff' }}>ИП</div>
            <div style={{ flex: 1 }}>
              <div style={{ fontSize: 14, fontWeight: 700, color: T.ink }}>Иван Петров</div>
              <div style={{ fontSize: 11, color: T.sub }}>@ivanrun</div>
            </div>
          </div>
          <div className="r3b-btn" onClick={() => prNav('mode')} style={{ display: 'flex', alignItems: 'center', gap: 8, marginTop: 10, padding: '8px 11px', borderRadius: 11, border: `1px solid ${T.accent}`, background: 'transparent' }}>
            <div style={{ width: 20, height: 20, borderRadius: 99, background: B_GRAD(T), display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 8, fontWeight: 800, color: '#fff', fontFamily: B_DISP }}>AI</div>
            <span style={{ flex: 1, fontSize: 12, fontWeight: 700, color: T.ink }}>Режим: AI-тренер</span>
            <span style={bLabel(T, { fontSize: 8, color: T.accent })}>сменить</span>
          </div>
        </div>
        {/* пункты */}
        <div style={{ flex: 1, minHeight: 0, padding: '0 12px', display: 'flex', flexDirection: 'column', gap: 3 }}>
          {items.map((it, i) => (
            <div key={i} className="r3b-btn" onClick={() => prNav(it.to)} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '10px 10px', borderRadius: 13, background: it.hot ? T.card2 : 'transparent', border: it.hot ? `1px solid ${T.accent}` : '1px solid transparent' }}>
              <BSetIcon T={T} ic={it.ic} active={it.hot} />
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ fontSize: 13, fontWeight: 700, color: T.ink }}>{it.t}</div>
                <div style={{ fontSize: 10.5, color: T.sub, marginTop: 1, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{it.s}</div>
              </div>
              {R3Icon.arrow(T.sub, 13)}
            </div>
          ))}
        </div>
        <div style={{ padding: '12px 20px 18px', borderTop: `1px solid ${T.line}`, flexShrink: 0, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <span className="r3b-btn" onClick={() => prNav('landing')} style={{ fontSize: 12.5, fontWeight: 700, color: T.bad }}>Выйти</span>
          <span style={bLabel(T, { fontSize: 8 })}>planRUN v3.2</span>
        </div>
      </div>
    </div>
  );
}

// ---------- Карта переходов ----------
function BFlowNode({ T, label, sub, kind = 'screen', w }) {
  const hot = kind === 'hot', modal = kind === 'modal', entry = kind === 'entry';
  return (
    <div style={{
      borderRadius: modal ? 10 : 13, padding: sub ? '9px 13px' : '10px 13px', width: w,
      background: hot ? B_GRAD(T) : entry ? T.card2 : T.card,
      border: modal ? `1.5px dashed ${T.cardBorder}` : `1px solid ${hot ? 'transparent' : T.cardBorder}`,
      boxShadow: hot ? T.glow : 'none', flexShrink: 0,
    }}>
      <div style={{ fontSize: 12, fontWeight: 700, color: hot ? '#fff' : T.ink, whiteSpace: 'nowrap' }}>{label}</div>
      {sub && <div style={{ fontSize: 9.5, color: hot ? 'rgba(255,255,255,0.8)' : T.sub, marginTop: 2, whiteSpace: 'nowrap' }}>{sub}</div>}
    </div>
  );
}

function BFlowArrow({ T, label }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 5, flexShrink: 0, padding: '0 2px' }}>
      {label && <span style={{ fontSize: 8.5, fontWeight: 700, color: T.accent, textTransform: 'uppercase', letterSpacing: '0.06em', whiteSpace: 'nowrap' }}>{label}</span>}
      <svg width="22" height="10" viewBox="0 0 22 10" fill="none" stroke={T.sub} strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
        <path d="M1 5h18M15 1.5L19.5 5 15 8.5"></path>
      </svg>
    </div>
  );
}

function BFlowLane({ T, title, color, children }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 14, padding: '13px 0', borderTop: `1px solid ${T.line}` }}>
      <div style={{ width: 120, flexShrink: 0 }}>
        <div style={{ fontSize: 10, fontWeight: 800, letterSpacing: '0.12em', textTransform: 'uppercase', color: color || T.sub }}>{title}</div>
      </div>
      <div style={{ display: 'flex', alignItems: 'center', gap: 7, flexWrap: 'wrap', rowGap: 10 }}>{children}</div>
    </div>
  );
}

function BFlowMap({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, fontFamily: B_BODY, overflow: 'hidden', display: 'flex', flexDirection: 'column', padding: '28px 36px' }}>
      <BStyle T={T} /><BDefs />
      <div style={{ display: 'flex', alignItems: 'baseline', gap: 14, marginBottom: 6 }}>
        <div style={{ fontFamily: B_DISP, fontSize: 21, fontWeight: 700, color: T.ink }}>Карта переходов</div>
        <div style={{ fontSize: 12, color: T.sub }}>как пользователи попадают на каждый экран</div>
        <div style={{ flex: 1 }}></div>
        <div style={{ display: 'flex', gap: 14 }}>
          {[['экран', T.card, `1px solid ${T.cardBorder}`], ['модал / шторка', 'transparent', `1.5px dashed ${T.cardBorder}`], ['ключевое действие', B_GRAD(T), 'none']].map(([l, bg, bd], i) => (
            <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
              <div style={{ width: 14, height: 10, borderRadius: 4, background: bg, border: bd }}></div>
              <span style={bLabel(T, { fontSize: 8.5 })}>{l}</span>
            </div>
          ))}
        </div>
      </div>

      <div style={{ flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column', justifyContent: 'space-evenly' }}>
        <BFlowLane T={T} title="Гость" color={T.accent}>
          <BFlowNode T={T} label="Лендинг" sub="/landing" kind="entry" />
          <BFlowArrow T={T} label="начать" />
          <BFlowNode T={T} label="Регистрация" sub="/register · шаг 1/3" />
          <BFlowArrow T={T} />
          <BFlowNode T={T} label="Онбординг: Цель" sub="цель → программа → дата" />
          <BFlowArrow T={T} />
          <BFlowNode T={T} label="Генерация плана" sub="2–3 мин · чеклист" kind="modal" />
          <BFlowArrow T={T} />
          <BFlowNode T={T} label="План готов" kind="hot" />
          <BFlowArrow T={T} />
          <BFlowNode T={T} label="Дашборд" sub="/" />
          <div style={{ ...bLabel(T, { fontSize: 8.5 }), marginLeft: 8 }}>· «Войти» → Вход → Дашборд · «Смотреть тренеров» → Каталог (публичный)</div>
        </BFlowLane>

        <BFlowLane T={T} title="Таб-бар бегуна" color={T.accent}>
          <BFlowNode T={T} label="Главная" sub="дашборд" kind="entry" />
          <BFlowNode T={T} label="План" sub="/calendar" kind="entry" />
          <BFlowNode T={T} label="Чат" sub="/chat · AI + тренер" kind="entry" />
          <BFlowNode T={T} label="Прогресс" sub="/stats" kind="entry" />
          <BFlowNode T={T} label="Профиль" sub="моя страница" kind="hot" />
          <BFlowArrow T={T} />
          <BFlowNode T={T} label="Страница пользователя" sub="цель · рекорды · ⛙ настройки" />
          <BFlowArrow T={T} label="тренеры" />
          <BFlowNode T={T} label="Каталог тренеров" sub="/trainers" />
          <BFlowArrow T={T} />
          <BFlowNode T={T} label="Профиль тренера" sub="/:username" />
          <BFlowArrow T={T} label="заявка" />
          <BFlowNode T={T} label="Запрос тренера" kind="modal" />
        </BFlowLane>

        <BFlowLane T={T} title="С главной">
          <BFlowNode T={T} label="Режим-чип" sub="в шапке" kind="modal" />
          <BFlowArrow T={T} />
          <BFlowNode T={T} label="Режим тренировок" sub="AI / тренер / сам" kind="modal" />
          <BFlowArrow T={T} label="тренер" />
          <BFlowNode T={T} label="Каталог тренеров" sub="/trainers" />
          <div style={{ width: 18 }}></div>
          <BFlowNode T={T} label="Колокольчик" kind="modal" />
          <BFlowArrow T={T} />
          <BFlowNode T={T} label="Уведомления" />
          <div style={{ width: 18 }}></div>
          <BFlowNode T={T} label="Тренировка дня" kind="hot" />
          <BFlowArrow T={T} label="начать / детали" />
          <BFlowNode T={T} label="Разбор тренировки" />
        </BFlowLane>

        <BFlowLane T={T} title="Из плана">
          <BFlowNode T={T} label="День в календаре" kind="entry" />
          <BFlowArrow T={T} label="выполнена" />
          <BFlowNode T={T} label="Разбор тренировки" sub="план vs факт · AI" />
          <BFlowArrow T={T} label="иначе" />
          <BFlowNode T={T} label="Запись результата" sub="бег · интервалы · ОФП…" kind="modal" />
          <div style={{ width: 18 }}></div>
          <BFlowNode T={T} label="Меню «⋯»" kind="modal" />
          <BFlowArrow T={T} label="пересчёт" />
          <BFlowNode T={T} label="Опрос готовности" sub="боль · сон · техника" kind="modal" />
          <BFlowArrow T={T} />
          <BFlowNode T={T} label="Пересчёт плана" kind="hot" />
        </BFlowLane>

        <BFlowLane T={T} title="Тренер" color={T.accent2}>
          <BFlowNode T={T} label="Команда" sub="центр управления" kind="entry" />
          <BFlowNode T={T} label="Поток" sub="события · ?view=stream" kind="entry" />
          <BFlowNode T={T} label="Календарь" sub="+ выбор атлета" kind="entry" />
          <BFlowNode T={T} label="Чат" kind="entry" />
          <BFlowNode T={T} label="Профиль" kind="hot" />
          <BFlowArrow T={T} />
          <BFlowNode T={T} label="Атлет" sub="drill-in оверлей" kind="modal" />
          <BFlowArrow T={T} label="назначить" />
          <BFlowNode T={T} label="Мастер назначения" sub="атлеты → шаблон → дата" kind="modal" />
          <BFlowArrow T={T} label="шаблоны" />
          <BFlowNode T={T} label="Библиотека" sub="/library" />
        </BFlowLane>

        <BFlowLane T={T} title="Профиль тренера">
          <BFlowNode T={T} label="Страница тренера" sub="⛙ → настройки" kind="modal" />
          <BFlowArrow T={T} />
          <BFlowNode T={T} label="Моя страница" sub="редактор + превью" />
          <BFlowNode T={T} label="Группы и заявки" sub="/coach/groups" />
          <BFlowNode T={T} label="Настройки" sub="/settings" />
          <div style={{ width: 18 }}></div>
          <BFlowNode T={T} label="Бегун: «Ты тренер?»" sub="в каталоге" kind="entry" />
          <BFlowArrow T={T} label="заявка" />
          <BFlowNode T={T} label="Стать тренером" sub="5 шагов" />
          <BFlowArrow T={T} label="модерация" />
          <BFlowNode T={T} label="Админка" sub="/admin" />
        </BFlowLane>
      </div>
    </div>
  );
}

Object.assign(window, { BUserDrawer, BFlowMap });
