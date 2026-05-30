/**
 * AthleteMobileTabs — sticky-навигация секций Dashboard на мобиле.
 *
 * 4 таба (Сегодня / Неделя / Цель / Прогресс) — каждый скроллит к секции
 * с заданным id. Подсветка активного таба — по pull-up: при скролле страницы
 * проверяем, какая секция в viewport.
 *
 * Рендерится только на мобиле (max-width 768px) внутри Dashboard.
 */

import { useEffect, useState, useRef, useCallback } from 'react';
import './AthleteMobileTabs.css';

const TABS = [
  { id: 'today_workout', label: 'Сегодня' },
  { id: 'calendar', label: 'Неделя' },
  { id: 'goal_countdown', label: 'Цель' },
  { id: 'stats', label: 'Прогресс' },
];

export default function AthleteMobileTabs() {
  const [active, setActive] = useState(TABS[0].id);
  const observerRef = useRef(null);

  // IntersectionObserver — следим какая секция в верхней половине экрана
  useEffect(() => {
    const found = TABS
      .map((t) => document.getElementById(`dashboard-section-${t.id}`))
      .filter(Boolean);
    if (found.length === 0) return undefined;

    const observer = new IntersectionObserver(
      (entries) => {
        // Берём первую секцию пересекающую rootMargin
        const visible = entries
          .filter((e) => e.isIntersecting)
          .sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
        if (visible.length > 0) {
          const id = visible[0].target.id.replace('dashboard-section-', '');
          setActive(id);
        }
      },
      { rootMargin: '-30% 0px -55% 0px', threshold: 0 }
    );

    found.forEach((el) => observer.observe(el));
    observerRef.current = observer;
    return () => observer.disconnect();
  }, []);

  const handleClick = useCallback((id) => {
    const el = document.getElementById(`dashboard-section-${id}`);
    if (!el) return;
    setActive(id);
    // Скролл с учётом sticky tabs высоты (≈56px)
    const y = el.getBoundingClientRect().top + window.scrollY - 64;
    window.scrollTo({ top: y, behavior: 'smooth' });
  }, []);

  return (
    <nav className="athlete-mob-tabs" aria-label="Секции дашборда">
      {TABS.map((t) => (
        <button
          key={t.id}
          type="button"
          className={`athlete-mob-tabs__btn ${active === t.id ? 'athlete-mob-tabs__btn--active' : ''}`}
          onClick={() => handleClick(t.id)}
        >
          {t.label}
        </button>
      ))}
    </nav>
  );
}
