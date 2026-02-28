/**
 * Экран блокировки приложения (PIN или биометрия).
 * Показывается при запуске, когда включены PIN или биометрия.
 */

import React, { useState, useEffect, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import { FingerprintIcon } from './Icons';
import PinInput from './PinInput';
import useAuthStore from '../../stores/useAuthStore';
import { isNativeCapacitor } from '../../services/TokenStorageService';
import '../../screens/LoginScreen.css';
import './TopHeader.css';

const LockScreen = () => {
  const [pinValue, setPinValue] = useState('');
  const [pinLoading, setPinLoading] = useState(false);
  const [biometricLoading, setBiometricLoading] = useState(false);
  const [error, setError] = useState('');
  const [isNetworkError, setIsNetworkError] = useState(false);
  const [pinEnabled, setPinEnabled] = useState(false);
  const [biometricAvailable, setBiometricAvailable] = useState(false);
  const [biometricEnabled, setBiometricEnabled] = useState(false);
  const hasTriggeredBiometric = useRef(false);

  const navigate = useNavigate();
  const { pinLogin, biometricLogin, logout, checkBiometricAvailability, checkPinAvailability } = useAuthStore();

  useEffect(() => {
    if (!isNativeCapacitor()) return;
    checkPinAvailability().then((r) => setPinEnabled(r?.enabled ?? false)).catch(() => {});
    checkBiometricAvailability().then((result) => {
      setBiometricAvailable(result?.available ?? false);
      setBiometricEnabled(result?.enabled ?? false);
    }).catch(() => {});
  }, [checkBiometricAvailability, checkPinAvailability]);

  // Авто-вызов биометрии при открытии, если отпечаток включён (один раз за сессию экрана)
  useEffect(() => {
    if (!biometricAvailable || !biometricEnabled || hasTriggeredBiometric.current) return;
    hasTriggeredBiometric.current = true;
    handleBiometricLogin();
  }, [biometricAvailable, biometricEnabled]);

  const handlePinSubmit = async (pin) => {
    if (!pin || pin.length !== 4) return;
    setPinLoading(true);
    setError('');
    setIsNetworkError(false);
    try {
      const result = await pinLogin(pin);
      if (result?.success) {
        return;
      }
      setError(result?.error || 'Неверный PIN');
      setIsNetworkError(result?.isNetworkError ?? false);
      if (!result?.isNetworkError) setPinValue('');
    } catch (err) {
      setError(err.message || 'Ошибка входа по PIN');
      setPinValue('');
    } finally {
      setPinLoading(false);
    }
  };

  const handleBiometricLogin = async () => {
    if (!biometricAvailable || !biometricEnabled) {
      setError('Биометрическая аутентификация недоступна');
      return;
    }
    setBiometricLoading(true);
    setError('');
    setIsNetworkError(false);
    try {
      const result = await biometricLogin();
      if (!result?.success) {
        setError(result?.error || 'Биометрическая аутентификация не прошла');
        setIsNetworkError(result?.isNetworkError ?? false);
      }
    } catch (err) {
      setError(err.message || 'Ошибка биометрической аутентификации');
    } finally {
      setBiometricLoading(false);
    }
  };

  const handleLoginByPassword = () => {
    logout(true).catch(() => {});
    if (isNativeCapacitor()) {
      window.location.href = '/landing?openLogin=1';
    } else {
      navigate('/landing', { state: { openLogin: true } });
    }
  };

  return (
    <div className="login-container">
      <div className="login-content login-content--inline login-content--lock">
        <h1 className="lock-screen-logo top-header-logo">
          <span className="logo-text"><span className="logo-plan">plan</span><span className="logo-run">RUN</span></span>
        </h1>
        <p className="login-subtitle">Разблокировать приложение</p>

        {pinEnabled && (
          <div className="biometric-section pin-section">
            <p className="pin-section-hint">Введите PIN-код</p>
            <PinInput
              length={4}
              value={pinValue}
              onChange={(v) => { setPinValue(v); setError(''); }}
              onComplete={handlePinSubmit}
              disabled={pinLoading || biometricLoading}
              showKeypad
              keypadExtra={biometricAvailable && biometricEnabled ? (
                <button
                  type="button"
                  className="pin-input__keypad-btn pin-input__keypad-btn--biometric"
                  onClick={handleBiometricLogin}
                  disabled={biometricLoading || pinLoading}
                  aria-label="Войти по отпечатку"
                >
                  {biometricLoading ? (
                    <span className="pin-input__keypad-loading">...</span>
                  ) : (
                    <FingerprintIcon size={28} strokeWidth={1.8} />
                  )}
                </button>
              ) : null}
            />
          </div>
        )}

        {!pinEnabled && biometricAvailable && biometricEnabled && (
          <div className="biometric-section">
            <button
              type="button"
              className="biometric-button"
              onClick={handleBiometricLogin}
              disabled={biometricLoading}
            >
              {biometricLoading ? (
                'Проверка...'
              ) : (
                <>
                  <span className="biometric-icon" aria-hidden><FingerprintIcon size={24} /></span>
                  <span>Войти по отпечатку</span>
                </>
              )}
            </button>
          </div>
        )}

        {error && (
          <div className={`login-error ${error.includes('Сессия истекла') ? 'login-error--session-expired' : ''} ${isNetworkError ? 'login-error--network' : ''}`}>
            {error}
          </div>
        )}

        {isNetworkError && (
          <button
            type="button"
            className="btn btn-primary btn--block"
            onClick={() => (pinEnabled ? handlePinSubmit(pinValue) : handleBiometricLogin())}
            disabled={pinLoading || biometricLoading || (pinEnabled && pinValue?.length !== 4)}
          >
            {pinLoading || biometricLoading ? 'Проверка...' : 'Повторить'}
          </button>
        )}

        <button
          type="button"
          className={`login-back-link ${error?.includes('Сессия истекла') ? 'login-back-link--prominent' : ''}`}
          onClick={handleLoginByPassword}
        >
          Войти по паролю
        </button>
      </div>
    </div>
  );
};

export default LockScreen;
