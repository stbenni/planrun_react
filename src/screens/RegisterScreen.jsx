/**
 * Экран минимальной регистрации (дизайн v3 RegAccount/RegVerify).
 * Логин/email/пароль → 6-значный код из письма → авто-логин. После регистрации
 * пользователь с onboarding_completed=0 редиректится на полноэкранный /onboarding.
 *
 * Используется в двух местах:
 *  - RegisterModal (embedInModal) — модалка на лендинге;
 *  - маршрут /register — full-screen (через .rgv3-shell).
 */

import { useState, useRef, useEffect } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import useAuthStore from '../stores/useAuthStore';
import getAuthClient from '../api/getAuthClient';
import { MailIcon } from '../components/common/Icons';
import { useVerificationCodeFlow } from '../hooks/useVerificationCodeFlow';
import './RegisterV3.css';

function detectBrowserTimezone() {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || null;
  } catch {
    return null;
  }
}

const RegisterScreen = ({ onRegister, embedInModal, onSuccess, onClose }) => {
  const navigate = useNavigate();
  const { api, updateUser } = useAuthStore();
  const currentApi = api || getAuthClient();

  const [formData, setFormData] = useState({ password: '', email: '' });
  const [validationErrors, setValidationErrors] = useState({});
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const codeInputRef = useRef(null);
  // Какой 6-значный код уже отправляли на проверку — чтобы автосабмит не зациклился
  // (после неверного кода не пытаться снова, пока юзер не изменит ввод).
  const autoSubmittedCodeRef = useRef('');

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

  const handleChange = (field, value) => {
    setFormData((prev) => ({ ...prev, [field]: value }));
    if (validationErrors[field]) {
      setValidationErrors((prev) => {
        const next = { ...prev };
        delete next[field];
        return next;
      });
    }
  };

  const validateField = async (field, value) => {
    if (!currentApi) return { valid: true };
    try {
      const result = await currentApi.validateField(field, value);
      if (!result.valid) {
        setValidationErrors((prev) => ({ ...prev, [field]: result.message || '' }));
        return { valid: false, message: result.message };
      }
      return { valid: true };
    } catch (err) {
      console.error('Validation error:', err);
      return { valid: true };
    }
  };

  const requestVerificationCode = async () => {
    if (!currentApi) throw new Error('API не инициализирован.');
    await currentApi.sendVerificationCode(formData.email.trim());
    markCodeSent();
  };

  const handleSubmit = async () => {
    setLoading(true);
    setError('');
    if (!currentApi) {
      setError('API не инициализирован.');
      setLoading(false);
      return;
    }

    // Шаг «форма»: валидация + отправка кода на email.
    if (verificationStep === 'form') {
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

    // Шаг «код»: подтверждение и создание аккаунта.
    const codeDigits = (verificationCode || '').replace(/\D/g, '');
    if (codeDigits.length !== 6) {
      setError('Введите 6-значный код из письма');
      setLoading(false);
      return;
    }
    try {
      const result = await currentApi.registerMinimal({
        email: formData.email.trim(),
        password: formData.password,
        verification_code: codeDigits,
        timezone: detectBrowserTimezone() || undefined,
      });
      if (result.success) {
        const authenticatedUser = {
          ...(result.user && typeof result.user === 'object' ? result.user : {}),
          authenticated: true,
        };
        updateUser(authenticatedUser);

        try {
          if (onRegister) await Promise.resolve(onRegister(authenticatedUser));
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

  const handleResend = async (e) => {
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
  };

  const isCodeStep = verificationStep === 'code';

  // Автосабмит: как только введены 6 цифр (вручную или авто-вставкой OTP на iOS) —
  // проверяем код без нажатия кнопки. Защита: не сабмитим повторно тот же код и во время loading.
  useEffect(() => {
    if (verificationStep !== 'code') {
      autoSubmittedCodeRef.current = '';
      return;
    }
    const digits = (verificationCode || '').replace(/\D/g, '');
    if (digits.length === 6 && !loading && autoSubmittedCodeRef.current !== digits) {
      autoSubmittedCodeRef.current = digits;
      handleSubmit();
    }
  }, [verificationCode, verificationStep, loading]);

  const minimal = (
    <div className="rgv3">
      {!isCodeStep ? (
        <>
          {!embedInModal && (
            <div className="rgv3__logo-wrap">
              <div className="rgv3__logo-mark">P</div>
              <div className="rgv3__brand">planrun</div>
              <div className="rgv3__tagline">Твой персональный план бега</div>
            </div>
          )}
          <form
            onSubmit={(e) => { e.preventDefault(); handleSubmit(); }}
            onFocusCapture={() => error && setError('')}
          >
            <div className={`rgv3__card ${embedInModal ? 'rgv3__card--bare' : ''}`}>
              <h1 className="rgv3__title">Создать аккаунт</h1>
              {!embedInModal && <p className="rgv3__subtitle">Займёт 30 секунд</p>}

              <div className="rgv3__field">
                <label>Email</label>
                <input
                  type="email"
                  className="rgv3__input"
                  placeholder="your@email.com"
                  value={formData.email}
                  onChange={(e) => handleChange('email', e.target.value)}
                  autoComplete="email"
                  autoFocus
                  disabled={loading}
                />
                {validationErrors.email && <small className="rgv3__error-text">{validationErrors.email}</small>}
              </div>
              <div className="rgv3__field">
                <label>Пароль</label>
                <input
                  type="password"
                  className="rgv3__input"
                  placeholder="Минимум 6 символов"
                  value={formData.password}
                  onChange={(e) => handleChange('password', e.target.value)}
                  autoCapitalize="none"
                  autoCorrect="off"
                  disabled={loading}
                />
              </div>

              {error && <div className="rgv3__error">{error}</div>}

              <button
                type="submit"
                className="rgv3__cta"
                disabled={loading || isVerificationCoolingDown}
              >
                {loading
                  ? 'Отправка...'
                  : isVerificationCoolingDown
                    ? `Подождите ${verificationSecondsLeft} сек`
                    : 'Продолжить →'}
              </button>
            </div>

            <p className="rgv3__signin">
              Уже есть аккаунт?{' '}
              <button type="button" className="rgv3__link" onClick={() => { onClose?.(); navigate('/login'); }}>Войти</button>
            </p>
            <p className="rgv3__privacy">
              Регистрируясь, вы соглашаетесь с <Link to="/privacy">политикой конфиденциальности</Link>.
            </p>
          </form>
        </>
      ) : (
        <>
          <div className="rgv3__verify-head">
            <div className="rgv3__verify-icon"><MailIcon size={34} /></div>
            <h1 className="rgv3__title">Проверь почту</h1>
            <p className="rgv3__subtitle">
              Отправили код на <b>{formData.email}</b>. Введи 6 цифр из письма.
            </p>
          </div>
          <form
            onSubmit={(e) => { e.preventDefault(); handleSubmit(); }}
            onFocusCapture={() => error && setError('')}
          >
            <div className="rgv3__code" onClick={() => codeInputRef.current?.focus()}>
              <input
                ref={codeInputRef}
                type="text"
                inputMode="numeric"
                maxLength={6}
                className="rgv3__code-input"
                value={verificationCode}
                onChange={(e) => setVerificationCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
                autoComplete="one-time-code"
                disabled={loading}
                autoFocus
              />
              {[0, 1, 2, 3, 4, 5].map((i) => (
                <div
                  key={i}
                  className={`rgv3__code-box ${verificationCode[i] ? 'rgv3__code-box--filled' : ''} ${verificationCode.length === i ? 'rgv3__code-box--active' : ''}`}
                >
                  {verificationCode[i] || ''}
                </div>
              ))}
            </div>

            <p className="rgv3__resend">
              Письма нет? Проверь «Спам» ·{' '}
              <button type="button" className="rgv3__link" onClick={handleResend} disabled={loading || isVerificationCoolingDown}>
                {isVerificationCoolingDown ? `Повтор через ${verificationSecondsLeft} сек` : 'Отправить ещё раз'}
              </button>
            </p>

            {error && <div className="rgv3__error">{error}</div>}

            <button
              type="button"
              className="rgv3__cta"
              disabled={loading}
              onClick={(e) => { e.preventDefault(); handleSubmit(); }}
            >
              {loading ? 'Проверка...' : 'Подтвердить'}
            </button>
            <p className="rgv3__attempts">Осталось попыток: {codeAttemptsLeft}</p>
          </form>
        </>
      )}
    </div>
  );

  return embedInModal ? minimal : <div className="rgv3-shell">{minimal}</div>;
};

export default RegisterScreen;
