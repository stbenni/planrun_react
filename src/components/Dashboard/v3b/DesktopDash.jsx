/**
 * Дашборд бегуна · десктоп (мок BRunnerDesktop): верхняя пилюля-навигация,
 * 3 колонки — цель / сегодня / телеметрия.
 */

import { PrIcon, PrRing, PrLabel, PrLiveDot, PrLogo, PrSpark } from '../../ui';
import { ModeChip, DashBell, MetricTile, WeekDots } from './parts';
import { TodayCardLarge } from './TodayCard';
import {
  tsbStatus, readinessFromTsb, acwrLabel, weeklyPaceSeries, stats30d,
  daysToRace, formatRaceDate, computeDelta, weeksProgress, derivePhase,
  DIST_LABELS, USER_DIST_TO_KEY, weeklyKmSeries,
} from './dashData';

const NAV_TABS = [
  { id: 'home', label: 'Сегодня' },
  { id: 'calendar', label: 'План' },
  { id: 'stats', label: 'Данные' },
  { id: 'chat', label: 'AI-тренер' },
];

const ZONE_ROWS = [
  ['easy', 'Лёгкий', 'var(--pr-sub)'],
  ['marathon', 'Марафонский', 'var(--pr-good)'],
  ['threshold', 'Пороговый', 'var(--pr-accent)'],
  ['interval', 'Интервальный', 'var(--pr-accent-2)'],
  ['repetition', 'Повторный', 'var(--pr-bad)'],
];

function GoalCard({ user, plan, prediction }) {
  const raceDate = user?.race_date || user?.target_marathon_date;
  const distance = user?.race_distance;
  const targetTime = user?.race_target_time;
  const days = daysToRace(raceDate);
  const { weeksDone, weeksTotal } = weeksProgress(plan);
  const progress = weeksTotal > 0 ? Math.min(1, weeksDone / weeksTotal) : 0;
  const predFormatted = prediction?.predictions?.[USER_DIST_TO_KEY[distance]]?.formatted || null;
  const delta = predFormatted && targetTime ? computeDelta(targetTime, predFormatted) : null;

  if (!raceDate) {
    return (
      <div className="pr-card" style={{ padding: '22px', flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 10, textAlign: 'center' }}>
        <PrLabel size={9} style={{ alignSelf: 'flex-start' }}>Цель сезона</PrLabel>
        <div style={{ fontSize: 13, color: 'var(--pr-sub)', lineHeight: 1.5 }}>
          Цель не указана — задай дистанцию и дату в настройках тренировок.
        </div>
      </div>
    );
  }

  return (
    <div className="pr-card" style={{ padding: '22px 22px 18px', flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 10 }}>
      <PrLabel size={9} style={{ alignSelf: 'flex-start' }}>Цель сезона</PrLabel>
      <PrRing pct={progress} size={150} stroke={12}>
        <div style={{ fontFamily: 'var(--pr-font-display)', fontSize: 30, fontWeight: 700, color: 'var(--pr-ink)', lineHeight: 1 }}>
          {days != null ? days : '—'}
        </div>
        <PrLabel size={8}>дней</PrLabel>
      </PrRing>
      <div style={{ textAlign: 'center' }}>
        <div style={{ fontFamily: 'var(--pr-font-display)', fontSize: 15, fontWeight: 700, color: 'var(--pr-ink)' }}>
          {DIST_LABELS[distance] || distance || 'Забег'}
        </div>
        <div style={{ fontSize: 12.5, color: 'var(--pr-sub)', marginTop: 3 }}>
          {formatRaceDate(raceDate)}{targetTime ? ` · цель ${targetTime}` : ''}
        </div>
      </div>
      <div style={{ width: '100%', display: 'flex', justifyContent: 'space-between', borderTop: '1px solid var(--pr-line)', paddingTop: 12 }}>
        <div>
          <PrLabel size={8.5}>Прогноз</PrLabel>
          <div style={{ fontFamily: 'var(--pr-font-display)', fontSize: 16, fontWeight: 700, color: 'var(--pr-accent)' }}>
            {predFormatted || '—'}
            {delta && (
              <span style={{ fontSize: 10.5, fontFamily: 'var(--pr-font-body)', color: delta.faster ? 'var(--pr-good)' : 'var(--pr-bad)', marginLeft: 6 }}>
                {delta.text}
              </span>
            )}
          </div>
        </div>
        <div style={{ textAlign: 'right' }}>
          <PrLabel size={8.5}>Неделя плана</PrLabel>
          <div style={{ fontFamily: 'var(--pr-font-display)', fontSize: 16, fontWeight: 700, color: 'var(--pr-ink)' }}>
            {weeksTotal ? `${Math.min(weeksDone + 1, weeksTotal)} / ${weeksTotal}` : '—'}
          </div>
        </div>
      </div>
    </div>
  );
}

function FormCard({ trainingLoad, onOpenStats }) {
  if (!trainingLoad?.available) {
    return (
      <div className="pr-card" style={{ padding: '14px 16px' }}>
        <PrLabel size={9}>Форма · TSB</PrLabel>
        <div style={{ fontSize: 12, color: 'var(--pr-sub)', marginTop: 8, lineHeight: 1.5 }}>
          Нужно ≥7 дней с данными для расчёта формы.
        </div>
      </div>
    );
  }
  const { current, daily } = trainingLoad;
  const tsb = Math.round(current.tsb);
  const status = tsbStatus(tsb);
  const readiness = readinessFromTsb(tsb);
  const acwr = acwrLabel(current.acwr_status);
  const series = (daily || []).slice(-28);
  const tsbSpark = series.map((d) => Number(d.tsb) || 0);
  const todayTrimp = series.length ? Math.round(Number(series[series.length - 1].trimp) || 0) : 0;
  const trimp7d = Math.round(series.slice(-7).reduce((s, d) => s + (Number(d.trimp) || 0), 0));

  return (
    <div className="pr-card pr-hover" style={{ padding: '14px 16px', cursor: 'pointer' }} onClick={onOpenStats}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
        <PrLabel size={9}>Форма · TSB</PrLabel>
        <div style={{ fontSize: 11, fontWeight: 700, color: status.color === 'var(--pr-ink)' ? 'var(--pr-sub)' : status.color }}>
          {status.label} · {tsb >= 0 ? `+${tsb}` : tsb}
        </div>
      </div>
      <div style={{ display: 'flex', alignItems: 'flex-end', gap: 12 }}>
        <div style={{ fontFamily: 'var(--pr-font-display)', fontSize: 30, fontWeight: 700, color: 'var(--pr-ink)', lineHeight: 1 }}>
          {readiness}
        </div>
        <div style={{ flex: 1 }}>
          {tsbSpark.length >= 2 && <PrSpark data={tsbSpark} w={150} h={26} color="var(--pr-good)" />}
        </div>
      </div>
      <div style={{ display: 'flex', borderTop: '1px solid var(--pr-line)', marginTop: 10, paddingTop: 9 }}>
        {[
          ['ACWR', current.acwr != null ? Number(current.acwr).toFixed(1) : '—', acwr.text, acwr.color],
          ['TRIMP день', String(todayTrimp), '', 'var(--pr-ink)'],
          ['TRIMP · 7д', String(trimp7d), '', 'var(--pr-ink)'],
        ].map(([l, v, s, c], i) => (
          <div key={l} style={{ flex: 1, borderLeft: i ? '1px solid var(--pr-line)' : 'none', paddingLeft: i ? 12 : 0 }}>
            <PrLabel size={7.5}>{l}</PrLabel>
            <div style={{ fontFamily: 'var(--pr-font-display)', fontSize: 15, fontWeight: 700, color: i === 0 ? 'var(--pr-ink)' : c }}>{v}</div>
            {s && <div style={{ fontSize: 9, fontWeight: 600, color: c }}>{s}</div>}
          </div>
        ))}
      </div>
    </div>
  );
}

function PaceZonesCard({ paces }) {
  const rows = ZONE_ROWS.filter(([key]) => paces?.[key]);
  if (!rows.length) return null;
  return (
    <div className="pr-card pr-hover" style={{ padding: '14px 16px', flex: 1 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 10 }}>
        <PrLabel size={9}>Зоны темпа · из VDOT</PrLabel>
        <PrLabel size={8.5} color="var(--pr-accent)">обновляются сами</PrLabel>
      </div>
      {rows.map(([key, name, color], i) => (
        <div key={key} style={{ display: 'flex', alignItems: 'center', gap: 9, padding: '5px 0', borderTop: i ? '1px solid var(--pr-line)' : 'none' }}>
          <span style={{ width: 7, height: 7, borderRadius: 999, background: color, flexShrink: 0 }} />
          <span style={{ flex: 1, fontSize: 11.5, fontWeight: 600, color: 'var(--pr-sub)' }}>{name}</span>
          <span style={{ fontFamily: 'var(--pr-font-display)', fontSize: 12.5, fontWeight: 700, color: 'var(--pr-ink)' }}>
            {paces[key]}
            <span style={{ fontSize: 8.5, color: 'var(--pr-sub)', fontFamily: 'var(--pr-font-body)' }}> /км</span>
          </span>
        </div>
      ))}
    </div>
  );
}

function PrRecords({ records }) {
  if (!records?.length) return null;
  return (
    <div className="pr-card" style={{ padding: '16px 20px' }}>
      <PrLabel size={9} style={{ marginBottom: 10 }}>Личные рекорды</PrLabel>
      {records.map((p, i) => (
        <div key={p.dist} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '7px 0', borderTop: i ? '1px solid var(--pr-line)' : 'none' }}>
          <span style={{ fontSize: 13, fontWeight: 600, color: 'var(--pr-sub)' }}>
            {p.dist}{p.fresh && <span style={{ color: 'var(--pr-accent)', fontWeight: 700 }}> · новый</span>}
          </span>
          <span style={{ fontFamily: 'var(--pr-font-display)', fontSize: 14, fontWeight: 700, color: p.fresh ? 'var(--pr-accent)' : 'var(--pr-ink)' }}>{p.time}</span>
        </div>
      ))}
    </div>
  );
}

export default function DesktopDash({
  api, user, firstName, mode, weekModel, trainingLoad, prediction, records, syncedProvider,
  briefing, todayWorkout, hasAnyPlannedWorkout, plan, workoutsByDate,
  onModeClick, onStart, onNavigate, onOpenCalendar, onOpenStats,
}) {
  const vdot = prediction?.vdot != null ? prediction.vdot : null;
  const paces = prediction?.training_paces || null;
  const kmSpark = weeklyKmSeries(workoutsByDate);
  const paceSpark = weeklyPaceSeries(workoutsByDate);
  const s30 = stats30d(workoutsByDate);

  return (
    <div style={{ display: 'flex', flexDirection: 'column', flex: 1, minHeight: 0 }}>
      {/* верхняя панель */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 30, padding: '0 36px', height: 64, flexShrink: 0 }}>
        <PrLogo size={17} />
        <div className="pr-card" style={{ borderRadius: 999, padding: '5px 6px', display: 'flex', gap: 2 }}>
          {NAV_TABS.map((t, i) => (
            <button
              key={t.id}
              type="button"
              onClick={() => i !== 0 && onNavigate?.(t.id)}
              style={{
                fontFamily: 'var(--pr-font-body)',
                fontSize: 13,
                fontWeight: 600,
                padding: '7px 16px',
                borderRadius: 999,
                border: 'none',
                background: i === 0 ? 'var(--pr-grad)' : 'transparent',
                color: i === 0 ? '#fff' : 'var(--pr-sub)',
                cursor: 'pointer',
              }}
            >
              {t.label}
            </button>
          ))}
        </div>
        <div style={{ flex: 1 }} />
        {syncedProvider && <PrLiveDot label={`синхронизировано · ${syncedProvider}`} />}
        <ModeChip mode={mode} onClick={onModeClick} />
        <DashBell api={api} />
        <button
          type="button"
          onClick={() => onNavigate?.('settings')}
          style={{ width: 36, height: 36, borderRadius: 999, background: 'var(--pr-grad)', border: 'none', display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: 'var(--pr-font-display)', fontSize: 12, fontWeight: 700, color: '#fff', cursor: 'pointer' }}
        >
          {(firstName || 'Б')[0].toUpperCase()}
        </button>
      </div>

      <div style={{ flex: 1, minHeight: 0, display: 'grid', gridTemplateColumns: '320px minmax(0,1fr) 320px', gap: 16, padding: '4px 36px 24px' }}>
        {/* левая — цель */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16, minHeight: 0 }}>
          <GoalCard user={user} plan={plan} prediction={prediction} />
          <PrRecords records={records} />
        </div>

        {/* центр — сегодня */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16, minHeight: 0 }}>
          {todayWorkout ? (
            <TodayCardLarge
              workout={todayWorkout}
              plan={plan}
              briefing={briefing}
              onStart={onStart}
              onReschedule={onStart}
              onMarkDone={onStart}
            />
          ) : (
            <div className="pr-card" style={{ padding: '26px 30px', flex: 1.4, display: 'flex', flexDirection: 'column', justifyContent: 'center', gap: 10 }}>
              <PrLiveDot label="сегодня" />
              <div style={{ fontFamily: 'var(--pr-font-display)', fontSize: 38, fontWeight: 700, color: 'var(--pr-ink)' }}>
                {hasAnyPlannedWorkout ? 'Отдых' : 'План пуст'}
              </div>
              <div style={{ fontSize: 14, color: 'var(--pr-sub)', lineHeight: 1.5, maxWidth: 420 }}>
                {hasAnyPlannedWorkout
                  ? 'День восстановления: полный отдых или лёгкая активность.'
                  : 'Добавь тренировки в календарь — они появятся здесь.'}
              </div>
            </div>
          )}
          <div className="pr-card" style={{ padding: '16px 24px 14px', flexShrink: 0 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 10 }}>
              <PrLabel size={9}>
                {weekModel?.weekNumber ? `Неделя ${weekModel.weekNumber} · ` : ''}
                {weekModel ? `${weekModel.doneKm} из ${weekModel.planKm || '—'} км` : 'Неделя'}
              </PrLabel>
              {(() => {
                const { weeksDone, weeksTotal } = weeksProgress(plan);
                const phase = derivePhase(weeksDone, weeksTotal);
                return phase ? <PrLabel size={9} color="var(--pr-accent)">{phase} фаза</PrLabel> : null;
              })()}
            </div>
            <div style={{ cursor: 'pointer' }} onClick={onOpenCalendar}>
              <WeekDots days={weekModel?.days} />
            </div>
          </div>
        </div>

        {/* правая — телеметрия */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10, minHeight: 0 }}>
          <FormCard trainingLoad={trainingLoad} onOpenStats={onOpenStats} />
          <MetricTile label="VDOT" value={vdot != null ? vdot : '—'} onClick={onOpenStats} />
          <MetricTile
            label="Объём недели"
            value={weekModel ? weekModel.doneKm : s30.km}
            unit={weekModel?.planKm ? `/ ${weekModel.planKm} км` : 'км'}
            spark={kmSpark}
            sparkColor="var(--pr-accent-2)"
            onClick={onOpenCalendar}
          />
          <MetricTile label="Средний темп" value={s30.pace || '—'} unit="/км" spark={paceSpark} onClick={onOpenStats} />
          <PaceZonesCard paces={paces} />
        </div>
      </div>
    </div>
  );
}
