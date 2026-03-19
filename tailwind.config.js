/** @type {import('tailwindcss').Config} */
export default {
  // Префикс tw- чтобы не конфликтовать с существующими стилями приложения
  prefix: 'tw-',
  content: ['./index.html', './src/**/*.{js,jsx}'],
  theme: {
    container: {
      center: true,
      padding: '2rem',
      screens: { '2xl': '1600px' },
    },
    extend: {
      fontFamily: {
        display: ['Montserrat', 'sans-serif'],
      },
      colors: {
        // Переменные для лендинга (определены в LandingScreen)
        border: 'hsl(var(--tw-border) / <alpha-value>)',
        background: 'hsl(var(--tw-background) / <alpha-value>)',
        foreground: 'hsl(var(--tw-foreground) / <alpha-value>)',
        primary: {
          DEFAULT: 'hsl(var(--tw-primary) / <alpha-value>)',
          foreground: 'hsl(var(--tw-primary-foreground) / <alpha-value>)',
        },
        muted: {
          DEFAULT: 'hsl(var(--tw-muted) / <alpha-value>)',
          foreground: 'hsl(var(--tw-muted-foreground) / <alpha-value>)',
        },
      },
      keyframes: {
        float: {
          '0%, 100%': { transform: 'translateY(0px)' },
          '50%': { transform: 'translateY(-10px)' },
        },
        'pulse-glow': {
          '0%, 100%': { opacity: '0.4' },
          '50%': { opacity: '0.8' },
        },
      },
      animation: {
        float: 'float 6s ease-in-out infinite',
        'pulse-glow': 'pulse-glow 3s ease-in-out infinite',
      },
      backgroundImage: {
        'gradient-hero': 'linear-gradient(135deg, hsl(14, 90%, 55%) 0%, hsl(30, 100%, 50%) 50%, hsl(5, 85%, 50%) 100%)',
      },
      boxShadow: {
        'primary-glow': '0 0 40px -10px hsl(14, 90%, 55%, 0.4)',
      },
      transitionTimingFunction: {
        smooth: 'cubic-bezier(0.4, 0, 0.2, 1)',
      },
    },
  },
  plugins: [require('tailwindcss-animate')],
};
