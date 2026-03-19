import React, { useState, useEffect, useMemo, useRef } from 'react';
import { useNavigate, useLocation, useSearchParams } from 'react-router-dom';
import { motion } from 'framer-motion';
import useAuthStore from '../stores/useAuthStore';
import LoginModal from '../components/LoginModal';
import RegisterModal from '../components/RegisterModal';
import ParticlesBackground from '../components/ParticlesBackground';
import './LandingScreen.css';

const detectIOSDevice = () => {
  if (typeof navigator === 'undefined') return false;

  const ua = navigator.userAgent || '';
  const platform = navigator.platform || '';

  return /iPad|iPhone|iPod/.test(ua) || (platform === 'MacIntel' && navigator.maxTouchPoints > 1);
};

const LandingScreen = ({ onRegister, registrationEnabled = true }) => {
  const navigate = useNavigate();
  const location = useLocation();
  const [searchParams, setSearchParams] = useSearchParams();
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated);
  const [loginOpen, setLoginOpen] = useState(false);
  const [registerOpen, setRegisterOpen] = useState(false);
  const [registerReturnTo, setRegisterReturnTo] = useState(null);
  const showcaseRef = useRef(null);
  const isIOSDevice = useMemo(() => detectIOSDevice(), []);
  const isDark = useMemo(
    () => document.documentElement.getAttribute('data-theme') !== 'light',
    []
  );

  useEffect(() => {
    const showcase = showcaseRef.current;
    if (!showcase || typeof window === 'undefined') return undefined;

    let frameId = 0;
    const viewport = window.visualViewport;

    const updateViewportHeight = () => {
      cancelAnimationFrame(frameId);
      frameId = window.requestAnimationFrame(() => {
        const visualHeight = viewport?.height ?? window.innerHeight;
        const offsetTop = viewport?.offsetTop ?? 0;
        const offsetLeft = viewport?.offsetLeft ?? 0;
        const screenHeight = Math.round(visualHeight);
        const bottomInset = Math.max(0, Math.round(window.innerHeight - (visualHeight + offsetTop)));

        if (screenHeight > 0) {
          if (isIOSDevice) {
            showcase.style.removeProperty('--landing-screen-height');
          } else {
            showcase.style.setProperty('--landing-screen-height', `${screenHeight}px`);
          }
          showcase.style.setProperty('--landing-visual-offset-top', `${Math.round(offsetTop)}px`);
          showcase.style.setProperty('--landing-visual-offset-left', `${Math.round(offsetLeft)}px`);
          showcase.style.setProperty('--landing-runtime-bottom-inset', `${bottomInset}px`);
        }
      });
    };

    updateViewportHeight();

    window.addEventListener('resize', updateViewportHeight);
    window.addEventListener('orientationchange', updateViewportHeight);
    viewport?.addEventListener('resize', updateViewportHeight);
    viewport?.addEventListener('scroll', updateViewportHeight);

    return () => {
      cancelAnimationFrame(frameId);
      window.removeEventListener('resize', updateViewportHeight);
      window.removeEventListener('orientationchange', updateViewportHeight);
      viewport?.removeEventListener('resize', updateViewportHeight);
      viewport?.removeEventListener('scroll', updateViewportHeight);
      showcase.style.removeProperty('--landing-screen-height');
      showcase.style.removeProperty('--landing-visual-offset-top');
      showcase.style.removeProperty('--landing-visual-offset-left');
      showcase.style.removeProperty('--landing-runtime-bottom-inset');
    };
  }, [isIOSDevice]);

  useEffect(() => {
    const showcase = showcaseRef.current;
    if (!showcase) return undefined;

    showcase.classList.toggle('landing-showcase--ios', isIOSDevice);

    return () => {
      showcase.classList.remove('landing-showcase--ios');
    };
  }, [isIOSDevice]);

  useEffect(() => {
    const fromState = location.state?.openLogin;
    const fromQuery = searchParams.get('openLogin') === '1';
    if (fromState || fromQuery) {
      setLoginOpen(true);
      if (fromState) navigate(location.pathname, { replace: true, state: {} });
      if (fromQuery) setSearchParams((p) => { const n = new URLSearchParams(p); n.delete('openLogin'); return n; }, { replace: true });
    }
  }, [location.state?.openLogin, searchParams, location.pathname, navigate, setSearchParams]);

  useEffect(() => {
    if (!isAuthenticated) return;

    if (loginOpen) setLoginOpen(false);
    if (registerOpen) setRegisterOpen(false);
    if (registerReturnTo) setRegisterReturnTo(null);

    navigate('/', { replace: true });
  }, [isAuthenticated, loginOpen, navigate, registerOpen, registerReturnTo]);

  const handleLogin = (e) => {
    e?.preventDefault?.();
    e?.stopPropagation?.();
    setTimeout(() => setLoginOpen(true), 0);
  };

  const handleRegister = (e) => {
    e?.preventDefault?.();
    e?.stopPropagation?.();
    setRegisterReturnTo(null);
    setTimeout(() => setRegisterOpen(true), 0);
  };

  const handleCoachIntent = (e) => {
    e?.preventDefault?.();
    e?.stopPropagation?.();

    if (isAuthenticated) {
      navigate('/trainers/apply');
      return;
    }

    if (!registrationEnabled) {
      return;
    }

    setRegisterReturnTo({
      path: '/trainers/apply',
      state: { registrationSuccess: true, coachOnboarding: true },
    });
    setTimeout(() => setRegisterOpen(true), 0);
  };

  const handleRegisterModalClose = () => {
    setRegisterOpen(false);
    setRegisterReturnTo(null);
  };

  return (
    <div ref={showcaseRef} className="landing-showcase">
      {!registrationEnabled && (
        <div className="landing-notice" role="alert">
          Регистрация временно отключена администратором.
        </div>
      )}

      <motion.nav
        initial={{ opacity: 0 }}
        animate={{ opacity: 1 }}
        transition={{ duration: 0.8, delay: 0.5 }}
        className="landing-nav"
      >
        <div className="landing-nav-inner">
          <a href="/" className="landing-nav-logo">
            <span className="logo-text">
              <span className="logo-plan">plan</span>
              <span className="logo-run">RUN</span>
            </span>
          </a>
          <div className="landing-nav-actions">
            <button type="button" className="landing-nav-btn" onClick={handleLogin}>
              Войти
            </button>
            <button type="button" className="landing-nav-btn" onClick={handleCoachIntent}>
              Стать тренером
            </button>
          </div>
        </div>
      </motion.nav>

      <LoginModal isOpen={loginOpen} onClose={() => setLoginOpen(false)} />
      <RegisterModal
        isOpen={registerOpen}
        onClose={handleRegisterModalClose}
        onRegister={onRegister}
        returnTo={registerReturnTo}
      />

      <section className="landing-hero">
        <div className="landing-hero-bg" />
        <div className="landing-hero-gradient" />
        <div className="tw-absolute tw-inset-0 tw-left-0 tw-w-full lg:tw-left-1/2 lg:tw-w-1/2 tw-pointer-events-none">
          <ParticlesBackground isDark={isDark} />
        </div>

        <div className="landing-hero-inner">
          <motion.div
            initial={{ opacity: 0, y: 40 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 1, ease: [0.22, 1, 0.36, 1] }}
            className="landing-hero-cta"
          >
            <div className="landing-hero-copy-shell">
              <div className="landing-hero-kicker">Выбирай свой формат подготовки</div>

              <h1 className="landing-hero-title">
                <span>plan</span>
                <span className="landing-text-gradient">RUN</span>
              </h1>

              <p className="landing-hero-subtitle">
                Тренируйся с AI или выбери тренера.
                <br />
                <span className="landing-hero-subtitle-accent">От первых 5 км до марафона</span>
              </p>

              <div className="landing-features">
                <span className="landing-feature-pill">
                  <span className="landing-feature-pill-dot" aria-hidden />
                  План под цель и расписание
                </span>
                <span className="landing-feature-pill">
                  <span className="landing-feature-pill-dot" aria-hidden />
                  AI или живой тренер
                </span>
                <span className="landing-feature-pill">
                  <span className="landing-feature-pill-dot" aria-hidden />
                  Темп, пульс и прогресс
                </span>
                <span className="landing-feature-pill">
                  <span className="landing-feature-pill-dot" aria-hidden />
                  Импорт тренировок
                </span>
              </div>

              <div className="landing-cta-buttons">
                <motion.button
                  type="button"
                  className="landing-cta-button"
                  onClick={handleRegister}
                  disabled={!registrationEnabled}
                >
                  Начать бесплатно
                </motion.button>
              </div>
            </div>
          </motion.div>

          <div className="landing-hero-placeholder" aria-hidden />
        </div>

        <motion.div
          initial={{ opacity: 0, x: 60 }}
          animate={{ opacity: 1, x: 0 }}
          transition={{ duration: 1.2, delay: 0.2, ease: [0.22, 1, 0.36, 1] }}
          className="landing-hero-image-desktop"
        >
          <div className="landing-hero-image-desktop-glow" />
          <img
            src="/hero-image.png"
            alt="Тренер и AI-ассистент planRUN"
            className="landing-hero-image-desktop-img landing-hero-image-shadow"
          />
        </motion.div>

        <motion.div
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          transition={{ duration: 1, delay: 0.3 }}
          className="landing-hero-image-mobile"
        >
          <img
            src="/hero-image.png"
            alt="Тренер и AI-ассистент planRUN"
            className="landing-hero-image-mobile-img"
          />
        </motion.div>

        <div className="landing-copyright">
          © 2026 planRUN
        </div>
      </section>
    </div>
  );
};

export default LandingScreen;
