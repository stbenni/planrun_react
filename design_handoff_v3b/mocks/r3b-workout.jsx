// ============================================================
// v3B · Разбор тренировки (мобайл) — карта, сплиты, зоны, AI.
// ============================================================

const BWD_SPLITS = [
  { km: 1, pace: '5:52', s: 352, z: 1 }, { km: 2, pace: '5:48', s: 348, z: 2 },
  { km: 3, pace: '4:38', s: 278, z: 3 }, { km: 4, pace: '4:35', s: 275, z: 3 },
  { km: 5, pace: '4:33', s: 273, z: 3 }, { km: 6, pace: '4:36', s: 276, z: 3 },
  { km: 7, pace: '4:31', s: 271, z: 4 }, { km: 8, pace: '4:34', s: 274, z: 3 },
  { km: 9, pace: '5:58', s: 358, z: 1 }, { km: 10, pace: '6:02', s: 362, z: 1 },
];
const BWD_ZONES = [
  { z: 'Z1', pct: 22, c: '#9AA5B4' }, { z: 'Z2', pct: 14, c: '#3DDC97' },
  { z: 'Z3', pct: 48, c: '#FF5A1F' }, { z: 'Z4', pct: 13, c: '#FF2D78' }, { z: 'Z5', pct: 3, c: '#FF5470' },
];

function BRouteMap({ T, h = 170 }) {
  return (
    <div style={{
      height: h, borderRadius: 18, overflow: 'hidden', position: 'relative', flexShrink: 0,
      background: T.bgFlat === '#0B0F16'
        ? 'linear-gradient(135deg, #121A26 0%, #0D131C 60%, #111B22 100%)'
        : 'linear-gradient(135deg, #DDE5EF 0%, #E8EDF4 60%, #DCE6E9 100%)',
      border: `1px solid ${T.cardBorder}`,
    }}>
      {/* стилизованные улицы */}
      <svg width="100%" height="100%" viewBox="0 0 358 170" preserveAspectRatio="none" style={{ position: 'absolute', inset: 0, opacity: 0.5 }}>
        {[[0, 40, 358, 36], [0, 92, 358, 100], [0, 140, 358, 132]].map((l, i) => (
          <line key={i} x1={l[0]} y1={l[1]} x2={l[2]} y2={l[3]} stroke={T.line} strokeWidth="5"></line>
        ))}
        {[[60, 0, 70, 170], [150, 0, 140, 170], [250, 0, 262, 170], [320, 0, 312, 170]].map((l, i) => (
          <line key={i} x1={l[0]} y1={l[1]} x2={l[2]} y2={l[3]} stroke={T.line} strokeWidth="4"></line>
        ))}
      </svg>
      {/* маршрут */}
      <svg width="100%" height="100%" viewBox="0 0 358 170" preserveAspectRatio="none" style={{ position: 'absolute', inset: 0 }}>
        <path d="M 40 130 C 80 110, 90 60, 140 56 S 230 80, 260 64 S 320 36, 326 58 C 330 76, 290 96, 250 100 S 160 120, 120 136 S 60 142, 40 130 Z"
          fill="none" stroke="url(#r3b-grad)" strokeWidth="3.5" strokeLinecap="round"></path>
        <circle cx="40" cy="130" r="6" fill={T.bgFlat} stroke="#3DDC97" strokeWidth="3"></circle>
        <circle cx="326" cy="58" r="6" fill={T.bgFlat} stroke="#FF2D78" strokeWidth="3"></circle>
      </svg>
      <div style={{ position: 'absolute', left: 12, bottom: 10, display: 'flex', gap: 6 }}>
        <div style={{ ...bLabel(T, { fontSize: 8.5, color: T.ink }), background: T.card2, backdropFilter: 'blur(8px)', border: `1px solid ${T.cardBorder}`, borderRadius: 99, padding: '4px 10px' }}>
          Парк Горького · 10,2 км
        </div>
      </div>
    </div>
  );
}

function BWorkoutDetails({ dark }) {
  const T = dark ? B_T.dark : B_T.light;
  const maxS = 370, minS = 260;
  return (
    <div style={{ width: '100%', height: '100%', background: T.bg, display: 'flex', flexDirection: 'column', fontFamily: B_BODY, overflow: 'hidden' }}>
      <BStyle T={T} /><BDefs />
      {/* шапка */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '14px 18px 10px', flexShrink: 0 }}>
        <div className="r3b-btn" onClick={() => prNav('back')} style={{ width: 32, height: 32, borderRadius: 99, border: `1px solid ${T.cardBorder}`, background: T.card, display: 'flex', alignItems: 'center', justifyContent: 'center', transform: 'rotate(180deg)' }}>
          {R3Icon.arrow(T.ink, 15)}
        </div>
        <div style={{ flex: 1 }}>
          <div style={{ fontFamily: B_DISP, fontSize: 16, fontWeight: 700, color: T.ink }}>Темповый · 10 км</div>
          <div style={bLabel(T, { fontSize: 8.5 })}>сегодня, 07:40 · garmin</div>
        </div>
        <div style={{ ...bLabel(T, { fontSize: 9, color: T.good }), border: `1px solid ${T.good}`, borderRadius: 99, padding: '5px 11px' }}>в плане</div>
      </div>

      <div style={{ padding: '0 16px' }}>
        <BRouteMap T={T} h={116} />
      </div>

      {/* ключевые метрики */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 0, padding: '12px 18px 4px', flexShrink: 0 }}>
        {[['10,2', 'км'], ['46:51', 'время'], ['4:34', '/км ср.'], ['158', 'чсс ср.']].map(([v, l], i) => (
          <div key={i} style={{ textAlign: 'center', borderLeft: i ? `1px solid ${T.line}` : 'none' }}>
            <div style={{ fontFamily: B_DISP, fontSize: 19, fontWeight: 700, color: T.ink }}>{v}</div>
            <div style={bLabel(T, { fontSize: 8 })}>{l}</div>
          </div>
        ))}
      </div>

      {/* план vs факт */}
      <div className="r3b-card" style={bCard(T, { margin: '8px 16px 0', padding: '11px 16px', flexShrink: 0, background: T.card2 })}>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 8 }}>
          {[['Дистанция', '10 км', '10,2', true], ['Темп блока', '4:30–4:40', '4:34', true], ['Пульс', 'Z3 · ≤164', '158', true]].map(([l, p, f, ok], i) => (
            <div key={i} style={{ borderLeft: i ? `1px solid ${T.line}` : 'none', paddingLeft: i ? 12 : 0 }}>
              <div style={bLabel(T, { fontSize: 7.5 })}>{l}</div>
              <div style={{ fontSize: 10, color: T.sub, marginTop: 3 }}>план {p}</div>
              <div style={{ display: 'flex', alignItems: 'center', gap: 5 }}>
                <span style={{ fontFamily: B_DISP, fontSize: 14, fontWeight: 700, color: T.ink }}>{f}</span>
                {ok && R3Icon.check(T.good, 11)}
              </div>
            </div>
          ))}
        </div>
      </div>

      {/* сплиты */}
      <div className="r3b-card" style={bCard(T, { margin: '8px 16px 0', padding: '13px 16px', flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column' })}>
        <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 8 }}>
          <div style={bLabel(T, { fontSize: 9 })}>Сплиты по километрам</div>
          <div style={bLabel(T, { fontSize: 9, color: T.accent })}>цель 4:30–4:40</div>
        </div>
        <div style={{ flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column', gap: 4 }}>
          {BWD_SPLITS.map((s, i) => {
            const w = 30 + ((maxS - s.s) / (maxS - minS)) * 70;
            const inTarget = s.s >= 270 && s.s <= 280;
            const c = s.z >= 4 ? T.accent2 : s.z === 3 ? T.accent : s.z === 2 ? T.good : T.sub;
            return (
              <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 8, flex: 1, minHeight: 0 }}>
                <div style={{ fontSize: 10, fontWeight: 700, color: T.sub, width: 16, textAlign: 'right' }}>{s.km}</div>
                <div style={{ flex: 1, height: '62%', maxHeight: 14, borderRadius: 99, background: T.track, overflow: 'hidden' }}>
                  <div style={{ width: `${w}%`, height: '100%', borderRadius: 99, background: c, opacity: inTarget ? 1 : 0.65 }}></div>
                </div>
                <div style={{ fontFamily: B_DISP, fontSize: 11.5, fontWeight: 700, color: inTarget ? T.ink : T.sub, width: 34 }}>{s.pace}</div>
              </div>
            );
          })}
        </div>
      </div>

      {/* зоны ЧСС */}
      <div className="r3b-card" style={bCard(T, { margin: '8px 16px 0', padding: '13px 16px', flexShrink: 0 })}>
        <div style={bLabel(T, { fontSize: 9, marginBottom: 9 })}>Время в зонах ЧСС</div>
        <div style={{ display: 'flex', gap: 3, height: 12 }}>
          {BWD_ZONES.map((z, i) => (
            <div key={i} style={{ width: `${z.pct}%`, borderRadius: 99, background: z.c, opacity: 0.92 }}></div>
          ))}
        </div>
        <div style={{ display: 'flex', gap: 3, marginTop: 6 }}>
          {BWD_ZONES.map((z, i) => (
            <div key={i} style={{ width: `${z.pct}%`, minWidth: 28, ...bLabel(T, { fontSize: 8 }) }}>{z.z} · {z.pct}%</div>
          ))}
        </div>
      </div>

      {/* AI-разбор */}
      <div className="r3b-card" style={bCard(T, { margin: '8px 16px 14px', padding: '13px 16px', flexShrink: 0, border: `1px solid ${T.accent}`, background: T.card2 })}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 7 }}>
          <BLive T={T} label="AI-разбор" />
        </div>
        <div style={{ fontSize: 12.5, color: T.ink, lineHeight: 1.55 }}>
          Отличная работа: 6 темповых км в коридоре, дрейф пульса всего 4%. Седьмой километр — чуть быстрее цели, на пике это будет стоить сил. Завтра — полное восстановление.
        </div>
        <div style={{ display: 'flex', gap: 6, marginTop: 10 }}>
          {['Обсудить в чате', 'Изменить результат'].map((x, j) => (
            <div key={j} className="r3b-btn" onClick={() => prNav(j ? 'result' : 'chat')} style={{ fontSize: 11, fontWeight: 700, padding: '7px 13px', borderRadius: 9, background: j ? 'transparent' : B_GRAD(T), color: j ? T.sub : '#fff', border: j ? `1px solid ${T.cardBorder}` : 'none' }}>{x}</div>
          ))}
        </div>
      </div>
    </div>
  );
}

Object.assign(window, { BWorkoutDetails });
