/**
 * Модальное окно настройки PIN-кода (4 цифры).
 * Два шага: ввод PIN и подтверждение.
 */

import React, { useState, useRef, useEffect } from 'react';
import Modal from './Modal';
import PinInput from './PinInput';
import PinAuthService from '../../services/PinAuthService';
import './PinSetupModal.css';

const PinSetupModal = ({ isOpen, onClose, onSuccess, tokens }) => {
  const [step, setStep] = useState(1);
  const [pin1, setPin1] = useState('');
  const [pin2, setPin2] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const pin2Ref = useRef('');

  useEffect(() => {
    pin2Ref.current = pin2;
  }, [pin2]);

  const reset = () => {
    setStep(1);
    setPin1('');
    setPin2('');
    setError('');
    setLoading(false);
  };

  const handleClose = () => {
    reset();
    onClose();
  };

  const handlePin1Next = () => {
    if (pin1.length !== 4) {
      setError('PIN должен быть 4 цифры');
      return;
    }
    setStep(2);
    setPin2('');
    setError('');
  };

  const handlePin2Complete = async (pin) => {
    const valueToUse = pin ?? pin2Ref.current;
    setPin2(valueToUse);
    if (valueToUse !== pin1) {
      setError('PIN не совпадает. Попробуйте снова.');
      return;
    }
    if (!tokens?.accessToken || !tokens?.refreshToken) {
      setError('Сессия истекла. Закройте окно и войдите заново.');
      return;
    }
    setError('');
    setLoading(true);
    try {
      const savePromise = PinAuthService.setPinAndSaveTokens(valueToUse, tokens.accessToken, tokens.refreshToken);
      const timeoutPromise = new Promise((_, reject) =>
        setTimeout(() => reject(new Error('Сохранение заняло слишком много времени. Попробуйте снова.')), 15000)
      );
      await Promise.race([savePromise, timeoutPromise]);
      onSuccess?.();
      handleClose();
    } catch (e) {
      setError(e?.message || 'Ошибка сохранения PIN');
    } finally {
      setLoading(false);
    }
  };

  const handleBack = () => {
    setStep(1);
    setPin1('');
    setPin2('');
    setError('');
  };

  if (!isOpen) return null;

  return (
    <Modal isOpen={isOpen} onClose={handleClose} title="Установить PIN-код" size="small" variant="modern">
      <div className="pin-setup-modal">
        <p className="pin-setup-modal__hint">
          {step === 1 ? 'Придумайте PIN из 4 цифр' : 'Повторите PIN для подтверждения'}
        </p>
        {step === 1 ? (
          <>
            <PinInput
              length={4}
              value={pin1}
              onChange={(v) => { setPin1(v); setError(''); }}
              error={error}
              autoFocus
            />
            <button type="button" className="btn btn-primary btn--block" onClick={handlePin1Next} disabled={pin1.length !== 4}>
              Далее
            </button>
          </>
        ) : (
          <>
            <PinInput
              length={pin1.length}
              value={pin2}
              onChange={(v) => { setPin2(v); setError(''); }}
              onComplete={(v) => handlePin2Complete(v)}
              error={error}
              autoFocus
              disabled={loading}
            />
            <button
              type="button"
              className="btn btn-primary btn--block"
              onClick={() => handlePin2Complete()}
              disabled={loading || pin2.length !== 4}
            >
              {loading ? 'Сохранение…' : 'Подтвердить'}
            </button>
            <button type="button" className="btn btn-secondary btn--sm pin-setup-modal__back" onClick={handleBack} disabled={loading}>
              Назад
            </button>
          </>
        )}
      </div>
    </Modal>
  );
};

export default PinSetupModal;
