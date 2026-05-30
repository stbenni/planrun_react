/**
 * DashCustomizerV3 — модалка управления виджетами дашборда (v2 design).
 * 3 пресета (Простой/Средний/Профи) + индивидуальные тумблеры.
 * Состояние хранится в localStorage 'dashboard-v3-widgets'.
 */

import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { CloseIcon } from '../../common/Icons';
import './DashCustomizerV3.css';

const WIDGETS = [
  { id: 'today',   name: 'Сегодняшняя тренировка', emoji: '🎯', desc: 'Главная тренировка дня + AI-совет. Самое важное.', fixed: true },
  { id: 'next',    name: 'Следующая тренировка',   emoji: '⏭', desc: 'Что планируется после сегодняшней.',           preset: ['standard', 'pro'] },
  { id: 'week',    name: 'Неделя',                 emoji: '📅', desc: 'Все 7 дней с типами и прогрессом.',              preset: ['simple', 'standard', 'pro'] },
  { id: 'goal',    name: 'Главная цель',           emoji: '🏆', desc: 'Countdown + прогноз vs цель + тренд.',           preset: ['simple', 'standard', 'pro'] },
  { id: 'form',    name: 'Форма и нагрузка',       emoji: '📊', desc: 'TSB / ATL / CTL — серьёзная аналитика.',         preset: ['standard', 'pro'] },
  { id: 'pr',      name: 'Личные рекорды',         emoji: '⭐', desc: '4 карточки: 5K / 10K / 21.1K / 42.2K.',         preset: ['simple', 'standard', 'pro'] },
  { id: 'trends',  name: 'Тренд месяца',           emoji: '📈', desc: 'Объём этого месяца vs прошлый.',                 preset: ['pro'] },
  { id: 'race',    name: 'VDOT-прогнозы',          emoji: '🎲', desc: 'Прогноз для 5K, 10K, Полу, Марафон.',           preset: ['pro'] },
  { id: 'pace',    name: 'Тренировочные зоны',     emoji: '⚡', desc: 'Темпы лёгкого / темпового / интервалов.',        preset: ['pro'] },
  { id: 'stats',   name: 'Статистика',             emoji: '📉', desc: 'Дистанция / время / тренировки за период.',      preset: ['standard', 'pro'] },
];

const PRESETS = {
  simple:   { name: 'Простой',  desc: 'Основа: тренировка, неделя, цель, рекорды' },
  standard: { name: 'Средний',  desc: '+ нагрузка и статистика' },
  pro:      { name: 'Профи',    desc: 'Все виджеты' },
};

const STORAGE_KEY = 'dashboard-v3-widgets';

export function getEnabledWidgets() {
  try {
    if (typeof localStorage === 'undefined') return getPresetEnabled('standard');
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return getPresetEnabled('standard');
    const arr = JSON.parse(raw);
    if (!Array.isArray(arr) || arr.length === 0) return getPresetEnabled('standard');
    return new Set([...arr, 'today']); // today всегда включён
  } catch {
    return getPresetEnabled('standard');
  }
}

function getPresetEnabled(preset) {
  const ids = ['today'];
  WIDGETS.forEach((w) => { if (w.preset?.includes(preset)) ids.push(w.id); });
  return new Set(ids);
}

function saveEnabled(set) {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(Array.from(set)));
    // Уведомить дашборд через window event
    window.dispatchEvent(new CustomEvent('dashboard-v3-widgets-changed', { detail: Array.from(set) }));
  } catch { /* silent */ }
}

export default function DashCustomizerV3({ isOpen, onClose }) {
  const [enabled, setEnabled] = useState(() => getEnabledWidgets());
  const [preset, setPreset] = useState('custom');

  useEffect(() => {
    if (!isOpen) return undefined;
    const onKey = (e) => { if (e.key === 'Escape') onClose?.(); };
    document.addEventListener('keydown', onKey);
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = prev;
    };
  }, [isOpen, onClose]);

  if (!isOpen) return null;

  const toggle = (id) => {
    const w = WIDGETS.find((x) => x.id === id);
    if (w?.fixed) return;
    const n = new Set(enabled);
    if (n.has(id)) n.delete(id); else n.add(id);
    setEnabled(n);
    setPreset('custom');
    saveEnabled(n);
  };

  const applyPreset = (key) => {
    setPreset(key);
    const n = getPresetEnabled(key);
    setEnabled(n);
    saveEnabled(n);
  };

  const target = (typeof document !== 'undefined' && document.getElementById('modal-root')) || document?.body;
  if (!target) return null;

  const content = (
    <>
      <div className="dash-cust-v3__scrim" onClick={onClose} aria-hidden />
      <div className="dash-cust-v3" role="dialog" aria-label="Настройка дэшборда">
        <header className="dash-cust-v3__head">
          <div className="dash-cust-v3__title">Настройка дэшборда</div>
          <button type="button" className="dash-cust-v3__close" onClick={onClose} aria-label="Закрыть">
            <CloseIcon size={18} />
          </button>
        </header>

        <div className="dash-cust-v3__body">
          <div className="dash-cust-v3__eyebrow">БЫСТРЫЙ ВЫБОР</div>
          <div className="dash-cust-v3__presets">
            {Object.entries(PRESETS).map(([k, p]) => (
              <button
                key={k}
                type="button"
                onClick={() => applyPreset(k)}
                className={`dash-cust-v3__preset ${preset === k ? 'dash-cust-v3__preset--active' : ''}`}
              >
                <div className="dash-cust-v3__preset-name">{p.name}</div>
                <div className="dash-cust-v3__preset-desc">{p.desc}</div>
              </button>
            ))}
          </div>

          <div className="dash-cust-v3__eyebrow dash-cust-v3__eyebrow--mt">
            ВКЛЮЧИТЬ ВИДЖЕТЫ · {enabled.size} из {WIDGETS.length}
          </div>
          <div className="dash-cust-v3__list">
            {WIDGETS.map((w) => {
              const on = enabled.has(w.id);
              return (
                <button
                  key={w.id}
                  type="button"
                  onClick={() => toggle(w.id)}
                  aria-disabled={w.fixed}
                  className={`dash-cust-v3__row ${on ? 'dash-cust-v3__row--on' : ''} ${w.fixed ? 'dash-cust-v3__row--fixed' : ''}`}
                >
                  <span className="dash-cust-v3__row-emoji" aria-hidden>{w.emoji}</span>
                  <div className="dash-cust-v3__row-text">
                    <div className="dash-cust-v3__row-name">
                      {w.name}
                      {w.fixed && <span className="dash-cust-v3__row-lock">всегда</span>}
                    </div>
                    <div className="dash-cust-v3__row-desc">{w.desc}</div>
                  </div>
                  <span className={`dash-cust-v3__switch ${on ? 'dash-cust-v3__switch--on' : ''}`} aria-hidden>
                    <span className="dash-cust-v3__switch-knob" />
                  </span>
                </button>
              );
            })}
          </div>
        </div>

        <footer className="dash-cust-v3__foot">
          <button type="button" className="dash-cust-v3__save" onClick={onClose}>
            Готово · {enabled.size} виджетов
          </button>
        </footer>
      </div>
    </>
  );

  return createPortal(content, target);
}
