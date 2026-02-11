/**
 * Модальное окно регистрации (на лендинге).
 * Минимальная регистрация: только логин, email, пароль. После успеха — дашборд и попап специализации.
 */

import React from 'react';
import { useNavigate } from 'react-router-dom';
import Modal from './common/Modal';
import RegisterScreen from '../screens/RegisterScreen';

const RegisterModal = ({ isOpen, onClose, onRegister }) => {
  const navigate = useNavigate();

  const handleSuccess = () => {
    onClose();
    navigate('/', { state: { registrationSuccess: true } });
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
      <RegisterScreen
        embedInModal
        minimalOnly
        onRegister={onRegister}
        onSuccess={handleSuccess}
        onClose={onClose}
      />
    </Modal>
  );
};

export default RegisterModal;
