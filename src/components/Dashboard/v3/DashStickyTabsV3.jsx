/**
 * DashStickyTabsV3 — sticky навигация по секциям дашборда (mobile-only).
 * Клик по табу → smooth-scroll к секции с этим data-section.
 * Скролл по странице → подсветка активного таба через IntersectionObserver.
 *
 * Применение: разместить ОДИН раз в начале мобильного дашборда.
 * Секции (div.dashboard-section) должны иметь атрибут data-section="<key>".
 */

import { useEffect, useRef, useState } from 'react';
import './DashStickyTabsV3.css';

const DEFAULT_TABS = [
  { id: 'today', label: 'Сегодня' },
  { id: 'week', label: 'Неделя' },
  { id: 'goal', label: 'Цель' },
  { id: 'form', label: 'Форма' },
  { id: 'pr', label: 'PR' },
  { id: 'more', label: 'Ещё' },
];

export default function DashStickyTabsV3({ tabs = DEFAULT_TABS }) {
  const [active, setActive] = useState(tabs[0]?.id);
  const navRef = useRef(null);
  const programmaticScrollRef = useRef(false);

  // Scrollspy: следим какая секция в верхней половине viewport
  useEffect(() => {
    if (typeof window === 'undefined') return undefined;
    const sections = Array.from(document.querySelectorAll('[data-section]'));
    if (sections.length === 0) return undefined;

    const observer = new IntersectionObserver(
      (entries) => {
        if (programmaticScrollRef.current) return;
        // Берём самый верхний пересекающийся
        const visible = entries
          .filter((e) => e.isIntersecting)
          .map((e) => ({ id: e.target.getAttribute('data-section'), top: e.boundingClientRect.top }))
          .sort((a, b) => a.top - b.top)[0];
        if (visible?.id) setActive(visible.id);
      },
      { rootMargin: '-90px 0px -50% 0px', threshold: [0, 0.5, 1] }
    );

    sections.forEach((el) => observer.observe(el));
    return () => observer.disconnect();
  }, []);

  // Прокрутка активного chip в зону видимости горизонтального скролла.
  // ВАЖНО: НЕ используем chip.scrollIntoView — на iOS он игнорирует `block: 'nearest'`
  // и скроллит весь viewport, из-за чего страница «прыгает» при scroll-spy.
  useEffect(() => {
    if (!navRef.current || !active) return;
    const inner = navRef.current.querySelector('.dash-sticky-tabs-v3__inner');
    const chip = navRef.current.querySelector(`[data-tab-id="${active}"]`);
    if (!inner || !chip) return;
    const targetLeft = chip.offsetLeft - (inner.clientWidth - chip.clientWidth) / 2;
    inner.scrollTo({ left: Math.max(0, targetLeft), behavior: 'smooth' });
  }, [active]);

  const handleClick = (id) => {
    setActive(id);
    const target = document.querySelector(`[data-section="${id}"]`);
    if (!target) return;
    programmaticScrollRef.current = true;
    const offset = 96; // высота sticky-таба + небольшой gap
    const top = target.getBoundingClientRect().top + window.scrollY - offset;
    window.scrollTo({ top, behavior: 'smooth' });
    setTimeout(() => { programmaticScrollRef.current = false; }, 700);
  };

  return (
    <nav className="dash-sticky-tabs-v3" aria-label="Разделы дашборда" ref={navRef}>
      <div className="dash-sticky-tabs-v3__inner">
        {tabs.map((t) => (
          <button
            key={t.id}
            type="button"
            data-tab-id={t.id}
            className={`dash-sticky-tabs-v3__chip ${active === t.id ? 'dash-sticky-tabs-v3__chip--active' : ''}`}
            onClick={() => handleClick(t.id)}
          >
            {t.label}
          </button>
        ))}
      </div>
    </nav>
  );
}
