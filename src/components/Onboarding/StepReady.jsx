/**
 * Экран «План готов» (мок BObReady, фикс аудита №2): цифры плана + первая неделя + CTA.
 * Для self-режима — компактный вариант «Календарь готов» без AI-цифр.
 */

import { PrLabel, PrLiveDot, PrButton } from '../ui';
import { plural } from './obKit';

const DOW = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];

function goalTail(formData) {
  if (formData.goal_type === 'race' || formData.goal_type === 'time_improvement') {
    switch (formData.race_distance) {
      case 'marathon': return 'твоего марафона';
      case 'half': return 'твоего полумарафона';
      case '10k': return 'забега на 10 км';
      case '5k': return 'забега на 5 км';
      default: return 'твоего забега';
    }
  }
  return null;
}

function FirstWeekDots({ days }) {
  return (
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(7,1fr)', gap: 6 }}>
      {days.map((d, i) => (
        <div key={d.key} style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 5 }}>
          <div
            style={{
              width: 30,
              height: 30,
              borderRadius: 999,
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              background: d.rest ? 'transparent' : 'var(--pr-card-2)',
              border: d.rest ? '1.5px dashed var(--pr-line)' : '1.5px dashed var(--pr-sub)',
            }}
          >
            <span style={{ fontFamily: 'var(--pr-font-body)', fontSize: 11, fontWeight: 700, color: 'var(--pr-sub)' }}>
              {d.rest ? '·' : (d.km ?? '✓')}
            </span>
          </div>
          <PrLabel size={8.5}>{DOW[i]}</PrLabel>
        </div>
      ))}
    </div>
  );
}

export default function StepReady({ planMode, summary, formData, planMessage, onOpenCalendar }) {
  // self-режим: календарь без AI-плана
  if (!planMode) {
    return (
      <div style={{ flex: 1, display: 'flex', flexDirection: 'column' }}>
        <div style={{ flex: 1, display: 'flex', flexDirection: 'column', justifyContent: 'center', gap: 14, padding: '24px 2px' }}>
          <PrLiveDot label="календарь готов" />
          <div style={{ fontFamily: 'var(--pr-font-display)', fontSize: 30, fontWeight: 700, color: 'var(--pr-ink)', lineHeight: 1.15 }}>
            Календарь<br />в твоих руках.
          </div>
          <div style={{ fontSize: 13.5, color: 'var(--pr-sub)', lineHeight: 1.55 }}>
            {planMessage || 'Добавляй тренировки на любую дату, отмечай результаты — статистика и прогресс считаются автоматически.'}
          </div>
        </div>
        <div style={{ padding: '0 0 18px' }}>
          <PrButton onClick={onOpenCalendar} style={{ width: '100%', padding: '15px 18px', fontSize: 14.5 }}>
            Открыть календарь →
          </PrButton>
        </div>
      </div>
    );
  }

  const tail = goalTail(formData);
  const title = summary?.weeksTotal && tail
    ? <>{summary.weeksTotal} {plural(summary.weeksTotal, 'неделя', 'недели', 'недель')} до<br />{tail}.</>
    : summary?.weeksTotal
      ? <>Твой план на<br />{summary.weeksTotal} {plural(summary.weeksTotal, 'неделю', 'недели', 'недель')} готов.</>
      : <>Твой план готов.</>;

  const stats = [
    summary?.peakKm ? [String(summary.peakKm), 'км/нед пик', false] : null,
    summary?.daysPerWeek ? [String(summary.daysPerWeek), 'дней в нед', false] : null,
    formData.race_target_time
      ? [formData.race_target_time, 'цель', true]
      : formData.race_date
        ? [new Date(`${formData.race_date}T00:00:00`).toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' }), 'забег', true]
        : null,
  ].filter(Boolean);

  return (
    <div style={{ flex: 1, display: 'flex', flexDirection: 'column' }}>
      <div style={{ flex: 1, display: 'flex', flexDirection: 'column', justifyContent: 'center', gap: 14, padding: '24px 2px' }}>
        <PrLiveDot label="план готов" />
        <div style={{ fontFamily: 'var(--pr-font-display)', fontSize: 30, fontWeight: 700, color: 'var(--pr-ink)', lineHeight: 1.15 }}>
          {title}
        </div>
        <div style={{ fontSize: 13.5, color: 'var(--pr-sub)', lineHeight: 1.55 }}>
          {summary?.workouts
            ? `${summary.workouts} ${plural(summary.workouts, 'тренировка', 'тренировки', 'тренировок')}${summary.peakKm ? ` · пик ${summary.peakKm} км/нед` : ''}. `
            : ''}
          План будет адаптироваться под каждую твою тренировку.
        </div>

        {stats.length > 0 && (
          <div style={{ display: 'grid', gridTemplateColumns: `repeat(${stats.length},1fr)`, gap: 8, marginTop: 6 }}>
            {stats.map(([v, l, accent]) => (
              <div key={l} className="pr-card" style={{ padding: '12px 10px', textAlign: 'center' }}>
                <div style={{ fontFamily: 'var(--pr-font-display)', fontSize: 17, fontWeight: 700, color: accent ? 'var(--pr-accent)' : 'var(--pr-ink)' }}>
                  {v}
                </div>
                <PrLabel size={7.5} style={{ marginTop: 3 }}>{l}</PrLabel>
              </div>
            ))}
          </div>
        )}

        {summary?.firstWeek && (
          <div className="pr-card" style={{ padding: '14px 16px', background: 'var(--pr-card-2)' }}>
            <PrLabel size={8.5} style={{ marginBottom: 10 }}>
              Первая неделя{summary.firstWeekRange ? ` · ${summary.firstWeekRange}` : ''}
            </PrLabel>
            <FirstWeekDots days={summary.firstWeek} />
          </div>
        )}
      </div>

      <div style={{ padding: '0 0 18px' }}>
        <PrButton onClick={onOpenCalendar} style={{ width: '100%', padding: '15px 18px', fontSize: 14.5 }}>
          Открыть календарь →
        </PrButton>
      </div>
    </div>
  );
}
