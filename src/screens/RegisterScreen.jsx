/**
 * Экран регистрации: только минимальная (логин, email, пароль) или специализация (после входа).
 * Полная многошаговая форма не используется — везде минимальная регистрация, затем специализация на дашборде.
 */

import React, { useState, useEffect, useRef } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import useAuthStore from '../stores/useAuthStore';
import {
  ClipboardListIcon,
  TimeIcon,
  CheckIcon,
  AlertTriangleIcon,
  XCircleIcon,
  BotIcon,
  PenLineIcon,
  UserIcon,
  TargetIcon,
  LeafIcon,
  RunningIcon,
  HeartIcon,
  SettingsIcon,
  CalendarIcon,
  GraduationCapIcon,
  PaceIcon,
  MedalIcon,
} from '../components/common/Icons';
import getAuthClient from '../api/getAuthClient';
import { useVerificationCodeFlow } from '../hooks/useVerificationCodeFlow';
import './RegisterScreen.css';
import './LoginScreen.css'; /* стили логина для короткого попапа регистрации */

const RegisterScreen = ({ onRegister, embedInModal, onSuccess, onClose, minimalOnly, specializationOnly, onSpecializationSuccess }) => {
  const navigate = useNavigate();
  const { api, updateUser } = useAuthStore();
  const currentApi = api || getAuthClient();
  // Везде только два режима: специализация (попап после входа) или минимальная регистрация (логин/email/пароль)
  const isMinimalFlow = !specializationOnly;
  const [step, setStep] = useState(0);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const {
    verificationStep,
    verificationCode,
    setVerificationCode,
    codeAttemptsLeft,
    isCoolingDown: isVerificationCoolingDown,
    secondsLeft: verificationSecondsLeft,
    handleRequestError,
    handleConfirmError,
    markCodeSent,
  } = useVerificationCodeFlow({ onError: setError });
  
  // Данные формы - все поля
  const [formData, setFormData] = useState({
    // Шаг 0: Режим (без выбора по умолчанию — пользователь выбирает карточкой)
    training_mode: '',
    
    // Шаг 1: Аккаунт
    username: '',
    password: '',
    email: '',
    
    // Шаг 2: Цель
    goal_type: '',
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
    will_do_ofp: '', // '' | 'yes' | 'no' — показываем вопросы ОФП только при 'yes'
    preferred_ofp_days: [],
    ofp_preference: '',
    training_time_pref: '',
    has_treadmill: false,
    health_notes: '',
    device_type: '',
    
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
  /** После успешной отправки специализации с генерацией плана — показать оповещение перед закрытием */
  const [planSubmitResult, setPlanSubmitResult] = useState(null);
  const [goalStepFieldsHeight, setGoalStepFieldsHeight] = useState(0);
  const goalStepFieldsInnerRef = useRef(null);
  const [goalAssessment, setGoalAssessment] = useState(null);
  const [assessmentLoading, setAssessmentLoading] = useState(false);
  const assessmentTimerRef = useRef(null);

  const requestVerificationCode = async () => {
    if (!currentApi) {
      throw new Error('API не инициализирован.');
    }

    await currentApi.sendVerificationCode(formData.email.trim());
    markCodeSent();
  };

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

  // Замер высоты блока цели для плавной анимации при переключении селекта
  useEffect(() => {
    if (!formData.goal_type) {
      setGoalStepFieldsHeight(0);
      return;
    }
    const measure = () => {
      const el = goalStepFieldsInnerRef.current;
      if (el) setGoalStepFieldsHeight(el.scrollHeight);
    };
    const id = requestAnimationFrame(() => {
      requestAnimationFrame(measure);
    });
    return () => cancelAnimationFrame(id);
  }, [formData.goal_type, showRaceFields, showWeightLossFields, showHealthFields, showHealthPlanWeeks, formData.health_program]);

  // При смене шага плавно прокручиваем форму вверх (в модалке — тело модалки, иначе окно)
  useEffect(() => {
    const el = document.querySelector('.app-modal-body');
    if (el) {
      el.scrollTo({ top: 0, behavior: 'smooth' });
    } else {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
  }, [step]);

  // Оценка реалистичности цели (VDOT)
  useEffect(() => {
    if (!showRaceFields) { setGoalAssessment(null); return; }
    if (!formData.race_distance || !formData.race_date) return;

    clearTimeout(assessmentTimerRef.current);
    assessmentTimerRef.current = setTimeout(async () => {
      setAssessmentLoading(true);
      try {
        if (!currentApi) return;
        const result = await currentApi.assessGoal({
          goal_type: formData.goal_type,
          race_distance: formData.race_distance,
          race_date: formData.race_date,
          race_target_time: formData.race_target_time || '',
          training_start_date: formData.training_start_date,
          weekly_base_km: formData.weekly_base_km || 0,
          sessions_per_week: formData.preferred_days?.length || formData.sessions_per_week || 3,
          experience_level: formData.experience_level,
          last_race_distance: formData.last_race_distance || '',
          last_race_distance_km: formData.last_race_distance_km || '',
          last_race_time: formData.last_race_time || '',
          easy_pace_sec: formData.easy_pace_sec || '',
        });
        if (result?.verdict) setGoalAssessment(result);
      } catch { /* ignore */ }
      finally { setAssessmentLoading(false); }
    }, 800);

    return () => clearTimeout(assessmentTimerRef.current);
  }, [
    showRaceFields, formData.race_distance, formData.race_date, formData.race_target_time,
    formData.training_start_date, formData.weekly_base_km, formData.sessions_per_week,
    formData.experience_level, formData.last_race_distance, formData.last_race_time,
    formData.easy_pace_sec, formData.preferred_days?.length, api,
  ]);

  // Для режима self пропускаем шаг 2 (цель)
  const getTotalSteps = () => {
    if (specializationOnly) {
      return formData.training_mode === 'self' ? 2 : 3; // режим, (цель), профиль
    }
    return formData.training_mode === 'self' ? 3 : 4;
  };

  // Текущий индекс шага для прогресса и индикатора (0..totalSteps-1).
  const getCurrentStepIndex = () => {
    if (specializationOnly) {
      return step; // 0, 1, 2
    }
    if (formData.training_mode === 'self') {
      return step === 3 ? 2 : step;
    }
    return step;
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
    if (!currentApi) return { valid: true };
    try {
      const result = await currentApi.validateField(field, value);
      if (!result.valid) {
        setValidationErrors(prev => ({ ...prev, [field]: result.message || '' }));
        return { valid: false, message: result.message };
      }
      return { valid: true };
    } catch (err) {
      console.error('Validation error:', err);
      return { valid: true };
    }
  };

  const handleNext = async () => {
    setError('');
    
    if (isMinimalFlow) {
      await handleSubmitMinimal();
      return;
    }
    
    if (specializationOnly) {
      if (step === 0) {
        if (!formData.training_mode) {
          setError('Пожалуйста, выберите режим тренировок');
          return;
        }
        if (formData.training_mode === 'self') {
          setStep(1); // сразу к профилю
        } else {
          setStep(1); // цель
        }
      } else if (step === 1) {
        if (formData.training_mode === 'self') {
          // Режим «самостоятельно»: шаг 1 уже профиль — сразу сохраняем, без перехода на шаг 2
          if (!formData.gender) {
            setError('Пожалуйста, выберите пол');
            return;
          }
          await handleSubmitSpecialization();
          return;
        }
        if (formData.training_mode !== 'self') {
          if (!formData.goal_type) {
            setError('Выберите цель');
            return;
          }
          if (formData.goal_type === 'race') {
            if (!formData.race_date && !formData.target_marathon_date) {
              setError('Укажите дату забега или целевую дату');
              return;
            }
          } else if (formData.goal_type === 'time_improvement') {
            if (!formData.target_marathon_date && !formData.race_date) {
              setError('Укажите дату марафона или дату забега');
              return;
            }
          } else if (formData.goal_type === 'weight_loss') {
            if (!formData.weight_goal_kg) setError('Укажите целевой вес');
            else if (!formData.weight_goal_date) setError('Укажите дату достижения цели');
            else setStep(2);
            return;
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
        }
        setStep(2);
      } else if (step === 2) {
        if (!formData.gender) {
          setError('Пожалуйста, выберите пол');
          return;
        }
        if (formData.training_mode !== 'self' && !formData.experience_level) {
          setError('Укажите ваш опыт');
          return;
        }
        if (formData.training_mode !== 'self' && !formData.will_do_ofp) {
          setError('Ответьте, планируете ли вы делать ОФП');
          return;
        }
        await handleSubmitSpecialization();
      }
      return;
    }
    
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
      if (!formData.email || !String(formData.email).trim()) {
        setError('Введите email');
        return;
      }
      
      const usernameResult = await validateField('username', formData.username);
      if (!usernameResult.valid) {
        setError(usernameResult.message || 'Имя пользователя уже занято');
        return;
      }
      const emailResult = await validateField('email', formData.email.trim());
      if (!emailResult.valid) {
        setError(emailResult.message || 'Некорректный email или уже используется');
        return;
      }
      
      if (formData.training_mode === 'self') {
        setStep(3);
      } else {
        setStep(2);
      }
    } else if (step === 2) {
      // Шаг 2: Цель — валидация в зависимости от типа цели
      if (!formData.goal_type) {
        setError('Выберите цель');
        return;
      }
      if (formData.goal_type === 'race') {
        if (!formData.race_date && !formData.target_marathon_date) {
          setError('Укажите дату забега или целевую дату');
          return;
        }
      } else if (formData.goal_type === 'time_improvement') {
        if (!formData.target_marathon_date && !formData.race_date) {
          setError('Укажите дату марафона или дату забега');
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
      
      if (formData.training_mode !== 'self' && !formData.will_do_ofp) {
        setError('Ответьте, планируете ли вы делать ОФП');
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
        sessions_per_week: formData.preferred_days?.length || formData.sessions_per_week || null,
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
          if (onSuccess) {
            onSuccess(result);
            return;
          }

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

  const handleSubmitMinimal = async () => {
    setLoading(true);
    setError('');
    if (!currentApi) {
      setError('API не инициализирован.');
      setLoading(false);
      return;
    }
    if (verificationStep === 'form') {
      if (!formData.username || formData.username.length < 3) {
        setError('Имя пользователя не менее 3 символов');
        setLoading(false);
        return;
      }
      if (!formData.password || formData.password.length < 6) {
        setError('Пароль не менее 6 символов');
        setLoading(false);
        return;
      }
      if (!formData.email || !String(formData.email).trim()) {
        setError('Введите email');
        setLoading(false);
        return;
      }
      if (isVerificationCoolingDown) {
        setLoading(false);
        return;
      }
      try {
        const usernameResult = await validateField('username', formData.username);
        if (!usernameResult.valid) {
          setError(usernameResult.message || 'Имя пользователя уже занято');
          setLoading(false);
          return;
        }
        const emailResult = await validateField('email', formData.email.trim());
        if (!emailResult.valid) {
          setError(emailResult.message || 'Некорректный email или уже используется');
          setLoading(false);
          return;
        }
        await requestVerificationCode();
      } catch (err) {
        handleRequestError(err);
      } finally {
        setLoading(false);
      }
      return;
    }
    // verificationStep === 'code' — подтверждение кода и создание аккаунта
    const codeDigits = (verificationCode || '').replace(/\D/g, '');
    if (codeDigits.length !== 6) {
      setError('Введите 6-значный код из письма');
      setLoading(false);
      return;
    }
    try {
      const result = await currentApi.registerMinimal({
        username: formData.username,
        email: formData.email.trim(),
        password: formData.password,
        verification_code: codeDigits,
      });
      if (result.success) {
        const authenticatedUser = {
          ...(result.user && typeof result.user === 'object' ? result.user : {}),
          authenticated: true,
        };

        updateUser(authenticatedUser);

        try {
          if (onRegister) {
            await Promise.resolve(onRegister(authenticatedUser));
          }
        } catch (callbackError) {
          if (process.env.NODE_ENV !== 'production') {
            console.warn('[RegisterScreen] onRegister callback failed:', callbackError);
          }
        }

        const successPayload = { ...result, user: authenticatedUser };

        if (onSuccess) {
          try {
            await Promise.resolve(onSuccess(successPayload));
            return;
          } catch (callbackError) {
            if (process.env.NODE_ENV !== 'production') {
              console.warn('[RegisterScreen] onSuccess callback failed:', callbackError);
            }
          }
        }

        navigate('/', { replace: true, state: { registrationSuccess: true } });
      } else {
        setError(result.error || 'Ошибка регистрации');
      }
    } catch (err) {
      handleConfirmError(err);
    } finally {
      setLoading(false);
    }
  };

  const handleSubmitSpecialization = async () => {
    setLoading(true);
    setError('');
    if (!currentApi) {
      setError('API не инициализирован.');
      setLoading(false);
      return;
    }
    try {
      const submitData = {
        ...formData,
        preferred_days: formData.preferred_days,
        preferred_ofp_days: formData.preferred_ofp_days,
        has_treadmill: formData.has_treadmill ? 1 : 0,
        is_first_race_at_distance: formData.is_first_race_at_distance ? 1 : 0,
        sessions_per_week: formData.preferred_days?.length || formData.sessions_per_week || null,
      };
      const result = await currentApi.completeSpecialization(submitData);
      if (result.success) {
        const userData = await currentApi.getCurrentUser();
        if (userData) updateUser(userData);
        if (result.plan_message && specializationOnly) {
          setPlanSubmitResult(result);
        } else {
          onSpecializationSuccess?.(result);
          onClose?.();
        }
      } else {
        setError(result.error || 'Ошибка сохранения');
      }
    } catch (err) {
      setError(err.message || 'Ошибка сохранения');
    } finally {
      setLoading(false);
    }
  };

  const totalSteps = getTotalSteps();
  const currentStepIndex = getCurrentStepIndex();
  const progress = ((currentStepIndex + 1) / totalSteps) * 100;
  const dayLabels = { mon: 'Пн', tue: 'Вт', wed: 'Ср', thu: 'Чт', fri: 'Пт', sat: 'Сб', sun: 'Вс' };
  const healthPrograms = [
    { value: 'start_running', Icon: LeafIcon, name: 'Начни бегать', duration: '8 недель', desc: 'С нуля до 20 минут непрерывного бега' },
    { value: 'couch_to_5k', Icon: RunningIcon, name: '5 км без остановки', duration: '10 недель', desc: 'Классическая программа Couch to 5K' },
    { value: 'regular_running', Icon: HeartIcon, name: 'Регулярный бег', duration: '12 недель', desc: '3 раза в неделю, плавный рост объёма' },
    { value: 'custom', Icon: SettingsIcon, name: 'Свой план', duration: 'по выбору', desc: 'Укажу параметры сам' },
  ];

  /* Короткий попап регистрации в стиле окна логина */
  if (embedInModal && isMinimalFlow) {
    const isCodeStep = verificationStep === 'code';
    return (
      <div className="login-content login-content--inline login-content--login">
        <h1 className="login-title">PlanRun</h1>
        <p className="login-subtitle">{isCodeStep ? 'Подтверждение email' : 'Регистрация'}</p>
        <form
          onSubmit={(e) => { e.preventDefault(); handleNext(); }}
          onFocusCapture={() => error && setError('')}
          className="login-form"
        >
          {!isCodeStep ? (
            <>
              <input
                type="text"
                className="login-input"
                placeholder="Логин"
                value={formData.username}
                onChange={(e) => handleChange('username', e.target.value)}
                autoCapitalize="none"
                autoCorrect="off"
                disabled={loading}
              />
              <input
                type="email"
                className="login-input"
                placeholder="Email"
                value={formData.email}
                onChange={(e) => handleChange('email', e.target.value)}
                autoComplete="email"
                disabled={loading}
              />
              <input
                type="password"
                className="login-input"
                placeholder="Пароль"
                value={formData.password}
                onChange={(e) => handleChange('password', e.target.value)}
                autoCapitalize="none"
                autoCorrect="off"
                disabled={loading}
              />
            </>
          ) : (
            <>
              <p className="register-code-hint">Код отправлен на <strong>{formData.email}</strong></p>
              <p className="register-code-spam">Если письма нет во входящих, проверьте папку «Спам».</p>
              <input
                type="text"
                inputMode="numeric"
                maxLength={6}
                className="login-input register-code-input"
                placeholder="000000"
                value={verificationCode}
                onChange={(e) => setVerificationCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
                autoComplete="one-time-code"
                disabled={loading}
                autoFocus
              />
              <p className="register-attempts">Осталось попыток: {codeAttemptsLeft}</p>
            </>
          )}
          {error && <div className="login-error">{error}</div>}
          <button
            type="button"
            className="login-button"
            disabled={loading || (!isCodeStep && isVerificationCoolingDown)}
            onClick={(e) => { e.preventDefault(); handleNext(); }}
          >
            {loading
              ? (isCodeStep ? 'Проверка...' : 'Отправка...')
              : (!isCodeStep && isVerificationCoolingDown)
                ? `Подождите ${verificationSecondsLeft} сек`
                : isCodeStep ? 'Подтвердить' : 'Далее'}
          </button>
          {isCodeStep && (
            <button
              type="button"
              className="register-resend-code"
              onClick={async (e) => {
                e.preventDefault();
                if (loading || isVerificationCoolingDown) return;
                setError('');
                setLoading(true);
                try {
                  await requestVerificationCode();
                } catch (err) {
                  handleRequestError(err);
                } finally {
                  setLoading(false);
                }
              }}
              disabled={loading || isVerificationCoolingDown}
            >
              {isVerificationCoolingDown ? `Повтор через ${verificationSecondsLeft} сек` : 'Запросить код повторно'}
            </button>
          )}
        </form>
      </div>
    );
  }

  const formContent = (
      <div className={embedInModal ? 'register-content register-content--modal' : 'register-content'}>
        <h1 className="register-title">{isMinimalFlow ? 'Регистрация' : 'Настройте свой план'}</h1>
        <p className="register-subtitle">
          {isMinimalFlow ? 'Логин, email и пароль — потом настроите план на дашборде' : 'Выберите режим, цель и заполните профиль'}
        </p>

        {!isMinimalFlow && specializationOnly && planSubmitResult && (
          <div className="register-plan-started">
            <div className="register-plan-started__icon">
              <BotIcon />
            </div>
            <h3 className="register-plan-started__title">План тренировок запущен на генерацию</h3>
            <p className="register-plan-started__message">
              {planSubmitResult.plan_message || 'Это займёт 3–5 минут. На дашборде отобразится статус.'}
            </p>
            <button
              type="button"
              className="btn btn-primary register-plan-started__btn"
              onClick={() => {
                onSpecializationSuccess?.(planSubmitResult);
                setPlanSubmitResult(null);
                onClose?.();
              }}
            >
              Закрыть
            </button>
          </div>
        )}

        {!isMinimalFlow && !planSubmitResult && (
        <>
        <div className="register-step-progress">
          <div className="register-step-progress-fill" style={{ width: `${progress}%` }}></div>
        </div>
        
        <div className="step-indicator">
          {specializationOnly ? (
            formData.training_mode === 'self' ? (
            <>
              <div className={`step ${currentStepIndex >= 0 ? 'active' : ''}`}>1. Режим</div>
              <div className={`step ${currentStepIndex >= 1 ? 'active' : ''}`}>2. Профиль</div>
            </>
          ) : (
            <>
              <div className={`step ${currentStepIndex >= 0 ? 'active' : ''}`}>1. Режим</div>
              <div className={`step ${currentStepIndex >= 1 ? 'active' : ''}`}>2. Цель</div>
              <div className={`step ${currentStepIndex >= 2 ? 'active' : ''}`}>3. Профиль</div>
            </>
          )) : formData.training_mode === 'self' ? (
            <>
              <div className={`step ${currentStepIndex >= 0 ? 'active' : ''}`}>1. Режим</div>
              <div className={`step ${currentStepIndex >= 1 ? 'active' : ''}`}>2. Аккаунт</div>
              <div className={`step ${currentStepIndex >= 2 ? 'active' : ''}`}>3. Профиль</div>
            </>
          ) : (
            <>
              <div className={`step ${currentStepIndex >= 0 ? 'active' : ''}`}>1. Режим</div>
              <div className={`step ${currentStepIndex >= 1 ? 'active' : ''}`}>2. Аккаунт</div>
              <div className={`step ${currentStepIndex >= 2 ? 'active' : ''}`}>3. Цель</div>
              <div className={`step ${currentStepIndex >= 3 ? 'active' : ''}`}>4. Профиль</div>
            </>
          )}
        </div>
        </>
        )}

        {error && <div className="register-error">{error}</div>}

        {!(specializationOnly && planSubmitResult) && (
        <form
          onSubmit={(e) => { e.preventDefault(); handleNext(); }}
          onFocusCapture={() => error && setError('')}
          className="register-form"
        >
          {/* Минимальная регистрация: аккаунт → код подтверждения */}
          {isMinimalFlow && (
            <div className="form-step">
              {verificationStep === 'form' ? (
                <>
                  <div className="form-group">
                    <label>Имя пользователя <span className="required">*</span></label>
                    <input type="text" value={formData.username} onChange={(e) => handleChange('username', e.target.value)} placeholder="ivan_runner" required />
                    {validationErrors.username && <small className="error-text">{validationErrors.username}</small>}
                  </div>
                  <div className="form-group">
                    <label>Пароль <span className="required">*</span></label>
                    <input type="password" value={formData.password} onChange={(e) => handleChange('password', e.target.value)} placeholder="Минимум 6 символов" minLength={6} required />
                  </div>
                  <div className="form-group">
                    <label>Email <span className="required">*</span></label>
                    <input type="email" value={formData.email} onChange={(e) => handleChange('email', e.target.value)} placeholder="your@email.com" required />
                    {validationErrors.email && <small className="error-text">{validationErrors.email}</small>}
                  </div>
                </>
              ) : (
                <>
                  <p className="register-code-hint">Код отправлен на <strong>{formData.email}</strong>. Введите 6 цифр из письма.</p>
                  <p className="register-code-spam">Если письма нет во входящих, проверьте папку «Спам».</p>
                  <div className="form-group">
                    <label>Код подтверждения <span className="required">*</span></label>
                    <input
                      type="text"
                      inputMode="numeric"
                      maxLength={6}
                      className="register-code-input"
                      value={verificationCode}
                      onChange={(e) => setVerificationCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
                      placeholder="000000"
                      autoComplete="one-time-code"
                      disabled={loading}
                    />
                    <small className="register-attempts">Осталось попыток: {codeAttemptsLeft}</small>
                  </div>
                  <button
                    type="button"
                    className="btn btn-secondary btn--sm"
                    disabled={loading || isVerificationCoolingDown}
                    onClick={async (e) => {
                      e.preventDefault();
                      if (loading || isVerificationCoolingDown) return;
                      setError('');
                      setLoading(true);
                      try {
                        await requestVerificationCode();
                      } catch (err) {
                        handleRequestError(err);
                      } finally {
                        setLoading(false);
                      }
                    }}
                  >
                    {isVerificationCoolingDown ? `Повтор через ${verificationSecondsLeft} сек` : 'Запросить код повторно'}
                  </button>
                </>
              )}
              <div className="register-form-primary-action">
                <button
                  type="button"
                  className="btn btn-primary"
                  disabled={loading || (verificationStep !== 'code' && isVerificationCoolingDown)}
                  onClick={(e) => { e.preventDefault(); handleNext(); }}
                >
                  {loading
                    ? (verificationStep === 'code' ? 'Проверка...' : 'Отправка...')
                    : (verificationStep !== 'code' && isVerificationCoolingDown)
                      ? `Подождите ${verificationSecondsLeft} сек`
                      : verificationStep === 'code' ? 'Подтвердить' : 'Далее'}
                </button>
              </div>
              <p className="register-privacy-note">
                Регистрируясь, вы соглашаетесь с{' '}
                <Link to="/privacy">политикой конфиденциальности</Link>.
              </p>
            </div>
          )}

          {/* Шаг 0: Выбор режима (только в режиме специализации) */}
          {!isMinimalFlow && step === 0 && (
            <div className="form-step">
              <p className="register-step-lead">
                Выбери, как хочешь тренироваться:
              </p>
              
              <div className="training-mode-grid">
                <label
                  className="training-mode-option"
                  onClick={() => {
                    handleChange('training_mode', 'ai');
                    setStep(1);
                  }}
                >
                  <input type="radio" name="training_mode" value="ai" checked={formData.training_mode === 'ai'} onChange={() => {}} readOnly />
                  <div className="training-mode-option__left">
                    <div className="training-mode-option__icon">
                      <BotIcon />
                    </div>
                    <div className="training-mode-option__title">AI-ТРЕНЕР</div>
                    <div className="training-mode-option__price">(бесплатно)</div>
                  </div>
                  <div className="training-mode-option__right">
                    <ul className="training-mode-option__list">
                      <li><CheckIcon className="training-mode-option__list-icon" />AI создаст персональный план</li>
                      <li><CheckIcon className="training-mode-option__list-icon" />Адаптирует его каждую неделю</li>
                      <li><CheckIcon className="training-mode-option__list-icon" />Анализирует твой прогресс</li>
                    </ul>
                    <div className="training-mode-option-badge training-mode-option-badge--recommend">
                      <CheckIcon className="training-mode-option-badge__icon" />
                      Рекомендуем
                    </div>
                  </div>
                 
                </label>
                <label
                  className="training-mode-option"
                  onClick={() => {
                    handleChange('training_mode', 'self');
                    setStep(1);
                  }}
                >
                  <input type="radio" name="training_mode" value="self" checked={formData.training_mode === 'self'} onChange={() => {}} readOnly />
                  <div className="training-mode-option__left">
                    <div className="training-mode-option__icon">
                      <PenLineIcon />
                    </div>
                    <div className="training-mode-option__title">САМ</div>
                    <div className="training-mode-option__price">(бесплатно)</div>
                  </div>
                  <div className="training-mode-option__right">
                    <ul className="training-mode-option__list">
                      <li><CheckIcon className="training-mode-option__list-icon" />Создавай план сам</li>
                      <li><CheckIcon className="training-mode-option__list-icon" />Добавляй тренировки вручную</li>
                      <li><CheckIcon className="training-mode-option__list-icon" />Полный контроль над планом</li>
                    </ul>
                  </div>
                </label>
                <label className="training-mode-option training-mode-option--soon">
                  <input type="radio" name="training_mode" value="coach" disabled />
                  <div className="training-mode-option__left">
                    <div className="training-mode-option__icon">
                      <UserIcon />
                    </div>
                    <div className="training-mode-option__title">ЖИВОЙ ТРЕНЕР</div>
                    <div className="training-mode-option__price">(от 1000₽/мес)</div>
                  </div>
                  <div className="training-mode-option__right">
                    <ul className="training-mode-option__list">
                      <li><CheckIcon className="training-mode-option__list-icon" />Персональный тренер</li>
                      <li><CheckIcon className="training-mode-option__list-icon" />Корректировки плана</li>
                      <li>в реальном времени</li>
                      <li><CheckIcon className="training-mode-option__list-icon" />Поддержка и мотивация</li>
                    </ul>
                    <div className="training-mode-option-badge training-mode-option-badge--soon">Скоро</div>
                  </div>
                </label>
              </div>
            </div>
          )}

          {/* Шаг 2: Цель (только в режиме специализации) */}
          {!isMinimalFlow && ((!specializationOnly && step === 2) || (specializationOnly && step === 1 && formData.training_mode !== 'self')) && (
            <div className="form-step">
              <h2><TargetIcon className="form-heading-icon" />Какая у тебя цель?</h2>
              
              <div className="form-group">
                <label>Что вы хотите достичь? <span className="required">*</span></label>
                <select
                  className="goal-type-select"
                  value={formData.goal_type}
                  onChange={(e) => handleChange('goal_type', e.target.value)}
                  required
                >
                  <option value="">Выберите</option>
                  <option value="health">Просто бегать для здоровья</option>
                  <option value="race">Подготовка к забегу</option>
                  <option value="weight_loss">Снижение веса</option>
                  <option value="time_improvement">Улучшить время</option>
                </select>
              </div>

              <div
                className={`goal-step-fields-wrap ${formData.goal_type ? 'goal-step-fields-wrap--visible' : ''}`}
                style={{ maxHeight: formData.goal_type ? goalStepFieldsHeight : 0 }}
              >
                <div ref={goalStepFieldsInnerRef} className="goal-step-fields-wrap__inner">
              <div className="goal-recommendations">
                <div className="goal-recommendations__content">
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
                    <label>Дата забега {formData.goal_type === 'race' && <span className="required">*</span>}</label>
                    <input
                      type="date"
                      value={formData.race_date}
                      onChange={(e) => handleChange('race_date', e.target.value)}
                      min={new Date(Date.now() + 86400000).toISOString().split('T')[0]}
                      required={formData.goal_type === 'race'}
                    />
                    <small>План будет рассчитан до этой даты (для «Улучшить время» — дата марафона). Дата должна быть в будущем.</small>
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
                      {healthPrograms.map(program => (
                        <label key={program.value} className={`program-option ${formData.health_program === program.value ? 'selected' : ''}`}>
                          <input
                            type="radio"
                            name="health_program"
                            value={program.value}
                            checked={formData.health_program === program.value}
                            onChange={(e) => handleChange('health_program', e.target.value)}
                          />
                          <div className="program-card">
                            <span className="program-icon">
                              <program.Icon />
                            </span>
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
                </div>
              </div>

              <div className="form-group goal-step-date-field">
                <label><CalendarIcon className="form-label-icon" />С какого дня начинаем тренировки? <span className="required">*</span></label>
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
              </div>
            </div>
          )}

          {/* Шаг 3: Профиль (только в режиме специализации) */}
          {!isMinimalFlow && ((!specializationOnly && step === 3) || (specializationOnly && (step === 2 || (step === 1 && formData.training_mode === 'self')))) && (
            <div className="form-step">
              <h2>Ваш профиль</h2>
              
              {formData.training_mode === 'self' && (
                <>
                  <p className="register-step-lead">
                    Для создания календаря нужна базовая информация:
                  </p>
                  <div className="form-group">
                    <label><CalendarIcon className="form-label-icon" />Дата начала тренировок</label>
                    <input
                      type="date"
                      value={formData.training_start_date || ''}
                      onChange={(e) => handleChange('training_start_date', e.target.value)}
                      min={new Date().toISOString().split('T')[0]}
                    />
                    <small>С какой даты начинается ваш календарь (по умолчанию — следующий понедельник)</small>
                  </div>
                </>
              )}
              
              <div className="form-group">
                <label>Пол <span className="required">*</span></label>
                <div className="form-row form-row--two-cols profile-gender-row">
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
                  <div className="form-row form-row--two-cols">
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
                      <label><GraduationCapIcon className="form-label-icon" />Какой у тебя опыт? <span className="required">*</span></label>
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
                      <label><RunningIcon className="form-label-icon" />Сколько бегаешь сейчас?</label>
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
                    <div className="form-group hidden">
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
                      <h3 className="extended-profile__title">Расскажи больше о своём беге</h3>
                      <p className="extended-profile__desc">
                        Эти данные помогут создать более точный план (необязательно)
                      </p>
                      
                      <div className="form-group">
                        <label><PaceIcon className="form-label-icon" />Комфортный темп (минуты:секунды на км)</label>
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
                        <label><TargetIcon className="form-label-icon" />Это твой первый забег на целевую дистанцию?</label>
                        <div className="radio-group-horizontal">
                          <label className="radio-option">
                            <input
                              type="radio"
                              name="is_first_race_at_distance"
                              value="1"
                              checked={formData.is_first_race_at_distance === true || formData.is_first_race_at_distance === 1}
                              onChange={() => handleChange('is_first_race_at_distance', true)}
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
                        <label><MedalIcon className="form-label-icon" />Последний официальный результат</label>
                        <small className="form-group-hint form-group-hint--spaced">Поможет точнее оценить твой уровень</small>
                        
                        <div className="form-row form-row--compact">
                          <div className="form-group form-group--tight">
                            <label className="form-label--secondary">Дистанция</label>
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
                            <div className="form-group form-group--tight">
                              <label className="form-label--secondary">Дистанция последнего забега (км)</label>
                              <input
                                type="number"
                                min="0"
                                max="200"
                                step="0.1"
                                placeholder="15"
                                value={formData.last_race_distance_km}
                                onChange={(e) => handleChange('last_race_distance_km', e.target.value)}
                              />
                              <small className="form-help-inline">Укажите точную дистанцию в километрах, если она отличается от стандартных</small>
                            </div>
                          )}
                        </div>
                        
                        {formData.last_race_distance && formData.last_race_distance !== '' && (
                          <div className="form-row">
                            <div className="form-group form-group--tight">
                              <label className="form-label--secondary">Результат</label>
                              <input
                                type="time"
                                step="1"
                                value={formData.last_race_time}
                                onChange={(e) => handleChange('last_race_time', e.target.value)}
                              />
                              <small>Формат: ЧЧ:ММ:СС</small>
                            </div>
                            <div className="form-group form-group--tight">
                              <label className="form-label--secondary">Когда</label>
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
                    <label>Планируете ли вы делать ОФП? <span className="required">*</span></label>
                    <small className="form-group-hint form-group-hint--spaced">ОФП — общая физическая подготовка (силовые, растяжка)</small>
                    <div className="form-row form-row--two-cols ofp-choice-row">
                      <label className={`gender-option ${formData.will_do_ofp === 'yes' ? 'selected' : ''}`}>
                        <input
                          type="radio"
                          name="will_do_ofp"
                          value="yes"
                          checked={formData.will_do_ofp === 'yes'}
                          onChange={(e) => handleChange('will_do_ofp', e.target.value)}
                        />
                        Да
                      </label>
                      <label className={`gender-option ${formData.will_do_ofp === 'no' ? 'selected' : ''}`}>
                        <input
                          type="radio"
                          name="will_do_ofp"
                          value="no"
                          checked={formData.will_do_ofp === 'no'}
                          onChange={(e) => handleChange('will_do_ofp', e.target.value)}
                        />
                        Нет
                      </label>
                    </div>
                  </div>

                  <div className={`ofp-fields-wrap ${formData.will_do_ofp === 'yes' ? 'ofp-fields-wrap--visible' : ''}`}>
                    <div className="ofp-fields-wrap__inner">
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
                    </div>
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

          {/* Оценка реалистичности цели — всегда видна на последнем шаге для race/time_improvement */}
          {!isMinimalFlow && showRaceFields && step === totalSteps - 1 && (
            <div className="goal-assessment-card goal-assessment-card--always-visible">

              {/* Состояние: не хватает данных */}
              {!goalAssessment && !assessmentLoading && (
                <div className="goal-assessment-card__header">
                  <span className="goal-assessment-card__icon"><ClipboardListIcon size={18} /></span>
                  <span className="goal-assessment-card__title">Оценка цели</span>
                </div>
              )}
              {!goalAssessment && !assessmentLoading && (
                <div className="goal-assessment-card__msg goal-assessment-card__msg--info">
                  <p>
                    {!formData.race_distance
                      ? 'Укажите дистанцию забега на шаге «Цель», чтобы получить оценку.'
                      : !formData.race_date
                        ? 'Укажите дату забега на шаге «Цель».'
                        : 'Заполните данные профиля выше — оценка появится автоматически.'}
                  </p>
                </div>
              )}

              {/* Состояние: загрузка */}
              {assessmentLoading && (
                <div className="goal-assessment-card__header">
                  <span className="goal-assessment-card__icon goal-assessment-card__icon--spin"><TimeIcon size={18} /></span>
                  <span className="goal-assessment-card__title">Оцениваем цель...</span>
                </div>
              )}

              {/* Состояние: результат */}
              {goalAssessment && !assessmentLoading && (
                <>
                  <div className={`goal-assessment-card__verdict goal-assessment-card__verdict--${goalAssessment.verdict}`}>
                    <span className="goal-assessment-card__icon">
                      {goalAssessment.verdict === 'realistic' ? <CheckIcon size={18} /> : goalAssessment.verdict === 'challenging' ? <AlertTriangleIcon size={18} /> : <XCircleIcon size={18} />}
                    </span>
                    <span className="goal-assessment-card__title">
                      {goalAssessment.verdict === 'realistic' ? 'Цель реалистична' : goalAssessment.verdict === 'challenging' ? 'Амбициозная цель' : 'Цель труднодостижима'}
                    </span>
                  </div>

                  {goalAssessment.messages?.map((msg, i) => (
                    <div key={i} className={`goal-assessment-card__msg goal-assessment-card__msg--${msg.type}`}>
                      <p>{msg.text}</p>
                      {msg.suggestions?.length > 0 && (
                        <div className="goal-assessment-card__suggestions">
                          {msg.suggestions.map((s, j) => (
                            s.action ? (
                              <button key={j} type="button" className="btn btn-secondary btn--sm"
                                onClick={() => handleChange(s.action.field, s.action.value)}>
                                {s.text}
                              </button>
                            ) : (
                              <p key={j} className="goal-assessment-card__tip">{s.text}</p>
                            )
                          ))}
                        </div>
                      )}
                    </div>
                  ))}

                  {(goalAssessment.predictions && Object.keys(goalAssessment.predictions).length > 0 || goalAssessment.training_paces) && (
                    <div className="goal-assessment-card__data">
                      {goalAssessment.vdot && (
                        <div className="goal-assessment-card__vdot">
                          VDOT: <strong>{goalAssessment.vdot}</strong>{goalAssessment.vdot_source ? ` (на основе: ${goalAssessment.vdot_source})` : ''}
                        </div>
                      )}
                      {goalAssessment.predictions && Object.keys(goalAssessment.predictions).length > 0 && (
                        <div className="goal-assessment-card__section">
                          <div className="goal-assessment-card__section-title">Прогноз по дистанциям</div>
                          <div className="goal-assessment-card__grid">
                            {Object.entries(goalAssessment.predictions).map(([dist, time]) => (
                              <div key={dist} className="goal-assessment-card__cell">
                                <span className="goal-assessment-card__label">{dist === 'half' ? 'Полумарафон' : dist === 'marathon' ? 'Марафон' : dist.toUpperCase()}</span>
                                <span className="goal-assessment-card__value">{time}</span>
                              </div>
                            ))}
                          </div>
                        </div>
                      )}
                      {goalAssessment.training_paces && (
                        <div className="goal-assessment-card__section">
                          <div className="goal-assessment-card__section-title">Тренировочные зоны (мин/км)</div>
                          <div className="goal-assessment-card__grid">
                            <div className="goal-assessment-card__cell">
                              <span className="goal-assessment-card__label">Лёгкий</span>
                              <span className="goal-assessment-card__value">{goalAssessment.training_paces.easy}</span>
                            </div>
                            <div className="goal-assessment-card__cell">
                              <span className="goal-assessment-card__label">Марафонский</span>
                              <span className="goal-assessment-card__value">{goalAssessment.training_paces.marathon}</span>
                            </div>
                            <div className="goal-assessment-card__cell">
                              <span className="goal-assessment-card__label">Пороговый</span>
                              <span className="goal-assessment-card__value">{goalAssessment.training_paces.threshold}</span>
                            </div>
                            <div className="goal-assessment-card__cell">
                              <span className="goal-assessment-card__label">Интервальный</span>
                              <span className="goal-assessment-card__value">{goalAssessment.training_paces.interval}</span>
                            </div>
                          </div>
                        </div>
                      )}
                    </div>
                  )}
                </>
              )}
            </div>
          )}

          {!isMinimalFlow && (step > 0 || specializationOnly) && (
            <div className="register-form-actions">
              {step > 0 && (
                <button
                  type="button"
                  className="btn btn-secondary btn--block"
                  onClick={() => {
                    if (specializationOnly) {
                      setStep(step - 1);
                    } else if (step === 3 && formData.training_mode === 'self') {
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
              {step !== 0 && (
                <button
                  type="button"
                  className={`btn btn-primary btn--block ${goalAssessment?.verdict === 'unrealistic' && step === totalSteps - 1 ? 'btn--disabled-unrealistic' : ''}`}
                  disabled={loading || (goalAssessment?.verdict === 'unrealistic' && step === totalSteps - 1)}
                  onClick={(e) => { e.preventDefault(); handleNext(); }}
                >
                  {loading ? 'Обработка...' : (goalAssessment?.verdict === 'unrealistic' && step === totalSteps - 1) ? 'Исправьте параметры выше' : (specializationOnly && step === totalSteps - 1) ? 'Сохранить' : (step === 3 || (specializationOnly && step === 2)) ? 'Создать аккаунт' : 'Далее →'}
                </button>
              )}
            </div>
          )}
        </form>
        )}
      </div>
    );

  return embedInModal ? formContent : <div className="register-container">{formContent}</div>;
};

export default RegisterScreen;
