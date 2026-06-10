/**
 * Экран «Собираю твой план» (мок BObGenerating): кольцо % + живой чеклист пайплайна.
 * Генерация идёт в очереди на бэкенде; здесь поллим check_plan_status и по готовности
 * отдаём наверх onReady. Процент — визуальная аппроксимация (реального прогресса у бэка нет).
 */

import { useEffect, useRef, useState } from 'react';
import { PrRing, PrLabel, PrButton } from '../ui';
import { ObError } from './obKit';

const POLL_MS = 5000;

const PIPELINE = [
  { t: 'Профиль и цель', at: 0 },
  { t: 'История тренировок', at: 8 },
  { t: 'Расчёт зон и VDOT', at: 20 },
  { t: 'Сборка недель плана', at: 45 },
  { t: 'Проверка нагрузки', at: 80 },
];

export default function StepGenerating({ client, subtitle, onReady, onDashboard }) {
  const [elapsed, setElapsed] = useState(0);
  const [genError, setGenError] = useState('');
  const onReadyRef = useRef(onReady);
  onReadyRef.current = onReady;

  useEffect(() => {
    const t = setInterval(() => setElapsed((s) => s + 1), 1000);
    return () => clearInterval(t);
  }, []);

  useEffect(() => {
    let alive = true;
    let timer = null;
    const tick = async () => {
      let status = null;
      try {
        status = await client.checkPlanStatus();
      } catch {
        /* сеть мигнула — попробуем в следующий тик */
      }
      if (!alive) return;
      if (status?.has_plan) {
        onReadyRef.current?.();
        return;
      }
      if (status?.error) {
        setGenError(status.error);
        return;
      }
      timer = setTimeout(tick, POLL_MS);
    };
    timer = setTimeout(tick, 4000);
    return () => {
      alive = false;
      clearTimeout(timer);
    };
  }, [client]);

  // Асимптотический прогресс: быстро в начале, плавно к ~93% (генерация 2–3 мин).
  const pct = Math.min(0.93, 1 - Math.exp(-elapsed / 75));
  // Виртуальный «процент пайплайна» для чеклиста
  const pipePct = pct * 100;

  return (
    <div style={{ flex: 1, display: 'flex', flexDirection: 'column' }}>
      <div style={{ flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 22, padding: '24px 8px' }}>
        <PrRing pct={pct} size={150} stroke={12}>
          <div style={{ fontFamily: 'var(--pr-font-display)', fontSize: 32, fontWeight: 700, color: 'var(--pr-ink)' }}>
            {Math.round(pct * 100)}%
          </div>
          <PrLabel size={8}>сборка</PrLabel>
        </PrRing>

        <div style={{ textAlign: 'center' }}>
          <div style={{ fontFamily: 'var(--pr-font-display)', fontSize: 23, fontWeight: 700, color: 'var(--pr-ink)' }}>
            Собираю твой план
          </div>
          <div style={{ fontSize: 13, color: 'var(--pr-sub)', marginTop: 6, lineHeight: 1.5 }}>
            {subtitle}
            {subtitle ? <br /> : null}
            Обычно это занимает 2–3 минуты.
          </div>
        </div>

        <div className="pr-card" style={{ padding: '6px 18px', width: '100%', maxWidth: 320 }}>
          {PIPELINE.map((s, i) => {
            const done = pipePct >= (PIPELINE[i + 1]?.at ?? 200);
            const live = !done && pipePct >= s.at;
            return (
              <div
                key={s.t}
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  gap: 11,
                  padding: '10px 0',
                  borderTop: i ? '1px solid var(--pr-line)' : 'none',
                  opacity: done || live ? 1 : 0.45,
                }}
              >
                {done ? (
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--pr-good)" strokeWidth="2.6" strokeLinecap="round" strokeLinejoin="round">
                    <path d="M4 12.5l5 5L20 6.5" />
                  </svg>
                ) : live ? (
                  <span className="pr-live-dot" style={{ margin: 3 }} />
                ) : (
                  <span style={{ width: 8, height: 8, borderRadius: 999, border: '1.5px solid var(--pr-sub)', margin: 3 }} />
                )}
                <span style={{ fontSize: 12.5, fontWeight: 600, color: live ? 'var(--pr-ink)' : 'var(--pr-sub)' }}>{s.t}</span>
              </div>
            );
          })}
        </div>

        {genError && <ObError>{genError}</ObError>}
        {genError && (
          <PrButton variant="secondary" onClick={onDashboard}>Перейти на дашборд →</PrButton>
        )}
      </div>

      {!genError && (
        <div style={{ padding: '0 0 18px', textAlign: 'center' }}>
          <PrLabel size={9} style={{ marginBottom: 10 }}>Можно закрыть — пришлём уведомление</PrLabel>
          <button
            type="button"
            onClick={onDashboard}
            style={{
              background: 'none',
              border: 'none',
              fontFamily: 'var(--pr-font-body)',
              fontSize: 12.5,
              fontWeight: 700,
              color: 'var(--pr-accent)',
              cursor: 'pointer',
            }}
          >
            Перейти на дашборд →
          </button>
        </div>
      )}
    </div>
  );
}
