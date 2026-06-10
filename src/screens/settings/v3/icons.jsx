// Монохромные line-иконки настроек v3 — порт SIC из прототипа (stroke = currentColor).
const wrap = (children, vb = '0 0 24 24') => (
  <svg width="20" height="20" viewBox={vb} fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">{children}</svg>
);

export const ProfileIcon = () => wrap(<><circle cx="12" cy="8" r="4" /><path d="M4 21a8 8 0 0 1 16 0" /></>);
export const TrainingIcon = () => wrap(<><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></>);
export const NotifIcon = () => wrap(<><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" /><path d="M13.7 21a2 2 0 0 1-3.4 0" /></>);
export const IntegIcon = () => wrap(<><path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1" /><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1" /></>);
export const LookIcon = () => wrap(<><circle cx="12" cy="12" r="4" /><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" /></>);
export const SecurityIcon = () => wrap(<><rect x="5" y="11" width="14" height="10" rx="2" /><path d="M8 11V7a4 4 0 0 1 8 0v4" /></>);
export const CoachesIcon = () => wrap(<><circle cx="9" cy="8" r="3.5" /><path d="M3 20a6 6 0 0 1 12 0" /><path d="M16 5.5a3.5 3.5 0 0 1 0 6.8M17 14.5a6 6 0 0 1 4 5.5" /></>);
export const ChevronIcon = () => wrap(<path d="M9 18l6-6-6-6" />);
export const BackIcon = () => wrap(<path d="M15 18l-6-6 6-6" />);
