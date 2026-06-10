/**
 * Карточка «Тренировка дня» v3B (мок BRunnerMobile/BRunnerDesktop).
 * Данные/парсинг перенесены из TodayHeroV3: описание дня → км/темп/время/сегменты.
 */

import { useMemo } from 'react';
import { PrIcon, PrLabel, PrLiveDot, PrButton } from '../../ui';
import { buildRunSegments, suggestPaceByType, estimateTimeMin } from '../../Calendar/v3/calV3';
import { IntervalBar } from './parts';
import { parseDescription, formatKm, TYPE_LABELS, TYPE_TITLE, formatHeaderDate } from './dashData';

const NON_RUNNING = ['other', 'sbu', 'rest', 'walking', 'free'];

function useWorkoutModel(workout, plan) {
  const type = workout?.type || workout?.planDays?.[0]?.type;
  const description = workout?.text || workout?.description || workout?.planDays?.[0]?.description || '';
  const parsed = useMemo(() => parseDescription(description), [description]);

  const km = workout?.distance_km ?? workout?.distance ?? workout?.planDays?.[0]?.distance_km ?? parsed.km ?? null;
  const pace = workout?.pace ?? workout?.planDays?.[0]?.pace ?? parsed.pace ?? null;
  const dur = workout?.duration_minutes ?? workout?.duration_min ?? parsed.dur ?? null;
  const paceSuggested = pace ? null : suggestPaceByType(plan, type, km);
  const timeMin = dur ?? estimateTimeMin(km, pace || paceSuggested);

  const seg = useMemo(() => {
    const exercises = Array.isArray(workout?.exercises) ? workout.exercises : [];
    if (exercises.length >= 2) {
      const segs = exercises.slice(0, 14).map((ex) => ({
        type: ex.category === 'run' ? (ex.type || type || 'easy') : 'easy',
        w: Math.max(1, Number(ex.distance_m || ex.duration_sec || 1)),
      }));
      return { segs, caption: null };
    }
    return buildRunSegments({ type, text: description, km, pace });
  }, [workout, type, description, km, pace]);

  const isRunning = type && !NON_RUNNING.includes(type);
  const title = isRunning
    ? `${TYPE_TITLE[type] || TYPE_LABELS[type] || type} · ${parsed.intervals ? parsed.intervals.text : `${formatKm(km)} км`}`
    : (TYPE_LABELS[type] || parsed.title || 'Тренировка');

  return {
    type,
    description,
    parsed,
    km,
    pace: pace || paceSuggested,
    paceApprox: !pace && !!paceSuggested,
    timeMin,
    seg,
    isRunning,
    title,
    isKey: !!(workout?.is_key_workout || workout?.planDays?.[0]?.is_key_workout),
    completed: !!workout?.completed,
    actual: workout?.actual || null,
  };
}

function metaLabel(m) {
  if (m.completed && m.actual) {
    const parts = [
      m.actual.distance_km ? `${formatKm(m.actual.distance_km)} км` : null,
      m.actual.pace ? `${m.actual.pace}/км` : null,
      m.actual.avg_heart_rate ? `ЧСС ${m.actual.avg_heart_rate}` : null,
    ].filter(Boolean);
    return parts.join(' · ');
  }
  const parts = [
    m.timeMin ? `~${m.timeMin} мин` : null,
    m.pace ? `${m.paceApprox ? '≈' : ''}${m.pace}/км` : null,
  ].filter(Boolean);
  return parts.join(' · ');
}

/** Мобильная карточка тренировки дня. */
export function TodayCard({ workout, plan, onStart, onReschedule, onMarkDone }) {
  const m = useWorkoutModel(workout, plan);
  return (
    <div className="pr-card" style={{ padding: '16px 18px' }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
        <PrLabel size={9}>
          Тренировка дня{m.isKey && <span style={{ color: 'var(--pr-accent)' }}> · ключевая</span>}
          {m.completed && <span style={{ color: 'var(--pr-good)' }}> · выполнена</span>}
        </PrLabel>
        <PrLabel size={9} color={m.completed ? 'var(--pr-good)' : 'var(--pr-accent)'}>{metaLabel(m)}</PrLabel>
      </div>
      <div style={{ fontFamily: 'var(--pr-font-display)', fontSize: 23, fontWeight: 700, color: 'var(--pr-ink)', marginBottom: m.seg || !m.isRunning ? 14 : 4 }}>
        {m.title}
      </div>
      {m.isRunning ? (
        <IntervalBar seg={m.seg} />
      ) : (
        m.description && (
          <div style={{ fontSize: 12.5, color: 'var(--pr-sub)', lineHeight: 1.5 }}>
            {m.description.split(/\r?\n/).slice(0, 4).map((line, i) => <div key={i}>{line}</div>)}
          </div>
        )
      )}
      {!m.completed && (
        <div style={{ display: 'flex', gap: 8, marginTop: 16 }}>
          <PrButton onClick={onStart} style={{ flex: 1 }}>Начать →</PrButton>
          <button
            type="button"
            className="pr-press"
            title="Перенести"
            onClick={onReschedule}
            style={{ width: 44, border: '1px solid var(--pr-card-border)', borderRadius: 14, background: 'var(--pr-card)', display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer' }}
          >
            {PrIcon.cal('var(--pr-sub)', 17)}
          </button>
          <button
            type="button"
            className="pr-press"
            title="Отметить выполненной"
            onClick={onMarkDone}
            style={{ width: 44, border: '1px solid var(--pr-card-border)', borderRadius: 14, background: 'var(--pr-card)', display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer' }}
          >
            {PrIcon.check('var(--pr-good)', 16)}
          </button>
        </div>
      )}
    </div>
  );
}

/** Десктопная hero-карточка «Сегодня» (центр BRunnerDesktop). */
export function TodayCardLarge({ workout, plan, briefing, onStart, onReschedule, onMarkDone }) {
  const m = useWorkoutModel(workout, plan);
  const [lead, tail] = m.isRunning ? m.title.split(' · ') : [m.title, null];
  const why = briefing || m.parsed.title || null;

  const steps = useMemo(() => {
    if (!m.seg?.caption) return null;
    const parts = m.seg.caption.split('→').map((s) => s.trim()).filter(Boolean);
    if (parts.length < 2) return null;
    const names = parts.length === 3 ? ['Разминка', 'Основной блок', 'Заминка'] : parts.map((_, i) => `Блок ${i + 1}`);
    return parts.slice(0, 3).map((p, i) => ({ n: String(i + 1).padStart(2, '0'), name: names[i], detail: p }));
  }, [m.seg]);

  return (
    <div className="pr-card" style={{ padding: '26px 30px', flex: 1.4, display: 'flex', flexDirection: 'column', justifyContent: 'space-between', gap: 18 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between' }}>
        <PrLiveDot label={m.completed ? 'тренировка выполнена' : 'тренировка дня'} color={m.completed ? 'var(--pr-good)' : 'var(--pr-accent)'} />
        <PrLabel size={9}>{formatHeaderDate()}</PrLabel>
      </div>
      <div>
        <div style={{ fontFamily: 'var(--pr-font-display)', fontSize: 38, fontWeight: 700, color: 'var(--pr-ink)', lineHeight: 1.1 }}>
          {lead}{tail && <> <span className="pr-grad-text">{tail}</span></>}
        </div>
        {why && (
          <div style={{ fontSize: 14, color: 'var(--pr-sub)', marginTop: 8, maxWidth: 460, lineHeight: 1.5, display: '-webkit-box', WebkitLineClamp: 3, WebkitBoxOrient: 'vertical', overflow: 'hidden' }}>
            {why}
          </div>
        )}
      </div>
      {steps ? (
        <div style={{ display: 'grid', gridTemplateColumns: `repeat(${steps.length},1fr)`, gap: 10 }}>
          {steps.map((s) => (
            <div key={s.n} style={{ background: 'var(--pr-card-2)', borderRadius: 14, padding: '12px 14px', border: '1px solid var(--pr-card-border)' }}>
              <PrLabel size={8.5} color="var(--pr-accent)">{s.n}</PrLabel>
              <div style={{ fontSize: 13.5, fontWeight: 700, color: 'var(--pr-ink)', margin: '3px 0 2px' }}>{s.name}</div>
              <div style={{ fontSize: 11.5, color: 'var(--pr-sub)' }}>{s.detail}</div>
            </div>
          ))}
        </div>
      ) : (
        !m.isRunning && m.description && (
          <div style={{ fontSize: 13, color: 'var(--pr-sub)', lineHeight: 1.55 }}>
            {m.description.split(/\r?\n/).slice(0, 5).map((line, i) => <div key={i}>{line}</div>)}
          </div>
        )
      )}
      {m.isRunning && <IntervalBar seg={m.seg} h={12} />}
      {!m.completed && (
        <div style={{ display: 'flex', gap: 10 }}>
          <PrButton onClick={onStart}>Начать тренировку →</PrButton>
          <PrButton variant="secondary" onClick={onReschedule}>Перенести</PrButton>
          <PrButton variant="secondary" onClick={onMarkDone} style={{ color: 'var(--pr-good)', display: 'flex', alignItems: 'center', gap: 7 }}>
            {PrIcon.check('var(--pr-good)', 14)} Выполнена
          </PrButton>
        </div>
      )}
    </div>
  );
}
