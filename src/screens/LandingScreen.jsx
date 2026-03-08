/**
 * Лендинг страница PlanRun
 */

import React, { useState, useEffect } from 'react';
import { useNavigate, useLocation, useSearchParams } from 'react-router-dom';
import LoginModal from '../components/LoginModal';
import RegisterModal from '../components/RegisterModal';
import './LandingScreen.css';

const LandingScreen = ({ onRegister, registrationEnabled = true }) => {
  const navigate = useNavigate();
  const location = useLocation();
  const [searchParams, setSearchParams] = useSearchParams();
  const [loginOpen, setLoginOpen] = useState(false);
  const [registerOpen, setRegisterOpen] = useState(false);

  useEffect(() => {
    const fromState = location.state?.openLogin;
    const fromQuery = searchParams.get('openLogin') === '1';
    if (fromState || fromQuery) {
      setLoginOpen(true);
      if (fromState) navigate(location.pathname, { replace: true, state: {} });
      if (fromQuery) setSearchParams((p) => { const n = new URLSearchParams(p); n.delete('openLogin'); return n; }, { replace: true });
    }
  }, [location.state?.openLogin, searchParams, location.pathname, navigate, setSearchParams]);

  return (
    <div className="landing-container">
      {!registrationEnabled && (
        <div className="landing-notice" role="alert">
          Регистрация временно отключена администратором.
        </div>
      )}
      <div className="landing-header">
        <div className="landing-nav">
          {registrationEnabled && (
          <button
            type="button"
            className="btn btn-landing-secondary"
            onClick={(e) => {
              e.preventDefault();
              e.stopPropagation();
              setTimeout(() => setRegisterOpen(true), 0);
            }}
          >
            Регистрация
          </button>
          )}
          <button
            type="button"
            className="btn btn-landing-secondary"
            onClick={(e) => {
              e.preventDefault();
              e.stopPropagation();
              setTimeout(() => setLoginOpen(true), 0);
            }}
          >
            Вход
          </button>
        </div>
      </div>

      <LoginModal isOpen={loginOpen} onClose={() => setLoginOpen(false)} />
      <RegisterModal
        isOpen={registerOpen}
        onClose={() => setRegisterOpen(false)}
        onRegister={onRegister}
      />

      <div className="landing-hero">
        <div className="landing-hero-inner">
          <div className="hero-grid">
            <div className="hero-text">
              <h1>planRUN <br /> найди своего тренера<br />или создай план с AI</h1>
              <div className="subtitle">
                Личный беговой тренер с обратной связью — или AI‑план за минуту. 
                Индивидуальный подход, учёт пульса, темпа, здоровья и доступных дней. От 5 км до марафона.
              </div>
              
              <div className="landing-badges">
                <span className="badge">AI‑план за 1 минуту</span>
                <span className="badge">Тренер с правками плана</span>
                <span className="badge">Импорт GPX/TCX из часов и бота</span>
                <span className="badge">Пульс, темп, каденс, высота</span>
              </div>
              
              <div className="landing-cta">
                <button 
                  className="btn-landing btn-landing-primary"
                  onClick={() => navigate('/register')}
                >
                  🔥 Сгенерировать AI‑план
                </button>
                <a
                  href="/planrun.apk"
                  className="btn-landing btn-landing-secondary"
                  style={{ marginLeft: '12px', textDecoration: 'none', display: 'inline-flex', alignItems: 'center' }}
                  download="planrun.apk"
                >
                  📱 Скачать приложение Android
                </a>
              </div>
              <div className="landing-coach-cta">
                <button
                  className="btn-landing btn-landing-outline"
                  onClick={() => navigate('/trainers/apply')}
                >
                  Стать тренером на planRUN
                </button>
              </div>
            </div>
            
            <div className="hero-image">
              <img
                src="/hero.webp"
                alt="Бегун на тренировке"
              />
            </div>
          </div>
        </div>
      </div>

      {/* Две карточки выбора пути */}
      <div className="feature-section">
        <div className="features-wrap">
          <div className="landing-features" style={{ maxWidth: '900px', margin: '0 auto' }}>
            <div className="feature-card">
              <div className="feature-icon">🤖</div>
              <div>
                <div className="feature-title">AI‑план за 1 минуту</div>
                <div className="feature-text">
                  Учитываем дистанцию, цель по времени, доступные дни, пульс и базовый объём. 
                  Автоадаптация каждую неделю по вашим фактическим тренировкам.
                </div>
              </div>
            </div>
            <div className="feature-card">
              <div className="feature-icon">🧑‍🏫</div>
              <div>
                <div className="feature-title">Тренер с обратной связью</div>
                <div className="feature-text">
                  Живое ведение, корректировки плана, ответы на вопросы. 
                  Совместный разбор прогресса и слабых мест.
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      
      {/* Ключевые преимущества */}
      <div className="feature-section" style={{ marginTop: '8px' }}>
        <div className="features-wrap">
          <div className="landing-features">
            <div className="feature-card">
              <div className="feature-icon">📈</div>
              <div>
                <div className="feature-title">План vs факт каждую неделю</div>
                <div className="feature-text">
                  Сравниваем выполненные тренировки с планом, анализируем пульс, темп, каденс, высоту и калории. 
                  Рекомендации по нагрузке и ключевым дням.
                </div>
              </div>
            </div>
            
            <div className="feature-card">
              <div className="feature-icon">🎯</div>
              <div>
                <div className="feature-title">Планы 12–30 недель</div>
                <div className="feature-text">
                  5/10/21/42 км, темповые, интервалы, ОФП, taper перед стартом. 
                  Подгоняем объём и темп под ваш график и самочувствие.
                </div>
              </div>
            </div>
            
            <div className="feature-card">
              <div className="feature-icon">🫀</div>
              <div>
                <div className="feature-title">Глубокие метрики</div>
                <div className="feature-text">
                  Пульс, каденс, высота, калории, темп. 
                  Еженедельные отчёты и готовые данные для AI‑анализа или тренера.
                </div>
              </div>
            </div>
            
            <div className="feature-card">
              <div className="feature-icon">👥</div>
              <div>
                <div className="feature-title">Командная работа</div>
                <div className="feature-text">
                  Доступ для тренера, совместные корректировки, комментарии к ключевым тренировкам. 
                  Человечность в паре с данными.
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      
      <div className="landing-footer">
        <p>&copy; 2025 PlanRun. Умные тренировки для бегунов.</p>
        <p style={{ marginTop: '8px', opacity: 0.7 }}>
          🏃 Бегите умно. Выберите: живой тренер или AI — и достигайте цели.
        </p>
      </div>
    </div>
  );
};

export default LandingScreen;
