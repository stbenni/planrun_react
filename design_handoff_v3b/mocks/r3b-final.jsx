// ============================================================
// v3B · Финальный блок: заявка тренера, редактор страницы,
// группы, админка, восстановление пароля, политика.
// ============================================================

// ---------- Стать тренером (/trainers/apply) · десктоп ----------
function BApplyCoach({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  const steps = [
    { t: 'Специализация', st: 'done' },
    { t: 'Опыт и достижения', st: 'active' },
    { t: 'О себе и подход', st: 'wait' },
    { t: 'Сертификации и контакты', st: 'wait' },
    { t: 'Стоимость услуг', st: 'wait' },
  ];
  const specs = ['Марафон', 'Полумарафон', '5/10 км', 'Ультра', 'Трейл', 'Начинающие', 'Травмы и восстановление', 'Питание', 'Ментальные навыки'];
  const on = [0, 1, 5];
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, display: 'flex', flexDirection: 'column', fontFamily: B_BODY, overflow: 'hidden' }}>
      <BStyle T={T} /><BDefs />
      <div style={{ display: 'flex', alignItems: 'center', gap: 24, padding: '0 36px', height: 64, flexShrink: 0 }}>
        <BLogo T={T} size={17} />
        <div style={bLabel(T, { fontSize: 10, color: T.ink })}>Стать тренером</div>
        <div style={{ flex: 1 }}></div>
        <div style={{ display: 'flex', gap: 20 }}>
          {[['Шагов', '5'], ['Модерация', '1–2 дня']].map(([l, v], i) => (
            <div key={i} style={{ textAlign: 'right' }}>
              <div style={bLabel(T, { fontSize: 8.5 })}>{l}</div>
              <div style={{ fontFamily: B_DISP, fontSize: 15, fontWeight: 700, color: T.ink }}>{v}</div>
            </div>
          ))}
        </div>
      </div>

      <div style={{ flex: 1, minHeight: 0, display: 'grid', gridTemplateColumns: '320px 1fr', gap: 16, padding: '2px 36px 24px' }}>
        {/* journey */}
        <div className="r3b-card" style={bCard(T, { padding: '20px 22px', display: 'flex', flexDirection: 'column' })}>
          <div style={bLabel(T, { fontSize: 9, marginBottom: 4 })}>Заполнение профиля</div>
          <div style={{ fontFamily: B_DISP, fontSize: 17, fontWeight: 700, color: T.ink, marginBottom: 14 }}>2 из 5 · 40%</div>
          <div style={{ height: 6, borderRadius: 99, background: T.track, marginBottom: 18 }}>
            <div style={{ width: '40%', height: '100%', borderRadius: 99, background: B_GRAD(T), boxShadow: T.glow }}></div>
          </div>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 4, flex: 1 }}>
            {steps.map((s, i) => (
              <div key={i} className="r3b-btn" style={{ display: 'flex', alignItems: 'center', gap: 11, padding: '10px 12px', borderRadius: 12, background: s.st === 'active' ? T.card2 : 'transparent', border: s.st === 'active' ? `1px solid ${T.accent}` : '1px solid transparent', opacity: s.st === 'wait' ? 0.55 : 1 }}>
                <div style={{ width: 22, height: 22, borderRadius: 99, flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', background: s.st === 'done' ? T.good : s.st === 'active' ? B_GRAD(T) : T.track, color: '#fff', fontSize: 10, fontWeight: 800 }}>
                  {s.st === 'done' ? R3Icon.check('#fff', 10) : i + 1}
                </div>
                <span style={{ fontSize: 13, fontWeight: 700, color: s.st === 'wait' ? T.sub : T.ink }}>{s.t}</span>
              </div>
            ))}
          </div>
          <div className="r3b-card" style={bCard(T, { padding: '12px 14px', background: T.card2, marginTop: 12 })}>
            <div style={bLabel(T, { fontSize: 8.5, color: T.accent, marginBottom: 5 })}>Что важно</div>
            <div style={{ fontSize: 11.5, color: T.sub, lineHeight: 1.5 }}>Конкретика решает: «марафон 2:45» убеждает сильнее, чем «большой опыт».</div>
          </div>
        </div>

        {/* активный шаг */}
        <div className="r3b-card" style={bCard(T, { padding: '24px 30px', display: 'flex', flexDirection: 'column', minHeight: 0 })}>
          <div style={{ fontFamily: B_DISP, fontSize: 19, fontWeight: 700, color: T.ink }}>Опыт и достижения</div>
          <div style={{ fontSize: 12.5, color: T.sub, marginTop: 4, marginBottom: 18 }}>Это увидят бегуны в каталоге — заполняй как для будущего ученика.</div>
          <div style={{ display: 'grid', gridTemplateColumns: '180px 1fr', gap: 14, alignItems: 'start' }}>
            <BField T={T} label="Опыт (лет) *" value="12" />
            <BField T={T} label="Свои достижения как бегун" value="Марафон 2:38, КМС, финишер Boston Marathon 2023" />
          </div>
          <div style={{ marginTop: 14 }}>
            <BField T={T} label="Достижения учеников" placeholder="Например: 10 учеников финишировали марафон, 2 выполнили BQ" />
          </div>
          <div style={{ marginTop: 18 }}>
            <div style={bLabel(T, { fontSize: 9, marginBottom: 8 })}>Специализация · выбрано 3 (из шага 1)</div>
            <div style={{ display: 'flex', gap: 7, flexWrap: 'wrap' }}>
              {specs.map((s, i) => (
                <div key={i} className="r3b-btn" style={{ fontSize: 11.5, fontWeight: 700, padding: '7px 13px', borderRadius: 99, background: on.includes(i) ? B_GRAD(T) : T.card, color: on.includes(i) ? '#fff' : T.sub, border: on.includes(i) ? 'none' : `1px solid ${T.cardBorder}` }}>{s}</div>
              ))}
            </div>
          </div>
          <div style={{ flex: 1 }}></div>
          <div style={{ display: 'flex', gap: 10 }}>
            <div className="r3b-btn" style={{ border: `1px solid ${T.cardBorder}`, background: T.card, color: T.ink, borderRadius: 12, padding: '12px 20px', fontSize: 13, fontWeight: 600 }}>← Назад</div>
            <div style={{ flex: 1 }}></div>
            <div className="r3b-btn" style={{ background: B_GRAD(T), color: '#fff', borderRadius: 12, padding: '12px 26px', fontSize: 13, fontWeight: 700, boxShadow: T.glow }}>Дальше: о себе →</div>
          </div>
        </div>
      </div>
    </div>
  );
}

// ---------- Редактор страницы тренера (/trainers/page) · десктоп ----------
function BCoachPageEditor({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, display: 'flex', flexDirection: 'column', fontFamily: B_BODY, overflow: 'hidden' }}>
      <BStyle T={T} /><BDefs />
      <div style={{ display: 'flex', alignItems: 'center', gap: 24, padding: '0 36px', height: 64, flexShrink: 0 }}>
        <BLogo T={T} size={17} />
        <div style={bLabel(T, { fontSize: 10, color: T.ink })}>Моя страница · planrun.ru/sergey</div>
        <div style={{ flex: 1 }}></div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <span style={{ fontSize: 12.5, fontWeight: 600, color: T.ink }}>Видна в каталоге</span>
          <BToggle T={T} on={true} />
        </div>
        <div className="r3b-btn" style={{ background: B_GRAD(T), color: '#fff', borderRadius: 12, padding: '10px 20px', fontSize: 13, fontWeight: 700, boxShadow: T.glow }}>Сохранить</div>
      </div>

      <div style={{ flex: 1, minHeight: 0, display: 'grid', gridTemplateColumns: '420px 1fr', gap: 16, padding: '2px 36px 24px' }}>
        {/* редактор */}
        <div className="r3b-card" style={bCard(T, { padding: '20px 24px', display: 'flex', flexDirection: 'column', gap: 13, minHeight: 0, overflow: 'hidden' })}>
          <div style={bLabel(T, { fontSize: 9 })}>Редактор</div>
          <BField T={T} label="Слоган" value="Бег должен встраиваться в жизнь, а не ломать её" />
          <BField T={T} label="О себе" value="Готовлю к марафонам и полумарафонам — от первого финиша до 2:50…" right={<span style={bLabel(T, { fontSize: 8, color: T.good })}>320/500</span>} />
          <div>
            <div style={bLabel(T, { fontSize: 9, marginBottom: 7 })}>Специализации</div>
            <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
              {['Марафон', 'Полумарафон', 'Подводка к старту', '+ добавить'].map((s, i) => (
                <div key={i} className="r3b-btn" style={{ fontSize: 11, fontWeight: 700, padding: '6px 12px', borderRadius: 99, background: i < 3 ? B_GRAD(T) : 'transparent', color: i < 3 ? '#fff' : T.accent, border: i < 3 ? 'none' : `1px dashed ${T.accent}` }}>{s}</div>
              ))}
            </div>
          </div>
          <div>
            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 7 }}>
              <div style={bLabel(T, { fontSize: 9 })}>Тарифы</div>
              <span style={bLabel(T, { fontSize: 8.5, color: T.accent })}>+ тариф</span>
            </div>
            {[['План + еженедельный разбор', '6 000 ₽ / мес'], ['Полное ведение + чат', '12 000 ₽ / мес'], ['Разовая консультация', '2 500 ₽']].map(([n, p], i) => (
              <div key={i} className="r3b-card" style={bCard(T, { padding: '10px 13px', display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: i ? 6 : 0, background: T.card2 })}>
                <span style={{ fontSize: 12.5, fontWeight: 600, color: T.ink }}>{n}</span>
                <span style={{ fontFamily: B_DISP, fontSize: 12, fontWeight: 700, color: T.accent, whiteSpace: 'nowrap' }}>{p}</span>
              </div>
            ))}
          </div>
          <div className="r3b-card" style={bCard(T, { padding: '11px 14px', display: 'flex', alignItems: 'center', gap: 11, background: T.card2 })}>
            <div style={{ flex: 1 }}>
              <div style={{ fontSize: 12.5, fontWeight: 700, color: T.ink }}>Принимаю новых учеников</div>
              <div style={{ fontSize: 10.5, color: T.sub, marginTop: 1 }}>8 из 10 мест занято</div>
            </div>
            <BToggle T={T} on={true} />
          </div>
        </div>

        {/* живой превью */}
        <div style={{ display: 'flex', flexDirection: 'column', minHeight: 0, gap: 8 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <BLive T={T} label="живой предпросмотр" />
            <div style={{ flex: 1 }}></div>
            <div style={bLabel(T, { fontSize: 8.5 })}>так страницу видят бегуны</div>
          </div>
          <div className="r3b-card" style={bCard(T, { flex: 1, minHeight: 0, overflow: 'hidden', display: 'flex', flexDirection: 'column' })}>
            <div style={{ height: 110, flexShrink: 0, position: 'relative', background: `linear-gradient(120deg, ${T.accent}33 0%, ${T.accent2}22 60%, transparent 100%), ${T.bgFlat}` }}>
              <div style={{ position: 'absolute', left: 32, bottom: -30, width: 80, height: 80, borderRadius: 99, background: B_GRAD(T), border: `3px solid ${T.bgFlat}`, display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: B_DISP, fontSize: 21, fontWeight: 700, color: '#fff' }}>СК</div>
            </div>
            <div style={{ padding: '40px 32px 20px', flex: 1, minHeight: 0 }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 9 }}>
                <span style={{ fontFamily: B_DISP, fontSize: 20, fontWeight: 700, color: T.ink }}>Сергей Климов</span>
                <div style={{ width: 8, height: 8, borderRadius: 99, background: T.good }}></div>
              </div>
              <div style={{ fontSize: 12.5, color: T.sub, marginTop: 3 }}>Тренер по бегу · КМС · 12 лет · ★ 4.9 (31 отзыв)</div>
              <div style={{ fontSize: 13, color: T.ink, fontStyle: 'italic', margin: '12px 0', opacity: 0.85 }}>«Бег должен встраиваться в жизнь, а не ломать её»</div>
              <div style={{ display: 'flex', gap: 6 }}>
                {['Марафон', 'Полумарафон', 'Подводка к старту'].map((s, i) => (
                  <div key={i} style={{ fontSize: 10, fontWeight: 700, padding: '4px 11px', borderRadius: 99, background: T.card2, border: `1px solid ${T.cardBorder}`, color: T.sub }}>{s}</div>
                ))}
              </div>
              <div style={{ display: 'flex', gap: 10, marginTop: 18 }}>
                <div className="r3b-btn" style={{ background: B_GRAD(T), color: '#fff', borderRadius: 12, padding: '11px 20px', fontSize: 12.5, fontWeight: 700, boxShadow: T.glow }}>Запросить тренера →</div>
                <div className="r3b-btn" style={{ border: `1px solid ${T.cardBorder}`, background: T.card, color: T.ink, borderRadius: 12, padding: '11px 16px', fontSize: 12.5, fontWeight: 600 }}>Написать</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

// ---------- Группы тренера · десктоп ----------
function BCoachGroups({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  const groups = [
    { name: 'Марафон · осень', n: 5, compl: 89, on: true },
    { name: 'Группа 5–10 км', n: 3, compl: 76 },
    { name: 'Восстановление', n: 2, compl: 94 },
  ];
  const members = R3.athletes.slice(0, 5);
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, display: 'flex', flexDirection: 'column', fontFamily: B_BODY, overflow: 'hidden' }}>
      <BStyle T={T} /><BDefs />
      <div style={{ display: 'flex', alignItems: 'center', gap: 24, padding: '0 36px', height: 64, flexShrink: 0 }}>
        <BLogo T={T} size={17} />
        <div style={bLabel(T, { fontSize: 10, color: T.ink })}>Группы и заявки</div>
        <div style={{ flex: 1 }}></div>
        <div className="r3b-btn" style={{ background: B_GRAD(T), color: '#fff', borderRadius: 12, padding: '9px 18px', fontSize: 13, fontWeight: 700, display: 'flex', alignItems: 'center', gap: 7, boxShadow: T.glow }}>
          {R3Icon.plus('#fff', 14)} Новая группа
        </div>
      </div>

      <div style={{ flex: 1, minHeight: 0, display: 'grid', gridTemplateColumns: '300px 1fr 340px', gap: 14, padding: '2px 36px 24px' }}>
        {/* группы */}
        <div className="r3b-card" style={bCard(T, { display: 'flex', flexDirection: 'column', overflow: 'hidden' })}>
          <div style={{ padding: '14px 18px 10px', borderBottom: `1px solid ${T.line}`, ...bLabel(T, { fontSize: 9 }) }}>Группы · 3</div>
          {groups.map((g, i) => (
            <div key={i} className="r3b-btn" style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '14px 18px', background: g.on ? T.card2 : 'transparent', borderLeft: g.on ? `3px solid ${T.accent}` : '3px solid transparent' }}>
              <R3Ring pct={g.compl / 100} size={36} stroke={4} color="url(#r3b-grad)" track={T.track} round={false}>
                <span style={{ fontSize: 9, fontWeight: 800, color: T.ink }}>{g.n}</span>
              </R3Ring>
              <div style={{ flex: 1 }}>
                <div style={{ fontSize: 13, fontWeight: 700, color: T.ink }}>{g.name}</div>
                <div style={{ fontSize: 10.5, color: T.sub, marginTop: 2 }}>{g.n} атлетов · выполнение {g.compl}%</div>
              </div>
            </div>
          ))}
        </div>

        {/* детали группы */}
        <div className="r3b-card" style={bCard(T, { display: 'flex', flexDirection: 'column', minHeight: 0, padding: '18px 24px' })}>
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 4 }}>
            <div>
              <div style={{ fontFamily: B_DISP, fontSize: 18, fontWeight: 700, color: T.ink }}>Марафон · осень</div>
              <div style={{ fontSize: 12, color: T.sub, marginTop: 3 }}>5 атлетов · общая цель: марафоны сентябрь–октябрь</div>
            </div>
            <div style={{ display: 'flex', gap: 8 }}>
              <div className="r3b-btn" style={{ fontSize: 12, fontWeight: 600, padding: '8px 14px', borderRadius: 10, border: `1px solid ${T.cardBorder}`, background: T.card2, color: T.ink }}>Сообщение группе</div>
              <div className="r3b-btn" style={{ fontSize: 12, fontWeight: 700, padding: '8px 14px', borderRadius: 10, background: B_GRAD(T), color: '#fff' }}>Назначить всем</div>
            </div>
          </div>
          <div style={{ display: 'flex', gap: 24, padding: '12px 0', borderBottom: `1px solid ${T.line}` }}>
            {[['Объём недели', '198 км'], ['Выполнение', '89%'], ['Средний VDOT', '50.2'], ['В риске', '1']].map(([l, v], i) => (
              <div key={i}>
                <div style={bLabel(T, { fontSize: 8.5 })}>{l}</div>
                <div style={{ fontFamily: B_DISP, fontSize: 18, fontWeight: 700, color: i === 3 ? T.bad : T.ink, marginTop: 2 }}>{v}</div>
              </div>
            ))}
          </div>
          <div style={{ flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column' }}>
            {members.map((a, i) => (
              <div key={a.id} className="r3b-cell" style={{ display: 'flex', alignItems: 'center', gap: 12, flex: 1, minHeight: 0, borderBottom: i < members.length - 1 ? `1px solid ${T.line}` : 'none' }}>
                <div style={{ width: 30, height: 30, borderRadius: 99, flexShrink: 0, background: a.risk ? T.bad : T.card2, border: a.risk ? 'none' : `1px solid ${T.cardBorder}`, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 10, fontWeight: 800, color: a.risk ? '#fff' : T.ink }}>{a.ini}</div>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ fontSize: 12.5, fontWeight: 700, color: T.ink }}>{a.name}</div>
                  <div style={{ fontSize: 10.5, color: T.sub, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{a.last}</div>
                </div>
                <R3Spark data={a.trend} w={56} h={16} color={a.risk ? T.bad : T.accent} sw={1.6} />
                <div style={{ fontFamily: B_DISP, fontSize: 13, fontWeight: 700, color: a.compl < 60 ? T.bad : T.ink, width: 40, textAlign: 'right' }}>{a.compl}%</div>
              </div>
            ))}
          </div>
        </div>

        {/* заявки */}
        <div className="r3b-card" style={bCard(T, { display: 'flex', flexDirection: 'column', minHeight: 0, overflow: 'hidden' })}>
          <div style={{ padding: '14px 18px 10px', borderBottom: `1px solid ${T.line}`, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <div style={bLabel(T, { fontSize: 9 })}>Заявки учеников</div>
            <div style={{ minWidth: 18, height: 18, borderRadius: 99, background: B_GRAD(T), color: '#fff', fontSize: 9.5, fontWeight: 800, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: '0 5px' }}>2</div>
          </div>
          {[
            { name: 'Игорь Савин', goal: 'Полумарафон из 1:45 · апрель', exp: 'Бегает 2 года · 35 км/нед', msg: '«Упёрся в плато, нужен взгляд со стороны»' },
            { name: 'Лена Крылова', goal: 'Первый марафон · октябрь', exp: 'Бегает 1 год · 25 км/нед', msg: '«Хочу финишировать без стены»' },
          ].map((ap, i) => (
            <div key={i} style={{ padding: '14px 18px', borderBottom: `1px solid ${T.line}` }}>
              <div style={{ fontSize: 13.5, fontWeight: 700, color: T.ink }}>{ap.name}</div>
              <div style={{ fontSize: 11.5, color: T.accent, fontWeight: 700, marginTop: 3 }}>{ap.goal}</div>
              <div style={{ fontSize: 11, color: T.sub, marginTop: 2 }}>{ap.exp}</div>
              <div style={{ fontSize: 11.5, color: T.ink, opacity: 0.8, marginTop: 6, fontStyle: 'italic' }}>{ap.msg}</div>
              <div style={{ display: 'flex', gap: 6, marginTop: 9 }}>
                <div className="r3b-btn" style={{ flex: 1, fontSize: 11, fontWeight: 700, padding: '7px 0', borderRadius: 9, background: B_GRAD(T), color: '#fff', textAlign: 'center' }}>Принять</div>
                <div className="r3b-btn" style={{ flex: 1, fontSize: 11, fontWeight: 600, padding: '7px 0', borderRadius: 9, border: `1px solid ${T.cardBorder}`, background: T.card2, color: T.ink, textAlign: 'center' }}>Написать</div>
                <div className="r3b-btn" style={{ fontSize: 11, fontWeight: 600, padding: '7px 12px', borderRadius: 9, border: `1px solid ${T.cardBorder}`, color: T.sub }}>✕</div>
              </div>
            </div>
          ))}
          <div style={{ flex: 1 }}></div>
        </div>
      </div>
    </div>
  );
}

Object.assign(window, { BApplyCoach, BCoachPageEditor, BCoachGroups });
