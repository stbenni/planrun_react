/* CalHeaderV3 — навигация периода + подзаголовок + сегмент Неделя/Месяц.
   Адаптивно: на десктопе CSS раскладывает шире. Сегмент скрыт, если режим
   зафиксирован извне (lockView) — для публичных профилей. */
import React from 'react';

export default function CalHeaderV3({
  title,
  subtitle,
  onPrev,
  onNext,
  viewMode = 'week',
  onViewMode,
  lockView = false,
  hideSeg = false,
  menu = null,
}) {
  return (
    <div className="calv3-head-wrap">
      {/* Навигация периода: стрелки по краям (удобно большому пальцу), заголовок по центру */}
      <div className="calv3-head">
        {onPrev && (
          <button type="button" className="calv3-nav-btn" onClick={onPrev} aria-label="Назад">‹</button>
        )}
        <div className="calv3-head-center">
          <div className="calv3-head-title">{title}</div>
          {subtitle && <div className="calv3-head-sub">{subtitle}</div>}
        </div>
        {onNext && (
          <button type="button" className="calv3-nav-btn" onClick={onNext} aria-label="Вперёд">›</button>
        )}
      </div>

      {/* Ряд переключателя Неделя/Месяц + ⋯-меню плана справа */}
      {!lockView && !hideSeg && (
        <div className="calv3-seg-row">
          <div className="calv3-seg" role="tablist" aria-label="Режим календаря">
            <button
              type="button"
              role="tab"
              aria-selected={viewMode === 'week'}
              className={`calv3-seg-btn${viewMode === 'week' ? ' is-on' : ''}`}
              onClick={() => onViewMode?.('week')}
            >
              Неделя
            </button>
            <button
              type="button"
              role="tab"
              aria-selected={viewMode === 'full'}
              className={`calv3-seg-btn${viewMode === 'full' ? ' is-on' : ''}`}
              onClick={() => onViewMode?.('full')}
            >
              Месяц
            </button>
          </div>
          {menu && <div className="calv3-seg-menu">{menu}</div>}
        </div>
      )}
    </div>
  );
}
