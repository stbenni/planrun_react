/**
 * Экран регистрации нового пользователя
 * Полная многошаговая форма со всеми полями
 */

import React, { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import useAuthStore from '../stores/useAuthStore';
import './RegisterScreen.css';

const RegisterScreen = ({ onRegister, embedInModal, onSuccess, onClose }) => {
  const navigate = useNavigate();
  const { api } = useAuthStore();
  const [step, setStep] = useState(0);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  
  // Данные формы - все поля
  const [formData, setFormData] = useState({
    // Шаг 0: Режим
    training_mode: 'ai',
    
    // Шаг 1: Аккаунт
    username: '',
    password: '',
    email: '',
    
    // Шаг 2: Цель
    goal_type: 'health',
    race_distance: '',
    race_date: '',
    race_target_time: '',
    target_marathon_date: '',
    target_marathon_time: '',
    weight_goal_kg: '',
    weight_goal_date: '',
    health_program: '',
    health_plan_weeks: '',
    training_start_date: getNextMonday(),
    
    // Шаг 3: Профиль
    gender: null,
    birth_year: '',
    height_cm: '',
    weight_kg: '',
    experience_level: 'novice',
    weekly_base_km: '',
    sessions_per_week: '',
    preferred_days: [],
    preferred_ofp_days: [],
    ofp_preference: '',
    training_time_pref: '',
    has_treadmill: false,
    health_notes: '',
    
    // Расширенный профиль (для race/time_improvement)
    easy_pace_min: '', // формат MM:SS
    easy_pace_sec: '', // для сохранения в БД
    is_first_race_at_distance: false,
    last_race_distance: '',
    last_race_distance_km: '',
    last_race_time: '',
    last_race_date: '',
  });
  
  const [validationErrors, setValidationErrors] = useState({});
  const [showExtendedProfile, setShowExtendedProfile] = useState(false);
  const [showRaceFields, setShowRaceFields] = useState(false);
  const [showWeightLossFields, setShowWeightLossFields] = useState(false);
  const [showHealthFields, setShowHealthFields] = useState(false);
  const [showHealthPlanWeeks, setShowHealthPlanWeeks] = useState(false);

  // Функция для получения следующего понедельника
  function getNextMonday() {
    const today = new Date();
    const day = today.getDay();
    const diff = day === 0 ? 1 : 8 - day; // Если воскресенье, то +1, иначе до следующего понедельника
    const nextMonday = new Date(today);
    nextMonday.setDate(today.getDate() + diff);
    return nextMonday.toISOString().split('T')[0];
  }

  // Обновляем видимость полей при изменении цели
  useEffect(() => {
    const goalType = formData.goal_type;
    setShowRaceFields(goalType === 'race' || goalType === 'time_improvement');
    setShowWeightLossFields(goalType === 'weight_loss');
    setShowHealthFields(goalType === 'health');
    setShowExtendedProfile(goalType === 'race' || goalType === 'time_improvement');
    setShowHealthPlanWeeks(formData.health_program === 'custom');
  }, [formData.goal_type, formData.health_program]);

  // Для режима self пропускаем шаг 2
  const getTotalSteps = () => {
    return formData.training_mode === 'self' ? 3 : 4;
  };

  const handleChange = (field, value) => {
    setFormData(prev => ({ ...prev, [field]: value }));
    // Очищаем ошибку валидации при изменении
    if (validationErrors[field]) {
      setValidationErrors(prev => {
        const newErrors = { ...prev };
        delete newErrors[field];
        return newErrors;
      });
    }
  };

  const handleArrayChange = (field, value, checked) => {
    setFormData(prev => {
      const currentArray = prev[field] || [];
      const newArray = checked
        ? [...currentArray, value]
        : currentArray.filter(item => item !== value);
      
      // Автоматически обновляем sessions_per_week если изменяем preferred_days
      const updates = { [field]: newArray };
      if (field === 'preferred_days') {
        updates.sessions_per_week = String(newArray.length);
      }
      
      return { ...prev, ...updates };
    });
  };

  const validateField = async (field, value) => {
    const currentApi = api || useAuthStore.getState().api;
    if (!currentApi) return true;
    
    try {
      const result = await currentApi.validateField(field, value);
      if (!result.valid) {
        setValidationErrors(prev => ({ ...prev, [field]: result.message }));
        return false;
      }
      return true;
    } catch (err) {
      console.error('Validation error:', err);
      return true;
    }
  };

  const handleNext = async () => {
    setError('');
    
    if (step === 0) {
      // Шаг 0: Режим тренировок
      if (!formData.training_mode) {
        setError('Пожалуйста, выберите режим тренировок');
        return;
      }
      // Для режима self пропускаем шаг 2
      if (formData.training_mode === 'self') {
        setStep(3); // Переходим сразу к профилю
      } else {
        setStep(1);
      }
    } else if (step === 1) {
      // Шаг 1: Аккаунт
      if (!formData.username || formData.username.length < 3) {
        setError('Имя пользователя должно быть не менее 3 символов');
        return;
      }
      if (!formData.password || formData.password.length < 6) {
        setError('Пароль должен быть не менее 6 символов');
        return;
      }
      
      const usernameValid = await validateField('username', formData.username);
      if (!usernameValid) {
        setError(validationErrors.username || 'Имя пользователя уже занято');
        return;
      }
      
      if (formData.email) {
        await validateField('email', formData.email);
      }
      
      setStep(2);
    } else if (step === 2) {
      // Шаг 2: Цель - валидация в зависимости от типа цели
      if (formData.goal_type === 'race' || formData.goal_type === 'time_improvement') {
        if (!formData.race_date) {
          setError('Дата забега обязательна для выбранной цели');
          return;
        }
      } else if (formData.goal_type === 'weight_loss') {
        if (!formData.weight_goal_kg) {
          setError('Укажите целевой вес');
          return;
        }
        if (!formData.weight_goal_date) {
          setError('Укажите дату, к которой хотите достичь цели');
          return;
        }
      } else if (formData.goal_type === 'health') {
        if (!formData.health_program) {
          setError('Выберите программу');
          return;
        }
        if (formData.health_program === 'custom' && !formData.health_plan_weeks) {
          setError('Укажите срок плана');
          return;
        }
      }
      
      if (!formData.training_start_date) {
        setError('Укажите дату начала тренировок');
        return;
      }
      
      setStep(3);
    } else if (step === 3) {
      // Шаг 3: Профиль
      if (!formData.gender) {
        setError('Пожалуйста, выберите пол');
        return;
      }
      
      if (formData.training_mode !== 'self' && !formData.experience_level) {
        setError('Укажите ваш опыт');
        return;
      }
      
      // Отправляем регистрацию
      await handleSubmit();
    }
  };

  const handleSubmit = async () => {
    setLoading(true);
    setError('');
    
    try {
      // Получаем API клиент
      const currentApi = api || useAuthStore.getState().api;
      if (!currentApi) {
        setError('API не инициализирован. Попробуйте обновить страницу.');
        setLoading(false);
        return;
      }
      
      // Подготавливаем данные для отправки
      const submitData = {
        ...formData,
        preferred_days: formData.preferred_days,
        preferred_ofp_days: formData.preferred_ofp_days,
        has_treadmill: formData.has_treadmill ? 1 : 0,
        is_first_race_at_distance: formData.is_first_race_at_distance ? 1 : 0,
        // Автоматически вычисляем sessions_per_week из preferred_days
        sessions_per_week: formData.preferred_days?.length || formData.sessions_per_week || null,
        // Удаляем device_type из регистрации (оно в интеграциях)
        device_type: undefined,
      };
      
      const result = await currentApi.register(submitData);
      if (result.success) {
        // Устанавливаем авторизацию в store перед вызовом onRegister
        useAuthStore.setState({ 
          user: result.user || { authenticated: true },
          isAuthenticated: true 
        });
        
        if (onRegister) {
          onRegister(result.user);
        }
        
        // Небольшая задержка чтобы состояние успело обновиться
        setTimeout(() => {
          // Перенаправляем на главную страницу с информацией о генерации плана
          navigate('/', { 
            state: { 
              registrationSuccess: true,
              planMessage: result.plan_message || 'Регистрация успешна!'
            } 
          });
        }, 100);
      } else {
        setError(result.error || 'Ошибка регистрации');
      }
    } catch (err) {
      setError(err.message || 'Произошла ошибка при регистрации');
    } finally {
      setLoading(false);
    }
  };

  const totalSteps = getTotalSteps();
  const progress = ((step + 1) / totalSteps) * 100;
  const dayLabels = { mon: 'Пн', tue: 'Вт', wed: 'Ср', thu: 'Чт', fri: 'Пт', sat: 'Сб', sun: 'Вс' };

  const formContent = (
      <div className={embedInModal ? 'register-content register-content--modal' : 'register-content'}>
        <h1 className="register-title">🏃 Начните свой путь</h1>
        <p className="register-subtitle">Создайте персональный план тренировок</p>
        
        <div className="register-step-progress">
          <div className="register-step-progress-fill" style={{ width: `${progress}%` }}></div>
        </div>
        
        <div className="step-indicator">
          <div className={`step ${step >= 0 ? 'active' : ''}`}>0. Режим</div>
          <div className={`step ${step >= 1 ? 'active' : ''}`} style={{ display: formData.training_mode === 'self' ? 'none' : 'block' }}>1. Аккаунт</div>
          <div className={`step ${step >= 2 ? 'active' : ''}`} style={{ display: formData.training_mode === 'self' ? 'none' : 'block' }}>2. Цель</div>
          <div className={`step ${step >= 3 ? 'active' : ''}`}>3. Профиль</div>
        </div>

        {error && <div className="register-error">{error}</div>}

        <form onSubmit={(e) => { e.preventDefault(); handleNext(); }} className="register-form">
          {/* Шаг 0: Выбор режима */}
          {step === 0 && (
            <div className="form-step">
              <h2>🏃 Добро пожаловать в PlanRun!</h2>
              <p style={{ marginBottom: '30px', color: '#6b7280', fontSize: '1.05em' }}>
                Выбери, как хочешь тренироваться:
              </p>
              
              <div className="training-mode-grid" style={{ gridTemplateColumns: '1fr 1fr 1fr', gap: '20px', marginBottom: '30px' }}>
                <label className={`training-mode-option ${formData.training_mode === 'ai' ? 'selected' : ''}`}>
                  <input
                    type="radio"
                    name="training_mode"
                    value="ai"
                    checked={formData.training_mode === 'ai'}
                    onChange={(e) => handleChange('training_mode', e.target.value)}
                  />
                  <div style={{ fontSize: '3em', marginBottom: '15px' }}>🤖</div>
                  <div style={{ fontWeight: 700, fontSize: '1.2em', marginBottom: '10px' }}>AI-ТРЕНЕР</div>
                  <div style={{ color: '#6b7280', fontSize: '0.95em', marginBottom: '15px' }}>(бесплатно)</div>
                  <ul style={{ textAlign: 'left', listStyle: 'none', padding: 0, margin: 0 }}>
                    <li style={{ margin: '8px 0' }}>✓ AI создаст персональный план</li>
                    <li style={{ margin: '8px 0' }}>✓ Адаптирует его каждую неделю</li>
                    <li style={{ margin: '8px 0' }}>✓ Анализирует твой прогресс</li>
                  </ul>
                  <div style={{ marginTop: '20px', padding: '10px', background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)', color: 'white', borderRadius: '8px', fontWeight: 600 }}>👈 Рекомендуем</div>
                </label>
                
                <label className={`training-mode-option ${formData.training_mode === 'self' ? 'selected' : ''}`}>
                  <input
                    type="radio"
                    name="training_mode"
                    value="self"
                    checked={formData.training_mode === 'self'}
                    onChange={(e) => handleChange('training_mode', e.target.value)}
                  />
                  <div style={{ fontSize: '3em', marginBottom: '15px' }}>📝</div>
                  <div style={{ fontWeight: 700, fontSize: '1.2em', marginBottom: '10px' }}>САМОСТОЯТЕЛЬНО</div>
                  <div style={{ color: '#6b7280', fontSize: '0.95em', marginBottom: '15px' }}>(бесплатно)</div>
                  <ul style={{ textAlign: 'left', listStyle: 'none', padding: 0, margin: 0 }}>
                    <li style={{ margin: '8px 0' }}>✓ Создавай план сам</li>
                    <li style={{ margin: '8px 0' }}>✓ Добавляй тренировки вручную</li>
                    <li style={{ margin: '8px 0' }}>✓ Полный контроль над планом</li>
                  </ul>
                </label>
                
                <label style={{ opacity: 0.6, cursor: 'not-allowed', background: '#f9fafb' }}>
                  <input type="radio" name="training_mode" value="coach" disabled />
                  <div style={{ fontSize: '3em', marginBottom: '15px' }}>👤</div>
                  <div style={{ fontWeight: 700, fontSize: '1.2em', marginBottom: '10px' }}>ЖИВОЙ ТРЕНЕР</div>
                  <div style={{ color: '#6b7280', fontSize: '0.95em', marginBottom: '15px' }}>(от 1000₽/мес)</div>
                  <div style={{ position: 'absolute', top: '10px', right: '10px', background: '#fbbf24', color: 'white', padding: '4px 8px', borderRadius: '4px', fontSize: '0.75em', fontWeight: 600 }}>Скоро</div>
                </label>
              </div>
              
              <div style={{ textAlign: 'center', padding: '15px', background: '#f3f4f6', borderRadius: '10px', color: '#6b7280', fontSize: '0.9em', marginBottom: '20px' }}>
                💡 Функция "Живой тренер" появится в ближайшее время. Пока доступен только AI-тренер.
              </div>
            </div>
          )}

          {/* Шаг 1: Аккаунт */}
          {step === 1 && (
            <div className="form-step">
              <h2>Создайте аккаунт</h2>
              <div className="form-group">
                <label>Имя пользователя <span className="required">*</span></label>
                <input
                  type="text"
                  value={formData.username}
                  onChange={(e) => handleChange('username', e.target.value)}
                  placeholder="ivan_runner"
                  required
                />
                <small>Будет использоваться для входа и вашего персонального URL</small>
                {validationErrors.username && (
                  <small className="error-text">{validationErrors.username}</small>
                )}
              </div>
              
              <div className="form-group">
                <label>Пароль <span className="required">*</span></label>
                <input
                  type="password"
                  value={formData.password}
                  onChange={(e) => handleChange('password', e.target.value)}
                  placeholder="Минимум 6 символов"
                  minLength={6}
                  required
                />
                <small>Используйте надежный пароль</small>
              </div>
              
              <div className="form-group">
                <label>Email</label>
                <input
                  type="email"
                  value={formData.email}
                  onChange={(e) => handleChange('email', e.target.value)}
                  placeholder="your@email.com"
                />
                <small>Для восстановления пароля и уведомлений (необязательно)</small>
                {validationErrors.email && (
                  <small className="error-text">{validationErrors.email}</small>
                )}
              </div>
            </div>
          )}

          {/* Шаг 2: Цель */}
          {step === 2 && (
            <div className="form-step">
              <h2>🎯 Какая у тебя цель?</h2>
              
              <div className="form-group">
                <label>Что вы хотите достичь? <span className="required">*</span></label>
                <select
                  value={formData.goal_type}
                  onChange={(e) => handleChange('goal_type', e.target.value)}
                  required
                >
                  <option value="health">Просто бегать для здоровья</option>
                  <option value="race">Подготовка к забегу</option>
                  <option value="weight_loss">Снижение веса</option>
                  <option value="time_improvement">Улучшить время</option>
                </select>
              </div>
              
              {/* Поля для забега */}
              {showRaceFields && (
                <div>
                  <div className="form-group">
                    <label>Целевая дистанция забега</label>
                    <select
                      value={formData.race_distance}
                      onChange={(e) => handleChange('race_distance', e.target.value)}
                    >
                      <option value="">Выберите дистанцию</option>
                      <option value="5k">5 км</option>
                      <option value="10k">10 км</option>
                      <option value="half">Полумарафон (21.1 км)</option>
                      <option value="marathon">Марафон (42.2 км)</option>
                    </select>
                    <small>Какую дистанцию вы планируете пробежать?</small>
                  </div>
                  
                  <div className="form-group">
                    <label>Дата забега <span className="required">*</span></label>
                    <input
                      type="date"
                      value={formData.race_date}
                      onChange={(e) => handleChange('race_date', e.target.value)}
                      min={new Date(Date.now() + 86400000).toISOString().split('T')[0]}
                      required={showRaceFields}
                    />
                    <small>План будет рассчитан до этой даты. Дата должна быть в будущем.</small>
                  </div>
                  
                  <div className="form-group">
                    <label>Целевое время</label>
                    <input
                      type="time"
                      step="1"
                      value={formData.race_target_time}
                      onChange={(e) => handleChange('race_target_time', e.target.value)}
                    />
                    <small>Например: 3:30:00 для марафона</small>
                  </div>
                </div>
              )}
              
              {/* Поля для похудения */}
              {showWeightLossFields && (
                <div>
                  <div className="form-group">
                    <label>Целевой вес (кг) <span className="required">*</span></label>
                    <input
                      type="number"
                      min="30"
                      max="250"
                      step="0.1"
                      placeholder="70"
                      value={formData.weight_goal_kg}
                      onChange={(e) => handleChange('weight_goal_kg', e.target.value)}
                      required={showWeightLossFields}
                    />
                    <small>Реалистичная цель: не более 1 кг в неделю</small>
                  </div>
                  
                  <div className="form-group">
                    <label>К какой дате хотите достичь цели? <span className="required">*</span></label>
                    <input
                      type="date"
                      value={formData.weight_goal_date}
                      onChange={(e) => handleChange('weight_goal_date', e.target.value)}
                      min={new Date(Date.now() + 28 * 24 * 60 * 60 * 1000).toISOString().split('T')[0]}
                      required={showWeightLossFields}
                    />
                    <small>Минимум 4 недели от сегодня.</small>
                  </div>
                </div>
              )}
              
              {/* Поля для здоровья */}
              {showHealthFields && (
                <div>
                  <div className="form-group">
                    <label>Выберите программу <span className="required">*</span></label>
                    <div className="program-options">
                      {[
                        { value: 'start_running', icon: '🌱', name: 'Начни бегать', duration: '8 недель', desc: 'С нуля до 20 минут непрерывного бега' },
                        { value: 'couch_to_5k', icon: '🏃', name: '5 км без остановки', duration: '10 недель', desc: 'Классическая программа Couch to 5K' },
                        { value: 'regular_running', icon: '💪', name: 'Регулярный бег', duration: '12 недель', desc: '3 раза в неделю, плавный рост объёма' },
                        { value: 'custom', icon: '⚙️', name: 'Свой план', duration: 'по выбору', desc: 'Укажу параметры сам' },
                      ].map(program => (
                        <label key={program.value} className={`program-option ${formData.health_program === program.value ? 'selected' : ''}`}>
                          <input
                            type="radio"
                            name="health_program"
                            value={program.value}
                            checked={formData.health_program === program.value}
                            onChange={(e) => handleChange('health_program', e.target.value)}
                          />
                          <div className="program-card">
                            <span className="program-icon">{program.icon}</span>
                            <span className="program-name">{program.name}</span>
                            <span className="program-duration">{program.duration}</span>
                            <span className="program-desc">{program.desc}</span>
                          </div>
                        </label>
                      ))}
                    </div>
                  </div>
                  
                  {showHealthPlanWeeks && (
                    <div className="form-group">
                      <label>На какой срок план? <span className="required">*</span></label>
                      <select
                        value={formData.health_plan_weeks}
                        onChange={(e) => handleChange('health_plan_weeks', e.target.value)}
                        required={showHealthPlanWeeks}
                      >
                        <option value="">Выберите...</option>
                        <option value="4">4 недели (пробный)</option>
                        <option value="8">8 недель (базовый)</option>
                        <option value="12">12 недель (полный курс)</option>
                        <option value="16">16 недель (расширенный)</option>
                      </select>
                    </div>
                  )}
                  
                </div>
              )}
              
              <div className="form-group" style={{ marginTop: '20px', paddingTop: '20px', borderTop: '2px solid #e5e7eb' }}>
                <label>📅 С какого дня начинаем тренировки? <span className="required">*</span></label>
                <input
                  type="date"
                  value={formData.training_start_date}
                  onChange={(e) => handleChange('training_start_date', e.target.value)}
                  min={new Date().toISOString().split('T')[0]}
                  required
                />
                <small>Выбери дату начала тренировок. План будет рассчитан от этой даты до цели.</small>
              </div>
            </div>
          )}

          {/* Шаг 3: Профиль */}
          {step === 3 && (
            <div className="form-step">
              <h2>Ваш профиль</h2>
              
              {formData.training_mode === 'self' && (
                <p style={{ marginBottom: '20px', color: '#6b7280', fontSize: '1.05em' }}>
                  Для создания календаря нужна базовая информация:
                </p>
              )}
              
              <div className="form-group">
                <label>Пол <span className="required">*</span></label>
                <div className="form-row">
                  <label className={`gender-option ${formData.gender === 'male' ? 'selected' : ''}`}>
                    <input
                      type="radio"
                      name="gender"
                      value="male"
                      checked={formData.gender === 'male'}
                      onChange={(e) => handleChange('gender', e.target.value)}
                      required
                    />
                    Мужской
                  </label>
                  <label className={`gender-option ${formData.gender === 'female' ? 'selected' : ''}`}>
                    <input
                      type="radio"
                      name="gender"
                      value="female"
                      checked={formData.gender === 'female'}
                      onChange={(e) => handleChange('gender', e.target.value)}
                      required
                    />
                    Женский
                  </label>
                </div>
              </div>
              
              {formData.training_mode !== 'self' && (
                <>
                  <div className="form-row">
                    <div className="form-group">
                      <label>Год рождения</label>
                      <input
                        type="number"
                        min="1930"
                        max={new Date().getFullYear()}
                        placeholder="1990"
                        value={formData.birth_year}
                        onChange={(e) => handleChange('birth_year', e.target.value)}
                      />
                    </div>
                    <div className="form-group">
                      <label>Рост (см)</label>
                      <input
                        type="number"
                        min="100"
                        max="250"
                        placeholder="175"
                        value={formData.height_cm}
                        onChange={(e) => handleChange('height_cm', e.target.value)}
                      />
                    </div>
                  </div>
                  
                  <div className="form-row">
                    <div className="form-group">
                      <label>Вес (кг)</label>
                      <input
                        type="number"
                        min="30"
                        max="250"
                        step="0.1"
                        placeholder="70.0"
                        value={formData.weight_kg}
                        onChange={(e) => handleChange('weight_kg', e.target.value)}
                      />
                    </div>
                    <div className="form-group">
                      <label>💪 Какой у тебя опыт? <span className="required">*</span></label>
                      <select
                        value={formData.experience_level}
                        onChange={(e) => handleChange('experience_level', e.target.value)}
                        required
                      >
                        <option value="novice">Новичок (не бегаю или менее 3 месяцев)</option>
                        <option value="beginner">Начинающий (3-6 месяцев регулярного бега)</option>
                        <option value="intermediate">Средний (6-12 месяцев регулярного бега)</option>
                        <option value="advanced">Продвинутый (1-2 года регулярного бега)</option>
                        <option value="expert">Опытный (более 2 лет регулярного бега)</option>
                      </select>
                      <small>Выберите уровень, который лучше всего описывает ваш опыт в беге</small>
                    </div>
                  </div>
                  
                  <div className="form-row">
                    <div className="form-group">
                      <label>🏃 Сколько бегаешь сейчас?</label>
                      <input
                        type="number"
                        min="0"
                        max="400"
                        step="1"
                        placeholder="30"
                        value={formData.weekly_base_km}
                        onChange={(e) => handleChange('weekly_base_km', e.target.value)}
                      />
                      <small>км в неделю</small>
                    </div>
                    <div className="form-group">
                      <label>Тренировок в неделю</label>
                      <input
                        type="number"
                        min="1"
                        max="7"
                        placeholder="3"
                        value={formData.preferred_days?.length || formData.sessions_per_week || ''}
                        readOnly
                        style={{ backgroundColor: '#f3f4f6', cursor: 'not-allowed' }}
                      />
                      <small>Автоматически рассчитано из выбранных дней для бега</small>
                    </div>
                  </div>
                  
                  {/* Расширенный профиль бегуна */}
                  {showExtendedProfile && (
                    <div className="extended-profile">
                      <h3 style={{ margin: '25px 0 15px', color: '#374151', fontSize: '1.1em' }}>📊 Расскажи больше о своём беге</h3>
                      <p style={{ color: '#6b7280', marginBottom: '20px', fontSize: '0.95em' }}>
                        Эти данные помогут создать более точный план (необязательно)
                      </p>
                      
                      <div className="form-group">
                        <label>🚶 Комфортный темп (минуты:секунды на км)</label>
                        <input
                          type="text"
                          value={formData.easy_pace_min || ''}
                          onChange={(e) => {
                            let value = e.target.value;
                            
                            // Удаляем все кроме цифр и двоеточия
                            value = value.replace(/[^\d:]/g, '');
                            
                            // Ограничиваем количество двоеточий (только одно)
                            const colonCount = (value.match(/:/g) || []).length;
                            if (colonCount > 1) {
                              const firstColonIndex = value.indexOf(':');
                              value = value.substring(0, firstColonIndex + 1) + value.substring(firstColonIndex + 1).replace(/:/g, '');
                            }
                            
                            // Ограничиваем длину до 5 символов (MM:SS)
                            if (value.length > 5) {
                              value = value.substring(0, 5);
                            }
                            
                            // Проверяем валидность формата
                            // Разрешаем: пусто, M, MM, M:, MM:, M:S, MM:S, M:SS, MM:SS
                            const validPattern = /^(\d{1,2}:?\d{0,2})?$/;
                            if (value === '' || validPattern.test(value)) {
                              handleChange('easy_pace_min', value);
                              
                              // Конвертируем в секунды для сохранения только если формат полный (MM:SS)
                              if (value.includes(':')) {
                                const parts = value.split(':');
                                if (parts.length === 2) {
                                  const minStr = parts[0];
                                  const secStr = parts[1];
                                  
                                  // Проверяем что есть и минуты и секунды (хотя бы одна цифра)
                                  if (minStr.length > 0 && secStr.length >= 1) {
                                    const min = parseInt(minStr) || 0;
                                    const sec = parseInt(secStr.padEnd(2, '0')) || 0; // Дополняем секунды нулем если нужно
                                    
                                    if (!isNaN(min) && !isNaN(sec) && sec < 60 && min >= 0) {
                                      const totalSec = min * 60 + sec;
                                      if (totalSec >= 180 && totalSec <= 600) {
                                        handleChange('easy_pace_sec', String(totalSec));
                                      } else {
                                        // Не очищаем, просто не сохраняем если вне диапазона
                                        // handleChange('easy_pace_sec', '');
                                      }
                                    }
                                  }
                                }
                              } else if (value === '') {
                                handleChange('easy_pace_sec', '');
                              }
                            }
                          }}
                          onBlur={(e) => {
                            // При потере фокуса форматируем значение если оно неполное
                            let value = e.target.value;
                            if (value && value.includes(':')) {
                              const parts = value.split(':');
                              if (parts.length === 2) {
                                const min = parts[0].padStart(1, '0'); // Минуты: минимум 1 цифра
                                const sec = parts[1].padEnd(2, '0').substring(0, 2); // Секунды: ровно 2 цифры
                                const formatted = `${min}:${sec}`;
                                if (formatted !== value) {
                                  handleChange('easy_pace_min', formatted);
                                  // Пересчитываем секунды
                                  const totalSec = (parseInt(min) || 0) * 60 + (parseInt(sec) || 0);
                                  if (totalSec >= 180 && totalSec <= 600) {
                                    handleChange('easy_pace_sec', String(totalSec));
                                  }
                                }
                              }
                            }
                          }}
                          placeholder="7:00"
                          maxLength={5}
                        />
                        <small>Введите темп в формате минуты:секунды (например, 7:00 означает 7 минут на километр)</small>
                      </div>
                      
                      <div className="form-group">
                        <label>🎯 Это твой первый забег на целевую дистанцию?</label>
                        <div className="radio-group-horizontal">
                          <label className="radio-option">
                            <input
                              type="radio"
                              name="is_first_race_at_distance"
                              value="1"
                              checked={formData.is_first_race === 1 || formData.is_first_race === true}
                              onChange={() => handleChange('is_first_race', 1)}
                            />
                            <span>Да, первый раз</span>
                          </label>
                          <label className="radio-option">
                            <input
                              type="radio"
                              name="is_first_race_at_distance"
                              value="0"
                              checked={formData.is_first_race_at_distance === false || formData.is_first_race_at_distance === 0}
                              onChange={() => handleChange('is_first_race_at_distance', false)}
                            />
                            <span>Нет, уже бегал(а)</span>
                          </label>
                        </div>
                      </div>
                      
                      <div className="form-group">
                        <label>🏅 Последний официальный результат</label>
                        <small style={{ display: 'block', marginBottom: '10px' }}>Поможет точнее оценить твой уровень</small>
                        
                        <div className="form-row" style={{ marginBottom: '10px' }}>
                          <div className="form-group" style={{ marginBottom: 0 }}>
                            <label style={{ fontSize: '0.85em' }}>Дистанция</label>
                            <select
                              value={formData.last_race_distance}
                              onChange={(e) => handleChange('last_race_distance', e.target.value)}
                            >
                              <option value="">Не указано</option>
                              <option value="5k">5 км</option>
                              <option value="10k">10 км</option>
                              <option value="half">Полумарафон</option>
                              <option value="marathon">Марафон</option>
                              <option value="other">Другая</option>
                            </select>
                          </div>
                          {formData.last_race_distance === 'other' && (
                            <div className="form-group" style={{ marginBottom: 0 }}>
                              <label style={{ fontSize: '0.85em' }}>Дистанция последнего забега (км)</label>
                              <input
                                type="number"
                                min="0"
                                max="200"
                                step="0.1"
                                placeholder="15"
                                value={formData.last_race_distance_km}
                                onChange={(e) => handleChange('last_race_distance_km', e.target.value)}
                              />
                              <small style={{ fontSize: '0.85em' }}>Укажите точную дистанцию в километрах, если она отличается от стандартных</small>
                            </div>
                          )}
                        </div>
                        
                        {formData.last_race_distance && formData.last_race_distance !== '' && (
                          <div className="form-row">
                            <div className="form-group" style={{ marginBottom: 0 }}>
                              <label style={{ fontSize: '0.85em' }}>Результат</label>
                              <input
                                type="time"
                                step="1"
                                value={formData.last_race_time}
                                onChange={(e) => handleChange('last_race_time', e.target.value)}
                              />
                              <small>Формат: ЧЧ:ММ:СС</small>
                            </div>
                            <div className="form-group" style={{ marginBottom: 0 }}>
                              <label style={{ fontSize: '0.85em' }}>Когда</label>
                              <input
                                type="month"
                                max={new Date().toISOString().slice(0, 7)}
                                value={formData.last_race_date}
                                onChange={(e) => handleChange('last_race_date', e.target.value)}
                              />
                            </div>
                          </div>
                        )}
                      </div>
                    </div>
                  )}
                  
                  <div className="form-group">
                    <label>Выбери дни для бега</label>
                    <div className="checkbox-group">
                      {Object.entries(dayLabels).map(([key, label]) => (
                        <label key={key} className="checkbox-item">
                          <input
                            type="checkbox"
                            value={key}
                            checked={formData.preferred_days.includes(key)}
                            onChange={(e) => handleArrayChange('preferred_days', key, e.target.checked)}
                          />
                          <span>{label}</span>
                        </label>
                      ))}
                    </div>
                  </div>
                  
                  <div className="form-group">
                    <label>Выбери дни для ОФП</label>
                    <div className="checkbox-group">
                      {Object.entries(dayLabels).map(([key, label]) => (
                        <label key={key} className="checkbox-item">
                          <input
                            type="checkbox"
                            value={key}
                            checked={formData.preferred_ofp_days.includes(key)}
                            onChange={(e) => handleArrayChange('preferred_ofp_days', key, e.target.checked)}
                          />
                          <span>{label}</span>
                        </label>
                      ))}
                    </div>
                    <small>ОФП — общая физическая подготовка (силовые упражнения, растяжка)</small>
                  </div>
                  
                  <div className="form-group">
                    <label>Где удобно делать ОФП?</label>
                    <select
                      value={formData.ofp_preference}
                      onChange={(e) => handleChange('ofp_preference', e.target.value)}
                    >
                      <option value="">Не важно</option>
                      <option value="gym">В тренажерном зале (с тренажерами)</option>
                      <option value="home">Дома самостоятельно</option>
                      <option value="both">И в зале, и дома</option>
                      <option value="group_classes">Групповые занятия</option>
                      <option value="online">Онлайн-платформы</option>
                    </select>
                    <small>Это поможет составить более подходящий план тренировок</small>
                  </div>
                  
                  <div className="form-row">
                    <div className="form-group">
                      <label>Предпочитаемое время</label>
                      <select
                        value={formData.training_time_pref}
                        onChange={(e) => handleChange('training_time_pref', e.target.value)}
                      >
                        <option value="">Не важно</option>
                        <option value="morning">Утро</option>
                        <option value="day">День</option>
                        <option value="evening">Вечер</option>
                      </select>
                    </div>
                    <div className="form-group">
                      <label style={{ display: 'flex', alignItems: 'center', gap: '10px', padding: '12px', border: '2px solid #e5e7eb', borderRadius: '10px', cursor: 'pointer', marginTop: '28px' }}>
                        <input
                          type="checkbox"
                          checked={formData.has_treadmill}
                          onChange={(e) => handleChange('has_treadmill', e.target.checked)}
                        />
                        <span>Есть доступ к беговой дорожке</span>
                      </label>
                    </div>
                  </div>
                  
                  <div className="form-group">
                    <label>Ограничения по здоровью</label>
                    <textarea
                      rows="3"
                      placeholder="Травмы, ограничения, рекомендации врача (необязательно)"
                      value={formData.health_notes}
                      onChange={(e) => handleChange('health_notes', e.target.value)}
                    />
                  </div>
                  
                  <div className="form-group">
                    <label>Устройство/платформа</label>
                    <input
                      type="text"
                      placeholder="Garmin, Polar, Coros, Apple Watch..."
                      value={formData.device_type}
                      onChange={(e) => handleChange('device_type', e.target.value)}
                    />
                    <small>Для лучшей интеграции (необязательно)</small>
                  </div>
                </>
              )}
            </div>
          )}

          <div className="form-actions">
            {step > 0 && (
              <button
                type="button"
                className="btn btn-secondary"
                onClick={() => {
                  // Для режима self при возврате с шага 3 нужно вернуться на шаг 1
                  if (step === 3 && formData.training_mode === 'self') {
                    setStep(1);
                  } else {
                    setStep(step - 1);
                  }
                }}
                disabled={loading}
              >
                ← Назад
              </button>
            )}
            <button
              type="submit"
              className="btn btn-primary"
              disabled={loading}
            >
              {loading ? 'Обработка...' : step === 3 ? 'Создать аккаунт' : 'Далее →'}
            </button>
          </div>
        </form>

        <div className="register-footer">
          <p>Уже есть аккаунт? <a href="/landing" onClick={(e) => { e.preventDefault(); if (embedInModal && onClose) onClose(); navigate('/landing', { state: embedInModal ? undefined : { openLogin: true } }); }}>Войти</a></p>
        </div>
      </div>
    );

  return embedInModal ? formContent : <div className="register-container">{formContent}</div>;
};

export default RegisterScreen;
