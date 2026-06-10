/**
 * AthleteSelect — кастомный dropdown для выбора атлета тренером.
 * Заменяет нативный <select>, чтобы корректно работать в тёмной теме.
 *
 * Props:
 *   value: string | null — текущий slug (или null/'' для «своего» режима)
 *   ownLabel: string — лейбл для «своего» режима (например, «Мой календарь»)
 *   athletes: [{ id, username, username_slug }]
 *   onChange: (slug: string | null) => void
 */

import { useEffect, useRef, useState } from 'react';
import { ChevronDownIcon, CheckIcon } from './Icons';
import { getDisplayName } from '../../utils/displayName';
import './AthleteSelect.css';

export default function AthleteSelect({ value, ownLabel = 'Мой календарь', athletes = [], onChange }) {
  const [open, setOpen] = useState(false);
  const wrapRef = useRef(null);
  const btnRef = useRef(null);
  const [activeIdx, setActiveIdx] = useState(-1);

  const items = [{ slug: '', label: ownLabel, isOwn: true }, ...athletes.map((a) => ({
    slug: a.username_slug,
    label: getDisplayName(a),
    isOwn: false,
  }))];

  const selected = items.find((i) => (i.slug || '') === (value || '')) || items[0];

  useEffect(() => {
    if (!open) return undefined;
    const onClick = (e) => {
      if (wrapRef.current && !wrapRef.current.contains(e.target)) {
        setOpen(false);
      }
    };
    const onKey = (e) => {
      if (e.key === 'Escape') { setOpen(false); btnRef.current?.focus(); return; }
      if (e.key === 'ArrowDown') { e.preventDefault(); setActiveIdx((i) => Math.min(items.length - 1, i + 1)); return; }
      if (e.key === 'ArrowUp') { e.preventDefault(); setActiveIdx((i) => Math.max(0, i - 1)); return; }
      if (e.key === 'Enter' && activeIdx >= 0) {
        e.preventDefault();
        const it = items[activeIdx];
        onChange?.(it.slug || null);
        setOpen(false);
      }
    };
    document.addEventListener('mousedown', onClick);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onClick);
      document.removeEventListener('keydown', onKey);
    };
  }, [open, activeIdx, items, onChange]);

  const handleSelect = (it) => {
    onChange?.(it.slug || null);
    setOpen(false);
    btnRef.current?.focus();
  };

  return (
    <div className="athlete-select" ref={wrapRef}>
      <button
        ref={btnRef}
        type="button"
        className={`athlete-select__btn ${open ? 'athlete-select__btn--open' : ''}`}
        onClick={() => {
          setOpen((v) => !v);
          setActiveIdx(items.findIndex((i) => (i.slug || '') === (value || '')));
        }}
        aria-haspopup="listbox"
        aria-expanded={open}
      >
        <span className="athlete-select__value">{selected.label}</span>
        <ChevronDownIcon size={16} className={`athlete-select__caret ${open ? 'athlete-select__caret--up' : ''}`} />
      </button>
      {open && (
        <ul className="athlete-select__menu" role="listbox">
          {items.map((it, idx) => {
            const isSelected = (it.slug || '') === (value || '');
            const isActive = idx === activeIdx;
            return (
              <li key={it.slug || '__own'}>
                <button
                  type="button"
                  role="option"
                  aria-selected={isSelected}
                  className={`athlete-select__option ${isSelected ? 'athlete-select__option--selected' : ''} ${isActive ? 'athlete-select__option--active' : ''} ${it.isOwn ? 'athlete-select__option--own' : ''}`}
                  onClick={() => handleSelect(it)}
                  onMouseEnter={() => setActiveIdx(idx)}
                >
                  <span className="athlete-select__option-label">{it.label}</span>
                  {isSelected && <CheckIcon size={14} />}
                </button>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
