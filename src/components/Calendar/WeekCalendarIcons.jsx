/**
 * Минималистичные SVG-иконки для ячеек недели (бег, ОФП, СБУ)
 */

import React from 'react';

/** Бег — кроссовок */
export function RunIcon({ className = '', ...props }) {
  return (
    <svg className={className} viewBox="0 0 512.149 512.149" fill="currentColor" {...props}>
      <g transform="translate(-1)">
        <path d="M504.427,111.44l-1.253-1.254c-11.776-11.776-30.967-11.802-42.814,0.035l-46.089,46.574c-2.428,2.436-6.312,2.534-8.845,0.203l-64.618-59.657c-6.276-5.8-14.442-8.987-22.996-8.987h-96.124c-2.269,0-4.44,0.865-6.082,2.419l-81.47,77.356c-11.935,11.944-12.756,31.197-1.818,42.92c5.844,6.268,13.736,9.719,22.219,9.719h0.15c8.413-0.044,16.499-3.619,22.087-9.728l57.538-60.893h20.595L120.63,300.218H37.81c-19.633,0-35.778,14.68-36.758,33.421c-0.521,9.79,2.904,19.094,9.64,26.191c6.638,7,15.969,11.008,25.618,11.008h123.586c2.436,0,4.767-1.006,6.444-2.798l63.32-67.593l53.248,55.684l-16.075,102.735c-4.052,17.02,4.114,34.357,19.412,41.198c4.714,2.119,9.719,3.178,14.698,3.178c5.358,0,10.69-1.227,15.598-3.655c9.481-4.696,16.296-13.285,18.776-23.967l27.463-147.306c0.53-2.86-0.38-5.809-2.445-7.865l-73.295-73.198l58.227-58.138l40.589,40.58c11.335,11.335,31.091,11.335,42.417,0l76.156-76.147c5.623-5.623,8.722-13.109,8.722-21.054C513.149,124.54,510.05,117.063,504.427,111.44z" />
        <path d="M407.065,114.837c29.211,0,52.966-23.755,52.966-52.966c0-29.211-23.755-52.966-52.966-52.966c-29.21,0-52.966,23.755-52.966,52.966C354.1,91.082,377.855,114.837,407.065,114.837z" />
      </g>
    </svg>
  );
}

/** ОФП — штанга */
export function OFPIcon({ className = '', ...props }) {
  return (
    <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.92" strokeMiterlimit="10" {...props}>
      <polyline points="10.08 23.5 10.08 17.75 13.92 17.75 13.92 23.5" />
      <line x1="5.29" y1="22.54" x2="5.29" y2="10.08" />
      <line x1="18.71" y1="10.08" x2="18.71" y2="22.54" />
      <line x1="5.29" y1="8.17" x2="5.29" y2="0.5" />
      <line x1="18.71" y1="0.5" x2="18.71" y2="8.17" />
      <line x1="21.58" y1="5.29" x2="21.58" y2="14.87" />
      <line x1="2.42" y1="5.29" x2="2.42" y2="14.87" />
      <line x1="0.5" y1="10.08" x2="23.5" y2="10.08" />
      <line x1="8.17" y1="17.75" x2="15.83" y2="17.75" />
      <line x1="2.42" y1="22.54" x2="8.17" y2="22.54" />
      <line x1="15.83" y1="22.54" x2="21.58" y2="22.54" />
    </svg>
  );
}

/** СБУ — специальные беговые упражнения (высокое поднимание бедра / дрилл) */
export function SbuIcon({ className = '', ...props }) {
  return (
    <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" {...props}>
      <circle cx="10" cy="6" r="1.5" />
      <path d="M10 8v8l-2 3" />
      <circle cx="14" cy="10" r="1.5" />
      <path d="M14 12v6l2 3" />
      <path d="M8 14h4M12 10h4" />
    </svg>
  );
}

/** Отдых — луна */
export function RestIcon({ className = '', ...props }) {
  return (
    <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" {...props}>
      <path d="M12 3a6 6 0 0 0 6 6c0 2.5-1.5 5-3 6l-3-2a2 2 0 0 1-2-2V3z" />
    </svg>
  );
}

/** Выполнено — галочка */
export function CompletedIcon({ className = '', ...props }) {
  return (
    <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" {...props}>
      <path d="M5 12l5 5L20 7" />
    </svg>
  );
}
