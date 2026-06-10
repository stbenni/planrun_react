/**
 * Модальное окно входа (используется на лендинге)
 */

import React from 'react';
import Modal from './common/Modal';
import LoginForm from './LoginForm';

const LoginModal = ({ isOpen, onClose }) => {
  // Навигацию после входа НЕ делаем здесь: LandingScreen сам уводит по isAuthenticated
  // (на /, а гейт App.jsx → /onboarding при незавершённом онбординге). Двойной navigate
  // вызывал «дёрганье» / / onboarding и пустой кадр на мобильных.
  const handleSuccess = () => {
    onClose();
  };

  if (!isOpen) return null;

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      size="small"
      hideHeader
      centerBody
    >
      <LoginForm onSuccess={handleSuccess} />
    </Modal>
  );
};

export default LoginModal;
