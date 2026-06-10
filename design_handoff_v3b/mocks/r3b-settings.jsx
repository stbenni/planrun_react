// ============================================================
// v3B · Настройки — мобайл (категории, интеграции) и десктоп.
// Соответствует src/screens/settings/v3 (CATS) + IntegrationsSectionV3.
// ============================================================

const BSET_CATS = [
  { ic: 'profile', title: 'Профиль', sub: 'Имя, аватар, физданные' },
  { ic: 'run', title: 'Тренировки', sub: 'Цель, режим, дни, темпы' },
  { ic: 'bell', title: 'Уведомления', sub: 'Каналы и расписание' },
  { ic: 'coach', title: 'Тренеры', sub: 'Мои тренеры, заявки' },
  { ic: 'integ', title: 'Интеграции', sub: 'Strava, Polar, Garmin…', on: true, badge: '3' },
  { ic: 'look', title: 'Внешний вид', sub: 'Тема оформления' },
  { ic: 'lock', title: 'Безопасность', sub: 'Email, пароль, PIN' },
];

const BSET_PROVIDERS = [
  { name: 'Strava', state: 'on', detail: 'GPS-треки и тренировки', sync: 'синк 07:41', mark: '#FC4C02' },
  { name: 'Garmin', state: 'on', detail: 'Часы и датчики', sync: 'синк 07:40', mark: '#007CC3' },
  { name: 'Telegram', state: 'on', detail: 'Бот: загрузка и брифинги', sync: '@planrun_bot', mark: '#29A9EB' },
  { name: 'Suunto', state: 'off', detail: 'GPS/FIT, авто-синк', mark: '#1A2B49' },
  { name: 'Polar', state: 'off', detail: 'Импорт тренировок', mark: '#D10027' },
  { name: 'COROS', state: 'off', detail: 'Импорт тренировок', mark: '#FF6B00' },
  { name: 'Huawei Health', state: 'off', detail: 'Импорт активностей', mark: '#CF0A2C' },
];

function BSetIcon({ T, ic, active }) {
  const c = active ? '#fff' : T.sub;
  const map = {
    profile: <circle cx="12" cy="8" r="4"></circle>,
    run: <path d="M13 4l4 3-2 4 3 2v7M13 4 7 8l2 5-4 4"></path>,
    bell: <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"></path>,
    coach: <path d="M8 21v-2a4 4 0 0 1 4-4h0a4 4 0 0 1 4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"></path>,
    integ: <path d="M9 3v4M15 3v4M7 7h10v5a5 5 0 0 1-10 0V7zM12 17v4"></path>,
    look: <path d="M12 3a9 9 0 1 0 9 9c0-1-1-2-2-2h-2a3 3 0 0 1-3-3V5c0-1-1-2-2-2z"></path>,
    lock: <path d="M5 11h14v9H5zM8 11V7a4 4 0 0 1 8 0v4"></path>,
  };
  return (
    <div style={{ width: 36, height: 36, borderRadius: 12, flexShrink: 0, background: active ? B_GRAD(T) : T.card2, border: active ? 'none' : `1px solid ${T.cardBorder}`, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
      <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke={c} strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
        {map[ic] || map.profile}
        {ic === 'profile' && <path d="M5 21a7 7 0 0 1 14 0"></path>}
        {ic === 'bell' && <path d="M13.7 21a2 2 0 0 1-3.4 0"></path>}
      </svg>
    </div>
  );
}

function BToggle({ T, on }) {
  return (
    <div style={{ width: 42, height: 24, borderRadius: 99, flexShrink: 0, background: on ? B_GRAD(T) : T.track, position: 'relative', cursor: 'pointer', transition: 'background .2s' }}>
      <div style={{ position: 'absolute', top: 3, left: on ? 21 : 3, width: 18, height: 18, borderRadius: 99, background: '#fff', boxShadow: '0 1px 4px rgba(0,0,0,0.3)', transition: 'left .2s' }}></div>
    </div>
  );
}

function BProviderRow({ T, p, dense = false }) {
  const on = p.state === 'on';
  return (
    <div className="r3b-card r3b-hover" style={bCard(T, { padding: dense ? '11px 14px' : '13px 16px', display: 'flex', alignItems: 'center', gap: 12, border: on ? `1px solid ${T.cardBorder}` : `1px dashed ${T.cardBorder}`, opacity: on ? 1 : 0.75 })}>
      <div style={{ width: 36, height: 36, borderRadius: 10, flexShrink: 0, background: p.mark, display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: B_DISP, fontSize: 13, fontWeight: 700, color: '#fff' }}>
        {p.name[0]}
      </div>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 7 }}>
          <span style={{ fontSize: 13.5, fontWeight: 700, color: T.ink }}>{p.name}</span>
          {on && <div style={{ width: 7, height: 7, borderRadius: 99, background: T.good }}></div>}
        </div>
        <div style={{ fontSize: 11, color: T.sub, marginTop: 2 }}>{on ? p.sync : p.detail}</div>
      </div>
      {on
        ? <div className="r3b-btn" style={{ ...bLabel(T, { fontSize: 8.5 }), border: `1px solid ${T.cardBorder}`, borderRadius: 99, padding: '5px 11px' }}>отключить</div>
        : <div className="r3b-btn" style={{ ...bLabel(T, { fontSize: 8.5, color: '#fff' }), background: B_GRAD(T), borderRadius: 99, padding: '5px 13px' }}>подключить</div>}
    </div>
  );
}

// ---------- Настройки · мобайл (категории) ----------
function BSettingsMobile({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, display: 'flex', flexDirection: 'column', fontFamily: B_BODY, overflow: 'hidden' }}>
      <BStyle T={T} /><BDefs />
      <div style={{ padding: '16px 20px 8px', flexShrink: 0 }}>
        <div style={{ fontFamily: B_DISP, fontSize: 17, fontWeight: 700, color: T.ink }}>Настройки</div>
      </div>
      {/* карточка пользователя */}
      <div className="r3b-card" style={bCard(T, { margin: '4px 16px 10px', padding: '14px 16px', display: 'flex', alignItems: 'center', gap: 13, flexShrink: 0 })}>
        <div style={{ width: 48, height: 48, borderRadius: 99, background: B_GRAD(T), display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: B_DISP, fontSize: 15, fontWeight: 700, color: '#fff' }}>ИП</div>
        <div style={{ flex: 1 }}>
          <div style={{ fontSize: 15, fontWeight: 700, color: T.ink }}>Иван Петров</div>
          <div style={{ fontSize: 11.5, color: T.sub, marginTop: 2 }}>@ivanrun · режим: AI-тренер</div>
        </div>
        <div style={{ ...bLabel(T, { fontSize: 8.5, color: T.accent }), border: `1px solid ${T.accent}`, borderRadius: 99, padding: '5px 11px' }}>PRO</div>
      </div>
      {/* категории */}
      <div style={{ flex: 1, minHeight: 0, padding: '0 16px', display: 'flex', flexDirection: 'column', gap: 7 }}>
        {BSET_CATS.map((c, i) => (
          <div key={i} className="r3b-card r3b-hover" onClick={() => { if (c.on) prNav('integrations'); }} style={bCard(T, { padding: '11px 14px', display: 'flex', alignItems: 'center', gap: 12, flex: 1, minHeight: 0, border: c.on ? `1.5px solid ${T.accent}` : `1px solid ${T.cardBorder}` })}>
            <BSetIcon T={T} ic={c.ic} active={c.on} />
            <div style={{ flex: 1, minWidth: 0 }}>
              <div style={{ fontSize: 13.5, fontWeight: 700, color: T.ink }}>{c.title}</div>
              <div style={{ fontSize: 11, color: T.sub, marginTop: 2 }}>{c.sub}</div>
            </div>
            {c.badge && <div style={{ ...bLabel(T, { fontSize: 8.5, color: T.good }), border: `1px solid ${T.good}`, borderRadius: 99, padding: '4px 9px' }}>{c.badge} активны</div>}
            <div style={{ transform: 'rotate(0deg)', opacity: 0.5 }}>{R3Icon.arrow(T.sub, 14)}</div>
          </div>
        ))}
      </div>
      <div style={{ padding: '10px 16px 12px', flexShrink: 0, display: 'flex', gap: 8 }}>
        <div className="r3b-btn" onClick={() => prNav('landing')} style={{ flex: 1, border: `1px solid ${T.cardBorder}`, background: T.card, color: T.bad, borderRadius: 14, padding: '12px 16px', fontSize: 13, fontWeight: 700, textAlign: 'center' }}>Выйти</div>
      </div>
      <BNav T={T} active="profile" />
    </div>
  );
}

// ---------- Настройки · мобайл (интеграции) ----------
function BSettingsIntegMobile({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, display: 'flex', flexDirection: 'column', fontFamily: B_BODY, overflow: 'hidden' }}>
      <BStyle T={T} /><BDefs />
      <div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '14px 18px 10px', flexShrink: 0 }}>
        <div className="r3b-btn" onClick={() => prNav('back')} style={{ width: 32, height: 32, borderRadius: 99, border: `1px solid ${T.cardBorder}`, background: T.card, display: 'flex', alignItems: 'center', justifyContent: 'center', transform: 'rotate(180deg)' }}>
          {R3Icon.arrow(T.ink, 15)}
        </div>
        <div>
          <div style={{ fontFamily: B_DISP, fontSize: 16, fontWeight: 700, color: T.ink }}>Интеграции</div>
          <div style={bLabel(T, { fontSize: 8.5, color: T.good })}>3 подключены · авто-синк включён</div>
        </div>
      </div>
      <div style={{ flex: 1, minHeight: 0, padding: '4px 16px 0', display: 'flex', flexDirection: 'column', gap: 8 }}>
        {BSET_PROVIDERS.map((p, i) => <BProviderRow key={i} T={T} p={p} dense />)}
      </div>
      <div className="r3b-card" style={bCard(T, { margin: '10px 16px 14px', padding: '12px 16px', display: 'flex', alignItems: 'center', gap: 12, flexShrink: 0, background: T.card2 })}>
        <div style={{ flex: 1 }}>
          <div style={{ fontSize: 13, fontWeight: 700, color: T.ink }}>Авто-синхронизация</div>
          <div style={{ fontSize: 11, color: T.sub, marginTop: 2 }}>Подтягивать тренировки сразу после завершения</div>
        </div>
        <BToggle T={T} on={true} />
      </div>
    </div>
  );
}

// ---------- Настройки · десктоп ----------
function BSettingsDesktop({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, display: 'flex', flexDirection: 'column', fontFamily: B_BODY, overflow: 'hidden' }}>
      <BStyle T={T} /><BDefs />
      <div style={{ display: 'flex', alignItems: 'center', gap: 26, padding: '0 36px', height: 64, flexShrink: 0 }}>
        <BLogo T={T} size={17} />
        <div className="r3b-card" style={{ ...bCard(T, { borderRadius: 99, padding: '5px 6px' }), display: 'flex', gap: 2 }}>
          {['Сегодня', 'План', 'Данные', 'AI-тренер'].map((x, i) => (
            <div key={i} onClick={() => prNav(['home', 'cal', 'stats', 'chat'][i])} style={{ fontSize: 13, fontWeight: 600, padding: '7px 16px', borderRadius: 99, color: T.sub, cursor: 'pointer' }}>{x}</div>
          ))}
        </div>
        <div style={{ flex: 1 }}></div>
        <div style={{ fontFamily: B_DISP, fontSize: 16, fontWeight: 700, color: T.ink }}>Настройки</div>
      </div>

      <div style={{ flex: 1, minHeight: 0, display: 'grid', gridTemplateColumns: '300px 1fr', gap: 16, padding: '2px 36px 24px' }}>
        {/* rail */}
        <div className="r3b-card" style={bCard(T, { display: 'flex', flexDirection: 'column', overflow: 'hidden' })}>
          <div style={{ padding: '16px 18px', display: 'flex', alignItems: 'center', gap: 12, borderBottom: `1px solid ${T.line}` }}>
            <div style={{ width: 42, height: 42, borderRadius: 99, background: B_GRAD(T), display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: B_DISP, fontSize: 13, fontWeight: 700, color: '#fff' }}>ИП</div>
            <div>
              <div style={{ fontSize: 14, fontWeight: 700, color: T.ink }}>Иван Петров</div>
              <div style={{ fontSize: 11, color: T.sub }}>@ivanrun · AI-режим</div>
            </div>
          </div>
          <div style={{ flex: 1, padding: '8px 10px', display: 'flex', flexDirection: 'column', gap: 2 }}>
            {BSET_CATS.map((c, i) => (
              <div key={i} className="r3b-btn" style={{ display: 'flex', alignItems: 'center', gap: 11, padding: '9px 10px', borderRadius: 12, background: c.on ? T.card2 : 'transparent', border: c.on ? `1px solid ${T.cardBorder}` : '1px solid transparent' }}>
                <BSetIcon T={T} ic={c.ic} active={c.on} />
                <div style={{ flex: 1 }}>
                  <div style={{ fontSize: 13, fontWeight: 700, color: c.on ? T.ink : T.sub }}>{c.title}</div>
                </div>
                {c.on && <div style={{ width: 6, height: 6, borderRadius: 99, background: T.accent }}></div>}
              </div>
            ))}
          </div>
          <div style={{ padding: '12px 18px', borderTop: `1px solid ${T.line}` }}>
            <div className="r3b-btn" style={{ fontSize: 12.5, fontWeight: 700, color: T.bad }}>Выйти из аккаунта</div>
          </div>
        </div>

        {/* контент: интеграции */}
        <div className="r3b-card" style={bCard(T, { display: 'flex', flexDirection: 'column', minHeight: 0, padding: '22px 28px' })}>
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 6 }}>
            <div>
              <div style={{ fontFamily: B_DISP, fontSize: 19, fontWeight: 700, color: T.ink }}>Интеграции</div>
              <div style={{ fontSize: 12.5, color: T.sub, marginTop: 3 }}>Тренировки подтягиваются автоматически после завершения.</div>
            </div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
              <span style={{ fontSize: 12.5, fontWeight: 600, color: T.ink }}>Авто-синк</span>
              <BToggle T={T} on={true} />
            </div>
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10, marginTop: 14, alignContent: 'start', flex: 1 }}>
            {BSET_PROVIDERS.map((p, i) => <BProviderRow key={i} T={T} p={p} />)}
            <div className="r3b-card" style={bCard(T, { padding: '13px 16px', border: `1px dashed ${T.accent}`, display: 'flex', alignItems: 'center', gap: 12, background: 'transparent' })}>
              <div style={{ width: 36, height: 36, borderRadius: 10, border: `1.5px dashed ${T.accent}`, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>{R3Icon.plus(T.accent, 15)}</div>
              <div>
                <div style={{ fontSize: 13.5, fontWeight: 700, color: T.ink }}>Загрузить файл</div>
                <div style={{ fontSize: 11, color: T.sub, marginTop: 2 }}>GPX / TCX / FIT вручную</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

Object.assign(window, { BSettingsMobile, BSettingsIntegMobile, BSettingsDesktop, BToggle, BProviderRow, BSetIcon });
