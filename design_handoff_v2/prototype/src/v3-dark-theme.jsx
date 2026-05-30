/* v3 Dark theme — tokens + dark variant components.
   Применяется через prop `dark` к MobileDashV3, DesktopDashV3, MobileNav. */

// Dark tokens — based on existing planrun dark theme (Nike-style #0B1015)
window.V2_DARK = {
  primary: '#FF6B3D',       // chuть ярче в dark
  primary400: '#FF8A65',
  primary600: '#FC4C02',
  primaryWash: 'rgba(252,76,2,0.12)',
  primarySoft: 'rgba(252,76,2,0.18)',

  success: '#2ED573',
  successWash: 'rgba(46,213,115,0.15)',
  warning: '#FFBD3E',
  warningWash: 'rgba(255,189,62,0.15)',
  danger: '#FF5252',
  dangerWash: 'rgba(255,82,82,0.15)',
  info: '#5B9DFF',
  infoWash: 'rgba(91,157,255,0.15)',

  ink: '#F1F5F9',
  ink2: '#CBD5E1',
  ink3: '#94A3B8',
  ink4: '#64748B',

  line: '#1F2731',
  line2: '#2A323D',

  surf: '#0B1015',
  surf2: '#13181F',
  surf3: '#1C222B',
  surf4: '#29313B',

  cardBg: 'rgba(28,34,43,0.72)',
  cardBgStrong: 'rgba(33,41,52,0.82)',
  cardBorder: 'rgba(252,76,2,0.18)',
  cardBorderSoft: 'rgba(255,255,255,0.06)',
  cardInsetTop: 'rgba(255,255,255,0.04)',
  cardShadow: '0 16px 30px rgba(0,0,0,0.4), 0 6px 18px rgba(252,76,2,0.08)',

  appBgGradient: 'radial-gradient(120% 80% at 0% 0%, rgba(252,76,2,0.12) 0%, transparent 50%), radial-gradient(100% 70% at 100% 100%, rgba(252,76,2,0.08) 0%, transparent 55%), linear-gradient(180deg, #0F151D 0%, #0B1015 100%)',
  appBgGradientDesk: 'radial-gradient(60% 50% at 0% 0%, rgba(252,76,2,0.09) 0%, transparent 50%), radial-gradient(50% 60% at 100% 100%, rgba(252,76,2,0.06) 0%, transparent 55%), linear-gradient(180deg, #0F151D 0%, #0B1015 100%)',

  navBg: 'linear-gradient(180deg, rgba(33,41,52,0.86) 0%, rgba(28,34,43,0.82) 100%)',
  navBorder: 'rgba(252,76,2,0.18)',
};
