/**
 * Модальное окно входа (используется на лендинге)
 */

import React, { useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import Modal from './common/Modal';
import LoginForm from './LoginForm';

const LoginModal = ({ isOpen, onClose }) => {
  const navigate = useNavigate();

  const handleSuccess = () => {
    onClose();
    navigate('/');
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
