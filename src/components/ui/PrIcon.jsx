const svg = (s, stroke, extra, children) => (
  <svg
    width={s}
    height={s}
    viewBox="0 0 24 24"
    fill="none"
    stroke={stroke}
    strokeLinecap="round"
    strokeLinejoin="round"
    {...extra}
  >
    {children}
  </svg>
);

const PrIcon = {
  bell: (c = 'currentColor', s = 20) =>
    svg(s, c, { strokeWidth: 1.8 }, (
      <>
        <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" />
        <path d="M13.7 21a2 2 0 0 1-3.4 0" />
      </>
    )),
  arrow: (c = 'currentColor', s = 18) =>
    svg(s, c, { strokeWidth: 2.2 }, <path d="M5 12h14M13 6l6 6-6 6" />),
  check: (c = 'currentColor', s = 16) =>
    svg(s, c, { strokeWidth: 2.6 }, <path d="M4 12.5l5 5L20 6.5" />),
  flame: (c = 'currentColor', s = 18) =>
    svg(s, c, { strokeWidth: 1.8 }, (
      <path d="M12 22c4.4 0 7-2.8 7-6.5 0-2.5-1.4-4.7-3-6.5-.4 1.3-1.3 2.4-2.5 3C13.7 9 13 5.5 9.5 2c.3 3-1 5-2.6 6.8C5.3 10.6 5 12.4 5 13.9 5 19.2 7.6 22 12 22z" />
    )),
  search: (c = 'currentColor', s = 18) =>
    svg(s, c, { strokeWidth: 2 }, (
      <>
        <circle cx="11" cy="11" r="7" />
        <path d="M21 21l-4.3-4.3" />
      </>
    )),
  plus: (c = 'currentColor', s = 18) =>
    svg(s, c, { strokeWidth: 2.4 }, <path d="M12 5v14M5 12h14" />),
  chat: (c = 'currentColor', s = 18) =>
    svg(s, c, { strokeWidth: 1.8 }, (
      <path d="M21 12a8 8 0 0 1-8 8H4l2.2-2.7A8 8 0 1 1 21 12z" />
    )),
  home: (c = 'currentColor', s = 20) =>
    svg(s, c, { strokeWidth: 1.9 }, (
      <>
        <path d="M3 10.5L12 3l9 7.5" />
        <path d="M5 9.5V21h14V9.5" />
      </>
    )),
  cal: (c = 'currentColor', s = 20) =>
    svg(s, c, { strokeWidth: 1.9 }, (
      <>
        <rect x="3" y="5" width="18" height="16" rx="2" />
        <path d="M3 10h18M8 3v4M16 3v4" />
      </>
    )),
  stats: (c = 'currentColor', s = 20) =>
    svg(s, c, { strokeWidth: 2 }, <path d="M4 20V10M10 20V4M16 20v-7M22 20H2" />),
  run: (c = 'currentColor', s = 20) =>
    svg(s, c, { strokeWidth: 1.9 }, (
      <>
        <circle cx="15" cy="5" r="2.2" />
        <path d="M9 20l2.5-5L9 12l3-4 3 2.5 3 .5" />
        <path d="M6 14l3-2M12 8L9 7 6.5 9" />
      </>
    )),
  dots: (c = 'currentColor', s = 18) => (
    <svg width={s} height={s} viewBox="0 0 24 24" fill={c}>
      <circle cx="5" cy="12" r="1.8" />
      <circle cx="12" cy="12" r="1.8" />
      <circle cx="19" cy="12" r="1.8" />
    </svg>
  ),
  user: (c = 'currentColor', s = 20) =>
    svg(s, c, { strokeWidth: 1.9 }, (
      <>
        <circle cx="12" cy="8" r="3.6" />
        <path d="M5 20.5a7 7 0 0 1 14 0" />
      </>
    )),
  heart: (c = 'currentColor', s = 20) =>
    svg(s, c, { strokeWidth: 1.8 }, (
      <path d="M19.5 12.6L12 20l-7.5-7.4a5 5 0 1 1 7.5-6.6 5 5 0 1 1 7.5 6.6z" />
    )),
  gear: (c = 'currentColor', s = 20) =>
    svg(s, c, { strokeWidth: 1.8 }, (
      <>
        <circle cx="12" cy="12" r="3.4" />
        <path d="M12 2.5v2.8M12 18.7v2.8M2.5 12h2.8M18.7 12h2.8M5.2 5.2l2 2M16.8 16.8l2 2M18.8 5.2l-2 2M7.2 16.8l-2 2" />
      </>
    )),
};

export default PrIcon;
