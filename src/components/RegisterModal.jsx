/**
 * Модальное окно регистрации (на лендинге, те же размеры по смыслу что и страница)
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
      size="xlarge"
      hideHeader
    >
      <RegisterScreen
        embedInModal
        onRegister={onRegister}
        onSuccess={handleSuccess}
        onClose={onClose}
      />
    </Modal>
  );
};

export default RegisterModal;
