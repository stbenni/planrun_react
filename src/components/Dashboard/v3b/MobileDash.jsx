/**
 * Дашборд бегуна · мобайл (мок BRunnerMobile): шапка, кольцо готовности,
 * тренировка дня, метрики 2×2, неделя-точки. Иерархия вместо стопки виджетов.
 */

import { PrIcon, PrRing, PrLabel, PrLiveDot } from '../../ui';
import { ModeChip, DashBell, MetricTile, WeekDots } from './parts';
import { TodayCard } from './TodayCard';
import {
  greeting, formatHeaderDate, tsbStatus, readinessFromTsb, tsbAdvice,
  weeklyKmSeries, weeklyPaceSeries, stats30d,
} from './dashData';

export default function MobileDash({
  api, user, firstName, mode, streak, weekModel, trainingLoad, vdot,
  briefing, todayWorkout, hasAnyPlannedWorkout, plan, workoutsByDate,
  onModeClick, onStart, onOpenCalendar, onOpenStats,
}) {
  const tsb = trainingLoad?.available ? Math.round(trainingLoad.current.tsb) : null;
  const readiness = readinessFromTsb(tsb);
  const status = tsb != null ? tsbStatus(tsb) : null;
  const kmSpark = weeklyKmSeries(workoutsByDate);
  const paceSpark = weeklyPaceSeries(workoutsByDate);
  const s30 = stats30d(workoutsByDate);
  const trimpDaily = trainingLoad?.available ? (trainingLoad.daily || []).slice(-14).map((d) => Number(d.trimp) || 0) : null;
  const trimp7d = trainingLoad?.available
    ? Math.round((trainingLoad.daily || []).slice(-7).reduce((s, d) => s + (Number(d.trimp) || 0), 0))
    : null;

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 0 }}>
      {/* шапка */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '16px 20px 8px' }}>
        <div style={{ width: 38, height: 38, borderRadius: 999, background: 'var(--pr-grad)', display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: 'var(--pr-font-display)', fontSize: 12, fontWeight: 700, color: '#fff', flexShrink: 0 }}>
          {(firstName || 'Б')[0].toUpperCase()}
        </div>
        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
            <div style={{ fontSize: 14.5, fontWeight: 600, color: 'var(--pr-ink)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
              {greeting()}{firstName ? `, ${firstName}` : ''}
            </div>
            <ModeChip mode={mode} onClick={onModeClick} />
          </div>
          <PrLabel size={9} style={{ marginTop: 2 }}>
            {formatHeaderDate()}{weekModel?.weekNumber ? ` · Неделя ${weekModel.weekNumber}` : ''}
          </PrLabel>
        </div>
        {streak > 1 && (
          <div style={{ display: 'flex', alignItems: 'center', gap: 5 }}>
            {PrIcon.flame('var(--pr-accent)', 15)}
            <span style={{ fontFamily: 'var(--pr-font-display)', fontSize: 13, fontWeight: 700, color: 'var(--pr-ink)' }}>{streak}</span>
          </div>
        )}
        <div style={{ marginLeft: 2 }}>
          <DashBell api={api} />
        </div>
      </div>

      {/* готовность */}
      <div
        style={{ display: 'flex', alignItems: 'center', gap: 18, padding: '8px 20px', cursor: 'pointer' }}
        onClick={onOpenStats}
      >
        <PrRing pct={readiness != null ? readiness / 100 : 0} size={128} stroke={11}>
          <div style={{ fontFamily: 'var(--pr-font-display)', fontSize: 34, fontWeight: 700, color: 'var(--pr-ink)', lineHeight: 1 }}>
            {readiness != null ? readiness : '—'}
          </div>
          <PrLabel size={8}>готовность</PrLabel>
        </PrRing>
        <div style={{ flex: 1, display: 'flex', flexDirection: 'column', gap: 8, minWidth: 0 }}>
          {status
            ? <PrLiveDot label={status.label.toLowerCase()} color={status.color === 'var(--pr-ink)' ? 'var(--pr-accent)' : status.color} />
            : <PrLabel size={9}>нет данных</PrLabel>}
          <div style={{ fontSize: 13.5, fontWeight: 500, color: 'var(--pr-ink)', lineHeight: 1.45, display: '-webkit-box', WebkitLineClamp: 3, WebkitBoxOrient: 'vertical', overflow: 'hidden' }}>
            {briefing || (tsb != null ? tsbAdvice(tsb) : 'Подключи часы или добавляй тренировки — посчитаем форму и готовность.')}
          </div>
        </div>
      </div>

      {/* тренировка дня */}
      <div style={{ padding: '8px 16px 0' }}>
        {todayWorkout ? (
          <TodayCard
            workout={todayWorkout}
            plan={plan}
            onStart={onStart}
            onReschedule={onStart}
            onMarkDone={onStart}
          />
        ) : (
          <div className="pr-card" style={{ padding: '16px 18px' }}>
            <PrLabel size={9}>Тренировка дня</PrLabel>
            <div style={{ fontFamily: 'var(--pr-font-display)', fontSize: 23, fontWeight: 700, color: 'var(--pr-ink)', margin: '8px 0 4px' }}>
              {hasAnyPlannedWorkout ? 'Отдых' : 'План пуст'}
            </div>
            <div style={{ fontSize: 12.5, color: 'var(--pr-sub)', lineHeight: 1.5 }}>
              {hasAnyPlannedWorkout
                ? 'День восстановления: полный отдых или лёгкая активность.'
                : 'Добавь тренировки в календарь — они появятся здесь.'}
            </div>
            {!hasAnyPlannedWorkout && (
              <button
                type="button"
                className="pr-btn-secondary"
                onClick={onOpenCalendar}
                style={{ marginTop: 12 }}
              >
                Открыть календарь →
              </button>
            )}
          </div>
        )}
      </div>

      {/* метрики 2×2 */}
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10, padding: '10px 16px 0' }}>
        <MetricTile label="VDOT" value={vdot != null ? vdot : '—'} onClick={onOpenStats} />
        <MetricTile
          label="Объём недели"
          value={weekModel ? weekModel.doneKm : s30.km}
          unit={weekModel?.planKm ? `/ ${weekModel.planKm} км` : 'км'}
          spark={kmSpark}
          sparkColor="var(--pr-accent-2)"
          onClick={onOpenCalendar}
        />
        <MetricTile
          label="Нагрузка · 7 дней"
          value={trimp7d != null ? trimp7d : '—'}
          unit={trimp7d != null ? 'TRIMP' : ''}
          spark={trimpDaily && trimpDaily.filter((v) => v > 0).length >= 2 ? trimpDaily : null}
          sparkColor="var(--pr-good)"
          onClick={onOpenStats}
        />
        <MetricTile
          label="Средний темп"
          value={s30.pace || '—'}
          unit="/км"
          spark={paceSpark}
          onClick={onOpenStats}
        />
      </div>

      {/* неделя */}
      {weekModel && (
        <div style={{ padding: '14px 20px 12px', cursor: 'pointer' }} onClick={onOpenCalendar}>
          <WeekDots days={weekModel.days} />
        </div>
      )}
    </div>
  );
}
