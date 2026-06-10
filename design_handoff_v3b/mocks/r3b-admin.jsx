// ============================================================
// v3B · Админка (/admin), восстановление пароля, политика.
// ============================================================

// ---------- Админка · Пользователи ----------
function BAdminUsers({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  const users = [
    { name: 'Иван Петров', email: 'ivan@example.com', role: 'Бегун', mode: 'AI', reg: '12.01.2026', active: 'сегодня', on: true },
    { name: 'Сергей Климов', email: 'sergey@run.ru', role: 'Тренер', mode: '8 атлетов', reg: '03.11.2025', active: 'сегодня', on: true },
    { name: 'Мария Соколова', email: 'm.sokolova@mail.ru', role: 'Бегун', mode: 'Тренер', reg: '21.02.2026', active: 'вчера', on: true },
    { name: 'Павел Громов', email: 'pgromov@ya.ru', role: 'Бегун', mode: 'AI', reg: '08.04.2026', active: '5 дн назад', on: false },
    { name: 'Анна Волкова', email: 'volkova.a@gmail.com', role: 'Бегун', mode: 'Тренер', reg: '17.09.2025', active: 'сегодня', on: true },
    { name: 'Дмитрий Ким', email: 'dkim@example.com', role: 'Бегун', mode: 'AI', reg: '02.06.2026', active: 'вт', on: true },
  ];
  const tabs = ['Пользователи', 'Настройки сайта', 'Шаблоны уведомлений', 'Заявки тренеров'];
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, display: 'flex', flexDirection: 'column', fontFamily: B_BODY, overflow: 'hidden' }}>
      <BStyle T={T} /><BDefs />
      <div style={{ display: 'flex', alignItems: 'center', gap: 24, padding: '0 36px', height: 60, flexShrink: 0 }}>
        <BLogo T={T} size={16} />
        <div style={bLabel(T, { fontSize: 10, color: T.ink })}>Админка</div>
        <div className="r3b-card" style={{ ...bCard(T, { borderRadius: 99, padding: '4px 5px' }), display: 'flex', gap: 2 }}>
          {tabs.map((x, i) => (
            <div key={i} style={{ fontSize: 12, fontWeight: 700, padding: '7px 14px', borderRadius: 99, background: i === 0 ? B_GRAD(T) : 'transparent', color: i === 0 ? '#fff' : T.sub, cursor: 'pointer', whiteSpace: 'nowrap' }}>{x}{i === 3 ? ' · 1' : ''}</div>
          ))}
        </div>
        <div style={{ flex: 1 }}></div>
        <div className="r3b-btn" style={{ border: `1px solid ${T.cardBorder}`, background: T.card, color: T.ink, borderRadius: 10, padding: '8px 14px', fontSize: 12, fontWeight: 600 }}>Массовая рассылка</div>
      </div>

      {/* KPI платформы */}
      <div style={{ display: 'flex', gap: 12, padding: '4px 36px 14px', flexShrink: 0 }}>
        {[['Пользователей', '12 480', '+214 за нед', T.ink], ['Активных за 7 дней', '6 911', '55%', T.good], ['Тренеров', '64', '8 на модерации', T.ink], ['Планов сгенерировано', '18 302', 'сегодня 96', T.accent]].map(([l, v, s, c], i) => (
          <div key={i} className="r3b-card" style={bCard(T, { flex: 1, padding: '13px 18px' })}>
            <div style={bLabel(T, { fontSize: 8.5 })}>{l}</div>
            <div style={{ display: 'flex', alignItems: 'baseline', gap: 8, marginTop: 3 }}>
              <span style={{ fontFamily: B_DISP, fontSize: 21, fontWeight: 700, color: c }}>{v}</span>
              <span style={{ fontSize: 10.5, fontWeight: 600, color: T.sub }}>{s}</span>
            </div>
          </div>
        ))}
      </div>

      {/* таблица пользователей */}
      <div className="r3b-card" style={bCard(T, { margin: '0 36px 24px', flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column', overflow: 'hidden' })}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '12px 22px', borderBottom: `1px solid ${T.line}`, flexShrink: 0 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, border: `1px solid ${T.cardBorder}`, borderRadius: 10, padding: '7px 12px', width: 260, color: T.sub, background: T.card }}>
            {R3Icon.search(T.sub, 14)}
            <span style={{ fontSize: 12, fontWeight: 500 }}>Поиск по имени или email…</span>
          </div>
          {['Все', 'Бегуны', 'Тренеры', 'Неактивные'].map((x, i) => (
            <div key={i} className="r3b-btn" style={{ fontSize: 11, fontWeight: 700, padding: '6px 12px', borderRadius: 99, background: i === 0 ? B_GRAD(T) : 'transparent', color: i === 0 ? '#fff' : T.sub, border: i === 0 ? 'none' : `1px solid ${T.cardBorder}` }}>{x}</div>
          ))}
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: '2fr 2fr 100px 110px 110px 110px 90px', gap: 12, padding: '9px 22px', borderBottom: `1px solid ${T.line}`, ...bLabel(T, { fontSize: 8.5 }), flexShrink: 0 }}>
          <div>Пользователь</div><div>Email</div><div>Роль</div><div>Режим</div><div>Регистрация</div><div>Активность</div><div></div>
        </div>
        <div style={{ flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column' }}>
          {users.map((u, i) => (
            <div key={i} className="r3b-cell" style={{ display: 'grid', gridTemplateColumns: '2fr 2fr 100px 110px 110px 110px 90px', gap: 12, alignItems: 'center', padding: '0 22px', flex: 1, minHeight: 0, borderBottom: i < users.length - 1 ? `1px solid ${T.line}` : 'none' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                <div style={{ width: 8, height: 8, borderRadius: 99, background: u.on ? T.good : T.track, flexShrink: 0 }}></div>
                <span style={{ fontSize: 13, fontWeight: 700, color: T.ink }}>{u.name}</span>
              </div>
              <div style={{ fontSize: 12, color: T.sub }}>{u.email}</div>
              <div style={{ ...bLabel(T, { fontSize: 8.5, color: u.role === 'Тренер' ? T.accent : T.sub }) }}>{u.role}</div>
              <div style={{ fontSize: 12, fontWeight: 600, color: T.ink }}>{u.mode}</div>
              <div style={{ fontSize: 12, color: T.sub }}>{u.reg}</div>
              <div style={{ fontSize: 12, fontWeight: 600, color: u.active.includes('дн') ? T.bad : T.sub }}>{u.active}</div>
              <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end' }}>
                {['chat', 'dots'].map((ic, j) => (
                  <div key={j} className="r3b-btn" style={{ width: 28, height: 28, borderRadius: 9, border: `1px solid ${T.cardBorder}`, background: T.card, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>{R3Icon[ic](T.sub, 13)}</div>
                ))}
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

// ---------- Админка · Заявки тренеров + настройки сайта ----------
function BAdminCoachApps({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, display: 'flex', flexDirection: 'column', fontFamily: B_BODY, overflow: 'hidden' }}>
      <BStyle T={T} /><BDefs />
      <div style={{ display: 'flex', alignItems: 'center', gap: 24, padding: '0 36px', height: 60, flexShrink: 0 }}>
        <BLogo T={T} size={16} />
        <div style={bLabel(T, { fontSize: 10, color: T.ink })}>Админка</div>
        <div className="r3b-card" style={{ ...bCard(T, { borderRadius: 99, padding: '4px 5px' }), display: 'flex', gap: 2 }}>
          {['Пользователи', 'Настройки сайта', 'Шаблоны уведомлений', 'Заявки тренеров'].map((x, i) => (
            <div key={i} style={{ fontSize: 12, fontWeight: 700, padding: '7px 14px', borderRadius: 99, background: i === 3 ? B_GRAD(T) : 'transparent', color: i === 3 ? '#fff' : T.sub, cursor: 'pointer', whiteSpace: 'nowrap' }}>{x}{i === 3 ? ' · 1' : ''}</div>
          ))}
        </div>
        <div style={{ flex: 1 }}></div>
      </div>

      <div style={{ flex: 1, minHeight: 0, display: 'grid', gridTemplateColumns: '1fr 380px', gap: 16, padding: '4px 36px 24px' }}>
        {/* заявка на модерации */}
        <div className="r3b-card" style={bCard(T, { padding: '22px 28px', display: 'flex', flexDirection: 'column', minHeight: 0 })}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
            <div style={{ width: 52, height: 52, borderRadius: 99, background: B_GRAD(T), display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: B_DISP, fontSize: 15, fontWeight: 700, color: '#fff' }}>МТ</div>
            <div style={{ flex: 1 }}>
              <div style={{ fontFamily: B_DISP, fontSize: 18, fontWeight: 700, color: T.ink }}>Михаил Тропин</div>
              <div style={{ fontSize: 12, color: T.sub, marginTop: 2 }}>mtropin@trail.ru · подана 2 дня назад</div>
            </div>
            <div style={{ ...bLabel(T, { fontSize: 9, color: T.accent }), border: `1px solid ${T.accent}`, borderRadius: 99, padding: '6px 13px' }}>на модерации</div>
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '14px 28px', marginTop: 20 }}>
            {[
              ['Специализации', 'Трейл · Ультра · Травмы и восстановление'],
              ['Опыт', '15 лет · сборная региона по горному бегу'],
              ['Свои достижения', 'Elbrus Race 2024 — 2 место · UTMB финишер'],
              ['Достижения учеников', '6 финишеров ультра 100+ км'],
              ['Сертификации', 'Курс «Тренер по бегу» РУСАДА, First Aid'],
              ['Стоимость', 'Цены по запросу'],
            ].map(([l, v], i) => (
              <div key={i}>
                <div style={bLabel(T, { fontSize: 8.5, marginBottom: 4 })}>{l}</div>
                <div style={{ fontSize: 13, fontWeight: 600, color: T.ink, lineHeight: 1.5 }}>{v}</div>
              </div>
            ))}
          </div>
          <div style={{ marginTop: 16 }}>
            <div style={bLabel(T, { fontSize: 8.5, marginBottom: 4 })}>О себе</div>
            <div className="r3b-card" style={bCard(T, { padding: '12px 16px', background: T.card2, fontSize: 12.5, color: T.ink, lineHeight: 1.6 })}>
              Десять лет вожу группы в горах Кавказа. Учу не просто бегать дальше — учу возвращаться здоровым. Работаю с пульсом, рельефом и головой: ультра выигрывается питанием и терпением.
            </div>
          </div>
          <div style={{ flex: 1 }}></div>
          <div style={{ display: 'flex', gap: 10 }}>
            <div className="r3b-btn" style={{ background: B_GRAD(T), color: '#fff', borderRadius: 12, padding: '12px 26px', fontSize: 13, fontWeight: 700, boxShadow: T.glow }}>Одобрить</div>
            <div className="r3b-btn" style={{ border: `1px solid ${T.cardBorder}`, background: T.card, color: T.ink, borderRadius: 12, padding: '12px 20px', fontSize: 13, fontWeight: 600 }}>Запросить уточнения</div>
            <div style={{ flex: 1 }}></div>
            <div className="r3b-btn" style={{ border: `1px solid ${T.bad}`, color: T.bad, borderRadius: 12, padding: '12px 20px', fontSize: 13, fontWeight: 700, background: 'transparent' }}>Отклонить</div>
          </div>
        </div>

        {/* настройки сайта */}
        <div className="r3b-card" style={bCard(T, { padding: '20px 24px', display: 'flex', flexDirection: 'column', gap: 4 })}>
          <div style={bLabel(T, { fontSize: 9, marginBottom: 10 })}>Настройки сайта</div>
          {[
            ['Режим обслуживания', 'Сайт закрыт для всех, кроме админов', false],
            ['Регистрация открыта', 'Новые пользователи могут создавать аккаунты', true],
            ['Генерация планов', 'AI-пайплайн создания планов', true],
            ['Push-уведомления', 'Мобильные пуши через Capacitor', true],
          ].map(([t, s, on], i) => (
            <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '12px 0', borderTop: i ? `1px solid ${T.line}` : 'none' }}>
              <div style={{ flex: 1 }}>
                <div style={{ fontSize: 13, fontWeight: 700, color: T.ink }}>{t}</div>
                <div style={{ fontSize: 11, color: T.sub, marginTop: 2, lineHeight: 1.4 }}>{s}</div>
              </div>
              <BToggle T={T} on={on} />
            </div>
          ))}
          <div style={{ flex: 1 }}></div>
          <BField T={T} label="Контактный email" value="support@planrun.ru" />
        </div>
      </div>
    </div>
  );
}

// ---------- Забыли пароль / Сброс ----------
function BForgotPassword({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, display: 'flex', flexDirection: 'column', fontFamily: B_BODY, overflow: 'hidden' }}>
      <BStyle T={T} /><BDefs />
      <div style={{ padding: '20px 24px 0', flexShrink: 0 }}><BLogo T={T} size={18} /></div>
      <div style={{ flex: 1, display: 'flex', flexDirection: 'column', justifyContent: 'center', padding: '0 24px', gap: 14 }}>
        <div>
          <div style={{ fontFamily: B_DISP, fontSize: 24, fontWeight: 700, color: T.ink, lineHeight: 1.25 }}>Забыли пароль?</div>
          <div style={{ fontSize: 13.5, color: T.sub, marginTop: 8, lineHeight: 1.55 }}>Пришлём ссылку для сброса. План никуда не денется.</div>
        </div>
        <BField T={T} label="Email" value="ivan@example.com" />
        <div className="r3b-btn" onClick={() => prNav('reset')} style={{ background: B_GRAD(T), color: '#fff', borderRadius: 14, padding: '14px 18px', fontSize: 14.5, fontWeight: 700, textAlign: 'center', boxShadow: T.glow }}>
          Отправить ссылку →
        </div>
        <div style={{ textAlign: 'center' }}>
          <span className="r3b-btn" onClick={() => prNav('login')} style={{ fontSize: 12.5, fontWeight: 700, color: T.accent }}>← Вернуться ко входу</span>
        </div>
      </div>
    </div>
  );
}

function BResetPassword({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, display: 'flex', flexDirection: 'column', fontFamily: B_BODY, overflow: 'hidden' }}>
      <BStyle T={T} /><BDefs />
      <div style={{ padding: '20px 24px 0', flexShrink: 0 }}><BLogo T={T} size={18} /></div>
      <div style={{ flex: 1, display: 'flex', flexDirection: 'column', justifyContent: 'center', padding: '0 24px', gap: 14 }}>
        <div>
          <div style={{ fontFamily: B_DISP, fontSize: 24, fontWeight: 700, color: T.ink, lineHeight: 1.25 }}>Новый пароль.</div>
          <div style={{ fontSize: 13.5, color: T.sub, marginTop: 8 }}>Минимум 8 символов — и снова бежать.</div>
        </div>
        <BField T={T} label="Новый пароль" value="••••••••••" right={<span style={bLabel(T, { fontSize: 8.5, color: T.accent })}>показать</span>} />
        <div>
          <div style={{ display: 'flex', gap: 4, marginBottom: 6 }}>
            {[1, 1, 1, 0].map((v, i) => (
              <div key={i} style={{ flex: 1, height: 4, borderRadius: 99, background: v ? T.good : T.track }}></div>
            ))}
          </div>
          <div style={bLabel(T, { fontSize: 8.5, color: T.good })}>надёжный пароль</div>
        </div>
        <BField T={T} label="Повторите пароль" value="••••••••••" right={R3Icon.check(T.good, 15)} />
        <div className="r3b-btn" onClick={() => prNav('home')} style={{ background: B_GRAD(T), color: '#fff', borderRadius: 14, padding: '14px 18px', fontSize: 14.5, fontWeight: 700, textAlign: 'center', boxShadow: T.glow }}>
          Сохранить и войти →
        </div>
      </div>
    </div>
  );
}

// ---------- Политика конфиденциальности ----------
function BPrivacy({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  const sections = ['Какие данные собираем', 'Зачем они нужны', 'Данные тренировок и здоровья', 'Интеграции (Strava, Garmin…)', 'Хранение и удаление', 'Ваши права', 'Контакты'];
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, display: 'flex', flexDirection: 'column', fontFamily: B_BODY, overflow: 'hidden' }}>
      <BStyle T={T} /><BDefs />
      <div style={{ display: 'flex', alignItems: 'center', gap: 24, padding: '0 48px', height: 64, flexShrink: 0 }}>
        <BLogo T={T} size={17} />
        <div style={{ flex: 1 }}></div>
        <span className="r3b-btn" style={{ fontSize: 13, fontWeight: 700, color: T.ink }}>Войти</span>
      </div>
      <div style={{ flex: 1, minHeight: 0, display: 'grid', gridTemplateColumns: '280px 1fr', gap: 24, padding: '12px 48px 32px' }}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
          <div style={bLabel(T, { fontSize: 9, marginBottom: 10 })}>Содержание</div>
          {sections.map((s, i) => (
            <div key={i} className="r3b-btn" style={{ fontSize: 12.5, fontWeight: i === 2 ? 700 : 500, color: i === 2 ? T.accent : T.sub, padding: '7px 12px', borderLeft: `2px solid ${i === 2 ? T.accent : T.line}` }}>{s}</div>
          ))}
        </div>
        <div className="r3b-card" style={bCard(T, { padding: '30px 38px', minHeight: 0, overflow: 'hidden' })}>
          <div style={bLabel(T, { fontSize: 9, color: T.accent })}>Обновлено 1 июня 2026</div>
          <div style={{ fontFamily: B_DISP, fontSize: 24, fontWeight: 700, color: T.ink, margin: '10px 0 14px' }}>Политика конфиденциальности</div>
          <div style={{ fontSize: 13.5, color: T.sub, lineHeight: 1.7, maxWidth: 640 }}>
            PlanRun обрабатывает данные тренировок (GPS-треки, пульс, темп), чтобы строить и адаптировать ваш план. Мы не продаём данные третьим лицам и не используем их вне сервиса.
          </div>
          <div style={{ fontFamily: B_DISP, fontSize: 16, fontWeight: 700, color: T.ink, margin: '22px 0 8px' }}>Данные тренировок и здоровья</div>
          <div style={{ fontSize: 13.5, color: T.sub, lineHeight: 1.7, maxWidth: 640 }}>
            Треки и метрики поступают из подключённых сервисов (Strava, Garmin, Polar, Suunto, COROS, Huawei) или загружаются вручную. Они видны только вам и вашему тренеру — если вы его выбрали. Отключить интеграцию и удалить историю можно в любой момент в Настройках → Интеграции.
          </div>
          <div style={{ fontFamily: B_DISP, fontSize: 16, fontWeight: 700, color: T.ink, margin: '22px 0 8px' }}>Хранение и удаление</div>
          <div style={{ fontSize: 13.5, color: T.sub, lineHeight: 1.7, maxWidth: 640 }}>
            Удаление аккаунта стирает профиль, тренировки и переписку безвозвратно в течение 30 дней. Запрос — в Настройках → Безопасность или на support@planrun.ru.
          </div>
        </div>
      </div>
    </div>
  );
}

Object.assign(window, { BAdminUsers, BAdminCoachApps, BForgotPassword, BResetPassword, BPrivacy });
