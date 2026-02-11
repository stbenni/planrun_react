/**
 * Попап специализации (второй этап регистрации): режим, цель, профиль.
 * Показывается на дашборде, если пользователь прошёл только минимальную регистрацию.
 */

import React from 'react';
import Modal from './common/Modal';
import RegisterScreen from '../screens/RegisterScreen';
import useAuthStore from '../stores/useAuthStore';

const SpecializationModal = ({ isOpen, onClose }) => {
  const { updateUser, api } = useAuthStore();

  const handleSuccess = async () => {
    try {
      const userData = api ? await api.getCurrentUser() : null;
      if (userData) updateUser(userData);
    } catch (_) {}
    onClose();
  };

  if (!isOpen) return null;

  return (
    <Modal isOpen={isOpen} onClose={onClose} size="xlarge" hideHeader>
      <RegisterScreen
        embedInModal
        specializationOnly
        onSpecializationSuccess={handleSuccess}
        onClose={onClose}
      />
    </Modal>
  );
};

export default SpecializationModal;
