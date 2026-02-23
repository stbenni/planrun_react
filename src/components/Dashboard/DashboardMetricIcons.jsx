/**
 * Минималистичные SVG-иконки для карточек быстрых метрик (Дистанция, Активность, Время)
 */

import React from 'react';

const iconProps = {
  viewBox: '0 0 24 24',
  fill: 'none',
  stroke: 'currentColor',
  strokeWidth: 1.8,
  strokeLinecap: 'round',
  strokeLinejoin: 'round',
  'aria-hidden': true
};

/** Дистанция — маршрут/путь */
export function MetricDistanceIcon({ className = '', ...props }) {
  return (
    <svg className={className} {...iconProps} {...props}>
      <path d="M4 18l4-8 4 4 8-8" />
    </svg>
  );
}

/** Активность — календарь */
export function MetricActivityIcon({ className = '', ...props }) {
  return (
    <svg className={className} {...iconProps} {...props}>
      <rect x="3" y="4" width="18" height="18" rx="2" />
      <path d="M16 2v4M8 2v4M3 10h18" />
    </svg>
  );
}

/** Время — часы */
export function MetricTimeIcon({ className = '', ...props }) {
  return (
    <svg className={className} {...iconProps} {...props}>
      <circle cx="12" cy="12" r="9" />
      <path d="M12 6v6l4 2" />
    </svg>
  );
}
